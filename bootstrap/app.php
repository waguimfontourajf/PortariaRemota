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
