<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Spatie\Permission\Exceptions\UnauthorizedException;
use App\Exceptions\NegocioException;
use App\Exceptions\AutorizacionRequeridaException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //$middleware->redirectGuestsTo(fn () => abort(401, 'Usuario no autenticado'));
        // NO EXISTE VISTA PARA REDIRECCIONAR POR SER API
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'usuario.activo'     => \App\Http\Middleware\UsuarioActivo::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // 401 - No autenticado
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'UNAUTHENTICATED',
                'message' => 'No autenticado. Token inválido o no proporcionado.',
            ], 401);
        });

        // 403 - Sin permisos
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'FORBIDDEN',
                'message' => 'No tienes permisos para realizar esta acción.',
            ], 403);
        });

        // Nunca guardar en logs el PIN o la contraseña de quien autoriza
        $exceptions->dontFlash(['autorizacion', 'pin', 'pin_confirmation', 'password_actual']);

        // 403 - Sin permiso (middleware de Spatie)
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'FORBIDDEN',
                'message' => 'No tienes permisos para realizar esta acción.',
            ], 403);
        });

        // 403 - La operación pide un permiso que el usuario no tiene (la hace el administrador)
        $exceptions->render(function (AutorizacionRequeridaException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'SIN_PERMISO',
                'permiso' => $e->permiso,
                'invalido' => $e->invalido,
                'message' => $e->getMessage(),
            ], 403);
        });

        // 422 - Regla de negocio no cumplida (mensaje pensado para el usuario)
        $exceptions->render(function (NegocioException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'BUSINESS_RULE',
                'message' => $e->getMessage(),
            ], 422);
        });

        // 422 - Validación
        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'VALIDATION_ERROR',
                'message' => 'Error en los datos enviados.',
                'errors'  => $e->errors(),
            ], 422);
        });

        // 404 - Modelo no encontrado
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            $model = class_basename($e->getModel());
            return response()->json([
                'success' => false,
                'status'  => 'NOT_FOUND',
                'message' => "El recurso '{$model}' no fue encontrado.",
            ], 404);
        });

        // 404 - Ruta no encontrada
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'ROUTE_NOT_FOUND',
                'message' => 'La ruta solicitada no existe.',
            ], 404);
        });

        // 405 - Método no permitido
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'METHOD_NOT_ALLOWED',
                'message' => 'Método HTTP no permitido para esta ruta.',
            ], 405);
        });

        // 429 - Demasiadas peticiones
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'TOO_MANY_REQUESTS',
                'message' => 'Demasiadas peticiones. Intenta de nuevo más tarde.',
            ], 429);
        });

        // Otros errores HTTP: conservar su código
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            return response()->json([
                'success' => false,
                'status'  => 'HTTP_ERROR',
                'message' => $e->getMessage() ?: 'Error en la petición.',
            ], $e->getStatusCode());
        });

        // 500 - Error interno (catch-all)
        $exceptions->render(function (\Throwable $e, Request $request) {
            $isDebug = config('app.debug');

            return response()->json([
                'success' => false,
                'status'  => 'SERVER_ERROR',
                'message' => $isDebug ? $e->getMessage() : 'Error interno del servidor.',
                'debug'   => $isDebug ? [
                    'exception' => get_class($e),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ] : null,
            ], 500);
        });

    })->create();
