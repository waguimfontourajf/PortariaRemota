<?php

use App\Dispositivos\Drivers\ControlIdFacial;
use App\Dispositivos\Drivers\HikvisionFacial;
use App\Dispositivos\Drivers\HttpGenerico;
use App\Dispositivos\Drivers\IntelbrasFacial;
use App\Dispositivos\Drivers\ModbusRele;
use App\Dispositivos\Drivers\Shelly;
use App\Dispositivos\Drivers\Simulador;
use App\Dispositivos\Drivers\TuyaLocal;

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

    /*
    |--------------------------------------------------------------------------
    | Drivers de dispositivos
    |--------------------------------------------------------------------------
    |
    | Cada classe aparece como uma opção na caixa de seleção "Modelo / driver"
    | do cadastro de dispositivos. Para suportar um equipamento novo, crie a
    | classe em app/Dispositivos/Drivers e adicione-a aqui.
    |
    */

    'drivers' => [
        'reles' => [
            Shelly::class,
            TuyaLocal::class,
            ModbusRele::class,
            ControlIdFacial::class,
            IntelbrasFacial::class,
            HikvisionFacial::class,
            HttpGenerico::class,
            Simulador::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Acionamento
    |--------------------------------------------------------------------------
    */

    'acionamento' => [
        // Ignora um segundo comando para o mesmo ponto enquanto o primeiro não termina.
        'trava_segundos' => 10,
        // Limites do tempo de pulso configurável por ponto de acesso.
        'pulso_minimo_ms' => 100,
        'pulso_maximo_ms' => 30000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Atualização pelo painel (servidor Windows)
    |--------------------------------------------------------------------------
    |
    | O botão "Atualizar sistema" pede ao Windows para executar a tarefa
    | agendada criada por implantacao\instalar-atualizacao-pelo-painel.bat.
    |
    */

    'atualizacao' => [
        'tarefa' => env('PORTARIA_ATUALIZACAO_TAREFA', 'PortariaRemota-Atualizar'),
        // null = automático (habilitada somente no Windows)
        'habilitada' => env('PORTARIA_ATUALIZACAO_PAINEL'),
        // Depois desse tempo, uma atualização "executando" é considerada travada.
        'limite_minutos' => 20,
    ],

];
