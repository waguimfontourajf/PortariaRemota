<?php

namespace App\Http\Controllers;

use App\Support\AtualizacaoDoSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Andamento da atualização (consultado pela tela a cada poucos segundos).
 * Continua respondendo durante o modo manutenção (ver bootstrap/app.php).
 */
class EstadoAtualizacaoController extends Controller
{
    public function __invoke(Request $request, AtualizacaoDoSistema $atualizacao): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $versao = $atualizacao->versao();

        return response()->json([
            'estado' => $atualizacao->estado(),
            'alterado_em' => $atualizacao->estadoAlteradoEm()?->toIso8601String(),
            'versao' => $versao ? $versao['commit'].' — '.$versao['mensagem'] : null,
            'registro' => $atualizacao->registro(),
        ])->header('Cache-Control', 'no-store');
    }
}
