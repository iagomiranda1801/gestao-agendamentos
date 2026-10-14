<?php

use App\Http\Controllers\Api\IntegrationCustomerController;
use App\Http\Middleware\AuthenticateExternalIntegration;
use Illuminate\Support\Facades\Route;

Route::post('/v1/integrations/customers', IntegrationCustomerController::class)
    ->middleware(['throttle:external-integrations', AuthenticateExternalIntegration::class])
    ->name('api.integrations.customers.store');
