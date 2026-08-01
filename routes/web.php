<?php

use App\Http\Controllers\Web\AccountClosureController;
use App\Http\Controllers\Web\AccountRecoveryController;
use App\Http\Controllers\Web\AccountSecurityController;
use App\Http\Controllers\Web\AccountSessionController;
use App\Http\Controllers\Web\AffiliateReferralController;
use App\Http\Controllers\Web\AuthenticatedSessionController;
use App\Http\Controllers\Web\BillingReturnController;
use App\Http\Controllers\Web\EmailVerificationController;
use App\Http\Controllers\Web\MailboxController;
use App\Http\Controllers\Web\ManualCryptoInstructionController;
use App\Http\Controllers\Web\NewPasswordController;
use App\Http\Controllers\Web\OutboundAttachmentDownloadController;
use App\Http\Controllers\Web\OutboundDraftController;
use App\Http\Controllers\Web\OutboundMessageController;
use App\Http\Controllers\Web\OutboundNotificationController;
use App\Http\Controllers\Web\OutboundSenderProfileController;
use App\Http\Controllers\Web\PasswordResetLinkController;
use App\Http\Controllers\Web\PendingEmailVerificationController;
use App\Http\Controllers\Web\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('billing/return/{provider}', BillingReturnController::class)
    ->middleware(['signed', 'throttle:billing-return'])
    ->name('billing.return');
Route::get('billing/manual-crypto/{snapshot}', ManualCryptoInstructionController::class)
    ->middleware(['signed', 'throttle:billing-return'])
    ->whereUuid('snapshot')
    ->name('billing.manual-crypto.instructions');

Route::get('r/{affiliateCode}', AffiliateReferralController::class)
    ->middleware('throttle:60,1')
    ->where('affiliateCode', '[A-Za-z0-9]{4,32}')
    ->name('affiliate.referral');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:registration')
        ->name('register.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.store');

    Route::get('account/recovery', [AccountRecoveryController::class, 'create'])->name('account.recovery');
    Route::post('account/recovery', [AccountRecoveryController::class, 'store'])
        ->middleware('throttle:recovery')
        ->name('account.recovery.store');
});

Route::get('account/pending-email/verify/{id}/{hash}', PendingEmailVerificationController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('account.pending-email.verify');

Route::middleware(['auth', 'web.active'])->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification-resend')
        ->name('verification.send');

    Route::get('account/security', [AccountSecurityController::class, 'show'])->name('account.security');
    Route::get('account/sessions', [AccountSessionController::class, 'index'])->name('account.sessions');
    Route::delete('account/sessions/{sessionId}', [AccountSessionController::class, 'destroy'])->name('account.sessions.destroy');
    Route::delete('account/sessions', [AccountSessionController::class, 'destroyOthers'])->name('account.sessions.destroy-others');
    Route::get('account/close', [AccountClosureController::class, 'create'])->name('account.close');
    Route::post('account/close', [AccountClosureController::class, 'store'])->name('account.close.store');
});

Route::middleware(['auth', 'web.active', 'identity.verified'])->group(function (): void {
    Route::get('inbox', [MailboxController::class, 'index'])->name('mailbox.index');

    Route::get('outbound-messages', [OutboundMessageController::class, 'index'])
        ->name('outbound-messages.index');
    Route::get('outbound-notifications', [OutboundNotificationController::class, 'index'])->name('outbound-notifications.index');
    Route::post('outbound-notifications/read-all', [OutboundNotificationController::class, 'readAll'])->name('outbound-notifications.read-all');
    Route::post('outbound-notifications/{notification}/read', [OutboundNotificationController::class, 'read'])->whereUuid('notification')->name('outbound-notifications.read');
    Route::delete('outbound-notifications/{notification}', [OutboundNotificationController::class, 'destroy'])->whereUuid('notification')->name('outbound-notifications.destroy');
    Route::get('outbound-notification-preferences', [OutboundNotificationController::class, 'preferences'])->name('outbound-notification-preferences.index');
    Route::get('outbound-drafts', [OutboundDraftController::class, 'index'])->name('outbound-drafts.index');
    Route::get('outbound-drafts/compose', [OutboundDraftController::class, 'compose'])->name('outbound-drafts.compose');
    Route::post('outbound-drafts', [OutboundDraftController::class, 'store'])->name('outbound-drafts.store');
    Route::get('outbound-drafts/{draft}', [OutboundDraftController::class, 'edit'])->whereUuid('draft')->name('outbound-drafts.edit');
    Route::patch('outbound-drafts/{draft}', [OutboundDraftController::class, 'update'])->whereUuid('draft')->name('outbound-drafts.update');
    Route::delete('outbound-drafts/{draft}', [OutboundDraftController::class, 'destroy'])->whereUuid('draft')->name('outbound-drafts.destroy');
    Route::post('outbound-drafts/{draft}/submit', [OutboundDraftController::class, 'submit'])->whereUuid('draft')->name('outbound-drafts.submit');
    Route::post('outbound-drafts/{draft}/schedule', [OutboundDraftController::class, 'schedule'])->whereUuid('draft')->name('outbound-drafts.schedule');
    Route::get('outbound-messages/{message}', [OutboundMessageController::class, 'show'])
        ->whereUuid('message')
        ->name('outbound-messages.show');
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
    Route::get('outbound-messages/{message}/attachments/{attachment}', OutboundAttachmentDownloadController::class)
        ->whereUuid(['message', 'attachment'])
        ->name('outbound-messages.attachments.download');
    Route::get('outbound-sender-profiles', [OutboundSenderProfileController::class, 'index'])->name('outbound-sender-profiles.index');
    Route::post('outbound-sender-profiles', [OutboundSenderProfileController::class, 'store'])->name('outbound-sender-profiles.store');
    Route::get('outbound-sender-profiles/{profile}/edit', [OutboundSenderProfileController::class, 'edit'])->whereUuid('profile')->name('outbound-sender-profiles.edit');
    Route::patch('outbound-sender-profiles/{profile}', [OutboundSenderProfileController::class, 'update'])->whereUuid('profile')->name('outbound-sender-profiles.update');
    Route::delete('outbound-sender-profiles/{profile}', [OutboundSenderProfileController::class, 'destroy'])->whereUuid('profile')->name('outbound-sender-profiles.destroy');
    Route::post('outbound-sender-profiles/{profile}/default', [OutboundSenderProfileController::class, 'makeDefault'])->whereUuid('profile')->name('outbound-sender-profiles.default');
});
