<?php

namespace App\Console\Commands;

use App\Models\Dispositivo;
use App\Support\ImportacaoDePassagens;
use App\Support\SincronizacaoFacial;
use Illuminate\Console\Command;
use Throwable;

class LeitorasImportarCommand extends Command
{
    protected $signature = 'portaria:leitoras-importar {dispositivo? : UUID ou nome da leitora (sem informar: todas)}';

    protected $description = 'Traz para o Histórico as passagens registradas pelas leitoras faciais';

    public function handle(SincronizacaoFacial $sincronizacao, ImportacaoDePassagens $importacao): int
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
            try {
                $this->info("{$leitora->nome}: ".$importacao->importar($leitora).' passagem(ns) nova(s).');
            } catch (Throwable $e) {
                $this->error("{$leitora->nome}: {$e->getMessage()}");
                $falhou = true;
            }
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
