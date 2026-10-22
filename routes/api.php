<?php

use Illuminate\Support\Facades\Route;
use ServerSuite\Http\Controllers\Api\BillingApiController;

// Application API for billing (Paymenter, #38): Pterodactyl's own
// application API stack (application API keys, r_servers permission,
// throttle). {ref} is the panel server id, or external:<id> for the
// billing service id. Same paths and payloads as the Pelican plugin.
Route::middleware(['api', 'application-api', 'throttle:api.application'])
    ->prefix('api/application/serversuite')
    ->group(function () {
        Route::get('servers/{ref}', [BillingApiController::class, 'show'])->name('serversuite.api.server');
        Route::put('servers/{ref}/subdomains', [BillingApiController::class, 'subdomains'])->name('serversuite.api.subdomains');
        Route::put('servers/{ref}/backups', [BillingApiController::class, 'backups'])->name('serversuite.api.backups');
    });
