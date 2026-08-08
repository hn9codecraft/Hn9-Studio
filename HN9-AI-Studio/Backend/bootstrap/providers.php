<?php

use App\Providers\AIServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\DomainServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    DomainServiceProvider::class,
    AIServiceProvider::class,
];
