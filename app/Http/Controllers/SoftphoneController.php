<?php

namespace App\Http\Controllers;

use App\Central\Chamadas;
use App\Central\Pabx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dados do ramal do operador para o softphone no navegador.
 */
class SoftphoneController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_if(Chamadas::condominiosQueAtende($usuario)->isEmpty(), 403);

        if (! Pabx::audioAtivo()) {
            return response()->json(['ativo' => false])->header('Cache-Control', 'no-store');
        }

        $c = Pabx::credenciais($usuario);

        return response()->json([
            'ativo' => true,
            'wss' => Pabx::wss(),
            'uri' => 'sip:'.$c['ramal'].'@'.Pabx::dominio(),
            'usuario' => $c['ramal'],
            'senha' => $c['senha'],
            'stun' => Pabx::stun(),
            'nome' => $usuario->name,
        ])->header('Cache-Control', 'no-store');
    }
}
