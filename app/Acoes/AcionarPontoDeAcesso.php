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
use App\Models\Visita;
use App\Support\RegrasDeAcesso;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Abre um ponto de acesso (portão, porta...) e registra no histórico.
 *
 * É o ÚNICO caminho para acionar um ponto: painel web, app, central e
 * (futuramente) interfone e servidor local passam por aqui.
 */
class AcionarPontoDeAcesso
{
    private AcaoEvento $acao = AcaoEvento::Abrir;

    private ?Visita $visita = null;

    /**
     * @param  bool|null  $ligar  Para pontos "liga / desliga": true = ligar, false = desligar,
     *                            null = inverter o estado atual (consulta o aparelho).
     */
    public function __invoke(PontoAcesso $ponto, ?User $usuario, OrigemEvento $origem, ?string $ip = null, ?bool $ligar = null): EventoAcesso
    {
        $this->acao = $ponto->ehLigaDesliga()
            ? ($ligar === null ? AcaoEvento::Abrir : ($ligar ? AcaoEvento::Ligar : AcaoEvento::Desligar))
            : AcaoEvento::Abrir;

        $this->visita = null;

        if (! RegrasDeAcesso::podeAbrir($usuario, $ponto)) {
            return $this->registrar($ponto, $usuario, $origem, $ip, ResultadoEvento::Negado, 'Usuário sem permissão para abrir este ponto.');
        }

        return $this->acionar($ponto, $usuario, $origem, $ip, $ligar);
    }

    /**
     * Abertura feita por um visitante, pelo link que o morador enviou.
     * Cada abertura bem-sucedida consome um uso da autorização.
     */
    public function porVisitante(PontoAcesso $ponto, Visita $visita, ?string $ip = null): EventoAcesso
    {
        $this->acao = AcaoEvento::Abrir;
        $this->visita = $visita;

        $permitido = $ponto->condominio_id === $visita->condominio_id
            && $ponto->liberado_visitantes
            && ! $ponto->ehLigaDesliga()
            && $visita->pontos()->whereKey($ponto->id)->exists();

        if (! $permitido) {
            return $this->registrar($ponto, null, OrigemEvento::Visitante, $ip, ResultadoEvento::Negado, 'Este ponto não está liberado para esta autorização de visitante.');
        }

        // Reserva um uso de forma atômica (dois toques ao mesmo tempo não passam do limite).
        $bloqueio = DB::transaction(function () use ($visita) {
            $atual = Visita::query()->lockForUpdate()->findOrFail($visita->id);
            $motivo = $atual->bloqueio();

            if ($motivo === null) {
                $atual->increment('usos');
            }

            return $motivo;
        });

        if ($bloqueio !== null) {
            return $this->registrar($ponto, null, OrigemEvento::Visitante, $ip, ResultadoEvento::Negado, $bloqueio);
        }

        $evento = $this->acionar($ponto, null, OrigemEvento::Visitante, $ip, null);

        // Se o portão não abriu, devolve o uso para o visitante tentar de novo.
        if ($evento->resultado !== ResultadoEvento::Sucesso) {
            Visita::query()->whereKey($visita->id)->where('usos', '>', 0)->decrement('usos');
        }

        return $evento;
    }

    private function acionar(PontoAcesso $ponto, ?User $usuario, OrigemEvento $origem, ?string $ip, ?bool $ligar): EventoAcesso
    {
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

            if ($ponto->modo_acionamento === ModoAcionamento::Alternar) {
                $resultado = match ($ligar) {
                    true => $driver->ligar($dispositivo, $ponto->canal),
                    false => $driver->desligar($dispositivo, $ponto->canal),
                    null => $this->alternar($driver, $dispositivo, $ponto->canal),
                };

                if ($resultado->sucesso) {
                    $ponto->registrarEstado($resultado->ligado);
                    $this->acao = $resultado->ligado ? AcaoEvento::Ligar : AcaoEvento::Desligar;
                }
            } else {
                $resultado = $driver->pulso($dispositivo, $ponto->canal, $ponto->tempo_pulso_ms);
            }

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

        $nome = $this->visita
            ? 'Visitante: '.$this->visita->nome.($this->visita->autor ? ' (autorizado por '.$this->visita->autor->name.')' : '')
            : $usuario?->name;

        return EventoAcesso::create([
            'condominio_id' => $ponto->condominio_id,
            'ponto_acesso_id' => $ponto->id,
            'dispositivo_id' => $ponto->dispositivo_id,
            'user_id' => $usuario?->id,
            'pessoa_id' => $pessoaId,
            'ponto_nome' => $ponto->nome,
            'usuario_nome' => mb_substr((string) $nome, 0, 255) ?: null,
            'visita_id' => $this->visita?->id,
            'origem' => $origem,
            'acao' => $this->acao,
            'resultado' => $resultado,
            'detalhe' => mb_substr($detalhe, 0, 1000),
            'duracao_ms' => $duracao,
            'ip' => $ip,
        ]);
    }
}
