<?php

use App\Http\Controllers\EstadoAtualizacaoController;
use App\Http\Controllers\FotoPessoaController;
use App\Http\Controllers\NovidadesController;
use App\Http\Controllers\TrocarCondominioController;
use App\Http\Controllers\VisitanteController;
use App\Support\RegrasDeAcesso;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/painel')->name('home');

// Link do visitante (público, sem login): quem tem o link tem a autorização.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('v/{token}', [VisitanteController::class, 'mostrar'])->where('token', '[A-Za-z0-9]{32}')->name('visitante.mostrar');
    Route::post('v/{token}/abrir/{ponto}', [VisitanteController::class, 'abrir'])->where('token', '[A-Za-z0-9]{32}')
        ->middleware('throttle:6,1')->name('visitante.abrir');
});

Route::middleware(['auth'])->group(function () {
    // Morador (só morador) cai direto na tela de abrir, como num app.
    Route::get('painel', function () {
        $usuario = auth()->user();

        if (! $usuario->isSuperAdmin() && ! RegrasDeAcesso::temPerfilOperacionalEmAlgum($usuario) && $usuario->condominios()->exists()) {
            return redirect()->route('acessos.abrir');
        }

        return view('dashboard');
    })->name('dashboard');

    // Tempo real: as telas perguntam "tem novidade?" a cada poucos segundos.
    Route::get('novidades', NovidadesController::class)->name('novidades');

    Route::post('condominio/{condominio}/selecionar', TrocarCondominioController::class)
        ->name('condominio.selecionar');

    // Condomínios (lista e criação: super admin; edição: também o síndico do próprio)
    Route::livewire('condominios', 'pages::condominios.index')->name('condominios.index');
    Route::livewire('condominios/novo', 'pages::condominios.form')->name('condominios.criar');
    Route::livewire('condominios/{condominio}/editar', 'pages::condominios.form')->name('condominios.editar');

    // Cadastros do condomínio atual
    Route::livewire('cadastros/blocos', 'pages::cadastros.blocos')->name('blocos.index');
    Route::livewire('cadastros/unidades', 'pages::cadastros.unidades')->name('unidades.index');
    Route::livewire('cadastros/horarios', 'pages::cadastros.horarios')->name('horarios.index');
    Route::livewire('cadastros/pessoas', 'pages::cadastros.pessoas.index')->name('pessoas.index');
    Route::livewire('cadastros/pessoas/nova', 'pages::cadastros.pessoas.form')->name('pessoas.criar');
    Route::livewire('cadastros/pessoas/{pessoa}/editar', 'pages::cadastros.pessoas.form')->name('pessoas.editar');
    Route::get('cadastros/pessoas/{pessoa}/foto', FotoPessoaController::class)->name('pessoas.foto');

    // Operação
    Route::livewire('abrir', 'pages::acessos.abrir')->name('acessos.abrir');
    Route::livewire('visitas', 'pages::visitas.index')->name('visitas.index');
    Route::livewire('visitas/nova', 'pages::visitas.form')->name('visitas.criar');
    Route::livewire('visitas/{visita}', 'pages::visitas.ver')->name('visitas.ver');
    Route::livewire('historico', 'pages::acessos.historico')->name('acessos.historico');

    // Equipamentos (configuração técnica: super admin)
    Route::livewire('equipamentos/dispositivos', 'pages::dispositivos.index')->name('dispositivos.index');
    Route::livewire('equipamentos/dispositivos/novo', 'pages::dispositivos.form')->name('dispositivos.criar');
    Route::livewire('equipamentos/dispositivos/{dispositivo}/editar', 'pages::dispositivos.form')->name('dispositivos.editar');
    Route::livewire('equipamentos/mapa', 'pages::rede.mapa')->name('rede.mapa');
    Route::livewire('mapa-geral', 'pages::rede.geral')->name('rede.geral');
    Route::livewire('equipamentos/pontos-de-acesso', 'pages::dispositivos.pontos')->name('pontos.index');

    // Sistema (super admin)
    Route::livewire('sistema/atualizacao', 'pages::sistema.atualizacao')
        ->middleware('password.confirm')
        ->name('sistema.atualizacao');
    Route::livewire('sistema/alertas', 'pages::sistema.alertas')->name('sistema.alertas');
    Route::get('sistema/atualizacao/estado', EstadoAtualizacaoController::class)->name('sistema.atualizacao.estado');

    // Usuários do condomínio atual
    Route::livewire('usuarios', 'pages::usuarios.index')->name('usuarios.index');
    Route::livewire('usuarios/novo', 'pages::usuarios.form')->name('usuarios.criar');
    Route::livewire('usuarios/{usuario}/editar', 'pages::usuarios.form')->name('usuarios.editar');
});

require __DIR__.'/settings.php';
