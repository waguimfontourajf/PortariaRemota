<?php

namespace App\Support;

use App\Enums\Perfil;
use App\Models\Condominio;
use App\Models\User;

/**
 * Regras de quem pode alterar o quê na gestão de usuários.
 *
 * Princípio: o síndico gerencia o SEU condomínio. Ele não pode mexer na conta
 * (e-mail/senha) de quem também tem acesso a outros condomínios, nem em
 * super admins, nem dar o perfil de operador da central.
 */
final class RegrasDeUsuario
{
    /**
     * Perfis que o usuário logado pode dar ou tirar.
     *
     * @return list<Perfil>
     */
    public static function perfisAtribuiveis(User $ator): array
    {
        if ($ator->isSuperAdmin()) {
            return Perfil::cases();
        }

        return array_values(array_filter(Perfil::cases(), fn (Perfil $p) => $p !== Perfil::Operador));
    }

    /**
     * Pode alterar nome, e-mail e senha do usuário?
     */
    public static function podeEditarConta(User $ator, User $alvo, Condominio $condominio): bool
    {
        if ($ator->isSuperAdmin() || $ator->is($alvo)) {
            return true;
        }

        if ($alvo->isSuperAdmin()) {
            return false;
        }

        // Só se o usuário pertence exclusivamente a este condomínio.
        return $alvo->condominios()->where('condominios.id', '!=', $condominio->id)->doesntExist();
    }

    /**
     * Pode alterar os perfis do usuário neste condomínio?
     */
    public static function podeEditarPerfis(User $ator, User $alvo): bool
    {
        if ($ator->isSuperAdmin()) {
            return true;
        }

        // Ninguém altera os próprios perfis (evita se trancar fora) nem os de um super admin.
        return ! $ator->is($alvo) && ! $alvo->isSuperAdmin();
    }

    /**
     * Ativar/desativar e redefinir 2FA afetam o usuário em todos os
     * condomínios: só o super admin, e nunca em si mesmo.
     */
    public static function podeAdministrarConta(User $ator, User $alvo): bool
    {
        return $ator->isSuperAdmin() && ! $ator->is($alvo);
    }
}
