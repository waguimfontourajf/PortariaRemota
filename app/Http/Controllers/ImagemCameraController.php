<?php

namespace App\Http\Controllers;

use App\Cameras\Cameras;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Models\Dispositivo;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uma foto atual da câmera (o player pede várias por segundo).
 * Em caso de falha devolve 503 com a mensagem em texto.
 */
class ImagemCameraController extends Controller
{
    public function __invoke(Request $request, Dispositivo $dispositivo, Cameras $cameras): Response
    {
        abort_unless(Cameras::podeVer($request->user(), $dispositivo), 404);

        try {
            $jpeg = $cameras->imagem($dispositivo);
        } catch (ExcecaoDeComunicacao $e) {
            return response($e->getMessage(), 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
