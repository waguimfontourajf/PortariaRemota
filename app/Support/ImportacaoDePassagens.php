<?php

namespace App\Support;

use App\Dispositivos\Contratos\LeitoraFacial;
use App\Dispositivos\PassagemNaLeitora;
use App\Enums\AcaoEvento;
use App\Enums\OrigemEvento;
use App\Enums\ResultadoEvento;
use App\Models\Condominio;
use App\Models\Dispositivo;
use App\Models\EventoAcesso;
use App\Models\Pessoa;
use App\Models\PontoAcesso;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Traz para o Histórico as passagens registradas pelas leitoras faciais
 * (rosto reconhecido, negado, não reconhecido). Cada evento é importado uma
 * única vez (referência externa única por leitora).
 */
class ImportacaoDePassagens
{
    /** Na primeira importação de uma leitora, busca só os últimos dias. */
    public const DIAS_NA_PRIMEIRA_VEZ = 7;

    private const LOTES_POR_VEZ = 20;

    public function __construct(private readonly SincronizacaoFacial $sincronizacao) {}

    /**
     * Importa de todas as leitoras do condomínio, no máximo a cada
     * $intervaloSegundos (para poder ser chamado ao abrir o Histórico).
     *
     * @return array{importadas: int, erros: list<string>}
     */
    public function importarDoCondominio(Condominio|int $condominio, int $intervaloSegundos = 0): array
    {
        $totais = ['importadas' => 0, 'erros' => []];
        $id = $condominio instanceof Condominio ? $condominio->id : $condominio;

        // Evita duas importações simultâneas do mesmo condomínio.
        $trava = Cache::lock('passagens:condominio:'.$id, 300);

        if (! $trava->get()) {
            return $totais;
        }

        try {
            foreach ($this->sincronizacao->leitoras($id) as $leitora) {
                if ($intervaloSegundos > 0 && $leitora->eventos_importados_em?->gt(now()->subSeconds($intervaloSegundos))) {
                    continue;
                }

                try {
                    $totais['importadas'] += $this->importar($leitora);
                } catch (Throwable $e) {
                    $totais['erros'][] = "{$leitora->nome}: {$e->getMessage()}";
                    $leitora->forceFill(['eventos_importados_em' => now()])->saveQuietly(); // tenta de novo no próximo intervalo
                    $leitora->registrarContato(false, $e->getMessage());
                }
            }
        } finally {
            $trava->release();
        }

        return $totais;
    }

    /**
     * Importa as passagens novas de uma leitora. Devolve quantas entraram.
     */
    public function importar(Dispositivo $leitora): int
    {
        /** @var LeitoraFacial $driver */
        $driver = $leitora->driverDeRele();
        $desde = now()->subDays(self::DIAS_NA_PRIMEIRA_VEZ);
        $ponto = PontoAcesso::query()->where('dispositivo_id', $leitora->id)->orderBy('id')->first();
        $importadas = 0;
        $cursor = $leitora->eventos_cursor;

        for ($lote = 0; $lote < self::LOTES_POR_VEZ; $lote++) {
            $resultado = $driver->buscarPassagens($leitora, $cursor, $desde);

            foreach ($resultado['passagens'] as $passagem) {
                $importadas += $this->registrar($leitora, $ponto, $passagem) ? 1 : 0;
            }

            $anterior = $cursor;
            $cursor = $resultado['cursor'] ?? $cursor;

            if (! $resultado['mais'] || $cursor === $anterior) {
                break;
            }
        }

        $leitora->forceFill(['eventos_cursor' => $cursor, 'eventos_importados_em' => now()])->saveQuietly();
        $leitora->registrarContato(true);

        return $importadas;
    }

    private function registrar(Dispositivo $leitora, ?PontoAcesso $ponto, PassagemNaLeitora $passagem): bool
    {
        $existe = EventoAcesso::query()->where('dispositivo_id', $leitora->id)
            ->where('referencia_externa', $passagem->referencia)->exists();

        if ($existe) {
            return false;
        }

        $pessoa = $passagem->codigoPessoa
            ? Pessoa::withTrashed()->doCondominio($leitora->condominio_id)->find($passagem->codigoPessoa)
            : null;

        EventoAcesso::create([
            'condominio_id' => $leitora->condominio_id,
            'ponto_acesso_id' => $ponto?->id,
            'dispositivo_id' => $leitora->id,
            'referencia_externa' => $passagem->referencia,
            'user_id' => $pessoa?->user_id, // o morador com login vê a própria passagem
            'pessoa_id' => $pessoa?->id,
            'ponto_nome' => $ponto->nome ?? $leitora->nome,
            'usuario_nome' => $pessoa->nome ?? ($passagem->codigoPessoa ? "Código {$passagem->codigoPessoa} (não cadastrado)" : 'Rosto não reconhecido'),
            'origem' => OrigemEvento::Leitora,
            'acao' => AcaoEvento::Abrir,
            'resultado' => $passagem->liberada ? ResultadoEvento::Sucesso : ResultadoEvento::Negado,
            'detalhe' => $passagem->descricao,
            'created_at' => $passagem->momento->copy()->utc(),
        ]);

        return true;
    }
}
