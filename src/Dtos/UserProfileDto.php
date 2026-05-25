<?php

namespace Maya\Profile\Dtos;

use Maya\Profile\Enums\Locale;

/**
 * DTO inmutable del perfil del usuario autenticado.
 *
 * Los campos `id`, `email`, `name` y `locale` son comunes a todas las apps.
 * `extra` aloja campos específicos del dominio de cada app (p. ej. `roles`,
 * `permissions`, `teams`, `study_ids`) sin obligar al paquete a conocerlos.
 */
final class UserProfileDto
{
    /**
     * @param array<string, mixed> $extra Campos específicos de cada app.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly Locale $locale,
        public readonly array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'id'     => $this->id,
            'email'  => $this->email,
            'name'   => $this->name,
            'locale' => $this->locale->value,
        ]);
    }

    public function withLocale(Locale $locale): self
    {
        return new self($this->id, $this->email, $this->name, $locale, $this->extra);
    }
}
