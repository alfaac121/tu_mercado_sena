<?php

namespace App\Providers;


use Illuminate\Support\ServiceProvider;
use Tymon\JWTAuth\JWTGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;




class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Crear la inyección de dependencias para el guard JWT
        $this->app->bind(JWTGuard::class, function($app){
            return auth('api');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api_usuario', function (Request $request) {
            // Intentar obtener el usuario del request (inyectado por jwtVerify)
            $user = $request->user();
            
            // Si no hay usuario, usamos la IP (como plan B)
            $identifier = $user ? $user->id : $request->ip();

            // Limitar a 30 peticiones por minuto por cada ID de usuario
            return Limit::perMinute(60)->by($identifier)->response(function (Request $request, array $headers) {
                return response()->json([
                    'success' => 'error',
                    'message' => 'Demasiadas solicitudes. Intentalo más tarde'
                ], 429, $headers);
            });
        });
    }
}
