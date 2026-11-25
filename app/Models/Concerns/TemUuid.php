<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Gera automaticamente a coluna "uuid".
 *
 * O id numérico continua sendo a chave primária (mais rápido no MySQL);
 * o uuid é o identificador público usado em URLs e na sincronização
 * entre o servidor central e os servidores locais.
 */
trait TemUuid
{
    public static function bootTemUuid(): void
    {
        static::creating(function ($model) {
            if (blank($model->uuid)) {
                $model->uuid = (string) Str::uuid7();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
