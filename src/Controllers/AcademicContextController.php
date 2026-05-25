<?php

declare(strict_types=1);

namespace Maya\Profile\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maya\Profile\Http\Resources\AcademicContextResource;
use Maya\Profile\Services\Contracts\AcademicContextServiceInterface;

final class AcademicContextController extends Controller
{
    public function __construct(
        private readonly AcademicContextServiceInterface $academicContextService,
    ) {}

    /**
     * GET /api/v1/me/academic-context
     */
    public function showMe(Request $request): JsonResponse
    {
        $userId = $this->jwtUserId($request);

        $context = $this->academicContextService->forUser($userId);

        return (new AcademicContextResource($context))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * GET /api/v1/users/{userId}/academic-context
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        $context = $this->academicContextService->forUser($userId);

        return (new AcademicContextResource($context))
            ->response()
            ->setStatusCode(200);
    }

    private function jwtUserId(Request $request): string
    {
        $jwtProfile = (array) $request->attributes->get('jwt_user', []);

        return (string) ($jwtProfile['id'] ?? '');
    }
}
