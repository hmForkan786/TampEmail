<?php

use App\Http\Controllers\Api\V1\AttachmentDownloadController;
use App\Http\Controllers\Api\V1\BillingCheckoutController;
use App\Http\Controllers\Api\V1\CommercialUsageController;
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
use App\Http\Controllers\Api\V1\OutboundNotificationController;
use App\Http\Controllers\Api\V1\OutboundNotificationPreferenceController;
use App\Http\Controllers\Api\V1\OutboundSenderProfileController;
use App\Http\Controllers\Api\V1\OutboundUsageController;
use App\Http\Controllers\Api\V1\OutboundWebhookController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware(['api.request-log', 'api.key'])->group(function (): void {
    Route::middleware('throttle:billing-checkout')->group(function (): void {
        Route::post('billing/checkout', [BillingCheckoutController::class, 'store'])->name('billing.checkout.store');
        Route::get('billing/orders/{billingOrder}', [BillingCheckoutController::class, 'show'])->whereUuid('billingOrder')->name('billing.orders.show');
        Route::post('billing/orders/{billingOrder}/resume', [BillingCheckoutController::class, 'resume'])->whereUuid('billingOrder')->name('billing.orders.resume');
        Route::post('billing/orders/{billingOrder}/cancel', [BillingCheckoutController::class, 'cancel'])->whereUuid('billingOrder')->name('billing.orders.cancel');
    });
    Route::middleware(['api.scope:mail_servers:read', 'api.entitlement:api.read', 'api.rate-limit'])->group(function (): void {
        Route::get('mail-servers', [MailServerController::class, 'index'])->name('mail-servers.index');
        Route::get('mail-servers/{mailServer}', [MailServerController::class, 'show'])->name('mail-servers.show');
    });

    Route::middleware(['api.scope:mail_servers:write', 'api.entitlement:api.write', 'api.rate-limit'])->group(function (): void {
        Route::post('mail-servers', [MailServerController::class, 'store'])->name('mail-servers.store');
        Route::match(['put', 'patch'], 'mail-servers/{mailServer}', [MailServerController::class, 'update'])->name('mail-servers.update');
    });

    Route::middleware(['api.scope:inboxes:read', 'api.entitlement:api.read', 'api.rate-limit'])->group(function (): void {
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

    Route::middleware(['api.scope:inboxes:write', 'api.entitlement:api.write', 'api.rate-limit'])->group(function (): void {
        Route::post('inboxes', [InboxController::class, 'store'])->name('inboxes.store');
        Route::delete('inboxes/{inbox}', [InboxController::class, 'destroy'])->whereUuid('inbox')->name('inboxes.destroy');
        Route::patch('inboxes/{inbox}/expiration', [InboxController::class, 'renew'])->whereUuid('inbox')->name('inboxes.expiration');
    });

    Route::middleware(['api.scope:inboxes:write', 'api.entitlement:api.write', 'api.rate-limit'])->group(function (): void {
        Route::patch('inboxes/{inbox}/emails/{email}/read', [EmailReadStateController::class, 'read'])
            ->whereUuid(['inbox', 'email'])
            ->name('inboxes.emails.read');
        Route::patch('inboxes/{inbox}/emails/{email}/unread', [EmailReadStateController::class, 'unread'])
            ->whereUuid(['inbox', 'email'])
            ->name('inboxes.emails.unread');
    });

    Route::middleware(['api.scope:outbound_messages:read', 'api.entitlement:api.read', 'api.rate-limit'])->group(function (): void {
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
        Route::get('commercial/usage', [CommercialUsageController::class, 'show'])
            ->name('commercial.usage.show');
        Route::get('outbound-notification-preferences', [OutboundNotificationPreferenceController::class, 'show']);
        Route::get('outbound-notifications', [OutboundNotificationController::class, 'index']);
        Route::get('outbound-notifications/unread-count', [OutboundNotificationController::class, 'count']);
        Route::get('outbound-notifications/{notification}', [OutboundNotificationController::class, 'show'])->whereUuid('notification');
        Route::get('outbound-sender-profiles', [OutboundSenderProfileController::class, 'index'])
            ->name('outbound-sender-profiles.index');
        Route::get('outbound-sender-profiles/{profile}', [OutboundSenderProfileController::class, 'show'])
            ->whereUuid('profile')
            ->name('outbound-sender-profiles.show');
    });

    Route::middleware(['api.scope:outbound_messages:write', 'api.entitlement:api.write', 'api.rate-limit'])->group(function (): void {
        Route::patch('outbound-notification-preferences', [OutboundNotificationPreferenceController::class, 'update']);
        Route::post('outbound-notifications/read-all', [OutboundNotificationController::class, 'readAll']);
        Route::post('outbound-notifications/{notification}/read', [OutboundNotificationController::class, 'read'])->whereUuid('notification');
        Route::delete('outbound-notifications/{notification}', [OutboundNotificationController::class, 'destroy'])->whereUuid('notification');
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

    Route::middleware(['api.scope:outbound_messages:read', 'api.entitlement:api.read', 'api.rate-limit'])->group(function (): void {
        Route::get('webhooks', [WebhookEndpointController::class, 'index'])->name('webhooks.index');
        Route::get('webhooks/{webhook}', [WebhookEndpointController::class, 'show'])->whereUuid('webhook')->name('webhooks.show');
    });
    Route::middleware(['api.scope:outbound_messages:read', 'api.entitlement:api.read', 'api.entitlement:webhook.access', 'api.rate-limit'])->group(function (): void {
        Route::get('webhooks/{webhook}/deliveries', [WebhookEndpointController::class, 'deliveries'])->whereUuid('webhook')->name('webhooks.deliveries.index');
        Route::get('webhooks/{webhook}/deliveries/{delivery}', [WebhookEndpointController::class, 'showDelivery'])->whereUuid(['webhook', 'delivery'])->name('webhooks.deliveries.show');
    });
    Route::middleware(['api.scope:outbound_messages:write', 'api.entitlement:api.read', 'api.entitlement:api.write', 'api.entitlement:webhook.access', 'api.rate-limit'])->group(function (): void {
        Route::post('webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
        Route::patch('webhooks/{webhook}', [WebhookEndpointController::class, 'update'])->whereUuid('webhook')->name('webhooks.update');
        Route::delete('webhooks/{webhook}', [WebhookEndpointController::class, 'destroy'])->whereUuid('webhook')->name('webhooks.destroy');
        Route::post('webhooks/{webhook}/enable', [WebhookEndpointController::class, 'enable'])->whereUuid('webhook')->name('webhooks.enable');
        Route::post('webhooks/{webhook}/disable', [WebhookEndpointController::class, 'disable'])->whereUuid('webhook')->name('webhooks.disable');
        Route::post('webhooks/{webhook}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])->whereUuid('webhook')->name('webhooks.rotate-secret');
    });
});

Route::post('v1/inbound/webhook', InboundWebhookController::class)->name('api.v1.inbound.webhook');
Route::post('v1/webhooks/outbound/{provider}', OutboundWebhookController::class)
    ->name('api.v1.webhooks.outbound');
