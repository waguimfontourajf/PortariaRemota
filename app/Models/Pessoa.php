<?php

namespace App\Models;

use App\Enums\TipoPessoa;
use App\Models\Concerns\PertenceAoCondominio;
use App\Models\Concerns\TemUuid;
use Database\Factories\PessoaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pessoa cadastrada em um condomínio: morador, funcionário ou prestador.
 * Pode (ou não) ter um usuário de login vinculado.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string $nome
 * @property TipoPessoa $tipo
 * @property string|null $cpf
 * @property string|null $rg
 * @property Carbon|null $data_nascimento
 * @property string|null $telefone
 * @property string|null $email
 * @property string|null $foto_path
 * @property Carbon|null $biometria_autorizada_em
 * @property int|null $biometria_autorizada_por
 * @property int|null $horario_acesso_id
 * @property Carbon|null $acesso_de
 * @property Carbon|null $acesso_ate
 * @property-read HorarioAcesso|null $horarioAcesso
 * @property bool $ativo
 * @property string|null $observacoes
 * @property-read User|null $usuario
 */
#[Fillable(['nome', 'tipo', 'cpf', 'rg', 'data_nascimento', 'telefone', 'email', 'ativo', 'observacoes', 'horario_acesso_id', 'acesso_de', 'acesso_ate'])]
class Pessoa extends Model
{
    /** @use HasFactory<PessoaFactory> */
    use HasFactory, LogsActivity, PertenceAoCondominio, SoftDeletes, TemUuid;

    protected $table = 'pessoas';

    protected $attributes = ['tipo' => 'morador', 'ativo' => true];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPessoa::class,
            'data_nascimento' => 'date',
            'ativo' => 'boolean',
            'biometria_autorizada_em' => 'datetime',
            'acesso_de' => 'date',
            'acesso_ate' => 'date',
        ];
    }

    /**
     * @return BelongsToMany<Unidade, $this>
     */
    public function unidades(): BelongsToMany
    {
        return $this->belongsToMany(Unidade::class, 'pessoa_unidade')
            ->withPivot(['vinculo', 'responsavel'])
            ->withTimestamps();
    }

    /**
     * Pode ir para as leitoras faciais: ativa, com foto e com consentimento (LGPD).
     */
    public function liberadaParaLeitoraFacial(): bool
    {
        return $this->ativo && ! $this->trashed() && $this->foto_path !== null && $this->biometria_autorizada_em !== null;
    }

    /**
     * @return BelongsTo<HorarioAcesso, $this>
     */
    public function horarioAcesso(): BelongsTo
    {
        return $this->belongsTo(HorarioAcesso::class);
    }

    /**
     * Leitoras faciais para as quais a pessoa já foi enviada.
     *
     * @return BelongsToMany<Dispositivo, $this>
     */
    public function leitoras(): BelongsToMany
    {
        return $this->belongsToMany(Dispositivo::class, 'dispositivo_pessoa')
            ->withPivot(['assinatura', 'sincronizado_em', 'erro'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Busca por nome, CPF, telefone ou e-mail.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBusca(Builder $query, ?string $termo): void
    {
        $termo = trim((string) $termo);

        if ($termo === '') {
            return;
        }

        $digitos = preg_replace('/\D/', '', $termo);

        $query->where(function (Builder $q) use ($termo, $digitos) {
            $q->where('nome', 'like', "%{$termo}%")
                ->orWhere('email', 'like', "%{$termo}%");

            if ($digitos !== '') {
                $q->orWhereRaw("REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') LIKE ?", ["%{$digitos}%"])
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', '') LIKE ?", ["%{$digitos}%"]);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('cadastro')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
