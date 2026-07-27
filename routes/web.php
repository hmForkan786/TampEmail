<?php

use App\Http\Controllers\Web\AuthenticatedSessionController;
use App\Http\Controllers\Web\MailboxController;
use App\Http\Controllers\Web\OutboundAttachmentDownloadController;
use App\Http\Controllers\Web\OutboundDraftController;
use App\Http\Controllers\Web\OutboundMessageController;
use App\Http\Controllers\Web\OutboundNotificationController;
use App\Http\Controllers\Web\OutboundSenderProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
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
