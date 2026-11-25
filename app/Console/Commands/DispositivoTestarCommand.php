<?php

namespace App\Console\Commands;

use App\Dispositivos\Drivers\TuyaLocal;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;

class DispositivoTestarCommand extends Command
{
    protected $signature = 'portaria:dispositivo-testar
        {dispositivo? : UUID ou nome do dispositivo}
        {--acao=testar : testar, estado, ligar, desligar ou pulso}
        {--canal=1}
        {--ms=1000 : Tempo do pulso}
        {--detalhes : Mostra os pacotes trocados (Tuya)}';

    protected $description = 'Testa a comunicação com um dispositivo cadastrado (diagnóstico)';

    public function handle(): int
    {
        $dispositivo = $this->escolher();

        if (! $dispositivo) {
            $this->error('Dispositivo não encontrado.');

            return self::FAILURE;
        }

        $driver = $dispositivo->driverDeRele();
        $canal = max(1, (int) $this->option('canal'));
        $acao = (string) $this->option('acao');

        $this->info("Dispositivo: {$dispositivo->nome} | driver: {$dispositivo->nomeDoDriver()} | endereço: ".($dispositivo->host ?? '-'));

        if ($this->option('detalhes') && $driver instanceof TuyaLocal) {
            return $this->diagnosticoTuya($driver, $dispositivo, $acao, $canal);
        }

        $resultado = match ($acao) {
            'estado' => $driver->estado($dispositivo, $canal),
            'ligar' => $driver->ligar($dispositivo, $canal),
            'desligar' => $driver->desligar($dispositivo, $canal),
            'pulso' => $driver->pulso($dispositivo, $canal, (int) $this->option('ms')),
            default => $driver->testar($dispositivo),
        };

        $dispositivo->registrarContato($resultado->sucesso, $resultado->sucesso ? null : $resultado->mensagem);

        return $this->mostrar($resultado);
    }

    private function diagnosticoTuya(TuyaLocal $driver, Dispositivo $dispositivo, string $acao, int $canal): int
    {
        try {
            $cliente = $driver->cliente($dispositivo)->aoDiagnosticar(fn (string $linha) => $this->line('  '.$linha));

            if (in_array($acao, ['ligar', 'desligar'], true)) {
                $dps = array_map('trim', explode(',', (string) $dispositivo->opcao('dps', '1')));
                $cliente->definir([$dps[$canal - 1] ?? '1' => $acao === 'ligar']);
                $this->info('Comando aceito.');
            } else {
                $this->info('Data points: '.json_encode($cliente->consultar()));
            }

            $cliente->fechar();

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function mostrar(Resultado $resultado): int
    {
        $estado = $resultado->ligado === null ? '' : ' | estado: '.($resultado->ligado ? 'LIGADO' : 'desligado');

        if ($resultado->sucesso) {
            $this->info("OK ({$resultado->duracaoMs} ms): {$resultado->mensagem}{$estado}");
        } else {
            $this->error("FALHA ({$resultado->duracaoMs} ms): {$resultado->mensagem}");
        }

        if ($resultado->dados !== [] && $this->output->isVerbose()) {
            $this->line(json_encode($resultado->dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $resultado->sucesso ? self::SUCCESS : self::FAILURE;
    }

    private function escolher(): ?Dispositivo
    {
        $busca = $this->argument('dispositivo');

        if ($busca) {
            return Dispositivo::where('uuid', $busca)->orWhere('nome', $busca)->first();
        }

        $opcoes = Dispositivo::query()->orderBy('nome')->get()->mapWithKeys(fn (Dispositivo $d) => [$d->uuid => "{$d->nome} ({$d->nomeDoDriver()})"])->all();

        if ($opcoes === []) {
            return null;
        }

        return Dispositivo::where('uuid', select('Qual dispositivo?', $opcoes))->first();
    }
}
