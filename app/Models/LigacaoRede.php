<?php

namespace App\Models;

use App\Enums\TipoLigacao;
use App\Models\Concerns\PertenceAoCondominio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha do mapa: como dois equipamentos estão conectados.
 *
 * @property int $id
 * @property int $origem_id
 * @property int $destino_id
 * @property TipoLigacao $tipo
 * @property string|null $descricao
 */
#[Fillable(['origem_id', 'destino_id', 'tipo', 'descricao'])]
class LigacaoRede extends Model
{
    use PertenceAoCondominio;

    protected $table = 'ligacoes_rede';

    protected function casts(): array
    {
        return ['tipo' => TipoLigacao::class];
    }

    /**
     * @return BelongsTo<Dispositivo, $this>
     */
    public function origem(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class, 'origem_id');
    }

    /**
     * @return BelongsTo<Dispositivo, $this>
     */
    public function destino(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class, 'destino_id');
    }
}
