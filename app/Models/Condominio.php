<?php

namespace App\Models;

use App\Enums\ModoOperacao;
use App\Models\Concerns\TemUuid;
use Database\Factories\CondominioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $uuid
 * @property string $nome
 * @property string|null $cnpj
 * @property string|null $telefone
 * @property string|null $email
 * @property string|null $cep
 * @property string|null $logradouro
 * @property string|null $numero
 * @property string|null $complemento
 * @property string|null $bairro
 * @property string|null $cidade
 * @property string|null $uf
 * @property ModoOperacao $modo_operacao
 * @property string $fuso_horario
 * @property bool $ativo
 * @property string|null $observacoes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'nome', 'cnpj', 'telefone', 'email',
    'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf',
    'modo_operacao', 'fuso_horario', 'ativo', 'observacoes',
])]
class Condominio extends Model
{
    /** @use HasFactory<CondominioFactory> */
    use HasFactory, LogsActivity, SoftDeletes, TemUuid;

    protected $table = 'condominios';

    protected $attributes = [
        'modo_operacao' => 'direto',
        'fuso_horario' => 'America/Sao_Paulo',
        'ativo' => true,
    ];

    protected function casts(): array
    {
        return [
            'modo_operacao' => ModoOperacao::class,
            'ativo' => 'boolean',
        ];
    }

    /**
     * Usuários que têm algum perfil neste condomínio.
     *
     * @return BelongsToMany<User, $this>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            config('permission.table_names.model_has_roles'),
            config('permission.column_names.team_foreign_key'),
            config('permission.column_names.model_morph_key'),
        )->where('model_type', (new User)->getMorphClass())->distinct();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAtivos(Builder $query): void
    {
        $query->where('ativo', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cadastro')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
