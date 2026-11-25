<?php

namespace App\Models\Concerns;

use App\Models\Condominio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Para registros que pertencem a um condomínio (blocos, unidades, pessoas...).
 *
 * @property int $condominio_id
 * @property-read Condominio $condominio
 */
trait PertenceAoCondominio
{
    /**
     * @return BelongsTo<Condominio, $this>
     */
    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeDoCondominio(Builder $query, Condominio|int $condominio): void
    {
        $query->where(
            $this->qualifyColumn('condominio_id'),
            $condominio instanceof Condominio ? $condominio->id : $condominio,
        );
    }

    public function pertenceAo(Condominio|int|null $condominio): bool
    {
        $id = $condominio instanceof Condominio ? $condominio->id : $condominio;

        return $id !== null && $this->condominio_id === $id;
    }

    /**
     * Guarda o condomínio no registro de auditoria, para filtrar depois.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->put('condominio_id', $this->condominio_id);
    }
}
