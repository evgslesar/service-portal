<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\YooKassaWebhookController;
use App\Http\Middleware\ManageUsers;
use App\Livewire\Accounts;
use App\Livewire\Billing;
use App\Livewire\CreateRequest;
use App\Livewire\DeliveryWorkbench;
use App\Livewire\Inbox;
use App\Livewire\Job;
use App\Livewire\RequestDetail;
use App\Livewire\Stock;
use App\Livewire\Workspace;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/yookassa/webhook', YooKassaWebhookController::class)->middleware('throttle:60,1')->name('yookassa.webhook');

Route::middleware('guest')->group(function () {
    Route::get('/invitations/{invitation}', [InvitationController::class, 'show'])->middleware(['signed', 'throttle:invitation'])->name('invitation.show');
    Route::post('/invitations/{invitation}', [InvitationController::class, 'accept'])->middleware(['signed', 'throttle:invitation'])->name('invitation.accept');
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::view('/forgot-password', 'auth.forgot')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:login')->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset', ['token' => $token]))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:login')->name('password.update');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/two-factor', [AuthController::class, 'mfa'])->name('mfa');
    Route::post('/two-factor', [AuthController::class, 'confirmMfa'])->middleware('throttle:sensitive');
    Route::post('/two-factor/disable', [AuthController::class, 'disableMfa'])->middleware(['mfa', 'throttle:sensitive'])->name('mfa.disable');
    Route::middleware('mfa')->group(function () {
        Route::get('/', Workspace::class)->name('home');
        Route::get('/requests/new', CreateRequest::class)->name('requests.create');
        Route::get('/requests/{id}', RequestDetail::class)->whereNumber('id')->name('requests.show');
        Route::get('/requests/{id}/billing', Billing::class)->whereNumber('id')->name('requests.billing');
        Route::get('/requests/{id}/documents/results/{result}', [DocumentController::class, 'result'])->whereNumber(['id', 'result'])->name('documents.result');
        Route::get('/requests/{id}/documents/estimates/{estimate}', [DocumentController::class, 'estimate'])->whereNumber(['id', 'estimate'])->name('documents.estimate');
        Route::get('/requests/{id}/documents/invoices/{invoice}', [DocumentController::class, 'invoice'])->whereNumber(['id', 'invoice'])->name('documents.invoice');
        Route::get('/jobs/{id}', Job::class)->whereNumber('id')->name('jobs.show');
        Route::get('/photos/{attachment}', [PhotoController::class, 'show'])->name('photos.show');
        Route::get('/notifications', Inbox::class)->name('notifications');
        Route::redirect('/notification-preferences', '/delivery')->name('notification-preferences');
        Route::get('/delivery', DeliveryWorkbench::class)->name('delivery');
        Route::get('/accounts', Accounts::class)->middleware(ManageUsers::class)->name('accounts');
        Route::get('/stock', Stock::class)->name('stock');
        Route::view('/help', 'help')->name('help');
    });
});
