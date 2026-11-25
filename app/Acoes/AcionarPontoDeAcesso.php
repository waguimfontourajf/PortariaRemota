<?php

namespace App\Acoes;

use App\Dispositivos\Resultado;
use App\Enums\AcaoEvento;
use App\Enums\ModoAcionamento;
use App\Enums\OrigemEvento;
use App\Enums\ResultadoEvento;
use App\Models\EventoAcesso;
use App\Models\Pessoa;
use App\Models\PontoAcesso;
use App\Models\User;
use App\Support\RegrasDeAcesso;
use Illuminate\Support\Facades\Cache;

/**
 * Abre um ponto de acesso (portão, porta...) e registra no histórico.
 *
 * É o ÚNICO caminho para acionar um ponto: painel web, app, central e
 * (futuramente) interfone e servidor local passam por aqui.
 */
class AcionarPontoDeAcesso
{
    public function __invoke(PontoAcesso $ponto, ?User $usuario, OrigemEvento $origem, ?string $ip = null): EventoAcesso
    {
        if (! RegrasDeAcesso::podeAbrir($usuario, $ponto)) {
            return $this->registrar($ponto, $usuario, $origem, $ip, ResultadoEvento::Negado, 'Usuário sem permissão para abrir este ponto.');
        }

        $dispositivo = $ponto->dispositivo;

        if (! $ponto->ativo) {
            return $this->registrar($ponto, $usuario, $origem, $ip, ResultadoEvento::Falha, 'Ponto de acesso desativado.');
        }

        if (! $dispositivo || ! $dispositivo->ativo) {
            return $this->registrar($ponto, $usuario, $origem, $ip, ResultadoEvento::Falha, 'Nenhum dispositivo ativo ligado a este ponto.');
        }

        // Impede dois acionamentos simultâneos do mesmo ponto (ex.: duplo clique).
        $trava = Cache::lock('acionamento:ponto:'.$ponto->id, (int) config('portaria.acionamento.trava_segundos', 10));

        if (! $trava->get()) {
            return $this->registrar($ponto, $usuario, $origem, $ip, ResultadoEvento::Ocupado, 'Já existe um acionamento em andamento para este ponto.');
        }

        try {
            $driver = $dispositivo->driverDeRele();

            $resultado = $ponto->modo_acionamento === ModoAcionamento::Alternar
                ? $this->alternar($driver, $dispositivo, $ponto->canal)
                : $driver->pulso($dispositivo, $ponto->canal, $ponto->tempo_pulso_ms);

            $dispositivo->registrarContato($resultado->sucesso, $resultado->sucesso ? null : $resultado->mensagem);
        } finally {
            $trava->release();
        }

        return $this->registrar(
            $ponto, $usuario, $origem, $ip,
            $resultado->sucesso ? ResultadoEvento::Sucesso : ResultadoEvento::Falha,
            $resultado->mensagem,
            $resultado->duracaoMs,
        );
    }

    private function alternar($driver, $dispositivo, int $canal): Resultado
    {
        $estado = $driver->estado($dispositivo, $canal);

        if (! $estado->sucesso) {
            return $estado;
        }

        return $estado->ligado ? $driver->desligar($dispositivo, $canal) : $driver->ligar($dispositivo, $canal);
    }

    private function registrar(PontoAcesso $ponto, ?User $usuario, OrigemEvento $origem, ?string $ip, ResultadoEvento $resultado, string $detalhe, ?int $duracao = null): EventoAcesso
    {
        $pessoaId = $usuario
            ? Pessoa::query()->doCondominio($ponto->condominio_id)->where('user_id', $usuario->id)->value('id')
            : null;

        return EventoAcesso::create([
            'condominio_id' => $ponto->condominio_id,
            'ponto_acesso_id' => $ponto->id,
            'dispositivo_id' => $ponto->dispositivo_id,
            'user_id' => $usuario?->id,
            'pessoa_id' => $pessoaId,
            'ponto_nome' => $ponto->nome,
            'usuario_nome' => $usuario?->name,
            'origem' => $origem,
            'acao' => AcaoEvento::Abrir,
            'resultado' => $resultado,
            'detalhe' => mb_substr($detalhe, 0, 1000),
            'duracao_ms' => $duracao,
            'ip' => $ip,
        ]);
    }
}
