<?php

namespace Maya\Profile\Repositories\Resolvers;

use Maya\Profile\Dtos\UserProfileDto;
use Maya\Profile\Enums\Locale;
use Maya\Profile\Repositories\Contracts\UserProfileResolverInterface;

/**
 * Resolver por defecto: proyecta el payload del JWT directamente.
 *
 * Uso típico: apps que aún no enriquecen el perfil desde FDW (audit, logs,
 * authorization, dashboard). El locale se mockea con {@see Locale::default()}
 * hasta que `maya_core_employee.locale` exista en Odoo.
 */
final class JwtPassthroughResolver implements UserProfileResolverInterface
{
    public function resolve(string $userId, array $jwtProfile): UserProfileDto
    {
        $extra = $jwtProfile;
        unset($extra['id'], $extra['email'], $extra['name'], $extra['locale']);

        $localeValue = $jwtProfile['locale'] ?? null;
        $locale = (is_string($localeValue) && Locale::tryFrom($localeValue) !== null)
            ? Locale::from($localeValue)
            : Locale::default();

        return new UserProfileDto(
            id:     (string) ($jwtProfile['id'] ?? $userId),
            email:  $this->stringOrNull($jwtProfile['email'] ?? null),
            name:   $this->stringOrNull($jwtProfile['name'] ?? null),
            locale: $locale,
            extra:  $extra,
        );
    }

    public function invalidate(string $userId): void
    {
        // Resolver puro: no mantiene caché propia.
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }
        return null;
    }
}
