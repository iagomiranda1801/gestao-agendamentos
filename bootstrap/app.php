<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\UseSegmentRequestHost::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'webhooks/evolution',
            'webhooks/evolution/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->is('api/v1/integrations/*') && $response->getStatusCode() >= 500) {
                return response()->json(['success' => false, 'message' => 'Erro interno.'], 500);
            }

            return $response;
        });
    })->create();
