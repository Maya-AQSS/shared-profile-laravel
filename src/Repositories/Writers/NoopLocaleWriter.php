<?php

namespace Maya\Profile\Repositories\Writers;

use Maya\Profile\Enums\Locale;
use Maya\Profile\Repositories\Contracts\LocaleWriterInterface;

/**
 * Writer por defecto: no persiste. Sustituir por un writer FDW Odoo cuando
 * exista la columna `maya_core_employee.locale` + endpoint upstream.
 */
final class NoopLocaleWriter implements LocaleWriterInterface
{
    public function write(string $userId, Locale $locale): void
    {
        // No-op deliberado.
    }

    public function isPersistent(): bool
    {
        return false;
    }
}
