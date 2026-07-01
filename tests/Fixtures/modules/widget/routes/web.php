<?php

use Illuminate\Support\Facades\Route;

// Registered for every installed Widget module (BUG-3). Access is gated per tenant
// at request time by the `module.active` middleware (BUG-3 part C).
Route::middleware('module.active:widget')->get('/widget', fn () => 'widget-ok');
