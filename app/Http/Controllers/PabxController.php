<?php

namespace App\Http\Controllers;

use App\Central\Chamadas;
use App\Central\Pabx;
use App\Models\Chamada;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Avisos do Asterisk (função CURL do dialplan).
 *
 *   evento=inicio  ramal, id  → responde "uuid-da-chamada|PJSIP/op1&PJSIP/op2"
 *   evento=fim     id, status → registra o fim (atendida ou não)
 *
 * Protegido pelo token gerado na tela "Central telefônica".
 */
class PabxController extends Controller
{
    public function __invoke(Request $request, Chamadas $chamadas): Response
    {
        abort_unless(Pabx::confere($request->string('token')->toString()), 403);

        $dados = $request->validate([
            'evento' => ['required', 'in:inicio,fim'],
            'ramal' => ['required_if:evento,inicio', 'nullable', 'string', 'max:40'],
            'id' => ['required', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        if ($dados['evento'] === 'inicio') {
            $chamada = $chamadas->porRamal($dados['ramal'], $dados['id']);

            if (! $chamada) {
                return $this->texto('0|');
            }

            return $this->texto($chamada->uuid.'|'.Pabx::destinosPara($chamada->condominio_id));
        }

        foreach (Chamada::query()->where('id_externo', $dados['id'])->ativas()->get() as $chamada) {
            $chamadas->encerrar($chamada);
        }

        return $this->texto('ok');
    }

    private function texto(string $corpo): Response
    {
        return response($corpo, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
