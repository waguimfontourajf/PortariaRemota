<?php

namespace App\Http\Controllers;

use App\Models\Condominio;
use App\Support\CondominioAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrocarCondominioController extends Controller
{
    public function __invoke(Request $request, Condominio $condominio, CondominioAtual $condominioAtual): RedirectResponse
    {
        abort_unless($condominioAtual->trocar($request->user(), $condominio), 403);

        activity('acesso_sistema')
            ->causedBy($request->user())
            ->performedOn($condominio)
            ->log('Trocou o condomínio atual');

        return $request->input('voltar') === 'mapa'
            ? redirect()->route('rede.mapa')
            : redirect()->route('dashboard');
    }
}
