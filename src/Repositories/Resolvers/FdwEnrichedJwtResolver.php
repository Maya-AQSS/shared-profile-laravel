<?php

namespace Maya\Profile\Repositories\Resolvers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maya\Profile\Dtos\UserProfileDto;
use Maya\Profile\Repositories\Contracts\UserProfileResolverInterface;
use Throwable;

/**
 * Resolver enriquecido: parte del DTO que produce {@see JwtPassthroughResolver}
 * (identidad básica + locale del JWT) y añade los permisos del usuario
 * leídos de la FDW `user_resolved_permissions` que cada app expone hacia
 * `maya_authorization`.
 *
 * Por qué los permisos vienen de la FDW y no del JWT:
 * - El ecosistema autoriza por permisos resueltos en `maya_authorization`
 *   (jerarquía de roles + overrides grant/deny), no por `realm_access.roles`
 *   de Keycloak. Es la única fuente válida.
 * - La FDW ya está siendo consumida por `RequirePermissionMiddleware` por
 *   cada request protegida; aquí la consumimos UNA VEZ por login y dejamos
 *   el array en el DTO para que el frontend lo cachee en `localStorage`.
 *
 * Cache: los permisos se memorizan 5 min en Redis (mismo TTL que el
 * middleware) bajo la key `me_perms:{userId}` para evitar la query en
 * cada `/me` durante la sesión.
 *
 * Degradación: si la FDW no está disponible (entornos de test sin tabla,
 * conexión rota), devolvemos el DTO base sin `permissions`. El frontend
 * tratará la ausencia como «sin permisos cacheados» y se apoyará en las
 * respuestas 403 reales del middleware.
 */
final class FdwEnrichedJwtResolver implements UserProfileResolverInterface
{
    private const PERMISSIONS_CACHE_TTL = 300;

    public function __construct(
        private readonly JwtPassthroughResolver $base = new JwtPassthroughResolver(),
    ) {}

    public function resolve(string $userId, array $jwtProfile): UserProfileDto
    {
        $dto = $this->base->resolve($userId, $jwtProfile);

        $permissions = $this->loadPermissions($dto->id);
        if ($permissions === null) {
            return $dto;
        }

        return new UserProfileDto(
            id:     $dto->id,
            email:  $dto->email,
            name:   $dto->name,
            locale: $dto->locale,
            extra:  array_merge($dto->extra, ['permissions' => $permissions]),
        );
    }

    public function invalidate(string $userId): void
    {
        $this->base->invalidate($userId);
        Cache::forget($this->cacheKey($userId));
    }

    /**
     * @return list<string>|null  null si la FDW falla (degradación silenciosa).
     */
    private function loadPermissions(string $userId): ?array
    {
        if ($userId === '') {
            return [];
        }

        try {
            return Cache::remember(
                $this->cacheKey($userId),
                self::PERMISSIONS_CACHE_TTL,
                fn (): array => DB::table('user_resolved_permissions')
                    ->where('user_id', $userId)
                    ->pluck('permission_slug')
                    ->map(static fn (mixed $v): string => (string) $v)
                    ->values()
                    ->all(),
            );
        } catch (QueryException) {
            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cacheKey(string $userId): string
    {
        return "me_perms:{$userId}";
    }
}
