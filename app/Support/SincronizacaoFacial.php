<?php

namespace App\Support;

use App\Dispositivos\Contratos\LeitoraFacial;
use App\Dispositivos\RegistroDeDrivers;
use App\Models\Condominio;
use App\Models\Dispositivo;
use App\Models\Pessoa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mantém as leitoras faciais iguais ao cadastro de pessoas do sistema.
 *
 * Regra: vai para a leitora quem está ativo, tem foto, tem o consentimento
 * de biometria (LGPD) registrado e faz parte do público da leitora (tipo de
 * pessoa e blocos, configurados no cadastro da leitora). Quem deixa de
 * cumprir isso é removido da leitora.
 *
 * Roda na hora (sem fila), porque o servidor não tem processo de fila.
 */
class SincronizacaoFacial
{
    public function __construct(
        private readonly RegistroDeDrivers $drivers,
        private readonly FotoDePessoa $fotos,
    ) {}

    /**
     * Leitoras faciais ativas do condomínio.
     *
     * @return Collection<int, Dispositivo>
     */
    public function leitoras(Condominio|int $condominio): Collection
    {
        $chaves = collect($this->drivers->reles())
            ->filter(fn (string $classe) => is_subclass_of($classe, LeitoraFacial::class))
            ->keys()->all();

        if ($chaves === []) {
            return collect();
        }

        return Dispositivo::query()->doCondominio($condominio)
            ->where('ativo', true)->whereIn('driver', $chaves)->orderBy('nome')->get();
    }

    /**
     * A pessoa deve estar nesta leitora?
     */
    public function deveEstar(Dispositivo $leitora, Pessoa $pessoa): bool
    {
        return $pessoa->liberadaParaLeitoraFacial() && $this->fazParteDoPublico($leitora, $pessoa);
    }

    /**
     * Público configurado na leitora: tipo de pessoa e (opcional) blocos.
     */
    public function fazParteDoPublico(Dispositivo $leitora, Pessoa $pessoa): bool
    {
        $publico = (string) $leitora->opcao('publico', 'todos');
        $tipo = $pessoa->tipo->value;

        if ($publico === 'moradores' && $tipo !== 'morador') {
            return false;
        }

        if ($publico === 'funcionarios' && $tipo === 'morador') {
            return false;
        }

        $blocos = collect(explode(',', (string) $leitora->opcao('blocos', '')))
            ->map(fn (string $b) => mb_strtolower(trim($b)))->filter()->values();

        // Funcionários do condomínio não pertencem a um bloco: entram sempre.
        if ($blocos->isEmpty() || $tipo === 'funcionario') {
            return true;
        }

        return $pessoa->unidades->contains(fn ($unidade) => $blocos->contains(mb_strtolower(trim((string) $unidade->bloco?->nome))));
    }

    /**
     * Quantas pessoas do condomínio devem estar nesta leitora.
     */
    public function totalParaALeitora(Dispositivo $leitora): int
    {
        return Pessoa::query()->doCondominio($leitora->condominio_id)
            ->where('ativo', true)->whereNotNull('foto_path')->whereNotNull('biometria_autorizada_em')
            ->with('unidades.bloco')->get()
            ->filter(fn (Pessoa $p) => $this->fazParteDoPublico($leitora, $p))->count();
    }

    public function ehLeitora(Dispositivo $dispositivo): bool
    {
        return $this->drivers->existe($dispositivo->driver)
            && is_subclass_of($this->drivers->classe($dispositivo->driver), LeitoraFacial::class);
    }

    /**
     * Atualiza UMA pessoa em todas as leitoras do condomínio dela
     * (depois de salvar, excluir, trocar a foto...).
     *
     * @return list<array{leitora: string, sucesso: bool, mensagem: string}>
     */
    public function sincronizarPessoa(Pessoa $pessoa): array
    {
        $resultados = [];

        foreach ($this->leitoras($pessoa->condominio_id) as $leitora) {
            $resultados[] = $this->sincronizar($leitora, $pessoa, forcar: true) + ['leitora' => $leitora->nome];
        }

        return $resultados;
    }

    /**
     * Sincroniza todas as pessoas do condomínio em UMA leitora.
     * Sem $forcar, só envia quem mudou desde o último envio.
     *
     * @param  (callable(string): void)|null  $progresso
     * @return array{enviadas: int, removidas: int, sem_mudanca: int, erros: list<string>}
     */
    public function sincronizarLeitora(Dispositivo $leitora, bool $forcar = false, ?callable $progresso = null): array
    {
        @set_time_limit(0);
        $totais = ['enviadas' => 0, 'removidas' => 0, 'sem_mudanca' => 0, 'erros' => []];

        $pessoas = Pessoa::withTrashed()->doCondominio($leitora->condominio_id)
            ->with(['unidades.bloco', 'leitoras' => fn ($q) => $q->whereKey($leitora->id)])
            ->orderBy('nome')->get();

        foreach ($pessoas as $pessoa) {
            $estava = $pessoa->leitoras->isNotEmpty();

            if (! $this->deveEstar($leitora, $pessoa) && ! $estava) {
                continue;
            }

            $resultado = $this->sincronizar($leitora, $pessoa, $forcar);
            $totais[$resultado['acao']]++;

            if (! $resultado['sucesso']) {
                $totais['erros'][] = "{$pessoa->nome}: {$resultado['mensagem']}";
            }

            if ($progresso) {
                $progresso(($resultado['sucesso'] ? 'OK   ' : 'ERRO ')."{$pessoa->nome}: {$resultado['mensagem']}");
            }
        }

        return $totais;
    }

    /**
     * @return array{sucesso: bool, mensagem: string, acao: 'enviadas'|'removidas'|'sem_mudanca'}
     */
    private function sincronizar(Dispositivo $leitora, Pessoa $pessoa, bool $forcar): array
    {
        /** @var LeitoraFacial $driver */
        $driver = $leitora->driverDeRele();
        $registro = DB::table('dispositivo_pessoa')->where('dispositivo_id', $leitora->id)->where('pessoa_id', $pessoa->id)->first();

        if (! $this->deveEstar($leitora, $pessoa)) {
            if (! $registro) {
                $motivo = $pessoa->liberadaParaLeitoraFacial() ? 'fora do público desta leitora' : 'sem foto, sem autorização ou inativa';

                return ['sucesso' => true, 'mensagem' => "Não vai para esta leitora ({$motivo}).", 'acao' => 'sem_mudanca'];
            }

            $resultado = $driver->removerPessoa($leitora, $pessoa->id);

            if ($resultado->sucesso) {
                DB::table('dispositivo_pessoa')->where('id', $registro->id)->delete();
            } else {
                $this->anotarErro($leitora, $pessoa, $resultado->mensagem);
            }

            return ['sucesso' => $resultado->sucesso, 'mensagem' => $resultado->sucesso ? 'Removida da leitora.' : $resultado->mensagem, 'acao' => 'removidas'];
        }

        $foto = $this->fotos->conteudo($pessoa);

        if ($foto === null) {
            $this->anotarErro($leitora, $pessoa, 'Arquivo da foto não encontrado no servidor.');

            return ['sucesso' => false, 'mensagem' => 'Arquivo da foto não encontrado no servidor.', 'acao' => 'enviadas'];
        }

        $assinatura = hash('sha256', $pessoa->nome."\0".$foto);

        if (! $forcar && $registro && $registro->assinatura === $assinatura && $registro->erro === null) {
            return ['sucesso' => true, 'mensagem' => 'Já está atualizada.', 'acao' => 'sem_mudanca'];
        }

        $resultado = $driver->enviarPessoa($leitora, $pessoa->id, $pessoa->nome, $foto);

        DB::table('dispositivo_pessoa')->updateOrInsert(
            ['dispositivo_id' => $leitora->id, 'pessoa_id' => $pessoa->id],
            $resultado->sucesso
                ? ['assinatura' => $assinatura, 'sincronizado_em' => now(), 'erro' => null, 'updated_at' => now(), 'created_at' => $registro->created_at ?? now()]
                : ['erro' => mb_substr($resultado->mensagem, 0, 500), 'updated_at' => now(), 'created_at' => $registro->created_at ?? now()],
        );

        return ['sucesso' => $resultado->sucesso, 'mensagem' => $resultado->sucesso ? 'Enviada para a leitora.' : $resultado->mensagem, 'acao' => 'enviadas'];
    }

    private function anotarErro(Dispositivo $leitora, Pessoa $pessoa, string $mensagem): void
    {
        DB::table('dispositivo_pessoa')->updateOrInsert(
            ['dispositivo_id' => $leitora->id, 'pessoa_id' => $pessoa->id],
            ['erro' => mb_substr($mensagem, 0, 500), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
