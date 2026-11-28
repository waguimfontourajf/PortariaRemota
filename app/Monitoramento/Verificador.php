<?php

namespace App\Monitoramento;

use App\Models\Dispositivo;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Testa se os equipamentos respondem: ping, porta TCP ou driver.
 * Os pings rodam em paralelo para o ciclo de 1 minuto caber folgado.
 */
class Verificador
{
    /**
     * @param  Collection<int, Dispositivo>  $dispositivos
     * @return array<int, ResultadoVerificacao> indexado pelo id do dispositivo
     */
    public function verificar(Collection $dispositivos): array
    {
        $resultados = [];
        $porMetodo = $dispositivos->groupBy(fn (Dispositivo $d) => $d->metodo_monitoramento->value);

        foreach (($porMetodo['ping'] ?? collect())->chunk((int) config('portaria.monitoramento.paralelo', 20)) as $lote) {
            $resultados += $this->pingEmLote($lote);
        }

        foreach ($porMetodo['tcp'] ?? [] as $d) {
            $resultados[$d->id] = $this->tcp((string) $d->host, (int) ($d->porta_monitoramento ?: $d->porta ?: 80));
        }

        foreach ($porMetodo['driver'] ?? [] as $d) {
            $resultados[$d->id] = $this->driver($d);
        }

        return $resultados;
    }

    /**
     * @param  Collection<int, Dispositivo>  $lote
     * @return array<int, ResultadoVerificacao>
     */
    private function pingEmLote(Collection $lote): array
    {
        $timeout = (int) config('portaria.monitoramento.timeout_ms', 1500);
        $validos = $lote->filter(fn (Dispositivo $d) => $this->hostValido($d->host));
        $resultados = $lote->diffKeys($validos)
            ->mapWithKeys(fn (Dispositivo $d) => [$d->id => new ResultadoVerificacao(false, null, 'Endereço (IP) inválido ou não informado.')])
            ->all();

        if ($validos->isEmpty()) {
            return $resultados;
        }

        $execucoes = Process::pool(function (Pool $pool) use ($validos, $timeout) {
            foreach ($validos as $d) {
                $pool->as((string) $d->id)->timeout((int) ceil($timeout / 1000) + 5)->command($this->comandoPing((string) $d->host, $timeout));
            }
        })->start()->wait();

        foreach ($validos as $d) {
            $execucao = $execucoes[(string) $d->id];
            $resultados[$d->id] = $this->interpretarPing($execucao->successful(), $execucao->output());
        }

        return $resultados;
    }

    /**
     * @return list<string>
     */
    public function comandoPing(string $host, int $timeoutMs): array
    {
        return PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '1', '-w', (string) $timeoutMs, $host]
            : ['ping', '-c', '1', '-W', (string) max(1, (int) ceil($timeoutMs / 1000)), $host];
    }

    /**
     * Só considera resposta quando há "TTL" (o ping do Windows devolve
     * sucesso até para "host de destino inacessível").
     */
    public function interpretarPing(bool $sucesso, string $saida): ResultadoVerificacao
    {
        // O ping do Windows escreve na página de código do console (CP850).
        if (! mb_check_encoding($saida, 'UTF-8')) {
            $saida = mb_convert_encoding($saida, 'UTF-8', 'CP850');
        }

        if (! $sucesso || ! preg_match('/ttl[=:]/i', $saida)) {
            $linha = collect(preg_split('/\R/', $saida))->map(fn ($l) => trim($l))
                ->first(fn ($l) => preg_match('/(inacess|unreach|esgotado|timed out|timeout|não pôde|could not|falha|failure|100% )/iu', $l));

            return new ResultadoVerificacao(false, null, $linha ?: 'Sem resposta ao ping.');
        }

        $latencia = null;
        if (preg_match('/(?:time|tempo)\s*([=<])\s*([\d.,]+)\s*ms/iu', $saida, $m)) {
            $latencia = $m[1] === '<' ? 0 : (int) round((float) str_replace(',', '.', $m[2]));
        }

        return new ResultadoVerificacao(true, $latencia);
    }

    public function tcp(string $host, int $porta): ResultadoVerificacao
    {
        if (! $this->hostValido($host)) {
            return new ResultadoVerificacao(false, null, 'Endereço (IP) inválido ou não informado.');
        }

        $inicio = hrtime(true);
        $socket = @stream_socket_client("tcp://{$host}:{$porta}", $erroNumero, $erroTexto, config('portaria.monitoramento.timeout_ms', 1500) / 1000);

        if (! $socket) {
            return new ResultadoVerificacao(false, null, "Porta {$porta} não respondeu ({$erroTexto}).");
        }

        fclose($socket);

        return new ResultadoVerificacao(true, (int) round((hrtime(true) - $inicio) / 1_000_000));
    }

    private function driver(Dispositivo $d): ResultadoVerificacao
    {
        if (! $d->temDriver()) {
            return new ResultadoVerificacao(false, null, 'Equipamento sem driver para teste.');
        }

        try {
            $resultado = $d->driverDeRele()->testar($d);
        } catch (Throwable $e) {
            return new ResultadoVerificacao(false, null, $e->getMessage());
        }

        return new ResultadoVerificacao($resultado->sucesso, $resultado->sucesso ? $resultado->duracaoMs : null, $resultado->sucesso ? null : $resultado->mensagem);
    }

    /**
     * Aceita IPv4, IPv6 e nomes (sem espaços nem caracteres de comando).
     */
    private function hostValido(?string $host): bool
    {
        return filled($host) && (bool) preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $host) && ! str_starts_with($host, '-');
    }
}
