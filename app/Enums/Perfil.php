<?php

namespace App\Enums;

/**
 * Perfis de acesso atribuídos a um usuário DENTRO de um condomínio.
 *
 * O super admin não é um perfil: é uma marca no usuário (users.super_admin)
 * que libera tudo, em todos os condomínios.
 */
enum Perfil: string
{
    case Operador = 'operador';
    case Sindico = 'sindico';
    case Porteiro = 'porteiro';
    case Morador = 'morador';

    public function label(): string
    {
        return match ($this) {
            self::Operador => 'Operador da central',
            self::Sindico => 'Síndico / administrador',
            self::Porteiro => 'Porteiro local',
            self::Morador => 'Morador',
        };
    }

    /**
     * Perfis com acesso operacional amplo precisam de 2FA.
     */
    public function exige2fa(): bool
    {
        return match ($this) {
            self::Operador => true,
            default => false,
        };
    }

    /**
     * @return list<Permissao>
     */
    public function permissoes(): array
    {
        return match ($this) {
            self::Operador => [
                Permissao::AcessosAbrir,
                Permissao::HistoricoVer,
                Permissao::CamerasVer,
                Permissao::ChamadasAtender,
                Permissao::OcorrenciasRegistrar,
            ],
            self::Sindico => [
                Permissao::CondominioGerenciar,
                Permissao::UsuariosGerenciar,
                Permissao::CadastrosGerenciar,
                Permissao::AcessosAbrir,
                Permissao::HistoricoVer,
                Permissao::CamerasVer,
                Permissao::VisitasAutorizar,
            ],
            self::Porteiro => [
                Permissao::AcessosAbrir,
                Permissao::HistoricoVer,
                Permissao::CamerasVer,
                Permissao::ChamadasAtender,
                Permissao::OcorrenciasRegistrar,
            ],
            self::Morador => [
                Permissao::AcessosAbrir,
                Permissao::HistoricoProprioVer,
                Permissao::VisitasAutorizar,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function exigindo2fa(): array
    {
        return array_values(array_map(
            fn (self $perfil) => $perfil->value,
            array_filter(self::cases(), fn (self $perfil) => $perfil->exige2fa()),
        ));
    }
}
