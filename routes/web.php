<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\MarketDataController;
use Illuminate\Support\Facades\Route;

Route::redirect('/preview', '/')->name('preview');
Route::middleware(['auth', 'staff'])->group(function () {
    Route::redirect('/security', '/');
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/market-data', MarketDataController::class)->middleware('throttle:30,1')->name('market.data');
    Route::get('/users', [AdminController::class, 'users'])->name('users.index');
    Route::get('/reviews', [AdminController::class, 'reviews'])->name('reviews.index');
    Route::get('/reviews/{user}', [AdminController::class, 'show'])->whereUuid('user')->name('reviews.show');
    Route::post('/reviews/{user}', [AdminController::class, 'decide'])->whereUuid('user')->middleware(['password.confirm', 'throttle:20,1'])->name('reviews.decide');
    Route::get('/reviews/{user}/media/{kind}', [AdminController::class, 'media'])->whereUuid('user')->whereIn('kind', ['front', 'back', 'portrait', 'selfie'])->middleware('throttle:60,1')->name('reviews.media');
    Route::get('/audit', [AdminController::class, 'auditLog'])->name('audit.index');
    Route::get('/support', [AdminController::class, 'support'])->name('support.index');
    Route::post('/support/{ticket}/resolve', [AdminController::class, 'resolve'])->whereUuid('ticket')->middleware(['password.confirm', 'throttle:20,1'])->name('support.resolve');
    Route::get('/integrations', [AdminController::class, 'integrations'])->name('integrations');
});
