<?php

namespace App\Models;

use App\Dispositivos\Contratos\DriverDeRele;
use App\Dispositivos\RegistroDeDrivers;
use App\Enums\MetodoMonitoramento;
use App\Enums\StatusMonitoramento;
use App\Enums\TipoDispositivo;
use App\Models\Concerns\PertenceAoCondominio;
use App\Models\Concerns\TemUuid;
use Database\Factories\DispositivoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Equipamento instalado no condomínio (relé, câmera, interfone...).
 *
 * @property int $id
 * @property string $uuid
 * @property string $nome
 * @property TipoDispositivo $tipo
 * @property string $driver
 * @property string|null $host
 * @property int|null $porta
 * @property int $canais
 * @property array<string, mixed>|null $config Configurações do driver (guardadas criptografadas)
 * @property bool $ativo
 * @property bool|null $online
 * @property Carbon|null $ultimo_contato_em
 * @property string|null $ultimo_erro
 * @property string|null $observacoes
 */
#[Fillable(['nome', 'tipo', 'driver', 'host', 'porta', 'mac', 'fabricante_modelo', 'canais', 'config', 'ativo', 'monitorar', 'metodo_monitoramento', 'porta_monitoramento', 'observacoes'])]
#[Hidden(['config'])]
class Dispositivo extends Model
{
    /** @use HasFactory<DispositivoFactory> */
    use HasFactory, LogsActivity, PertenceAoCondominio, SoftDeletes, TemUuid;

    protected $table = 'dispositivos';

    protected $attributes = ['tipo' => 'rele', 'canais' => 1, 'ativo' => true, 'monitorar' => true, 'metodo_monitoramento' => 'ping', 'status' => 'desconhecido', 'falhas_seguidas' => 0];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDispositivo::class,
            'porta' => 'integer',
            'canais' => 'integer',
            'config' => 'encrypted:array',
            'ativo' => 'boolean',
            'online' => 'boolean',
            'monitorar' => 'boolean',
            'metodo_monitoramento' => MetodoMonitoramento::class,
            'porta_monitoramento' => 'integer',
            'status' => StatusMonitoramento::class,
            'status_desde' => 'datetime',
            'latencia_ms' => 'integer',
            'falhas_seguidas' => 'integer',
            'verificado_em' => 'datetime',
            'mapa_x' => 'integer',
            'mapa_y' => 'integer',
            'ultimo_contato_em' => 'datetime',
            'eventos_importados_em' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PontoAcesso, $this>
     */
    public function pontosAcesso(): HasMany
    {
        return $this->hasMany(PontoAcesso::class);
    }

    /**
     * @return HasMany<QuedaDispositivo, $this>
     */
    public function quedas(): HasMany
    {
        return $this->hasMany(QuedaDispositivo::class);
    }

    public function temDriver(): bool
    {
        return filled($this->driver) && app(RegistroDeDrivers::class)->existe($this->driver);
    }

    /**
     * Status considerando se o monitoramento está ligado.
     */
    public function statusEfetivo(): StatusMonitoramento
    {
        return $this->ativo && $this->monitorar && (filled($this->host) || $this->metodo_monitoramento === MetodoMonitoramento::Driver)
            ? $this->status
            : StatusMonitoramento::NaoMonitorado;
    }

    /**
     * Percentual do tempo online no período (considera as quedas registradas).
     */
    public function disponibilidade(int $dias): ?float
    {
        $inicio = now()->subDays($dias);
        $inicioReal = $this->created_at && $this->created_at->gt($inicio) ? $this->created_at : $inicio;
        $total = now()->diffInSeconds($inicioReal, true);

        if ($total < 60) {
            return null;
        }

        $fora = $this->quedas()
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>', $inicioReal))
            ->get()
            ->sum(function (QuedaDispositivo $queda) use ($inicioReal) {
                $de = $queda->inicio->gt($inicioReal) ? $queda->inicio : $inicioReal;

                return ($queda->fim ?? now())->diffInSeconds($de, true);
            });

        return round(max(0, 100 - ($fora / $total * 100)), 2);
    }

    public function driverDeRele(): DriverDeRele
    {
        return app(RegistroDeDrivers::class)->rele($this->driver);
    }

    public function nomeDoDriver(): string
    {
        return app(RegistroDeDrivers::class)->existe($this->driver)
            ? app(RegistroDeDrivers::class)->rele($this->driver)::nome()
            : $this->driver;
    }

    /**
     * Valor de configuração do driver.
     */
    public function opcao(string $chave, mixed $padrao = null): mixed
    {
        $valor = $this->config[$chave] ?? null;

        return $valor === null || $valor === '' ? $padrao : $valor;
    }

    public function registrarContato(bool $online, ?string $erro = null): void
    {
        $this->forceFill([
            'online' => $online,
            'ultimo_contato_em' => $online ? now() : $this->ultimo_contato_em,
            'ultimo_erro' => $online ? null : mb_substr((string) $erro, 0, 500),
        ])->saveQuietly();
    }

    public function getActivitylogOptions(): LogOptions
    {
        // "config" não entra no log: contém senhas e chaves.
        return LogOptions::defaults()->useLogName('dispositivo')
            ->logOnly(['nome', 'tipo', 'driver', 'host', 'porta', 'canais', 'ativo'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
