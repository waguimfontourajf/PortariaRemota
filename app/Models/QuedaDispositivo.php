<?php

namespace App\Models;

use App\Models\Concerns\PertenceAoCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Período em que um equipamento ficou fora do ar.
 *
 * @property int $id
 * @property int $dispositivo_id
 * @property Carbon $inicio
 * @property Carbon|null $fim
 * @property string|null $motivo
 * @property-read Dispositivo $dispositivo
 */
class QuedaDispositivo extends Model
{
    use PertenceAoCondominio;

    protected $table = 'quedas_dispositivo';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime'];
    }

    /**
     * @return BelongsTo<Dispositivo, $this>
     */
    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class)->withTrashed();
    }

    public function duracaoSegundos(): int
    {
        return (int) ($this->fim ?? now())->diffInSeconds($this->inicio, true);
    }

    public function duracaoTexto(): string
    {
        $s = $this->duracaoSegundos();

        return match (true) {
            $s < 60 => $s.' s',
            $s < 3600 => intdiv($s, 60).' min',
            $s < 86400 => intdiv($s, 3600).' h '.intdiv($s % 3600, 60).' min',
            default => intdiv($s, 86400).' d '.intdiv($s % 86400, 3600).' h',
        };
    }
}
