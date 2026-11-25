<?php

namespace App\Console\Commands;

use App\Dispositivos\Tuya\NuvemTuya;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class TuyaChavesCommand extends Command
{
    protected $signature = 'portaria:tuya-chaves
        {--id= : Access ID do projeto em iot.tuya.com}
        {--secret= : Access Secret do projeto}
        {--regiao= : us (Américas), eu, cn, in, ueaz, weaz}
        {--aparelho= : Buscar só um aparelho pelo ID}';

    protected $description = 'Busca (uma única vez) o ID e a chave local dos aparelhos Tuya na nuvem da Tuya';

    public function handle(): int
    {
        $this->line('Passo a passo para obter Access ID e Secret: docs/TUYA.md');

        $id = $this->option('id') ?: text('Access ID (Client ID)', required: true);
        $secret = $this->option('secret') ?: password('Access Secret (Client Secret)', required: true);
        $regiao = $this->option('regiao') ?: select('Data center do projeto', [
            'us' => 'Western America (Brasil e Américas)',
            'ueaz' => 'Eastern America',
            'eu' => 'Central Europe',
            'weaz' => 'Western Europe',
            'cn' => 'China',
            'in' => 'India',
        ], default: 'us');

        try {
            $nuvem = new NuvemTuya($id, $secret, $regiao);
            $aparelhos = $this->option('aparelho')
                ? [$nuvem->aparelho($this->option('aparelho'))]
                : $nuvem->aparelhos();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($aparelhos === []) {
            $this->warn('Nenhum aparelho retornado. Confira se a conta do app (JWCOM SMART / Smart Life) foi vinculada ao projeto em "Devices > Link App Account".');

            return self::SUCCESS;
        }

        $this->table(['Nome', 'ID do aparelho', 'Chave local', 'Online'], array_map(
            fn ($a) => [$a['nome'], $a['id'], $a['chave_local'], $a['online'] ? 'sim' : 'não'],
            $aparelhos,
        ));

        $this->warn('A chave local muda se o aparelho for removido e pareado de novo no app. Guarde estes dados em local seguro.');
        $this->line('Para descobrir o IP e a versão do protocolo: php artisan portaria:tuya-procurar');

        return self::SUCCESS;
    }
}
