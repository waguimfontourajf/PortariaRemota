<?php

namespace App\Models;

use App\Enums\OrigemChamada;
use App\Enums\StatusChamada;
use App\Models\Concerns\PertenceAoCondominio;
use App\Models\Concerns\TemUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Chamada de interfone (ou botão) para a central de atendimento.
 *
 * @property int $id
 * @property string $uuid
 * @property int $condominio_id
 * @property int|null $ponto_acesso_id
 * @property int|null $dispositivo_id
 * @property OrigemChamada $origem
 * @property string|null $ramal_origem
 * @property string|null $id_externo
 * @property StatusChamada $status
 * @property int|null $atendida_por_id
 * @property Carbon|null $atendida_em
 * @property Carbon|null $encerrada_em
 * @property bool $porta_aberta
 * @property string|null $observacao
 * @property Carbon $created_at
 * @property-read Condominio $condominio
 * @property-read PontoAcesso|null $ponto
 * @property-read Dispositivo|null $interfone
 * @property-read User|null $atendente
 */
class Chamada extends Model
{
    use PertenceAoCondominio, TemUuid;

    protected $table = 'chamadas';

    protected $fillable = ['condominio_id', 'ponto_acesso_id', 'dispositivo_id', 'origem', 'ramal_origem', 'id_externo', 'status', 'observacao'];

    protected $attributes = ['status' => 'tocando', 'porta_aberta' => false];

    protected function casts(): array
    {
        return [
            'origem' => OrigemChamada::class,
            'status' => StatusChamada::class,
            'atendida_em' => 'datetime',
            'encerrada_em' => 'datetime',
            'porta_aberta' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PontoAcesso, $this>
     */
    public function ponto(): BelongsTo
    {
        return $this->belongsTo(PontoAcesso::class, 'ponto_acesso_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Dispositivo, $this>
     */
    public function interfone(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class, 'dispositivo_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function atendente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendida_por_id');
    }

    /**
     * @param  Builder<Chamada>  $query
     */
    public function scopeAtivas(Builder $query): void
    {
        $query->whereIn('status', [StatusChamada::Tocando->value, StatusChamada::EmAtendimento->value]);
    }

    public function titulo(): string
    {
        return $this->ponto?->nome ?? $this->interfone?->nome ?? ($this->ramal_origem ? __('Ramal :r', ['r' => $this->ramal_origem]) : __('Chamada'));
    }

    public function duracaoTexto(): string
    {
        $inicio = $this->atendida_em ?? $this->created_at;
        $segundos = (int) $inicio->diffInSeconds($this->encerrada_em ?? now(), true);

        return sprintf('%d:%02d', intdiv($segundos, 60), $segundos % 60);
    }
}
