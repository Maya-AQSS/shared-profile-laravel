<?php

namespace Maya\Profile\Repositories\Contracts;

use Maya\Profile\Dtos\UserProfileDto;

/**
 * Resuelve el perfil completo del usuario. Punto de extensión por app:
 *
 * - JWT passthrough (4 apps): {@see \Maya\Profile\Repositories\Resolvers\JwtPassthroughResolver}
 * - FDW + permisos académicos (DMS): la app provee su propio resolver.
 */
interface UserProfileResolverInterface
{
    /**
     * @param string               $userId     Claim `sub` del JWT.
     * @param array<string, mixed> $jwtProfile Payload del JWT (mínimo `id`).
     */
    public function resolve(string $userId, array $jwtProfile): UserProfileDto;

    /**
     * Invalida cualquier caché propia del resolver. Llamado tras escrituras
     * de perfil para forzar relectura.
     */
    public function invalidate(string $userId): void;
}
