<?php

use App\Http\Controllers\Web\AuthenticatedSessionController;
use App\Http\Controllers\Web\OutboundAttachmentDownloadController;
use App\Http\Controllers\Web\OutboundMessageController;
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

    Route::get('outbound-messages', [OutboundMessageController::class, 'index'])
        ->name('outbound-messages.index');
    Route::get('outbound-messages/{message}', [OutboundMessageController::class, 'show'])
        ->whereUuid('message')
        ->name('outbound-messages.show');
    Route::post('outbound-messages/{message}/cancel', [OutboundMessageController::class, 'cancel'])
        ->whereUuid('message')
        ->name('outbound-messages.cancel');
    Route::post('outbound-messages/{message}/retry', [OutboundMessageController::class, 'retry'])
        ->whereUuid('message')
        ->name('outbound-messages.retry');
    Route::get('outbound-messages/{message}/attachments/{attachment}', OutboundAttachmentDownloadController::class)
        ->whereUuid(['message', 'attachment'])
        ->name('outbound-messages.attachments.download');
});
