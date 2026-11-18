<?php

use App\Providers\ApiContractServiceProvider;
use App\Providers\AppServiceProvider;
use VentureDrake\LaravelCrm\LaravelCrmServiceProvider;

return [
    ApiContractServiceProvider::class,
    AppServiceProvider::class,
    LaravelCrmServiceProvider::class,
];
