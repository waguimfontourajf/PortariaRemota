<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obriga super admins e operadores a ativarem a verificação em duas etapas
 * antes de usar o sistema. Só a tela de segurança e o logout ficam liberados.
 */
class ExigirDoisFatores
{
    /** @var list<string> */
    private const ROTAS_LIBERADAS = [
        'security.edit',
        'profile.edit',
        'appearance.edit',
        'logout',
        'password.confirm',
        'password.confirmation',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (
            $usuario
            && ! $request->routeIs(...self::ROTAS_LIBERADAS)
            && ! $request->is('user/*', 'livewire*')
            && $usuario->precisaDeDoisFatores()
            && ! $usuario->temDoisFatoresAtivo()
        ) {
            if ($request->expectsJson()) {
                abort(403, __('Ative a verificação em duas etapas para continuar.'));
            }

            return redirect()->route('security.edit')
                ->with('aviso_2fa', __('Por segurança, ative a verificação em duas etapas (2FA) para continuar usando o sistema.'));
        }

        return $next($request);
    }
}
