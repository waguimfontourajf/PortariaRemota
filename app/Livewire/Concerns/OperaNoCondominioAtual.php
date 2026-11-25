<?php

namespace App\Livewire\Concerns;

use App\Enums\Permissao;
use App\Models\Condominio;
use App\Support\CondominioAtual;
use Illuminate\Database\Eloquent\Model;

/**
 * Para telas que trabalham com os dados do condomínio atual.
 *
 * As verificações devem ser chamadas no mount() E em cada ação, porque
 * as ações do Livewire chegam em requisições separadas.
 */
trait OperaNoCondominioAtual
{
    protected function condominio(): Condominio
    {
        $condominio = app(CondominioAtual::class)->get();

        abort_if($condominio === null, 403, __('Selecione um condomínio para continuar.'));

        return $condominio;
    }

    protected function exigirPermissao(Permissao $permissao): void
    {
        $this->condominio();

        abort_unless(auth()->user()?->can($permissao->value), 403);
    }

    /**
     * Garante que o registro é do condomínio atual (senão, 404).
     */
    protected function garantirDoCondominio(Model $registro): void
    {
        abort_unless($registro->getAttribute('condominio_id') === $this->condominio()->id, 404);
    }
}
