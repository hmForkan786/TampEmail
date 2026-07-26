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
use App\Http\Controllers\Api\V1\OutboundDraftController;
use App\Http\Controllers\Api\V1\OutboundMessageController;
use App\Http\Controllers\Api\V1\OutboundSenderProfileController;
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
        Route::get('outbound-drafts', [OutboundDraftController::class, 'index'])->name('outbound-drafts.index');
        Route::get('outbound-drafts/{draft}', [OutboundDraftController::class, 'show'])->whereUuid('draft')->name('outbound-drafts.show');
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
        Route::get('outbound-sender-profiles', [OutboundSenderProfileController::class, 'index'])
            ->name('outbound-sender-profiles.index');
        Route::get('outbound-sender-profiles/{profile}', [OutboundSenderProfileController::class, 'show'])
            ->whereUuid('profile')
            ->name('outbound-sender-profiles.show');
    });

    Route::middleware(['api.scope:outbound_messages:write', 'api.rate-limit'])->group(function (): void {
        Route::post('outbound-sender-profiles', [OutboundSenderProfileController::class, 'store'])
            ->name('outbound-sender-profiles.store');
        Route::patch('outbound-sender-profiles/{profile}', [OutboundSenderProfileController::class, 'update'])
            ->whereUuid('profile')
            ->name('outbound-sender-profiles.update');
        Route::delete('outbound-sender-profiles/{profile}', [OutboundSenderProfileController::class, 'destroy'])
            ->whereUuid('profile')
            ->name('outbound-sender-profiles.destroy');
        Route::post('outbound-sender-profiles/{profile}/default', [OutboundSenderProfileController::class, 'makeDefault'])
            ->whereUuid('profile')
            ->name('outbound-sender-profiles.default');
        Route::post('outbound-sender-profiles/{profile}/make-default', [OutboundSenderProfileController::class, 'makeDefault'])
            ->whereUuid('profile')
            ->name('outbound-sender-profiles.make-default');
        Route::post('outbound-drafts', [OutboundDraftController::class, 'store'])->name('outbound-drafts.store');
        Route::patch('outbound-drafts/{draft}', [OutboundDraftController::class, 'update'])->whereUuid('draft')->name('outbound-drafts.update');
        Route::delete('outbound-drafts/{draft}', [OutboundDraftController::class, 'destroy'])->whereUuid('draft')->name('outbound-drafts.destroy');
        Route::post('outbound-drafts/{draft}/submit', [OutboundDraftController::class, 'submit'])->whereUuid('draft')->name('outbound-drafts.submit');
        Route::post('outbound-drafts/{draft}/schedule', [OutboundDraftController::class, 'schedule'])->whereUuid('draft')->name('outbound-drafts.schedule');
        Route::post('outbound-messages', [OutboundMessageController::class, 'store'])
            ->name('outbound-messages.store');
        Route::patch('outbound-messages/{message}/schedule', [OutboundMessageController::class, 'schedule'])
            ->whereUuid('message')
            ->name('outbound-messages.schedule');
        Route::delete('outbound-messages/{message}/schedule', [OutboundMessageController::class, 'unschedule'])
            ->whereUuid('message')
            ->name('outbound-messages.unschedule');
        Route::post('outbound-messages/{message}/send-now', [OutboundMessageController::class, 'sendNow'])
            ->whereUuid('message')
            ->name('outbound-messages.send-now');
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
