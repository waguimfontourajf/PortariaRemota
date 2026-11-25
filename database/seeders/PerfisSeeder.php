<?php

namespace Database\Seeders;

use App\Enums\Perfil;
use App\Enums\Permissao;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria/atualiza as permissões e os perfis (globais, válidos em qualquer
 * condomínio) a partir dos enums App\Enums\Permissao e App\Enums\Perfil.
 * Pode ser executado quantas vezes for preciso.
 */
class PerfisSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (Permissao::cases() as $permissao) {
            Permission::findOrCreate($permissao->value, 'web');
        }

        $teamAnterior = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        foreach (Perfil::cases() as $perfil) {
            $role = Role::query()
                ->where('name', $perfil->value)
                ->where('guard_name', 'web')
                ->whereNull(config('permission.column_names.team_foreign_key'))
                ->first()
                ?? Role::create(['name' => $perfil->value, 'guard_name' => 'web']);

            $role->syncPermissions(array_map(fn (Permissao $p) => $p->value, $perfil->permissoes()));
        }

        $registrar->setPermissionsTeamId($teamAnterior);
        $registrar->forgetCachedPermissions();
    }
}
