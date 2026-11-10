<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\RuntimeProviderCredentials;
use Illuminate\Support\ServiceProvider;

class ProviderCredentialsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuntimeProviderCredentials::class, fn (): RuntimeProviderCredentials => new RuntimeProviderCredentials);
    }

    public function boot(): void
    {
        $credentials = $this->app->make(RuntimeProviderCredentials::class);
        $credentials->noteOrdering();
        if ($credentials->shouldApply()) {
            $credentials->apply();
        }
    }
}
