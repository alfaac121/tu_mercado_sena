<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
        $middleware->alias([
            'jwtVerify' => \App\Http\Middleware\ValidateJWTToken::class,
            'CheckChatBlock' => \App\Http\Middleware\CheckChatBlock::class,
            'CheckGmailRestriction' => \App\Http\Middleware\CheckGmailRestriction::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {

                // 1. Delegar si la excepción tiene su propio método render (como BusinessException)
                if (method_exists($e, 'render')) {
                    /** @var mixed $e */
                    return $e->render($request);
                }

                // 2. Errores de Validación (FormRequests)
                if ($e instanceof ValidationException) {
                    // Logs para encontrar el error (evita métodos inexistentes)
                    Log::error('Datos ingresados por el usuario', [
                        'errors' => $e->errors(),
                        // Registrar inputs (excluir contraseñas) y archivos del request
                        'input' => $request->except(['password', 'password_confirmation']),
                    ]);

                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Los datos proporcionados no son válidos.',
                        'errors'  => $e->errors(),
                    ], 422);
                }

                if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
                    return response()->json([
                        'status' => 'error',
                        'type' => 'RateLimitException',
                        'message' => 'Has realizado demasiadas solicitudes. Espera un momento.'
                    ], 429);
                }

                if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                    $response = $e->getResponse();
                    if ($response->getStatusCode() === 429) {
                        return response()->json([
                            'status' => 'error',
                            'type' => 'RateLimitException',
                            'message' => 'Límite de peticiones excedido. Inténtalo más tarde.'
                        ], 429);
                    }
                }

                if ($e instanceof NotFoundHttpException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'El recurso solicitado no fue encontrado.',
                    ], $e->getStatusCode());
                }

                if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Acceso denegado: No tienes permisos para realizar esta acción.',
                    ], 403);
                }

                if ($e instanceof MethodNotAllowedHttpException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'El método HTTP no está permitido para esta ruta.',
                    ], 405);
                }

                // 3. Personalización detallada de mensajes de JWT
                // Esto ayuda mucho al Frontend para saber si redirigir al Login o refrescar
                $message = $e->getMessage();
                $code = 500;

                if ($e instanceof TokenExpiredException) {
                    $message = 'El token ha expirado. Por favor, refresca tu sesión.';
                    $code = 401;
                } elseif ($e instanceof TokenInvalidException) {
                    $message = 'El token proporcionado es inválido.';
                    $code = 401;
                } elseif ($e instanceof JWTException) {
                    $message = 'Error de autenticación: ' . ($e->getMessage() ?: 'Token no proporcionado.');
                    $code = 401;
                } 
                // 4. Mapeo de otras excepciones comunes
                else {
                    $code = match(true) {
                        $e instanceof ModelNotFoundException,
                        $e instanceof NotFoundHttpException => 404,
                        default => ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getStatusCode() : 500),
                    };
                    // En desarrollo mostrar el mensaje real para depurar
                    $message = (config('app.debug') && $e->getMessage())
                        ? $e->getMessage()
                        : 'Ocurrió un error inesperado en el servidor.';
                }

                // 5. Logging para errores críticos (>= 500)
                if ($code >= 500) {
                    Log::error('API Error Critico', [
                        'class' => get_class($e),
                        'message' => $e->getMessage(),
                        'url' => $request->fullUrl(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => $message,
                ], $code);
            }
        });
    })->create();

return $app;