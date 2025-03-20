<?php

use Ody\Foundation\Facades\Route;
use Ody\Websocket\Facades\WebSocket;
use Ody\Websocket\Http\Controllers\ChannelAuthController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Authentication for private and presence channels
Route::post('/broadcasting/auth', [ChannelAuthController::class, 'auth']);

// On the WebSocket server
Route::post('/api/broadcast', function (ServerRequestInterface $request, ResponseInterface $response) {
    $data = $request->getParsedBody();

    // Validate request
    if (!isset($data['channel']) || !isset($data['event']) || !isset($data['data'])) {
        return $response->withStatus(400)->json(['error' => 'Missing required fields']);
    }

    // Authenticate the request (using API key or token)
//    if (!isset($data['api_key']) || $data['api_key'] !== config('websocket.api_key')) {
//        return $response->withStatus(401)->json(['error' => 'Unauthorized']);
//    }

    // Broadcast the message
    $sentCount = WebSocket::publish($data['channel'], $data['event'], $data['data']);

    return $response->json([
        'success' => true,
        'sent_to' => $sentCount
    ]);
});