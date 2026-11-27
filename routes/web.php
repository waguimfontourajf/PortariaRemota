<?php

use App\Http\Controllers\EstadoAtualizacaoController;
use App\Http\Controllers\FotoPessoaController;
use App\Http\Controllers\TrocarCondominioController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/painel')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('painel', 'dashboard')->name('dashboard');

    Route::post('condominio/{condominio}/selecionar', TrocarCondominioController::class)
        ->name('condominio.selecionar');

    // Condomínios (lista e criação: super admin; edição: também o síndico do próprio)
    Route::livewire('condominios', 'pages::condominios.index')->name('condominios.index');
    Route::livewire('condominios/novo', 'pages::condominios.form')->name('condominios.criar');
    Route::livewire('condominios/{condominio}/editar', 'pages::condominios.form')->name('condominios.editar');

    // Cadastros do condomínio atual
    Route::livewire('cadastros/blocos', 'pages::cadastros.blocos')->name('blocos.index');
    Route::livewire('cadastros/unidades', 'pages::cadastros.unidades')->name('unidades.index');
    Route::livewire('cadastros/pessoas', 'pages::cadastros.pessoas.index')->name('pessoas.index');
    Route::livewire('cadastros/pessoas/nova', 'pages::cadastros.pessoas.form')->name('pessoas.criar');
    Route::livewire('cadastros/pessoas/{pessoa}/editar', 'pages::cadastros.pessoas.form')->name('pessoas.editar');
    Route::get('cadastros/pessoas/{pessoa}/foto', FotoPessoaController::class)->name('pessoas.foto');

    // Operação
    Route::livewire('abrir', 'pages::acessos.abrir')->name('acessos.abrir');
    Route::livewire('historico', 'pages::acessos.historico')->name('acessos.historico');

    // Equipamentos (configuração técnica: super admin)
    Route::livewire('equipamentos/dispositivos', 'pages::dispositivos.index')->name('dispositivos.index');
    Route::livewire('equipamentos/dispositivos/novo', 'pages::dispositivos.form')->name('dispositivos.criar');
    Route::livewire('equipamentos/dispositivos/{dispositivo}/editar', 'pages::dispositivos.form')->name('dispositivos.editar');
    Route::livewire('equipamentos/pontos-de-acesso', 'pages::dispositivos.pontos')->name('pontos.index');

    // Sistema (super admin)
    Route::livewire('sistema/atualizacao', 'pages::sistema.atualizacao')
        ->middleware('password.confirm')
        ->name('sistema.atualizacao');
    Route::get('sistema/atualizacao/estado', EstadoAtualizacaoController::class)->name('sistema.atualizacao.estado');

    // Usuários do condomínio atual
    Route::livewire('usuarios', 'pages::usuarios.index')->name('usuarios.index');
    Route::livewire('usuarios/novo', 'pages::usuarios.form')->name('usuarios.criar');
    Route::livewire('usuarios/{usuario}/editar', 'pages::usuarios.form')->name('usuarios.editar');
});

require __DIR__.'/settings.php';
