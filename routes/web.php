<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::patch('documents/{source}/toggle', [DocumentController::class, 'toggleEnabled'])->name('documents.toggle');
    Route::delete('documents/{source}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('chat', [ChatController::class, 'store'])->name('chat.store');

    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
});

require __DIR__.'/settings.php';
