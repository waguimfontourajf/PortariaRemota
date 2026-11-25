<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('configuracoes', 'configuracoes/perfil');

    Route::livewire('configuracoes/perfil', 'pages::settings.profile')->name('profile.edit');

    Route::livewire('configuracoes/aparencia', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('configuracoes/seguranca', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
