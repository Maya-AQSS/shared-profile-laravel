<?php

namespace Maya\Profile\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maya\Profile\Http\Requests\UpdateLocaleRequest;
use Maya\Profile\Http\Resources\MeResource;
use Maya\Profile\Services\Contracts\UserProfileServiceInterface;

final class MeController extends Controller
{
    public function __construct(
        private readonly UserProfileServiceInterface $profileService,
    ) {}

    /**
     * GET /api/v1/me
     */
    public function show(Request $request): JsonResponse
    {
        [$userId, $jwtProfile] = $this->jwtContext($request);

        $profile = $this->profileService->getProfile($userId, $jwtProfile);

        return (new MeResource($profile))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * PUT /api/v1/me/locale
     */
    public function updateLocale(UpdateLocaleRequest $request): JsonResponse
    {
        [$userId, $jwtProfile] = $this->jwtContext($request);

        $profile = $this->profileService->updateLocale($userId, $jwtProfile, $request->locale());

        $response = (new MeResource($profile))->response()->setStatusCode(200);

        if (! $this->profileService->isLocalePersistent()) {
            $payload = $response->getData(true);
            $payload['meta'] = array_merge($payload['meta'] ?? [], ['locale_persisted' => false]);
            $response->setData($payload);
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function jwtContext(Request $request): array
    {
        $jwtProfile = (array) $request->attributes->get('jwt_user', []);
        $userId = (string) ($jwtProfile['id'] ?? '');

        return [$userId, $jwtProfile];
    }
}
