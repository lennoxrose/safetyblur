<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Admin\Extensions\safetyblur\safetyblurExtensionController;

// Blueprint adds 'extensions/safetyblur' prefix automatically
// GET route for settings page
Route::get('/', [safetyblurExtensionController::class, 'index'])->name('admin.extensions.safetyblur.index');

// POST routes
Route::post('/verify-license', [safetyblurExtensionController::class, 'verifyLicense'])->name('admin.extensions.safetyblur.verify');
Route::post('/heartbeat', [safetyblurExtensionController::class, 'heartbeat'])->name('admin.extensions.safetyblur.heartbeat');
Route::post('/settings', [safetyblurExtensionController::class, 'saveSettings'])->name('admin.extensions.safetyblur.settings');
