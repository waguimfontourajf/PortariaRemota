<?php

namespace App\Support;

use App\Models\Condominio;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Spatie\Permission\PermissionRegistrar;

/**
 * Guarda qual condomínio o usuário está operando no momento.
 *
 * Todas as permissões (spatie/laravel-permission com "teams") são avaliadas
 * dentro do condomínio atual. Registrado como "scoped" no container: uma
 * instância por requisição.
 */
class CondominioAtual
{
    public const CHAVE_SESSAO = 'condominio_atual_id';

    private ?Condominio $condominio = null;

    private bool $resolvido = false;

    public function __construct(
        private readonly Session $sessao,
        private readonly PermissionRegistrar $permissoes,
    ) {}

    /**
     * Descobre o condomínio atual do usuário: o salvo na sessão (se ele ainda
     * tiver acesso) ou o primeiro condomínio disponível.
     */
    public function resolver(User $usuario): ?Condominio
    {
        $id = $this->sessao->get(self::CHAVE_SESSAO);

        $condominio = $id
            ? $usuario->condominiosAcessiveis()->whereKey($id)->first()
            : null;

        $condominio ??= $usuario->condominiosAcessiveis()->first();

        $this->aplicar($usuario, $condominio);

        return $condominio;
    }

    /**
     * Troca o condomínio atual. Retorna false se o usuário não tem acesso.
     */
    public function trocar(User $usuario, Condominio $condominio): bool
    {
        if (! $usuario->podeAcessarCondominio($condominio)) {
            return false;
        }

        $this->aplicar($usuario, $condominio);

        return true;
    }

    public function get(): ?Condominio
    {
        return $this->condominio;
    }

    public function id(): ?int
    {
        return $this->condominio?->id;
    }

    public function resolvido(): bool
    {
        return $this->resolvido;
    }

    private function aplicar(User $usuario, ?Condominio $condominio): void
    {
        $this->condominio = $condominio;
        $this->resolvido = true;

        if ($condominio) {
            $this->sessao->put(self::CHAVE_SESSAO, $condominio->id);
        } else {
            $this->sessao->forget(self::CHAVE_SESSAO);
        }

        // Sem condomínio, usamos um id inexistente (0) para que nenhum perfil
        // de condomínio seja considerado por engano.
        $this->permissoes->setPermissionsTeamId($condominio?->id ?? 0);
        $usuario->unsetRelation('roles')->unsetRelation('permissions');
    }
}
