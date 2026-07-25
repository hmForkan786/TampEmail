<?php

use App\Http\Controllers\Api\V1\AttachmentDownloadController;
use App\Http\Controllers\Api\V1\EmailForwardController;
use App\Http\Controllers\Api\V1\EmailReadStateController;
use App\Http\Controllers\Api\V1\EmailReplyController;
use App\Http\Controllers\Api\V1\InboundWebhookController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Controllers\Api\V1\InboxEmailController;
use App\Http\Controllers\Api\V1\MailServerController;
use App\Http\Controllers\Api\V1\OutboundAttachmentDownloadController;
use App\Http\Controllers\Api\V1\OutboundMessageController;
use App\Http\Controllers\Api\V1\OutboundUsageController;
use App\Http\Controllers\Api\V1\OutboundWebhookController;
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
        Route::get('inboxes/{inbox}/emails/{email}/attachments/{attachment}', AttachmentDownloadController::class)
            ->whereUuid(['inbox', 'email', 'attachment'])
            ->name('inboxes.emails.attachments.download');
    });

    Route::middleware(['api.scope:inboxes:write', 'api.rate-limit'])->group(function (): void {
        Route::post('inboxes', [InboxController::class, 'store'])->name('inboxes.store');
        Route::delete('inboxes/{inbox}', [InboxController::class, 'destroy'])->whereUuid('inbox')->name('inboxes.destroy');
        Route::patch('inboxes/{inbox}/expiration', [InboxController::class, 'renew'])->whereUuid('inbox')->name('inboxes.expiration');
    });

    Route::middleware(['api.scope:inboxes:write', 'api.rate-limit'])->group(function (): void {
        Route::patch('inboxes/{inbox}/emails/{email}/read', [EmailReadStateController::class, 'read'])
            ->whereUuid(['inbox', 'email'])
            ->name('inboxes.emails.read');
        Route::patch('inboxes/{inbox}/emails/{email}/unread', [EmailReadStateController::class, 'unread'])
            ->whereUuid(['inbox', 'email'])
            ->name('inboxes.emails.unread');
    });

    Route::middleware(['api.scope:outbound_messages:read', 'api.rate-limit'])->group(function (): void {
        Route::get('outbound-messages', [OutboundMessageController::class, 'index'])
            ->name('outbound-messages.index');
        Route::get('outbound-messages/{message}', [OutboundMessageController::class, 'show'])
            ->whereUuid('message')
            ->name('outbound-messages.show');
        Route::get('outbound-messages/{message}/timeline', [OutboundMessageController::class, 'timeline'])
            ->whereUuid('message')
            ->name('outbound-messages.timeline');
        Route::get('outbound-messages/{message}/attachments/{attachment}', OutboundAttachmentDownloadController::class)
            ->whereUuid(['message', 'attachment'])
            ->name('outbound-messages.attachments.download');
        Route::get('outbound-usage', [OutboundUsageController::class, 'show'])
            ->name('outbound-usage.show');
    });

    Route::middleware(['api.scope:outbound_messages:write', 'api.rate-limit'])->group(function (): void {
        Route::post('outbound-messages', [OutboundMessageController::class, 'store'])
            ->name('outbound-messages.store');
        Route::post('outbound-messages/{message}/cancel', [OutboundMessageController::class, 'cancel'])
            ->whereUuid('message')
            ->name('outbound-messages.cancel');
        Route::post('outbound-messages/{message}/retry', [OutboundMessageController::class, 'retry'])
            ->whereUuid('message')
            ->name('outbound-messages.retry');
        Route::delete('outbound-messages/{message}', [OutboundMessageController::class, 'destroy'])
            ->whereUuid('message')
            ->name('outbound-messages.destroy');
        Route::post('emails/{email}/reply', [EmailReplyController::class, 'store'])
            ->whereUuid('email')
            ->name('emails.reply');
        Route::post('emails/{email}/forward', [EmailForwardController::class, 'store'])
            ->whereUuid('email')
            ->name('emails.forward');
    });
});

Route::post('v1/inbound/webhook', InboundWebhookController::class)->name('api.v1.inbound.webhook');
Route::post('v1/webhooks/outbound/{provider}', OutboundWebhookController::class)
    ->name('api.v1.webhooks.outbound');
