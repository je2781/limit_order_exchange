<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;   


// Route::inertia('/', 'Welcome', [
//     'canRegister' => Features::enabled(Features::registration()),
// ])->name('home');

// Route::middleware(['auth', 'verified'])->group(function () {
//     Route::inertia('dashboard', 'Dashboard')->name('dashboard');
// });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/api/profile', [ProfileController::class, 'show']);
    Route::get('/api/orders', [OrderController::class, 'index']);
    Route::post('/api/orders', [OrderController::class, 'store']);
    Route::post('/api/orders/{id}/cancel', [OrderController::class, 'cancel']);
});


Route::post('/api/logout', function () {
    Auth::logout();

    return redirect('/login');
})->middleware('auth:sanctum')->name('logout');

Route::middleware(['auth:sanctum'])->get('/', fn() => inertia('Wallet'))->name('wallet');


require __DIR__.'/settings.php';
