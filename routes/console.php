<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Monitoramento da rede: verifica os equipamentos a cada minuto.
// No Windows, o agendador é ativado por implantacao\instalar-agendador.bat.
Schedule::command('portaria:monitorar')->everyMinute()->withoutOverlapping(5);
