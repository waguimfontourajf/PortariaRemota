<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Configurações gerais editáveis pelo painel. Os valores ficam
 * CRIPTOGRAFADOS no banco (podem conter tokens).
 *
 * @property string $chave
 * @property string|null $valor
 */
class Configuracao extends Model
{
    protected $table = 'configuracoes';

    protected $primaryKey = 'chave';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['chave', 'valor'];

    public static function obter(string $chave, mixed $padrao = null): mixed
    {
        $valor = Cache::remember('configuracao:'.$chave, 300, function () use ($chave) {
            $bruto = static::query()->whereKey($chave)->value('valor');

            if ($bruto === null) {
                return null;
            }

            try {
                return Crypt::decryptString($bruto);
            } catch (Throwable) {
                return null;
            }
        });

        return $valor === null || $valor === '' ? $padrao : $valor;
    }

    public static function definir(string $chave, ?string $valor): void
    {
        static::query()->updateOrCreate(
            ['chave' => $chave],
            ['valor' => $valor === null || $valor === '' ? null : Crypt::encryptString($valor)],
        );

        Cache::forget('configuracao:'.$chave);
    }
}
