<?php

declare(strict_types=1);

namespace Maya\Profile\Repositories\Contracts;

use Maya\Profile\Dtos\LanguageDto;

/**
 * Contrato del reader de idiomas activos. Permite mockear en tests y sustituir
 * el origen (FDW Odoo, cache, lista estática) sin tocar a los consumidores.
 */
interface LanguageReaderInterface
{
    /**
     * Idiomas activos disponibles, el idioma por defecto primero.
     *
     * @return list<LanguageDto>
     */
    public function active(): array;
}
