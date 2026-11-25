<?php

namespace App\Models;

use App\Enums\AcaoEvento;
use App\Enums\OrigemEvento;
use App\Enums\ResultadoEvento;
use App\Models\Concerns\PertenceAoCondominio;
use App\Models\Concerns\TemUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Registro de um acionamento (histórico). Imutável.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $ponto_acesso_id
 * @property int|null $dispositivo_id
 * @property int|null $user_id
 * @property int|null $pessoa_id
 * @property string|null $ponto_nome
 * @property string|null $usuario_nome
 * @property OrigemEvento $origem
 * @property AcaoEvento $acao
 * @property ResultadoEvento $resultado
 * @property string|null $detalhe
 * @property int|null $duracao_ms
 * @property string|null $ip
 * @property Carbon $created_at
 */
class EventoAcesso extends Model
{
    use PertenceAoCondominio, TemUuid;

    public const UPDATED_AT = null;

    protected $table = 'eventos_acesso';

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'origem' => OrigemEvento::class,
            'acao' => AcaoEvento::class,
            'resultado' => ResultadoEvento::class,
            'duracao_ms' => 'integer',
            'created_at' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de acesso não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos de acesso não podem ser apagados.'));
    }

    /**
     * @return BelongsTo<PontoAcesso, $this>
     */
    public function pontoAcesso(): BelongsTo
    {
        return $this->belongsTo(PontoAcesso::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
