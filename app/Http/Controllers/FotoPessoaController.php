<?php

namespace App\Http\Controllers;

use App\Enums\Permissao;
use App\Models\Pessoa;
use App\Support\CondominioAtual;
use App\Support\FotoDePessoa;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Exibe a foto da pessoa (disco privado) só para quem gerencia os cadastros
 * do condomínio dela. Dado biométrico: nunca fica em pasta pública.
 */
class FotoPessoaController extends Controller
{
    public function __invoke(Request $request, Pessoa $pessoa, CondominioAtual $condominioAtual, FotoDePessoa $fotos): Response
    {
        abort_unless($condominioAtual->id() === $pessoa->condominio_id, 404);
        abort_unless($request->user()?->can(Permissao::CadastrosGerenciar->value), 403);

        $conteudo = $fotos->conteudo($pessoa);
        abort_if($conteudo === null, 404);

        return response($conteudo, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
