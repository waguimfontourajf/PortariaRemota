<?php

namespace App\Console\Commands;

use App\Models\Dispositivo;
use App\Support\SincronizacaoFacial;
use Illuminate\Console\Command;

class LeitorasSincronizarCommand extends Command
{
    protected $signature = 'portaria:leitoras-sincronizar
        {dispositivo? : UUID ou nome da leitora (sem informar: todas)}
        {--todas : Reenvia todas as pessoas, mesmo as que não mudaram}';

    protected $description = 'Envia as pessoas (com foto e autorização) para as leitoras faciais e remove as que saíram';

    public function handle(SincronizacaoFacial $sincronizacao): int
    {
        $busca = $this->argument('dispositivo');
        $leitoras = Dispositivo::query()->where('ativo', true)
            ->when($busca, fn ($q) => $q->where(fn ($q) => $q->where('uuid', $busca)->orWhere('nome', $busca)))
            ->get()->filter(fn (Dispositivo $d) => $sincronizacao->ehLeitora($d));

        if ($leitoras->isEmpty()) {
            $this->warn('Nenhuma leitora facial ativa encontrada.');

            return self::FAILURE;
        }

        $falhou = false;

        foreach ($leitoras as $leitora) {
            $this->info("Leitora: {$leitora->nome} ({$leitora->nomeDoDriver()}, {$leitora->host})");
            $totais = $sincronizacao->sincronizarLeitora($leitora, (bool) $this->option('todas'), fn (string $linha) => $this->line('  '.$linha));
            $this->line("  Enviadas: {$totais['enviadas']} | removidas: {$totais['removidas']} | sem mudança: {$totais['sem_mudanca']} | erros: ".count($totais['erros']));
            $falhou = $falhou || $totais['erros'] !== [];
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
