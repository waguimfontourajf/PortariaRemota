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
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    'modo_operacao', 'fuso_horario', 'ativo', 'observacoes', 'telegram_chat_id',
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
            'mapa_x' => 'integer',
            'mapa_y' => 'integer',
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
     * @return HasMany<Bloco, $this>
     */
    public function blocos(): HasMany
    {
        return $this->hasMany(Bloco::class);
    }

    /**
     * @return HasMany<HorarioAcesso, $this>
     */
    public function horariosAcesso(): HasMany
    {
        return $this->hasMany(HorarioAcesso::class);
    }

    /**
     * @return HasMany<Unidade, $this>
     */
    public function unidades(): HasMany
    {
        return $this->hasMany(Unidade::class);
    }

    /**
     * @return HasMany<Dispositivo, $this>
     */
    public function dispositivos(): HasMany
    {
        return $this->hasMany(Dispositivo::class);
    }

    /**
     * @return HasMany<PontoAcesso, $this>
     */
    public function pontosAcesso(): HasMany
    {
        return $this->hasMany(PontoAcesso::class);
    }

    /**
     * @return HasMany<EventoAcesso, $this>
     */
    public function eventosAcesso(): HasMany
    {
        return $this->hasMany(EventoAcesso::class);
    }

    /**
     * @return HasMany<LigacaoRede, $this>
     */
    public function ligacoesRede(): HasMany
    {
        return $this->hasMany(LigacaoRede::class);
    }

    /**
     * @return HasMany<QuedaDispositivo, $this>
     */
    public function quedas(): HasMany
    {
        return $this->hasMany(QuedaDispositivo::class);
    }

    /**
     * @return HasMany<Pessoa, $this>
     */
    public function pessoas(): HasMany
    {
        return $this->hasMany(Pessoa::class);
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
