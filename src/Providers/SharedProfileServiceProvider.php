<?php

namespace Maya\Profile\Providers;

use Illuminate\Support\ServiceProvider;
use Maya\Profile\Repositories\Contracts\AcademicDataReaderInterface;
use Maya\Profile\Repositories\Contracts\LocaleWriterInterface;
use Maya\Profile\Repositories\Contracts\UserProfileResolverInterface;
use Maya\Profile\Repositories\Readers\AcademicDataReader;
use Maya\Profile\Repositories\Resolvers\FdwEnrichedJwtResolver;
use Maya\Profile\Repositories\Writers\NoopLocaleWriter;
use Maya\Profile\Services\AcademicContextService;
use Maya\Profile\Services\Contracts\AcademicContextServiceInterface;
use Maya\Profile\Services\Contracts\UserProfileServiceInterface;
use Maya\Profile\Services\UserProfileService;

/**
 * Registra los bindings por defecto del paquete. Cada app puede sobrescribir
 * cualquiera de ellos (típicamente `UserProfileResolverInterface`) en su
 * propio `AppServiceProvider::register()` para inyectar lógica de dominio.
 */
class SharedProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Por defecto el resolver enriquece el JWT con los permisos
        // resueltos en `maya_authorization` (vía FDW `user_resolved_permissions`).
        // Si la FDW no está disponible, degrada silenciosamente al passthrough.
        // Las apps que necesiten lógica adicional (DMS añade department,
        // study_type_ids) pueden rebindear este contrato en su propio
        // `AppServiceProvider`.
        $this->app->bindIf(UserProfileResolverInterface::class, FdwEnrichedJwtResolver::class);
        $this->app->bindIf(LocaleWriterInterface::class, NoopLocaleWriter::class);
        $this->app->bindIf(UserProfileServiceInterface::class, UserProfileService::class);
        $this->app->bindIf(AcademicContextServiceInterface::class, AcademicContextService::class);
        $this->app->bindIf(AcademicDataReaderInterface::class, AcademicDataReader::class);
    }
}
