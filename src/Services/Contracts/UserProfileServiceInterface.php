<?php

namespace Maya\Profile\Services\Contracts;

use Maya\Profile\Dtos\UserProfileDto;
use Maya\Profile\Enums\Locale;

interface UserProfileServiceInterface
{
    /**
     * Perfil del usuario activo.
     *
     * @param array<string, mixed> $jwtProfile Payload del JWT (claim `sub` mínimo).
     */
    public function getProfile(string $userId, array $jwtProfile): UserProfileDto;

    /**
     * Actualiza el locale del usuario. Devuelve el perfil resultante.
     */
    public function updateLocale(string $userId, array $jwtProfile, Locale $locale): UserProfileDto;

    /**
     * Indica si la escritura del locale es persistente o un no-op.
     * El controller usa este flag para reportar `mocked: true` al cliente.
     */
    public function isLocalePersistent(): bool;
}
