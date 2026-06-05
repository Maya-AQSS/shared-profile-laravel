<?php

declare(strict_types=1);

namespace Maya\Profile\Repositories\Readers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maya\Profile\Dtos\LanguageDto;
use Maya\Profile\Enums\Locale;
use Maya\Profile\Repositories\Contracts\LanguageReaderInterface;
use Throwable;

/**
 * Lee los idiomas activos de la vista FDW `languages` (proyectada de Odoo
 * `res.lang`). Degrada con elegancia: si la vista no existe o el FDW falla,
 * devuelve el conjunto de locales con catálogo i18n de UI ({@see Locale}).
 *
 * Cache: TTL 5 min, key `languages:active`.
 */
final class LanguageReader implements LanguageReaderInterface
{
    private const CACHE_TTL = 300;

    /** Etiquetas nativas para el fallback por enum. */
    private const FALLBACK_LABELS = [
        'es' => 'Español',
        'va' => 'Valencià',
        'en' => 'English',
    ];

    public function active(): array
    {
        try {
            return Cache::remember(
                'languages:active',
                self::CACHE_TTL,
                fn (): array => $this->readFromView(),
            );
        } catch (Throwable) {
            return $this->fallback();
        }
    }

    /**
     * @return list<LanguageDto>
     */
    private function readFromView(): array
    {
        $rows = DB::table('languages')
            ->select('code', 'name', 'is_default')
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();

        if ($rows->isEmpty()) {
            return $this->fallback();
        }

        return $rows
            ->map(fn ($r): LanguageDto => new LanguageDto(
                code: (string) $r->code,
                name: (string) $r->name,
                isDefault: (bool) $r->is_default,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<LanguageDto>
     */
    private function fallback(): array
    {
        $default = Locale::default();

        return array_map(
            fn (Locale $l): LanguageDto => new LanguageDto(
                code: $l->value,
                name: self::FALLBACK_LABELS[$l->value] ?? $l->value,
                isDefault: $l === $default,
            ),
            Locale::cases(),
        );
    }
}
