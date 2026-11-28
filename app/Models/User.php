<?php

namespace App\Models;

use App\Enums\Perfil;
use App\Models\Concerns\TemUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $super_admin
 * @property bool $ativo
 * @property Carbon|null $ultimo_login_em
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'senha_sip', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable, TemUuid, TwoFactorAuthenticatable;

    protected $attributes = [
        'super_admin' => false,
        'ativo' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'super_admin' => 'boolean',
            'ativo' => 'boolean',
            'ultimo_login_em' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'senha_sip' => 'encrypted',
        ];
    }

    /**
     * Condomínios em que o usuário tem algum perfil.
     *
     * @return BelongsToMany<Condominio, $this>
     */
    public function condominios(): BelongsToMany
    {
        return $this->belongsToMany(
            Condominio::class,
            config('permission.table_names.model_has_roles'),
            config('permission.column_names.model_morph_key'),
            config('permission.column_names.team_foreign_key'),
        )->where('model_type', $this->getMorphClass())->distinct();
    }

    /**
     * Condomínios que o usuário pode acessar (super admin: todos).
     *
     * @return Builder<Condominio>
     */
    public function condominiosAcessiveis(): Builder
    {
        $query = Condominio::query()->ativos()->orderBy('nome');

        if ($this->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('id', $this->condominios()->select('condominios.id'));
    }

    public function podeAcessarCondominio(Condominio|int $condominio): bool
    {
        $id = $condominio instanceof Condominio ? $condominio->id : $condominio;

        return $this->condominiosAcessiveis()->whereKey($id)->exists();
    }

    /**
     * Dá um perfil ao usuário dentro de um condomínio (sem alterar o
     * condomínio atual da requisição).
     */
    public function atribuirPerfil(Perfil $perfil, Condominio $condominio): void
    {
        $this->noCondominio($condominio, fn () => $this->assignRole($perfil->value));
    }

    /**
     * Define exatamente os perfis do usuário em um condomínio.
     *
     * @param  list<Perfil>  $perfis
     */
    public function sincronizarPerfis(array $perfis, Condominio $condominio): void
    {
        $this->noCondominio($condominio, fn () => $this->syncRoles(array_map(fn (Perfil $p) => $p->value, $perfis)));
    }

    /**
     * Perfis do usuário em um condomínio.
     *
     * @return list<Perfil>
     */
    public function perfisNo(Condominio $condominio): array
    {
        return $this->noCondominio($condominio, fn () => $this->getRoleNames()
            ->map(fn (string $nome) => Perfil::tryFrom($nome))
            ->filter()
            ->values()
            ->all());
    }

    /**
     * Em quantos condomínios o usuário tem perfil.
     */
    public function quantidadeDeCondominios(): int
    {
        return $this->condominios()->count('condominios.id');
    }

    /**
     * @return HasMany<Pessoa, $this>
     */
    public function pessoas(): HasMany
    {
        return $this->hasMany(Pessoa::class);
    }

    public function removerPerfil(Perfil $perfil, Condominio $condominio): void
    {
        $this->noCondominio($condominio, fn () => $this->removeRole($perfil->value));
    }

    /**
     * Executa algo com as permissões no escopo de outro condomínio e
     * restaura o escopo anterior em seguida.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function noCondominio(Condominio $condominio, callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $anterior = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($condominio->id);
        $this->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($anterior);
            $this->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function isSuperAdmin(): bool
    {
        return $this->super_admin;
    }

    public function temDoisFatoresAtivo(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Super admins e usuários com perfis sensíveis (ex.: operador) em
     * QUALQUER condomínio precisam ter 2FA ativo.
     */
    public function precisaDeDoisFatores(): bool
    {
        if (! config('portaria.exigir_2fa')) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->temAlgumPerfil(Perfil::exigindo2fa());
    }

    /**
     * O usuário tem algum destes perfis em QUALQUER condomínio?
     *
     * @param  list<string>  $perfis
     */
    public function temAlgumPerfil(array $perfis): bool
    {
        return $this->getConnection()
            ->table(config('permission.table_names.model_has_roles').' as mhr')
            ->join(config('permission.table_names.roles').' as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $this->getMorphClass())
            ->where('mhr.'.config('permission.column_names.model_morph_key'), $this->getKey())
            ->whereIn('r.name', $perfis)
            ->exists();
    }

    /**
     * Super admin ou operador da central (acompanham vários condomínios).
     */
    public function ehEquipeDaCentral(): bool
    {
        return $this->isSuperAdmin() || $this->temAlgumPerfil([Perfil::Operador->value]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('usuario')
            ->logOnly(['name', 'email', 'super_admin', 'ativo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
