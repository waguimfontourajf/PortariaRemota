<?php

namespace App\Models;

use App\Dispositivos\Contratos\DriverDeRele;
use App\Dispositivos\RegistroDeDrivers;
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
#[Fillable(['nome', 'tipo', 'driver', 'host', 'porta', 'canais', 'config', 'ativo', 'observacoes'])]
#[Hidden(['config'])]
class Dispositivo extends Model
{
    /** @use HasFactory<DispositivoFactory> */
    use HasFactory, LogsActivity, PertenceAoCondominio, SoftDeletes, TemUuid;

    protected $table = 'dispositivos';

    protected $attributes = ['tipo' => 'rele', 'canais' => 1, 'ativo' => true];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDispositivo::class,
            'porta' => 'integer',
            'canais' => 'integer',
            'config' => 'encrypted:array',
            'ativo' => 'boolean',
            'online' => 'boolean',
            'ultimo_contato_em' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PontoAcesso, $this>
     */
    public function pontosAcesso(): HasMany
    {
        return $this->hasMany(PontoAcesso::class);
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
