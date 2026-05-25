<?php

namespace Maya\Profile\Routing;

use Illuminate\Support\Facades\Route;
use Maya\Profile\Controllers\MeController;

/**
 * Registra las rutas estándar del perfil. Llamar desde `routes/api.php` de
 * cada app dentro del grupo con middleware JWT correspondiente.
 *
 * Ejemplo:
 *   Route::middleware('jwt')->prefix('v1')->group(function () {
 *       \Maya\Profile\Routing\MeRoutes::register();
 *   });
 */
final class MeRoutes
{
    public static function register(): void
    {
        Route::get('/me', [MeController::class, 'show']);
        Route::put('/me/locale', [MeController::class, 'updateLocale']);
    }
}
