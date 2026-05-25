<?php

declare(strict_types=1);

namespace Maya\Profile\Routing;

use Illuminate\Support\Facades\Route;
use Maya\Profile\Controllers\AcademicContextController;

/**
 * Registra las rutas del contexto académico (id+code+name de study_types,
 * studies, course_modules, teams asignados a un usuario).
 *
 * Métodos separados `registerMe()` y `registerAdmin()` para que cada app
 * decida qué expone:
 *
 *  - `maya_dashboard`: solo `registerMe()` (perfil propio).
 *  - `maya_authorization`: ambos — `registerMe()` para el propio usuario y
 *    `registerAdmin()` dentro de un gate `permission:user.view` para que un
 *    admin pueda ver el contexto de cualquier usuario.
 *
 * Ejemplo (en `routes/api.php` de la app):
 *
 *   Route::middleware('auth.keycloak')->prefix('v1')->group(function () {
 *       AcademicContextRoutes::registerMe();
 *
 *       Route::middleware('permission:user.view')->group(function () {
 *           AcademicContextRoutes::registerAdmin();
 *       });
 *   });
 */
final class AcademicContextRoutes
{
    public static function registerMe(): void
    {
        Route::get('/me/academic-context', [AcademicContextController::class, 'showMe']);
    }

    public static function registerAdmin(): void
    {
        Route::get('/users/{userId}/academic-context', [AcademicContextController::class, 'show']);
    }
}
