<?php

namespace App\Http\Controllers;

use App\Central\Chamadas;
use App\Enums\OrigemChamada;
use App\Enums\TipoDispositivo;
use App\Models\Dispositivo;
use Illuminate\Http\JsonResponse;

/**
 * "Campainha": um botão (Shelly i3/i4, interfone com evento HTTP...) chama
 * este link e a chamada aparece na central, com câmera e botão ABRIR.
 * Sem áudio: o operador vê quem está no portão e decide.
 */
class CampainhaController extends Controller
{
    public function __invoke(string $token, Chamadas $chamadas): JsonResponse
    {
        $interfone = Dispositivo::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('tipo', TipoDispositivo::Interfone->value)
            ->where('ativo', true)
            ->first();

        abort_unless($interfone, 404);

        $chamada = $chamadas->iniciar($interfone, OrigemChamada::Campainha);

        return response()->json(['ok' => true, 'chamada' => $chamada->uuid]);
    }
}
