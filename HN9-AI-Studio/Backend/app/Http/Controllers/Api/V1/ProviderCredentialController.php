<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProviderCredentialRequest;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\ProviderConnectionTester;
use App\Services\ProviderCredentialService;
use App\Support\ApiResponse;
use App\Support\RuntimeProviderCredentials;
use Illuminate\Http\JsonResponse;

class ProviderCredentialController extends Controller
{
    public function __construct(
        private ProviderCredentialService $credentials,
        private ProviderConnectionTester $tester,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AiProvider::class);

        return ApiResponse::success($this->credentials->status());
    }

    public function update(UpdateProviderCredentialRequest $request, string $slug): JsonResponse
    {
        $this->authorize('viewAny', AiProvider::class);
        $settings = $request->validated('settings') ?? [];
        $apiKey = $request->exists('api_key') ? $request->validated('api_key') : null;

        return ApiResponse::success($this->credentials->setKey(
            $slug,
            is_string($apiKey) ? $apiKey : null,
            is_array($settings) ? $settings : [],
            $request->user() instanceof User ? $request->user() : null,
        ));
    }

    public function destroyKey(string $slug): JsonResponse
    {
        $this->authorize('viewAny', AiProvider::class);
        if (! isset(RuntimeProviderCredentials::MAP[$slug])) {
            return ApiResponse::error('This provider cannot be managed here.', 'provider_test_unsupported', 422);
        }
        $user = request()->user();

        return ApiResponse::success($this->credentials->removeKey($slug, $user instanceof User ? $user : null));
    }

    public function test(string $slug): JsonResponse
    {
        $this->authorize('viewAny', AiProvider::class);
        if (! isset(RuntimeProviderCredentials::MAP[$slug])) {
            return ApiResponse::error('This provider cannot be tested here.', 'provider_test_unsupported', 422);
        }
        $user = request()->user();
        $result = $this->tester->test($slug, $user instanceof User ? $user : null);
        if ($result['supported'] === false) {
            return ApiResponse::error($result['message'], 'provider_test_unsupported', 422);
        }

        return ApiResponse::success([
            'slug' => $result['slug'],
            'result' => $result['result'],
            'message' => $result['message'],
            'credential' => $this->credentials->statusFor($slug),
        ]);
    }
}
