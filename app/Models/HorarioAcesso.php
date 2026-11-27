<?php

namespace App\Models;

use App\Models\Concerns\PertenceAoCondominio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Faixa de horário em que uma pessoa pode entrar (aplicada nas leitoras).
 *
 * @property int $id
 * @property int $condominio_id
 * @property int $numero Posição do horário nas leitoras (1, 2, 3...)
 * @property string $nome
 * @property list<int> $dias 0 = domingo ... 6 = sábado
 * @property string $inicio HH:MM:SS
 * @property string $fim HH:MM:SS
 */
#[Fillable(['nome', 'dias', 'inicio', 'fim'])]
class HorarioAcesso extends Model
{
    use LogsActivity, PertenceAoCondominio;

    public const DIAS = [0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'];

    public const MAXIMO_POR_CONDOMINIO = 30;

    protected $table = 'horarios_acesso';

    protected function casts(): array
    {
        return ['dias' => 'array'];
    }

    /**
     * @return HasMany<Pessoa, $this>
     */
    public function pessoas(): HasMany
    {
        return $this->hasMany(Pessoa::class);
    }

    /**
     * Ex.: "Seg, Ter, Qua, Qui, Sex · 07:00 às 18:00".
     */
    public function descricao(): string
    {
        $dias = collect($this->dias)->map(fn ($d) => (int) $d)->sort()->values();
        $texto = $dias->all() === [1, 2, 3, 4, 5] ? 'Seg a Sex' : ($dias->count() === 7 ? 'Todos os dias' : $dias->map(fn ($d) => self::DIAS[$d])->join(', '));

        return $texto.' · '.substr($this->inicio, 0, 5).' às '.substr($this->fim, 0, 5);
    }

    /**
     * Segundos desde 00:00 (para a Control iD).
     */
    public static function segundos(string $hora): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hora.':0:0'));

        return $h * 3600 + $m * 60 + $s;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('cadastro')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
