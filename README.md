# WebSocket Server

A robust, feature-rich PHP WebSocket server for building real-time applications.

## Overview

Ody WebSocket is a high-performance WebSocket server implementation that provides a complete solution for building
real-time applications. Built on top of Swoole's coroutine capabilities, it offers excellent performance, scalability,
and a clean API for developers.

## Features

- **High Performance**: Leverages Swoole's coroutines for efficient, non-blocking I/O operations
- **Channel System**: Supports public, private, and presence channels for various use cases
- **Authentication**: Secure your WebSockets with token-based authentication
- **Middleware Pipeline**: Flexible middleware system for request/response processing
- **REST API Integration**: Run a REST API alongside your WebSocket server
- **Client Libraries**: JavaScript client library for browser integration
- **Comprehensive Logging**: Detailed logging of connections, messages, and errors
- **Rate Limiting**: Built-in protection against abuse and DoS attacks
- **Event-Driven Architecture**: Simple event-based programming model

## Installation

```bash
composer require ody/websocket
```

## Getting Started

### Configure Your Server

Create/edit the `config/websocket.php` file to configure your WebSocket server:

```php
return [
    'host' => env('WEBSOCKET_HOST', '127.0.0.1'),
    'port' => env('WEBSOCKET_PORT', 9502),
    'mode' => SWOOLE_PROCESS,
    'secret_key' => env('WEBSOCKET_SECRET_KEY', '123123123'),
    'sock_type' => SWOOLE_SOCK_TCP,
    'enable_api' => true,
    'callbacks' => [
        WsEvent::ON_HAND_SHAKE => [\Ody\Websocket\WsServerCallbacks::class, 'onHandShake'],
        WsEvent::ON_WORKER_START => [\Ody\Websocket\WsServerCallbacks::class, 'onWorkerStart'],
        WsEvent::ON_MESSAGE => [\Ody\Websocket\WsServerCallbacks::class, 'onMessage'],
        WsEvent::ON_CLOSE => [\Ody\Websocket\WsServerCallbacks::class, 'onClose'],
        WsEvent::ON_DISCONNECT => [\Ody\Websocket\WsServerCallbacks::class, 'onDisconnect'],
        // if enable_api is set to true, the Application class will be
        // bootstrapped and expose a REST API. This enables all normal
        // functionality of ODY framework including route middleware.
        WsEvent::ON_REQUEST => [\Ody\Websocket\WsServerCallbacks::class, 'onRequest'],
    ],

    "additional" => [
        "worker_num" => env('WEBSOCKET_WORKER_COUNT', swoole_cpu_num() * 2),
        'dispatch_mode' => 2, // Important: This ensures connections stay with their worker, does not work in SWOOLE_BASE
        /*
         * log level
         * SWOOLE_LOG_DEBUG (default)
         * SWOOLE_LOG_TRACE
         * SWOOLE_LOG_INFO
         * SWOOLE_LOG_NOTICE
         * SWOOLE_LOG_WARNING
         * SWOOLE_LOG_ERROR
         */
        'log_level' => SWOOLE_LOG_DEBUG,
        'log_file' => base_path('storage/logs/ody_websockets.log'),

        'ssl_cert_file' => null,
        'ssl_key_file' => null,
    ],

    'runtime' => [
        'enable_coroutine' => true,
        /**
         * SWOOLE_HOOK_TCP - Enable TCP hook only
         * SWOOLE_HOOK_TCP | SWOOLE_HOOK_UDP | SWOOLE_HOOK_SOCKETS - Enable TCP, UDP and socket hooks
         * SWOOLE_HOOK_ALL - Enable all runtime hooks
         * SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_FILE ^ SWOOLE_HOOK_STDIO - Enable all runtime hooks except file and stdio hooks
         * 0 - Disable runtime hooks
         */
        'hook_flag' => SWOOLE_HOOK_ALL,
    ],

    'middleware' => [
        // Common middleware applied to both pipelines
        'global' => [
            \Ody\Websocket\Middleware\LoggingMiddleware::class,
//            \Ody\Websocket\Middleware\MetricsMiddleware::class,
        ],

        // Handshake-specific middleware
        'handshake' => [
//            \Ody\Websocket\Middleware\AuthenticationMiddleware::class,
//            \Ody\Websocket\Middleware\OriginValidationMiddleware::class,
//            \Ody\Websocket\Middleware\ConnectionRateLimitMiddleware::class,
        ],

        // Message-specific middleware
        'message' => [
//            \Ody\Websocket\Middleware\MessageRateLimitMiddleware::class,
//            \Ody\Websocket\Middleware\MessageValidationMiddleware::class,
//            \Ody\Websocket\Middleware\MessageSizeLimitMiddleware::class,
        ],
    ],

    // Middleware parameters
    'middleware_params' => [
        // Authentication middleware parameters
        \Ody\Websocket\Middleware\AuthenticationMiddleware::class => [
            'header_name' => 'sec-websocket-protocol',
        ],

//        // Rate limit middleware parameters
//        \Ody\Websocket\Middleware\MessageRateLimitMiddleware::class => [
//            'messages_per_minute' => env('WEBSOCKET_RATE_LIMIT', 60),
//            'table_size' => 1024,
//        ],
//
//        // Origin validation middleware parameters
//        \Ody\Websocket\Middleware\OriginValidationMiddleware::class => [
//            'allowed_origins' => [
//                env('APP_URL', 'http://localhost'),
//                // Add additional allowed origins
//            ],
//        ],
    ],

    'rate_limits' => [
        'messages_per_minute' => env('WEBSOCKET_RATE_LIMIT', 60),
        'connections_per_minute' => env('WEBSOCKET_CONNECTION_LIMIT', 10),
    ],
];
```

Include WebsocketServiceProvider in the provider section of the config

```php
'providers' => [
    // Core providers
     
    // ... 
      
    // Package providers
    \Ody\Websocket\Providers\WebsocketServiceProvider::class,
    
    // ...
],
```

### Start the Server

```bash
php ody websocket:start
```

Add the `-d` flag to run the server in daemon mode:

```bash
php ody websocket:start -d
```

### Stop the Server

```bash
php ody websocket:stop
```

## Core Concepts

### Channels

Channels provide a way to categorize and manage WebSocket connections. There are three types of channels:

1. **Public Channels**: Open to all clients
2. **Private Channels**: Require authentication
3. **Presence Channels**: Track user presence with authentication

#### Public Channels

Public channels are open to any client and don't require authentication.

```javascript
// Client-side
const channel = wsClient.subscribe('my-channel');
```

#### Private Channels

Private channels require authentication and are prefixed with `private-`.

```javascript
// Client-side
const privateChannel = wsClient.subscribe('private-my-channel', {
    auth: 'your-auth-token'
});
```

#### Presence Channels

Presence channels track user presence and are prefixed with `presence-`.

```javascript
// Client-side
const presenceChannel = wsClient.subscribe('presence-chat', {
    auth: 'your-auth-token',
    channel_data: JSON.stringify({
        user_id: '1',
        user_info: {
            name: 'John Doe'
        }
    })
});

// Listen for presence events
presenceChannel.on('member_added', (data) => {
    console.log('User joined:', data.user_id);
});

presenceChannel.on('member_removed', (data) => {
    console.log('User left:', data.user_id);
});
```

### Authentication

The framework uses a simple token-based authentication system. For private and presence channels, you'll need to
implement an authentication endpoint:

```php
// Server-side
Route::post('/broadcasting/auth', [ChannelAuthController::class, 'auth']);
```

Clients must include the authentication token when subscribing to private or presence channels.

### Publishing Messages

#### From the Server

Use the `WebSocket` facade to publish messages from your PHP application:

```php
use Ody\Websocket\Facades\WebSocket;

// Publish to a channel
WebSocket::publish('my-channel', 'event-name', [
    'message' => 'Hello from the server!'
]);

// Publish to multiple channels
WebSocket::publishToChannels(['channel1', 'channel2'], 'event-name', [
    'message' => 'Broadcast to multiple channels'
]);

// Send to a specific client
WebSocket::whisper($fd, 'event-name', [
    'message' => 'Private message'
]);
```

#### From the Client

Clients can publish messages to channels they're subscribed to:

```javascript
// Public channel
channel.publish('event-name', {
    message: 'Hello everyone!'
});

// Private/presence channels (client events must be prefixed with 'client-')
privateChannel.publish('client-typing', {
    user: 'John Doe'
});
```

### Event Handling

Listen for events on channels:

```javascript
// Listen for a specific event
channel.on('message', (data) => {
    console.log('New message:', data.message);
});

// Global event handling
wsClient.on('connected', () => {
    console.log('Connected to WebSocket server');
});

wsClient.on('error', (error) => {
    console.error('WebSocket error:', error);
});
```

## Middleware System

The middleware system provides a way to process WebSocket connections and messages. Middleware components are executed
in a pipeline, allowing you to implement cross-cutting concerns like authentication, logging, and rate limiting.

### Built-in Middleware

- **LoggingMiddleware**: Logs connection and message events
- **AuthenticationMiddleware**: Handles WebSocket authentication
- **RateLimitingMiddleware**: Protects against abuse

### Custom Middleware

Create custom middleware by implementing the `WebSocketMiddlewareInterface`:

```php
namespace App\Websocket\Middleware;

use Ody\Websocket\Middleware\WebSocketMiddlewareInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class CustomMiddleware implements WebSocketMiddlewareInterface
{
    public function processHandshake(Request $request, Response $response, callable $next): bool
    {
        // Process handshake request
        return $next($request, $response);
    }
    
    public function processMessage(Server $server, Frame $frame, callable $next)
    {
        // Process message frame
        return $next($server, $frame);
    }
}
```

Then add your middleware to the configuration:

```php
// config/websocket.php
'middleware' => [
    'global' => [
        // ...
        \App\Websocket\Middleware\CustomMiddleware::class,
    ],
],
```

## JavaScript Client

### Installation

```html

<script src="path/to/OdyWebSocketClient.js"></script>
```

### Basic Usage

```javascript
// Create a client instance
const wsClient = new OdyWebSocketClient('ws://localhost:9502', 'your-secret-key', {
    debug: true,
    reconnectAttempts: 5
});

// Connect to the server
wsClient.connect().then(() => {
    console.log('Connected!');

    // Subscribe to a channel
    const channel = wsClient.subscribe('my-channel');

    // Listen for events
    channel.on('message', (data) => {
        console.log('Received message:', data);
    });

    // Publish an event
    channel.publish('message', {
        text: 'Hello world!'
    });
});

// Global event handlers
wsClient.on('error', (error) => {
    console.error('WebSocket error:', error);
});

wsClient.on('disconnected', () => {
    console.log('Disconnected from server');
});
```

## API Reference

### Server-Side

#### WebSocket Facade

- `publish(string $channel, string $event, array $data): int`
- `publishToChannels(array $channels, string $event, array $data): int`
- `whisper(int $fd, string $event, array $data, ?string $channel = null): bool`
- `getSubscribers(string $channel): array`
- `channelExists(string $channel): bool`
- `isSubscribed(int $fd, string $channel): bool`
- `getChannels(): array`

#### ChannelAuthGenerator

- `generatePrivateAuth(string $socketId, string $channel): string`
- `generatePresenceAuth(string $socketId, string $channel, array $userData): string`
- `validatePrivateAuth(string $socketId, string $channel, string $authSignature): bool`
- `validatePresenceAuth(string $socketId, string $channel, string $authSignature)`

### Client-Side

#### OdyWebSocketClient

- `connect(): Promise`
- `disconnect(): void`
- `subscribe(string channelName, object data = {}): Channel`
- `unsubscribe(string channelName): void`
- `publish(string channelName, string event, object data = {}): void`
- `on(string event, Function callback): OdyWebSocketClient`
- `off(string event, Function callback = null): OdyWebSocketClient`
- `getSocketId(): string|null`
- `isConnected(): boolean`

#### Channel

- `on(string event, Function callback): Channel`
- `off(string event, Function callback = null): Channel`
- `publish(string event, object data = {}): Channel`
- `unsubscribe(): void`

## Advanced Configuration

### SSL Support

Configure SSL for secure WebSocket connections:

```php
// config/websocket.php

//...
'sock_type' => SWOOLE_SOCK_TCP | SWOOLE_SSL,
//...
'aditional' => [
    // ...
    'ssl_cert_file' => '/path/to/cert.pem',
    'ssl_key_file' => '/path/to/key.pem',
    // ...
],
```

### Worker Configuration

Adjust the number of worker processes:

```php
// config/websocket.php
'additional' => [
    'worker_num' => env('WEBSOCKET_WORKER_COUNT', swoole_cpu_num() * 2),
],
```

### Logging

Configure logging levels:

```php
// config/websocket.php
'additional' => [
    'log_level' => SWOOLE_LOG_DEBUG,
    'log_file' => base_path('storage/logs/ody_websockets.log'),
],
```

### Rate Limiting

Set rate limits for connections and messages:

```php
// config/websocket.php
'rate_limits' => [
    'messages_per_minute' => env('WEBSOCKET_RATE_LIMIT', 60),
    'connections_per_minute' => env('WEBSOCKET_CONNECTION_LIMIT', 10),
],
```

## Examples

### Chat Application

```javascript
// Connect to the WebSocket server
const wsClient = new OdyWebSocketClient('ws://localhost:9502', 'your-secret-key');

// Subscribe to the chat channel
const chatChannel = wsClient.subscribe('presence-chat', {
    auth: authToken,
    channel_data: JSON.stringify({
        user_id: userId,
        user_info: {
            name: username
        }
    })
});

// Listen for new messages
chatChannel.on('message', (data) => {
    addMessageToChat(data.user, data.message);
});

// Listen for typing indicators
chatChannel.on('client-typing', (data) => {
    showTypingIndicator(data.user);
});

// Send a message
function sendMessage(message) {
    chatChannel.publish('message', {
        user: username,
        message: message
    });
}

// Send typing indicator
function sendTypingIndicator() {
    chatChannel.publish('client-typing', {
        user: username
    });
}
```

### Server-Side Broadcasting

```php
// In a controller
public function sendNotification($userId, $message)
{
    // Find the user's channel
    $userChannel = 'private-user-' . $userId;
    
    // Send the notification
    WebSocket::publish($userChannel, 'notification', [
        'message' => $message,
        'timestamp' => now()->toIso8601String()
    ]);
    
    return response()->json(['success' => true]);
}
```

## Troubleshooting

### Common Issues

1. **Connection Refused**
    - Check if the WebSocket server is running
    - Verify the host and port configuration

2. **Authentication Failed**
    - Ensure the secret key matches in both client and server
    - Check that the authentication token is valid

3. **Channel Subscription Failed**
    - Verify that you're using the correct channel name and prefix
    - Check authentication for private and presence channels

4. **High Memory Usage**
    - Adjust the worker_num setting in configuration
    - Check for memory leaks in custom middleware or handlers

### Debugging

Enable debug mode in the client:

```javascript
const wsClient = new OdyWebSocketClient('ws://localhost:9502', 'your-secret-key', {
    debug: true
});
```

Set the server log level to DEBUG:

```php
// config/websocket.php
'additional' => [
    'log_level' => SWOOLE_LOG_DEBUG,
],
```