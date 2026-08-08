<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ProviderRegistryServiceInterface;
use App\DTOs\Provider\ProviderData;
use App\DTOs\Provider\ProviderSettingData;
use App\Enums\Status;
use App\Exceptions\ProviderException;
use App\Models\AiProvider;
use App\Models\ProviderSetting;
use App\Models\User;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Manages the AI provider registry — resolution (read model) plus registration,
 * updates and configuration settings.
 *
 * Registry/metadata only: it records which providers exist and how they are
 * configured. It contains no provider client or generation code.
 */
final readonly class ProviderRegistryService implements ProviderRegistryServiceInterface
{
    public function __construct(
        private ProviderRepositoryInterface $providers,
        private ActivityLoggerInterface $activity,
    ) {}

    public function get(string $slug): AiProvider
    {
        $provider = $this->providers->findBySlug($slug);

        if ($provider === null) {
            throw ProviderException::unknown($slug);
        }

        if (! $provider->isStatus(Status::Active)) {
            throw ProviderException::inactive($slug);
        }

        return $provider;
    }

    public function has(string $slug): bool
    {
        $provider = $this->providers->findBySlug($slug);

        return $provider !== null && $provider->isStatus(Status::Active);
    }

    public function all(): Collection
    {
        return $this->providers->active();
    }

    public function forCategory(string $category): Collection
    {
        return $this->providers->activeByCategory($category);
    }

    public function default(string $category): ?AiProvider
    {
        return $this->providers->activeByCategory($category)->first();
    }

    public function register(ProviderData $data, ?User $causer = null): AiProvider
    {
        if ($this->providers->findBySlug($data->slug) !== null) {
            throw new ProviderException(
                message: "A provider with slug [{$data->slug}] already exists.",
                errorCode: 'provider_slug_taken',
                statusCode: 409,
                context: ['slug' => $data->slug],
            );
        }

        $provider = $this->providers->create($data->toArray());

        $this->activity->log('provider.registered', $provider, $causer, 'AI provider registered');

        return $provider;
    }

    public function update(AiProvider $provider, ProviderData $data, ?User $causer = null): AiProvider
    {
        $provider = $this->providers->update($provider, $data->toArray());

        $this->activity->log('provider.updated', $provider, $causer, 'AI provider updated');

        return $provider;
    }

    public function activate(AiProvider $provider, ?User $causer = null): AiProvider
    {
        return $this->update($provider, ProviderData::fromArray(array_merge($provider->toArray(), ['status' => Status::Active->value])), $causer);
    }

    public function deactivate(AiProvider $provider, ?User $causer = null): AiProvider
    {
        return $this->update($provider, ProviderData::fromArray(array_merge($provider->toArray(), ['status' => Status::Inactive->value])), $causer);
    }

    public function markTested(AiProvider $provider, ?User $causer = null): AiProvider
    {
        $metadata = array_merge((array) ($provider->metadata ?? []), ['last_tested_at' => now()->toIso8601String()]);

        return $this->update($provider, ProviderData::fromArray(array_merge($provider->toArray(), ['metadata' => $metadata])), $causer);
    }

    public function setSetting(ProviderSettingData $data, ?User $causer = null): ProviderSetting
    {
        $setting = $this->providers->updateOrCreateSetting($data);

        $this->activity->log('provider.setting_updated', $setting, $causer, "Setting [{$data->key}] updated");

        return $setting;
    }

    public function delete(AiProvider $provider, ?User $causer = null): bool
    {
        $deleted = $this->providers->delete($provider);

        if ($deleted) {
            $this->activity->log('provider.deleted', $provider, $causer, 'AI provider deleted');
        }

        return $deleted;
    }
}
