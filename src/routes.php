<?php

use Ody\Foundation\Facades\Route;
use Ody\Websocket\Http\Controllers\ChannelAuthController;

// Authentication for private and presence channels
Route::post('/broadcasting/auth', [ChannelAuthController::class, 'auth']);

// Add any other websocket-related routes here