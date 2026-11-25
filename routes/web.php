<?php

use App\Http\Controllers\TrocarCondominioController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/painel')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('painel', 'dashboard')->name('dashboard');

    Route::post('condominio/{condominio}/selecionar', TrocarCondominioController::class)
        ->name('condominio.selecionar');
});

require __DIR__.'/settings.php';
