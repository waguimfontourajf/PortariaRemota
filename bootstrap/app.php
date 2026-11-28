<?php

use App\Http\Middleware\BloquearUsuarioInativo;
use App\Http\Middleware\DefinirCondominioAtual;
use App\Http\Middleware\ExigirDoisFatores;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Atrás do Cloudflare Tunnel, as requisições chegam ao Apache vindas do
        // próprio servidor (cloudflared). Confia nesses proxies para obter o IP
        // real do visitante e saber que o acesso original foi em HTTPS.
        $middleware->trustProxies(at: array_filter(array_map('trim', explode(',', (string) env('PORTARIA_PROXIES_CONFIAVEIS', '127.0.0.1,::1')))));

        // A tela "Atualizar sistema" acompanha o andamento mesmo com o sistema em manutenção.
        $middleware->preventRequestsDuringMaintenance(except: ['sistema/atualizacao/estado']);

        // Avisos do PABX e da campainha chegam de aparelhos, sem sessão: protegidos por token.
        $middleware->validateCsrfTokens(except: ['pabx/evento', 'campainha/*']);

        $middleware->web(append: [
            BloquearUsuarioInativo::class,
            DefinirCondominioAtual::class,
            ExigirDoisFatores::class,
        ]);

        // O condomínio atual precisa estar definido antes de resolver os
        // parâmetros das rotas, para que as permissões usem o escopo certo.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: DefinirCondominioAtual::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
