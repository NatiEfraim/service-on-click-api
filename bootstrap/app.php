<?php

use App\Enums\HttpStatusCodeEnum;
use App\Exceptions\DomainError;
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
        // The access token arrives in a cookie; turn it into a bearer token first.
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

        // A business-rule refusal is an answer, not an error: not logged.
        $exceptions->dontReport(DomainError::class);

        // One vague answer for 403/404/405 so it never reveals whether a record exists.
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

        // 403/404/405 get the same vague answer; other statuses (409, 422...) say what is wrong.
        $exceptions->render(function (DomainError $e, Request $request) {
            $denied = in_array($e->status, [
                SymfonyResponse::HTTP_FORBIDDEN,
                SymfonyResponse::HTTP_NOT_FOUND,
                SymfonyResponse::HTTP_METHOD_NOT_ALLOWED,
            ], true);

            return response()->json(['message' => $denied ? HttpStatusCodeEnum::DENIED_MESSAGE : $e->getMessage()], $e->status);
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
