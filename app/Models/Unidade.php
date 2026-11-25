<?php

namespace App\Models;

use App\Enums\TipoUnidade;
use App\Models\Concerns\PertenceAoCondominio;
use App\Models\Concerns\TemUuid;
use Database\Factories\UnidadeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Apartamento, casa ou sala de um condomínio.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $bloco_id
 * @property string $numero
 * @property string|null $andar
 * @property TipoUnidade $tipo
 * @property string|null $observacoes
 * @property-read Bloco|null $bloco
 * @property-read string $identificacao
 */
#[Fillable(['bloco_id', 'numero', 'andar', 'tipo', 'observacoes'])]
class Unidade extends Model
{
    /** @use HasFactory<UnidadeFactory> */
    use HasFactory, LogsActivity, PertenceAoCondominio, TemUuid;

    protected $table = 'unidades';

    protected $attributes = ['tipo' => 'apartamento'];

    protected function casts(): array
    {
        return ['tipo' => TipoUnidade::class];
    }

    /**
     * @return BelongsTo<Bloco, $this>
     */
    public function bloco(): BelongsTo
    {
        return $this->belongsTo(Bloco::class);
    }

    /**
     * @return BelongsToMany<Pessoa, $this>
     */
    public function pessoas(): BelongsToMany
    {
        return $this->belongsToMany(Pessoa::class, 'pessoa_unidade')
            ->withPivot(['vinculo', 'responsavel'])
            ->withTimestamps();
    }

    /**
     * Ex.: "Bloco A · 101" ou "Casa 12".
     *
     * @return Attribute<string, never>
     */
    protected function identificacao(): Attribute
    {
        return Attribute::get(function (): string {
            $numero = $this->tipo === TipoUnidade::Apartamento || $this->bloco_id
                ? $this->numero
                : $this->tipo->label().' '.$this->numero;

            return $this->bloco ? $this->bloco->nome.' · '.$numero : $numero;
        });
    }

    /**
     * Ordena por bloco e número de forma natural (101, 102, ... 1001).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOrdenadas(Builder $query): void
    {
        $query->leftJoin('blocos', 'blocos.id', '=', 'unidades.bloco_id')
            ->select('unidades.*')
            ->orderBy('blocos.ordem')
            ->orderBy('blocos.nome')
            ->orderByRaw('LENGTH(unidades.numero)')
            ->orderBy('unidades.numero');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('cadastro')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
