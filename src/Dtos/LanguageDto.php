<?php

declare(strict_types=1);

namespace Maya\Profile\Dtos;

/**
 * Un idioma disponible para el usuario (selector de perfil / formularios
 * multiidioma). Origen: vista FDW `languages` (Odoo `res.lang`), con fallback
 * al enum {@see \Maya\Profile\Enums\Locale}.
 */
final readonly class LanguageDto
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isDefault,
    ) {}

    /**
     * @return array{code: string, name: string, is_default: bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'is_default' => $this->isDefault,
        ];
    }
}
