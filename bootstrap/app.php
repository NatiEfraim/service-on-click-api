<?php

use App\Enums\HttpStatusCodeEnum;
use App\Http\Middleware\VerifyCookie;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The personal access token arrives in a cookie; turn it into the bearer token before anything else.
        $middleware->prepend([
            VerifyCookie::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One vague answer for every refused request (403 - also a missing role -, 404 and 405): it does not say whether a record exists or why.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $deniedStatusCodes = [
                SymfonyResponse::HTTP_FORBIDDEN,
                SymfonyResponse::HTTP_NOT_FOUND,
                SymfonyResponse::HTTP_METHOD_NOT_ALLOWED,
            ];

            if (!$request->is('api/*') || !in_array($e->getStatusCode(), $deniedStatusCodes, true)) {
                return null;
            }

            return response()->json(['message' => HttpStatusCodeEnum::DENIED_MESSAGE], $e->getStatusCode());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json(
                ['message' => HttpStatusCodeEnum::UNAUTHORIZED->getMessage()],
                SymfonyResponse::HTTP_UNAUTHORIZED
            );
        });
    })->create();
