<?php

use App\Providers\AIServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\ProviderCredentialsServiceProvider;
use App\Providers\StoryServiceProvider;

return [
    ProviderCredentialsServiceProvider::class,
    AppServiceProvider::class,
    AuthServiceProvider::class,
    DomainServiceProvider::class,
    StoryServiceProvider::class,
    AIServiceProvider::class,
];
