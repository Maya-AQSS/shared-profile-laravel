<?php

declare(strict_types=1);

namespace Maya\Profile\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Maya\Profile\Dtos\LanguageDto;
use Maya\Profile\Repositories\Contracts\LanguageReaderInterface;

final class LanguageController extends Controller
{
    public function __construct(
        private readonly LanguageReaderInterface $languages,
    ) {}

    /**
     * GET /api/v1/languages — idiomas activos disponibles (Odoo res.lang),
     * el por defecto primero. Lo consumen el selector de idioma del perfil y
     * los formularios multiidioma (p.ej. alertas de panel).
     */
    public function index(): JsonResponse
    {
        $data = array_map(
            static fn (LanguageDto $l): array => $l->toArray(),
            $this->languages->active(),
        );

        return response()->json(['data' => $data]);
    }
}
