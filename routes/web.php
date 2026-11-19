<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\FactController;
use App\Http\Controllers\IdeaController;
use App\Http\Controllers\NameController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StateController;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1');
});

Route::middleware(['auth', EnsurePasswordChanged::class])->group(function () {
    Route::redirect('/', '/office');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('office', [OfficeController::class, 'show'])->name('office');
    Route::get('office/state', [StateController::class, 'show'])->name('office.state');
    Route::post('office/chat', [ChatController::class, 'store'])->middleware('throttle:30,1')->name('office.chat');

    Route::get('facts', [FactController::class, 'index'])->name('facts');
    Route::get('questions', [QuestionController::class, 'index'])->name('questions');
    Route::get('it', [IdeaController::class, 'index'])->name('ideas');

    Route::middleware('can:owner')->group(function () {
        Route::post('office/names', [NameController::class, 'store'])->name('names.store');
        Route::post('facts', [FactController::class, 'store'])->name('facts.store');
        Route::post('questions/{question}/answer', [QuestionController::class, 'answer'])->name('questions.answer');
        Route::post('it/{idea}', [IdeaController::class, 'decide'])->name('ideas.decide');
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
