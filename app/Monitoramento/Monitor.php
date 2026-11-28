<?php

namespace App\Monitoramento;

use App\Enums\StatusMonitoramento;
use App\Models\Dispositivo;
use App\Models\QuedaDispositivo;
use App\Notificacoes\AlertaDeRede;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Executa um ciclo de monitoramento: testa os equipamentos, atualiza o
 * status, registra quedas e dispara os alertas.
 *
 * Regras:
 *  - respondeu acima de "lento_ms" → lento;
 *  - "falhas_para_offline" falhas seguidas → offline (evita alarme por um pacote perdido);
 *  - ao ficar offline abre uma queda e avisa; ao voltar fecha a queda e avisa.
 */
class Monitor
{
    public function __construct(
        private readonly Verificador $verificador,
        private readonly AlertaDeRede $alertas,
    ) {}

    /**
     * @return array{verificados: int, online: int, lentos: int, offline: int, cairam: list<string>, voltaram: list<string>}
     */
    public function executar(?int $condominioId = null, ?Collection $somente = null): array
    {
        $dispositivos = $somente ?? $this->monitorados($condominioId)->get();
        $resultados = $this->verificador->verificar($dispositivos);
        $resumo = ['verificados' => 0, 'online' => 0, 'lentos' => 0, 'offline' => 0, 'cairam' => [], 'voltaram' => []];

        foreach ($dispositivos as $dispositivo) {
            $resultado = $resultados[$dispositivo->id] ?? null;

            if (! $resultado) {
                continue;
            }

            $mudanca = $this->aplicar($dispositivo, $resultado);
            $resumo['verificados']++;

            match ($dispositivo->status) {
                StatusMonitoramento::Online => $resumo['online']++,
                StatusMonitoramento::Lento => $resumo['lentos']++,
                StatusMonitoramento::Offline => $resumo['offline']++,
                default => null,
            };

            if ($mudanca === 'caiu') {
                $resumo['cairam'][] = $dispositivo->nome;
            } elseif ($mudanca === 'voltou') {
                $resumo['voltaram'][] = $dispositivo->nome;
            }
        }

        return $resumo;
    }

    /**
     * @return Builder<Dispositivo>
     */
    public function monitorados(?int $condominioId = null): Builder
    {
        return Dispositivo::query()
            ->with('condominio')
            ->where('ativo', true)
            ->where('monitorar', true)
            ->whereHas('condominio', fn ($q) => $q->where('ativo', true))
            ->where(fn ($q) => $q->whereNotNull('host')->orWhere('metodo_monitoramento', 'driver'))
            ->when($condominioId, fn ($q) => $q->where('condominio_id', $condominioId));
    }

    /**
     * @return 'caiu'|'voltou'|null
     */
    public function aplicar(Dispositivo $dispositivo, ResultadoVerificacao $resultado): ?string
    {
        $anterior = $dispositivo->status;
        $agora = now();
        $mudanca = null;

        if ($resultado->respondeu) {
            $novo = $resultado->latenciaMs !== null && $resultado->latenciaMs > (int) config('portaria.monitoramento.lento_ms', 500)
                ? StatusMonitoramento::Lento
                : StatusMonitoramento::Online;

            $dispositivo->forceFill([
                'falhas_seguidas' => 0,
                'latencia_ms' => $resultado->latenciaMs,
                'online' => true,
                'ultimo_contato_em' => $agora,
                'ultimo_erro' => null,
            ]);

            if ($anterior === StatusMonitoramento::Offline) {
                $queda = $dispositivo->quedas()->whereNull('fim')->latest('inicio')->first();
                if ($queda) {
                    $queda->update(['fim' => $agora]);
                    $this->alertas->voltou($dispositivo, $queda);
                }
                $mudanca = 'voltou';
            }
        } else {
            $falhas = min(255, $dispositivo->falhas_seguidas + 1);
            $dispositivo->forceFill([
                'falhas_seguidas' => $falhas,
                'latencia_ms' => null,
                'ultimo_erro' => mb_substr((string) $resultado->erro, 0, 500),
            ]);

            $novo = $falhas >= (int) config('portaria.monitoramento.falhas_para_offline', 2)
                ? StatusMonitoramento::Offline
                : ($anterior === StatusMonitoramento::Desconhecido ? StatusMonitoramento::Desconhecido : $anterior);

            if ($novo === StatusMonitoramento::Offline) {
                $dispositivo->forceFill(['online' => false]);
            }

            if ($novo === StatusMonitoramento::Offline && $anterior !== StatusMonitoramento::Offline) {
                $queda = QuedaDispositivo::create([
                    'condominio_id' => $dispositivo->condominio_id,
                    'dispositivo_id' => $dispositivo->id,
                    'inicio' => $agora,
                    'motivo' => mb_substr((string) $resultado->erro, 0, 500),
                ]);
                $mudanca = 'caiu';
            }
        }

        if ($novo !== $anterior) {
            $dispositivo->forceFill(['status' => $novo, 'status_desde' => $agora]);
        }

        $dispositivo->forceFill(['verificado_em' => $agora])->saveQuietly();

        if ($mudanca === 'caiu') {
            $this->alertas->caiu($dispositivo, $queda);
        }

        return $mudanca;
    }
}
