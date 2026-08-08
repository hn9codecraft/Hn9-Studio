<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProviderRegistryServiceInterface;
use App\DTOs\Provider\ProviderData;
use App\DTOs\Provider\ProviderSettingData;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProviderRequest;
use App\Http\Requests\IndexProviderSettingRequest;
use App\Http\Requests\ProviderActionRequest;
use App\Http\Requests\UpdateProviderRequest;
use App\Http\Requests\UpdateProviderSettingRequest;
use App\Http\Resources\ProviderResource;
use App\Http\Resources\ProviderSettingResource;
use App\Models\AiProvider;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\ProviderSettingRepositoryInterface;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProviderController extends Controller
{
    public function __construct(
        private ProviderRegistryServiceInterface $providers,
        private ProviderRepositoryInterface $providerRepository,
        private ProviderSettingRepositoryInterface $settingRepository,
    ) {}

    public function index(IndexProviderRequest $request): JsonResponse
    {
        $this->authorize('viewAny', AiProvider::class);

        $perPage = (int) ($request->validated()['perPage'] ?? 15);
        $filters = array_filter($request->validated(), fn ($value) => $value !== null && $value !== '');

        $page = $this->providerRepository->paginate($perPage, $filters, ['settings']);

        return ApiResponse::success(ProviderResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function show(ProviderActionRequest $request, string $uuid): JsonResponse
    {
        $provider = $this->providerRepository->findByUuidOrFail($uuid, ['settings']);

        $this->authorize('view', $provider);

        return ApiResponse::success(new ProviderResource($provider));
    }

    public function update(UpdateProviderRequest $request, string $uuid): JsonResponse
    {
        $provider = $this->providerRepository->findByUuidOrFail($uuid, ['settings']);

        $this->authorize('update', $provider);

        $payload = array_merge([
            'slug' => $provider->slug,
            'name' => $provider->name,
            'category' => $provider->category,
            'status' => $provider->status,
            'base_url' => $provider->base_url,
            'priority' => $provider->priority,
            'capabilities' => $provider->capabilities ?? [],
            'metadata' => $provider->metadata ?? [],
        ], $request->validated());

        $updated = $this->providers->update($provider, ProviderData::fromArray($payload), $request->user());

        return ApiResponse::success(new ProviderResource($updated));
    }

    public function enable(ProviderActionRequest $request, string $uuid): JsonResponse
    {
        $provider = $this->providerRepository->findByUuidOrFail($uuid, ['settings']);

        $this->authorize('update', $provider);

        $updated = $this->providers->update($provider, ProviderData::fromArray($this->providerPayload($provider, [
            'status' => Status::Active->value,
        ])), $request->user());

        return ApiResponse::success(new ProviderResource($updated));
    }

    public function disable(ProviderActionRequest $request, string $uuid): JsonResponse
    {
        $provider = $this->providerRepository->findByUuidOrFail($uuid, ['settings']);

        $this->authorize('update', $provider);

        $updated = $this->providers->update($provider, ProviderData::fromArray([
            'slug' => $provider->slug,
            'name' => $provider->name,
            'category' => $provider->category,
            'status' => Status::Inactive->value,
            'base_url' => $provider->base_url,
            'priority' => $provider->priority,
            'capabilities' => $provider->capabilities ?? [],
            'metadata' => $provider->metadata ?? [],
        ]), $request->user());

        return ApiResponse::success(new ProviderResource($updated));
    }

    public function test(ProviderActionRequest $request, string $uuid): JsonResponse
    {
        $provider = $this->providerRepository->findByUuidOrFail($uuid, ['settings']);

        $this->authorize('update', $provider);

        $updated = $this->providers->markTested($provider, $request->user());

        return ApiResponse::success(new ProviderResource($updated));
    }

    public function settingsIndex(IndexProviderSettingRequest $request): JsonResponse
    {
        $this->authorize('viewAny', AiProvider::class);

        $perPage = (int) ($request->validated()['perPage'] ?? 15);
        $page = $this->settingRepository->paginate($perPage, [], ['provider']);

        return ApiResponse::success(ProviderSettingResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function showSetting(ProviderActionRequest $request, string $uuid): JsonResponse
    {
        $setting = $this->settingRepository->findByUuidOrFail($uuid, ['provider']);

        $this->authorize('view', $setting->provider);

        return ApiResponse::success(new ProviderSettingResource($setting));
    }

    public function updateSetting(UpdateProviderSettingRequest $request, string $uuid): JsonResponse
    {
        $setting = $this->settingRepository->findByUuidOrFail($uuid, ['provider']);

        $this->authorize('update', $setting->provider);

        $data = ProviderSettingData::fromArray([
            'ai_provider_id' => $setting->ai_provider_id,
            'key' => $setting->key,
            'value' => $request->input('value', $setting->value),
            'is_secret' => $request->boolean('is_secret', $setting->is_secret),
            'environment' => $request->input('environment', $setting->environment),
        ]);

        $updated = $this->providers->setSetting($data, $request->user());

        return ApiResponse::success(new ProviderSettingResource($updated));
    }

    private function providerPayload(AiProvider $provider, array $overrides = []): array
    {
        return array_merge([
            'slug' => $provider->slug,
            'name' => $provider->name,
            'category' => $provider->category,
            'status' => $provider->status,
            'base_url' => $provider->base_url,
            'priority' => $provider->priority,
            'capabilities' => $provider->capabilities ?? [],
            'metadata' => $provider->metadata ?? [],
        ], $overrides);
    }
}
