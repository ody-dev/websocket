WIP

## Overview

The Ody WebSocket Channel System is a real-time communication framework built on top of Swoole's WebSocket server capabilities. It provides a pub/sub channel system similar to Pusher, allowing easy implementation of real-time features in your application.

## Key Features

- **Channel-based communication**: Subscribe to named channels for organized communication
- **Multiple channel types**: Public, private, and presence channels with different security levels
- **Authentication system**: Secure authentication for private and presence channels
- **Client presence tracking**: Monitor users' online status in presence channels
- **Server-side broadcasting**: Push messages to channels from anywhere in your application
- **Client event publishing**: Allow clients to publish events to channels they're subscribed to
- **WebSocket facade**: Simple interface for sending messages from any part of your application

## Architecture

The system consists of several components:

### Core Components

1. **ChannelManager**: Central registry for WebSocket channels that handles subscriptions, broadcasts, and message routing
2. **ChannelHandlers**: Type-specific handlers for different channel types (public, private, presence)
3. **ChannelClient**: Server-side client for publishing to channels
4. **ChannelAuthGenerator**: Generates authentication signatures for secure channels

### Server Integration

- **WsServerCallbacks**: Entry point for WebSocket events, integrated with the channel system
- **WebsocketChannelServiceProvider**: Registers the channel system with the framework's service container
- **WebSocket Facade**: Provides a convenient interface for using the channel system

## Channel Types

### Public Channels

Public channels are open to all clients. No authentication is required to subscribe.

```javascript
// Client-side
const channel = wsClient.subscribe('my-channel');
```

### Private Channels

Private channels require authentication. Clients must provide a valid auth token to subscribe.

```javascript
// Client-side
const privateChannel = wsClient.subscribe('private-my-channel', {
    auth: 'auth-token-from-server'
});
```

### Presence Channels

Presence channels track user presence. They require authentication and user identification.

```javascript
// Client-side
const presenceChannel = wsClient.subscribe('presence-my-channel', {
    auth: 'auth-token-from-server',
    channel_data: JSON.stringify({
        user_id: '123',
        user_info: { name: 'John Doe' }
    })
});
```

## Usage Examples

### Server-side Broadcasting

```php
use Ody\Websocket\Facades\WebSocket;

// Broadcast to a public channel
WebSocket::publish('my-channel', 'new-message', [
    'text' => 'Hello world!',
    'user' => 'System',
    'timestamp' => time()
]);

// Get subscribers for a channel
$subscribers = WebSocket::getSubscribers('my-channel');

// Check if a client is subscribed
if (WebSocket::isSubscribed($clientId, 'my-channel')) {
    // Client is subscribed
}
```

### Client-side Subscription

```javascript
// Initialize the client
const wsClient = new OdyWebSocketClient('ws://example.com:9502', 'api-key');

// Subscribe to a channel
const channel = wsClient.subscribe('my-channel');

// Listen for events
channel.on('new-message', function(data) {
    console.log('New message:', data.text);
    console.log('From:', data.user);
});

// Publish events (client to client)
channel.publish('client-typing', { user: 'John' });
```

### Authentication for Private Channels

To authenticate private and presence channels, your application must provide an authentication endpoint:

```php
// routes.php
use Ody\Foundation\Facades\Route;
use Ody\Websocket\Http\Controllers\ChannelAuthController;

// Authentication for private and presence channels
Route::post('/broadcasting/auth', [ChannelAuthController::class, 'auth']);
```

The client must request authentication:

```javascript
// Client code
async function subscribeToPrivateChannel() {
    // Get auth token from server
    const response = await fetch('/broadcasting/auth', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            socket_id: client.getSocketId(),
            channel_name: 'private-channel'
        })
    });
    
    const auth = await response.json();
    
    // Subscribe with auth token
    client.subscribe('private-channel', { auth: auth.auth });
}
```

## Cross-Server Communication

The WebSocket channel system supports communication from external services:

1. **HTTP API**: Use the WebSocket server's API endpoint to broadcast messages:
   ```php
   // From an external service
   $httpClient->post('http://websocket-server/api/broadcast', [
       'json' => [
           'channel' => 'my-channel',
           'event' => 'new-message',
           'data' => ['text' => 'Hello from microservice!'],
           'api_key' => 'your-api-key'
       ]
   ]);
   ```

2. **Redis Pub/Sub**: For more robust communication, integrate with Redis:
   ```php
   // From an external service
   Redis::publish('websocket.broadcast', json_encode([
       'channel' => 'my-channel',
       'event' => 'new-message',
       'data' => ['text' => 'Hello from microservice!']
   ]));
   ```

## Configuration

In your `config/websocket.php` file:

```php
return [
    'host' => env('WEBSOCKET_HOST', '127.0.0.1'),
    'port' => env('WEBSOCKET_PORT', 9502),
    'sock_type' => SWOOLE_SOCK_TCP,
    'additional' => [
        "worker_num" => env('WEBSOCKET_WORKER_COUNT', swoole_cpu_num() * 2),
        // Other Swoole settings
    ],
    'secret_key' => env('WEBSOCKET_SECRET_KEY', 'your-secret-key'),
    'api_key' => env('WEBSOCKET_API_KEY', 'your-api-key'),
    'ssl' => [
        'ssl_cert_file' => null,
        'ssl_key_file' => null,
    ],
];
```

## Starting the WebSocket Server

To start the WebSocket server:

```bash
php artisan websocket:start
```

To run it in the background:

```bash
php artisan websocket:start -d
```

## Testing

Use the included test page to verify your WebSocket setup:

1. Open `websocket-test-page.html` in your browser
2. Enter your WebSocket server URL (e.g., `ws://localhost:9502`)
3. Enter your API key
4. Click "Connect"
5. Subscribe to channels and test sending/receiving messages

## Error Handling

The system includes comprehensive error handling and logging:

- Connection errors are logged and reported to clients
- Subscription failures are reported with descriptive messages
- Messages to non-existent channels trigger exceptions
- Client disconnections are detected and handled gracefully

## Extending the System

You can extend the system by:

1. **Creating custom channel handlers**:
   ```php
   class CustomChannelHandler implements ChannelHandlerInterface {
       // Implement the interface methods
   }
   
   // Register your handler
   $channelManager->registerChannelHandler('custom', new CustomChannelHandler());
   ```

2. **Adding middleware** (future enhancement):
   ```php
   // Example of middleware integration
   $channelManager->addMiddleware(new AuthorizationMiddleware());
   ```

3. **Custom authentication logic**:
   ```php
   // Customize the channel auth controller
   class MyChannelAuthController extends ChannelAuthController {
       protected function getCurrentUser(ServerRequestInterface $request): ?array {
           // Your custom user retrieval logic
       }
   }
   ```
