<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');

    $middleware->alias([
        'role' => \App\Http\Middleware\EnsureRole::class,
    ]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // Every framework-default JSON error response gets a stable
        // `error_code` alongside the English `message`, so the Flutter
        // client can map codes to localized strings instead of parsing
        // English sentences. Custom application errors use App\Support\ApiError
        // for the same shape — see that class for the convention.
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'validation_failed',
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            return response()->json([
                'message' => 'Unauthenticated.',
                'error_code' => 'unauthenticated',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            return response()->json([
                'message' => $e->getMessage() ?: 'This action is unauthorized.',
                'error_code' => 'unauthorized',
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            return response()->json([
                'message' => "No query results for model [{$e->getModel()}]" . (($id = $e->getIds()[0] ?? null) ? " {$id}" : ''),
                'error_code' => 'not_found',
                'meta' => ['model' => class_basename($e->getModel())],
            ], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            // Laravel converts a failed route-model-binding's ModelNotFoundException
            // into a NotFoundHttpException before custom render() callbacks run
            // (see Illuminate\Foundation\Exceptions\Handler::prepareException()),
            // wrapping the original as ->getPrevious(). Unwrap it here so route-
            // model-binding 404s still carry the model name in `meta`.
            $previous = $e->getPrevious();
            if ($previous instanceof ModelNotFoundException) {
                return response()->json([
                    'message' => "No query results for model [{$previous->getModel()}]" . (($id = $previous->getIds()[0] ?? null) ? " {$id}" : ''),
                    'error_code' => 'not_found',
                    'meta' => ['model' => class_basename($previous->getModel())],
                ], 404);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'Not found.',
                'error_code' => 'not_found',
            ], 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            return response()->json([
                'message' => $e->getMessage() ?: 'Method not allowed.',
                'error_code' => 'method_not_allowed',
            ], 405);
        });
    })->create();
