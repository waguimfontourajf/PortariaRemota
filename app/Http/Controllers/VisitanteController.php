<?php

namespace App\Http\Controllers;

use App\Acoes\AcionarPontoDeAcesso;
use App\Enums\ResultadoEvento;
use App\Models\PontoAcesso;
use App\Models\Visita;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Página pública do visitante (link enviado pelo morador). Não exige login:
 * quem tem o link tem a autorização, dentro da validade e do limite de usos.
 */
class VisitanteController extends Controller
{
    public function mostrar(string $token): View
    {
        $visita = $this->buscar($token);

        return view('visitante.mostrar', [
            'visita' => $visita,
            'bloqueio' => $visita->bloqueio(),
            'pontos' => $visita->pontos()->where('ativo', true)->where('liberado_visitantes', true)->orderBy('ordem')->get(),
            'token' => $token,
        ]);
    }

    public function abrir(Request $request, string $token, string $ponto, AcionarPontoDeAcesso $acionar): RedirectResponse
    {
        $visita = $this->buscar($token);
        $pontoAcesso = PontoAcesso::query()->where('uuid', $ponto)->where('condominio_id', $visita->condominio_id)->firstOrFail();

        $evento = $acionar->porVisitante($pontoAcesso, $visita, $request->ip());

        return redirect()->route('visitante.mostrar', $token)->with('resultado', [
            'sucesso' => $evento->resultado === ResultadoEvento::Sucesso,
            'ponto' => $pontoAcesso->nome,
            'mensagem' => $evento->resultado === ResultadoEvento::Sucesso
                ? __('Pode entrar! O :ponto foi aberto.', ['ponto' => $pontoAcesso->nome])
                : ($evento->resultado === ResultadoEvento::Negado ? $evento->detalhe : __('Não foi possível abrir agora. Tente de novo em alguns segundos ou chame pelo interfone.')),
        ]);
    }

    private function buscar(string $token): Visita
    {
        // Mesmo tempo de resposta para token válido ou não (não ajuda a "adivinhar").
        $visita = Visita::peloToken($token);
        abort_if($visita === null, 404);

        return $visita->load(['condominio', 'autor:id,name', 'unidade.bloco']);
    }
}
