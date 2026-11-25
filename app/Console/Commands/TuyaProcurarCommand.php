<?php

namespace App\Console\Commands;

use App\Dispositivos\Tuya\DescobertaTuya;
use Illuminate\Console\Command;
use Throwable;

class TuyaProcurarCommand extends Command
{
    protected $signature = 'portaria:tuya-procurar {--segundos=10 : Quanto tempo escutar a rede}';

    protected $description = 'Procura aparelhos Tuya na rede local e mostra IP, ID e versão do protocolo';

    public function handle(DescobertaTuya $descoberta): int
    {
        $segundos = max(3, (int) $this->option('segundos'));
        $this->info("Escutando a rede por {$segundos} segundos (os aparelhos se anunciam a cada ~5 s)...");

        try {
            $aparelhos = $descoberta->procurar($segundos);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($aparelhos === []) {
            $this->warn('Nenhum aparelho encontrado. Confira se este computador está na mesma rede Wi-Fi/cabo dos aparelhos e se o firewall do Windows libera UDP 6666, 6667 e 7000 para o PHP.');

            return self::SUCCESS;
        }

        $this->table(['IP', 'ID do aparelho', 'Versão'], array_map(
            fn ($a) => [$a['ip'], $a['id'], $a['versao']],
            array_values($aparelhos),
        ));

        $this->line('Use estes dados no cadastro do dispositivo (driver "Tuya / Smart Life (rede local)").');
        $this->line('A chave local (local_key) é obtida com: php artisan portaria:tuya-chaves');

        return self::SUCCESS;
    }
}
