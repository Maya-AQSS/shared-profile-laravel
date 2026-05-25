<?php

namespace Maya\Profile\Repositories\Contracts;

use Maya\Profile\Enums\Locale;

/**
 * Persiste el cambio de locale del usuario. Punto de extensión por app:
 *
 * - No-op (default): {@see \Maya\Profile\Repositories\Writers\NoopLocaleWriter}
 *   usado mientras `maya_core_employee.locale` no existe en Odoo.
 * - Odoo FDW writer (futuro): cada app proveerá el escritor concreto cuando
 *   la columna exista.
 */
interface LocaleWriterInterface
{
    public function write(string $userId, Locale $locale): void;

    /**
     * Indica si el writer persiste realmente o es un no-op. Permite al
     * controller marcar la respuesta con `mocked: true` sin que el Service
     * tenga que conocer detalles de persistencia.
     */
    public function isPersistent(): bool;
}
