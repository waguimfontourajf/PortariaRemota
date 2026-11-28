<?php

namespace App\Models;

use App\Enums\ModoAcionamento;
use App\Enums\TipoPontoAcesso;
use App\Models\Concerns\PertenceAoCondominio;
use App\Models\Concerns\TemUuid;
use Database\Factories\PontoAcessoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Portão, porta ou cancela que pode ser aberto pelo sistema.
 *
 * @property int $id
 * @property string $uuid
 * @property string $nome
 * @property TipoPontoAcesso $tipo
 * @property int|null $dispositivo_id
 * @property int $canal
 * @property ModoAcionamento $modo_acionamento
 * @property int $tempo_pulso_ms
 * @property bool $liberado_moradores
 * @property bool $liberado_visitantes
 * @property bool $ativo
 * @property int $ordem
 * @property bool|null $ligado Último estado conhecido (liga / desliga)
 * @property Carbon|null $estado_em
 * @property string|null $observacoes
 * @property-read Dispositivo|null $dispositivo
 */
#[Fillable(['nome', 'tipo', 'dispositivo_id', 'interfone_id', 'canal', 'modo_acionamento', 'tempo_pulso_ms', 'liberado_moradores', 'liberado_visitantes', 'ativo', 'ordem', 'observacoes'])]
class PontoAcesso extends Model
{
    /** @use HasFactory<PontoAcessoFactory> */
    use HasFactory, LogsActivity, PertenceAoCondominio, SoftDeletes, TemUuid;

    protected $table = 'pontos_acesso';

    protected $attributes = [
        'tipo' => 'portao_social',
        'canal' => 1,
        'modo_acionamento' => 'pulso',
        'tempo_pulso_ms' => 1000,
        'liberado_moradores' => false,
        'liberado_visitantes' => false,
        'ativo' => true,
        'ordem' => 0,
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPontoAcesso::class,
            'modo_acionamento' => ModoAcionamento::class,
            'canal' => 'integer',
            'tempo_pulso_ms' => 'integer',
            'liberado_moradores' => 'boolean',
            'liberado_visitantes' => 'boolean',
            'ativo' => 'boolean',
            'ordem' => 'integer',
            'ligado' => 'boolean',
            'estado_em' => 'datetime',
        ];
    }

    public function ehLigaDesliga(): bool
    {
        return $this->modo_acionamento === ModoAcionamento::Alternar;
    }

    public function registrarEstado(?bool $ligado): void
    {
        $this->forceFill(['ligado' => $ligado, 'estado_em' => now()])->saveQuietly();
    }

    /**
     * @return BelongsTo<Dispositivo, $this>
     */
    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    /**
     * Interfone que toca para este portão.
     *
     * @return BelongsTo<Dispositivo, $this>
     */
    public function interfone(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class, 'interfone_id');
    }

    /**
     * Câmeras que mostram este portão.
     *
     * @return BelongsToMany<Dispositivo, $this>
     */
    public function cameras(): BelongsToMany
    {
        return $this->belongsToMany(Dispositivo::class, 'camera_ponto_acesso', 'ponto_acesso_id', 'dispositivo_id')
            ->withPivot('ordem')->orderByPivot('ordem');
    }

    /**
     * @return HasMany<EventoAcesso, $this>
     */
    public function eventos(): HasMany
    {
        return $this->hasMany(EventoAcesso::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('cadastro')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
