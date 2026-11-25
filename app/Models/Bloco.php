<?php

namespace App\Models;

use App\Models\Concerns\PertenceAoCondominio;
use App\Models\Concerns\TemUuid;
use Database\Factories\BlocoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Bloco ou torre de um condomínio.
 *
 * @property int $id
 * @property string $uuid
 * @property string $nome
 * @property int $ordem
 */
#[Fillable(['nome', 'ordem'])]
class Bloco extends Model
{
    /** @use HasFactory<BlocoFactory> */
    use HasFactory, LogsActivity, PertenceAoCondominio, TemUuid;

    protected $table = 'blocos';

    protected $attributes = ['ordem' => 0];

    protected function casts(): array
    {
        return ['ordem' => 'integer'];
    }

    /**
     * @return HasMany<Unidade, $this>
     */
    public function unidades(): HasMany
    {
        return $this->hasMany(Unidade::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('cadastro')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
