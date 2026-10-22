<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use ServerSuite\Http\Controllers\StatusCardController;

// Public status cards (#29). No session, cookies or login: the card is all
// there is. Throttled per IP; the ping itself is cached per subdomain.
Route::middleware(ThrottleRequests::class . ':120,1')
    ->prefix('status')
    ->group(function () {
        Route::get('{fqdn}', [StatusCardController::class, 'show'])
            ->where('fqdn', '[A-Za-z0-9.-]{3,253}')->name('serversuite.status-card');
        Route::get('{fqdn}/badge.svg', [StatusCardController::class, 'badge'])
            ->where('fqdn', '[A-Za-z0-9.-]{3,253}')->name('serversuite.status-badge');
    });
