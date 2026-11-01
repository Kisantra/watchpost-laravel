<?php

use Illuminate\Support\Facades\Route;
use Kisantra\Watchpost\Http\BrowserScriptController;
use Kisantra\Watchpost\Http\ClientReportController;

// Outside the web group on purpose: no session, no CSRF cookie, nothing to log in to.
Route::get('watchpost/browser.js', BrowserScriptController::class)->name('watchpost.browser');
Route::post('watchpost/client', ClientReportController::class)->middleware('throttle:30,1')->name('watchpost.client');
