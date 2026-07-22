<?php

use App\Http\Controllers\Api\V1\InboxEmailController;
use App\Http\Controllers\Api\V1\InboundWebhookController;
use App\Http\Controllers\Api\V1\MailServerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware(['api.request-log', 'api.key'])->group(function (): void {
    Route::middleware(['api.scope:mail_servers:read', 'api.rate-limit'])->group(function (): void {
        Route::get('mail-servers', [MailServerController::class, 'index'])->name('mail-servers.index');
        Route::get('mail-servers/{mailServer}', [MailServerController::class, 'show'])->name('mail-servers.show');
    });

    Route::middleware(['api.scope:mail_servers:write', 'api.rate-limit'])->group(function (): void {
        Route::post('mail-servers', [MailServerController::class, 'store'])->name('mail-servers.store');
        Route::match(['put', 'patch'], 'mail-servers/{mailServer}', [MailServerController::class, 'update'])->name('mail-servers.update');
    });

    Route::middleware(['api.scope:inboxes:read', 'api.rate-limit'])->group(function (): void {
        Route::get('inboxes/{inbox}/emails', [InboxEmailController::class, 'index'])
            ->whereUuid('inbox')
            ->name('inboxes.emails.index');
        Route::get('inboxes/{inbox}/emails/{email}', [InboxEmailController::class, 'show'])
            ->whereUuid(['inbox', 'email'])
            ->name('inboxes.emails.show');
    });
});

Route::post('v1/inbound/webhook', InboundWebhookController::class)->name('api.v1.inbound.webhook');
