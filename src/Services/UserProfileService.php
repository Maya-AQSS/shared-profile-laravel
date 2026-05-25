<?php

namespace Maya\Profile\Services;

use Maya\Profile\Dtos\UserProfileDto;
use Maya\Profile\Enums\Locale;
use Maya\Profile\Repositories\Contracts\LocaleWriterInterface;
use Maya\Profile\Repositories\Contracts\UserProfileResolverInterface;
use Maya\Profile\Services\Contracts\UserProfileServiceInterface;

final class UserProfileService implements UserProfileServiceInterface
{
    public function __construct(
        private readonly UserProfileResolverInterface $resolver,
        private readonly LocaleWriterInterface $localeWriter,
    ) {}

    public function getProfile(string $userId, array $jwtProfile): UserProfileDto
    {
        return $this->resolver->resolve($userId, $jwtProfile);
    }

    public function updateLocale(string $userId, array $jwtProfile, Locale $locale): UserProfileDto
    {
        $this->localeWriter->write($userId, $locale);
        $this->resolver->invalidate($userId);

        // El resolver puede no leer el nuevo valor todavía (writer no-op o
        // caches upstream). Aplicamos el locale directamente sobre el DTO
        // para que la respuesta refleje la intención del cliente.
        return $this->resolver->resolve($userId, $jwtProfile)->withLocale($locale);
    }

    public function isLocalePersistent(): bool
    {
        return $this->localeWriter->isPersistent();
    }
}
