<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Atualização do sistema pelo painel.
 *
 * O PHP NUNCA executa git/composer/npm diretamente: ele apenas pede ao
 * Windows para rodar a tarefa agendada "PortariaRemota-Atualizar", que
 * executa implantacao\atualizar.bat com o usuário que a criou.
 */
class AtualizacaoDoSistema
{
    public function disponivel(): bool
    {
        $configurado = config('portaria.atualizacao.habilitada');

        if ($configurado !== null && $configurado !== '') {
            return filter_var($configurado, FILTER_VALIDATE_BOOL);
        }

        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Verifica se a tarefa agendada existe.
     */
    public function tarefaInstalada(): bool
    {
        if (! $this->disponivel()) {
            return false;
        }

        return Process::timeout(15)->run(['schtasks', '/query', '/tn', $this->tarefa()])->successful();
    }

    /**
     * @return 'nunca'|'executando'|'sucesso'|'erro'|'travada'
     */
    public function estado(): string
    {
        $arquivo = $this->caminho('estado.txt');

        if (! File::exists($arquivo)) {
            return 'nunca';
        }

        $estado = trim((string) File::get($arquivo));

        if ($estado === 'executando'
            && Carbon::createFromTimestamp(File::lastModified($arquivo))->lt(now()->subMinutes((int) config('portaria.atualizacao.limite_minutos', 20)))) {
            return 'travada';
        }

        return in_array($estado, ['executando', 'sucesso', 'erro'], true) ? $estado : 'nunca';
    }

    public function estadoAlteradoEm(): ?Carbon
    {
        $arquivo = $this->caminho('estado.txt');

        return File::exists($arquivo) ? Carbon::createFromTimestamp(File::lastModified($arquivo)) : null;
    }

    /**
     * Versão instalada (gravada pelo atualizar.bat).
     *
     * @return array{commit: string, data: ?Carbon, mensagem: string}|null
     */
    public function versao(): ?array
    {
        $arquivo = $this->caminho('versao.txt');

        if (! File::exists($arquivo)) {
            return null;
        }

        $partes = explode('|', trim((string) File::get($arquivo)), 3);

        if (count($partes) < 3) {
            return null;
        }

        try {
            $data = Carbon::parse($partes[1]);
        } catch (\Throwable) {
            $data = null;
        }

        return ['commit' => $partes[0], 'data' => $data, 'mensagem' => $partes[2]];
    }

    /**
     * Últimas linhas do registro da atualização.
     */
    public function registro(int $linhas = 150): string
    {
        $arquivo = storage_path('logs/atualizacao.log');

        if (! File::exists($arquivo)) {
            return '';
        }

        $conteudo = (string) File::get($arquivo);
        // O cmd do Windows grava em ANSI/OEM; converte para UTF-8 quando necessário.
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }

        $todas = preg_split('/\r\n|\n|\r/', rtrim($conteudo)) ?: [];

        return implode("\n", array_slice($todas, -$linhas));
    }

    /**
     * Pede ao Windows para iniciar a atualização.
     */
    public function iniciar(): void
    {
        if (! $this->disponivel()) {
            throw new RuntimeException('A atualização pelo painel só funciona no servidor Windows.');
        }

        if ($this->estado() === 'executando') {
            throw new RuntimeException('Já existe uma atualização em andamento.');
        }

        File::ensureDirectoryExists(dirname($this->caminho('estado.txt')));
        File::put($this->caminho('estado.txt'), 'executando');

        $resultado = Process::timeout(30)->run(['schtasks', '/run', '/tn', $this->tarefa()]);

        if (! $resultado->successful()) {
            File::put($this->caminho('estado.txt'), 'erro');

            throw new RuntimeException('O Windows não iniciou a tarefa de atualização. Ela foi instalada? (implantacao\\instalar-atualizacao-pelo-painel.bat). Detalhe: '.trim($resultado->errorOutput() ?: $resultado->output()));
        }
    }

    private function tarefa(): string
    {
        return (string) config('portaria.atualizacao.tarefa');
    }

    private function caminho(string $arquivo): string
    {
        return storage_path('app/atualizacao/'.$arquivo);
    }
}
