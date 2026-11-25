<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modo do servidor
    |--------------------------------------------------------------------------
    |
    | "central": servidor principal (painel de todos os condomínios, central de
    |            atendimento, licenças).
    | "local":   servidor instalado dentro de um condomínio. Toma as decisões
    |            localmente e sincroniza com o servidor central.
    |
    */

    'modo' => env('PORTARIA_MODO', 'central'),

    /*
    |--------------------------------------------------------------------------
    | Verificação em duas etapas (2FA)
    |--------------------------------------------------------------------------
    |
    | Quando ativo, super admins e perfis marcados como "exige 2FA" (ver
    | App\Enums\Perfil) só conseguem usar o sistema depois de ativar o 2FA.
    |
    */

    'exigir_2fa' => (bool) env('PORTARIA_EXIGIR_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | Primeiro super admin (usado pelo DatabaseSeeder)
    |--------------------------------------------------------------------------
    */

    'admin' => [
        'nome' => env('PORTARIA_ADMIN_NOME', 'Administrador'),
        'email' => env('PORTARIA_ADMIN_EMAIL'),
        'senha' => env('PORTARIA_ADMIN_SENHA'),
    ],

];
