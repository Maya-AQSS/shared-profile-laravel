<?php

namespace Maya\Profile\Enums;

/**
 * Locales soportados por el ecosistema Maya.
 *
 * Origen: Odoo (`maya_core_employee.locale`) cuando exista la columna; hoy
 * mockeado por defecto a {@see self::default()}.
 */
enum Locale: string
{
    case Spanish = 'es';
    case Valencian = 'va';
    case English = 'en';

    public static function default(): self
    {
        return self::Spanish;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
