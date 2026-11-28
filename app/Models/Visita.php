<?php

namespace App\Models;

use App\Models\Concerns\PertenceAoCondominio;
use App\Models\Concerns\TemUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Autorização de visitante (link + QR code enviado pelo morador).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property int|null $unidade_id
 * @property string $nome
 * @property string|null $documento
 * @property string|null $telefone
 * @property string|null $observacao
 * @property string $token_hash
 * @property string $token
 * @property Carbon $valido_de
 * @property Carbon $valido_ate
 * @property int $usos_maximos
 * @property int $usos
 * @property Carbon|null $revogada_em
 * @property-read User|null $autor
 * @property-read Unidade|null $unidade
 */
class Visita extends Model
{
    use PertenceAoCondominio, TemUuid;

    protected $table = 'visitas';

    protected $guarded = ['id', 'uuid', 'token', 'token_hash', 'usos', 'revogada_em'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'valido_de' => 'datetime',
            'valido_ate' => 'datetime',
            'revogada_em' => 'datetime',
            'usos_maximos' => 'integer',
            'usos' => 'integer',
        ];
    }

    /**
     * Gera um token novo (32 caracteres aleatórios) e devolve o valor em claro.
     */
    public function gerarToken(): string
    {
        $token = Str::random(32);
        $this->forceFill(['token' => $token, 'token_hash' => hash('sha256', $token)]);

        return $token;
    }

    public static function peloToken(string $token): ?self
    {
        return static::query()->where('token_hash', hash('sha256', $token))->first();
    }

    public function link(): string
    {
        return route('visitante.mostrar', $this->token);
    }

    /**
     * Motivo pelo qual o link não pode ser usado agora (ou null se pode).
     */
    public function bloqueio(): ?string
    {
        return match (true) {
            $this->revogada_em !== null => __('Esta autorização foi cancelada por quem a criou.'),
            now()->lt($this->valido_de) => __('Esta autorização ainda não começou a valer. Válida a partir de :data.', ['data' => $this->valido_de->timezone($this->condominio->fuso_horario)->format('d/m H:i')]),
            now()->gt($this->valido_ate) => __('Esta autorização expirou.'),
            $this->usos >= $this->usos_maximos => __('Esta autorização já foi usada o número máximo de vezes.'),
            ! $this->condominio->ativo => __('Condomínio indisponível.'),
            default => null,
        };
    }

    public function situacao(): string
    {
        return match (true) {
            $this->revogada_em !== null => 'cancelada',
            now()->gt($this->valido_ate) => 'expirada',
            $this->usos >= $this->usos_maximos => 'usada',
            now()->lt($this->valido_de) => 'agendada',
            default => 'ativa',
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Unidade, $this>
     */
    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    /**
     * @return BelongsToMany<PontoAcesso, $this>
     */
    public function pontos(): BelongsToMany
    {
        return $this->belongsToMany(PontoAcesso::class, 'ponto_acesso_visita');
    }

    /**
     * @return HasMany<EventoAcesso, $this>
     */
    public function eventos(): HasMany
    {
        return $this->hasMany(EventoAcesso::class);
    }
}
