<?php

use App\Http\Controllers\Api\V1\MailServerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware('api.key')->group(function (): void {
    Route::middleware('api.scope:mail_servers:read')->group(function (): void {
        Route::get('mail-servers', [MailServerController::class, 'index'])->name('mail-servers.index');
        Route::get('mail-servers/{mailServer}', [MailServerController::class, 'show'])->name('mail-servers.show');
    });

    Route::middleware('api.scope:mail_servers:write')->group(function (): void {
        Route::post('mail-servers', [MailServerController::class, 'store'])->name('mail-servers.store');
        Route::match(['put', 'patch'], 'mail-servers/{mailServer}', [MailServerController::class, 'update'])->name('mail-servers.update');
    });
});
