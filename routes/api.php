<?php

use App\Http\Controllers\Api\V1\InboxEmailController;
use App\Http\Controllers\Api\V1\InboundWebhookController;
use App\Http\Controllers\Api\V1\MailServerController;
use App\Http\Controllers\Api\V1\InboxController;
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
        Route::get('inboxes', [InboxController::class, 'index'])->name('inboxes.index');
        Route::get('inboxes/{inbox}', [InboxController::class, 'show'])->whereUuid('inbox')->name('inboxes.show');
        Route::get('inboxes/{inbox}/emails', [InboxEmailController::class, 'index'])
            ->whereUuid('inbox')
            ->name('inboxes.emails.index');
        Route::get('inboxes/{inbox}/emails/{email}', [InboxEmailController::class, 'show'])
            ->whereUuid(['inbox', 'email'])
            ->name('inboxes.emails.show');
    });

    Route::middleware(['api.scope:inboxes:write', 'api.rate-limit'])->group(function (): void {
        Route::post('inboxes', [InboxController::class, 'store'])->name('inboxes.store');
        Route::delete('inboxes/{inbox}', [InboxController::class, 'destroy'])->whereUuid('inbox')->name('inboxes.destroy');
        Route::patch('inboxes/{inbox}/expiration', [InboxController::class, 'renew'])->whereUuid('inbox')->name('inboxes.expiration');
    });
});

Route::post('v1/inbound/webhook', InboundWebhookController::class)->name('api.v1.inbound.webhook');
