<?php

namespace App\Support;

use App\Enums\Perfil;
use App\Enums\Permissao;
use App\Models\Condominio;
use App\Models\PontoAcesso;
use App\Models\User;

/**
 * Quem pode abrir qual ponto de acesso.
 */
final class RegrasDeAcesso
{
    public static function podeAbrir(?User $usuario, PontoAcesso $ponto): bool
    {
        if (! $usuario || ! $usuario->ativo) {
            return false;
        }

        if ($usuario->isSuperAdmin()) {
            return true;
        }

        $condominio = $ponto->condominio;

        if (! $usuario->podeAcessarCondominio($condominio)) {
            return false;
        }

        return $usuario->noCondominio($condominio, function () use ($usuario, $ponto, $condominio) {
            if (! $usuario->can(Permissao::AcessosAbrir->value)) {
                return false;
            }

            // Operador, síndico e porteiro abrem qualquer ponto; morador só os liberados.
            return self::temPerfilOperacional($usuario, $condominio) || $ponto->liberado_moradores;
        });
    }

    public static function temPerfilOperacional(User $usuario, Condominio $condominio): bool
    {
        return collect($usuario->perfisNo($condominio))->contains(fn (Perfil $p) => $p !== Perfil::Morador);
    }
}
