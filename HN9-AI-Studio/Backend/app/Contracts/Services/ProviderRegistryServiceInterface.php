<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Contracts\Providers\ProviderRegistryInterface;
use App\DTOs\Provider\ProviderData;
use App\DTOs\Provider\ProviderSettingData;
use App\Models\AiProvider;
use App\Models\ProviderSetting;
use App\Models\User;

/**
 * Manages the AI provider registry — registration, updates and configuration
 * settings — on top of the read-model {@see ProviderRegistryInterface}.
 *
 * Registry/metadata only: it stores which providers exist and how they are
 * configured. It contains no provider client/integration code.
 */
interface ProviderRegistryServiceInterface extends ProviderRegistryInterface
{
    public function register(ProviderData $data, ?User $causer = null): AiProvider;

    public function update(AiProvider $provider, ProviderData $data, ?User $causer = null): AiProvider;

    public function activate(AiProvider $provider, ?User $causer = null): AiProvider;

    public function deactivate(AiProvider $provider, ?User $causer = null): AiProvider;

    public function markTested(AiProvider $provider, ?User $causer = null): AiProvider;

    /**
     * Create or update a single configuration setting for a provider.
     */
    public function setSetting(ProviderSettingData $data, ?User $causer = null): ProviderSetting;

    public function delete(AiProvider $provider, ?User $causer = null): bool;
}
