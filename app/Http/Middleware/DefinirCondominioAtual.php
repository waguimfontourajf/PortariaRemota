<?php

namespace App\Http\Middleware;

use App\Support\CondominioAtual;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o condomínio atual da requisição e o escopo das permissões.
 */
class DefinirCondominioAtual
{
    public function __construct(private readonly CondominioAtual $condominioAtual) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario) {
            $this->condominioAtual->resolver($usuario);
        }

        View::share('condominioAtual', $this->condominioAtual->get());

        return $next($request);
    }
}
