<?php

namespace App\Console\Commands;

use App\Monitoramento\Monitor;
use Illuminate\Console\Command;

class MonitorarCommand extends Command
{
    protected $signature = 'portaria:monitorar {--condominio= : ID de um condomínio específico}';

    protected $description = 'Verifica se os equipamentos estão online (roda sozinho a cada minuto pelo agendador)';

    public function handle(Monitor $monitor): int
    {
        $inicio = microtime(true);
        $resumo = $monitor->executar($this->option('condominio') ? (int) $this->option('condominio') : null);
        Monitor::registrarExecucao();

        $this->info(sprintf(
            '%d verificados em %.1f s: %d online, %d lentos, %d offline.',
            $resumo['verificados'], microtime(true) - $inicio, $resumo['online'], $resumo['lentos'], $resumo['offline'],
        ));

        foreach ($resumo['cairam'] as $nome) {
            $this->error("Caiu: {$nome}");
        }

        foreach ($resumo['voltaram'] as $nome) {
            $this->info("Voltou: {$nome}");
        }

        return self::SUCCESS;
    }
}
