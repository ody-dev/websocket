<?php
declare(strict_types=1);
namespace Ody\Websocket;

use Ody\Foundation\Application;
use Ody\Foundation\Bootstrap;
use Ody\Foundation\Http\RequestCallback;
use Ody\Foundation\HttpServer;
use Ody\Websocket\Channel\ChannelManager;
use Ody\Websocket\Middleware\MiddlewareManager;
use Ody\Websocket\Middleware\WebSocketMiddlewareInterface;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\Websocket\Server;

class WsServerCallbacks
{
    /**
     * @var Server
     */
    private static Server $server;

    /**
     * @var Table
     */
    public static Table $fds;

    /**
     * @var ChannelManager|null
     */
    private static ?ChannelManager $channelManager = null;

    /**
     * @var MiddlewareManager
     */
    private static MiddlewareManager $middlewareManager;

    /**
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * @var WebSocketMiddlewareInterface[]
     */
    protected array $handshakeMiddleware = [];

    /**
     * @var WebSocketMiddlewareInterface[]
     */
    protected array $messageMiddleware = [];

    /**
     * @var Application|null
     */
    private static ?Application $app = null;

    /**
     * Queue of middleware to be registered after initialization
     */
    private static array $middlewareQueue = [
        'handshake' => [],
        'message' => [],
        'global' => []
    ];

    public static function init($server): void
    {
        static::$server = $server;
        static::$middlewareManager = new MiddlewareManager();
        static::$initialized = true;

        // Process queued middleware
        self::processMiddlewareQueue();
        
        static::createFdsTable();
        static::initializeChannelManager($server);
        static::onStart(static::$server);

        if (config('websocket.enable_api')) {
            // Get existing application instance
            self::$app = Bootstrap::init();

            // Ensure the application is bootstrapped
            if (!self::$app->isBootstrapped()) {
                self::$app->bootstrap();
            }

            logger()->debug("REST API initialized.");
        }
    }

    /**
     * Process queued middleware after initialization
     */
    private static function processMiddlewareQueue(): void
    {
        // Add handshake middleware
        foreach (static::$middlewareQueue['handshake'] as $middleware) {
            static::$middlewareManager->addHandshakeMiddleware($middleware);
        }

        // Add message middleware
        foreach (static::$middlewareQueue['message'] as $middleware) {
            static::$middlewareManager->addMessageMiddleware($middleware);
        }

        // Add middleware to both pipelines
        foreach (static::$middlewareQueue['global'] as $middleware) {
            static::$middlewareManager->addMiddleware($middleware);
        }

        // Clear the queue
        static::$middlewareQueue = [
            'handshake' => [],
            'message' => [],
            'global' => []
        ];
    }

    /**
     * Queue handshake middleware for registration
     */
    public static function addHandshakeMiddleware(WebSocketMiddlewareInterface $middleware): void
    {
        if (static::$initialized) {
            static::$middlewareManager->addHandshakeMiddleware($middleware);
        } else {
            static::$middlewareQueue['handshake'][] = $middleware;
        }
    }

    /**
     * Queue message middleware for registration
     */
    public static function addMessageMiddleware(WebSocketMiddlewareInterface $middleware): void
    {
        if (static::$initialized) {
            static::$middlewareManager->addMessageMiddleware($middleware);
        } else {
            static::$middlewareQueue['message'][] = $middleware;
        }
    }

    /**
     * Queue middleware for both pipelines
     */
    public static function addMiddleware(WebSocketMiddlewareInterface $middleware): void
    {
        if (static::$initialized) {
            static::$middlewareManager->addMiddleware($middleware);
        } else {
            static::$middlewareQueue['global'][] = $middleware;
        }
    }

    // Rest of the implementation with separate pipeline creation
    // using the appropriate middleware arrays

    protected function createHandshakePipeline(callable $finalHandler): callable
    {
        // Start with the final handler
        $pipeline = $finalHandler;

        // Use handshake-specific middleware
        foreach (array_reverse($this->handshakeMiddleware) as $middleware) {
            $next = $pipeline;
            $pipeline = function (Request $request, Response $response) use ($middleware, $next) {
                return $middleware->processHandshake($request, $response, $next);
            };
        }

        return $pipeline;
    }

    protected function createMessagePipeline(callable $finalHandler): callable
    {
        // Start with the final handler
        $pipeline = $finalHandler;

        // Use message-specific middleware
        foreach (array_reverse($this->messageMiddleware) as $middleware) {
            $next = $pipeline;
            $pipeline = function (Server $server, Frame $frame) use ($middleware, $next) {
                return $middleware->processMessage($server, $frame, $next);
            };
        }

        return $pipeline;
    }

    /**
     * Initialize the channel manager
     *
     * @param Server $server WebSocket server instance
     * @return void
     */
    public static function initializeChannelManager(Server $server): void
    {
        static::$channelManager = new ChannelManager($server, logger());

        // Any additional setup for the channel manager can go here
    }

    /**
     * Get the channel manager instance
     *
     * @return ChannelManager|null
     */
    public static function getChannelManager(): ?ChannelManager
    {
        return static::$channelManager;
    }

    public static function onStart (Server $server): void
    {
        $protocol = ($server->ssl) ? "https" : "http";
        logger()->info('websocket server started successfully');
        logger()->info("listening on $protocol://$server->host:$server->port");
        logger()->info('press Ctrl+C to stop the server');
    }

    /*
     * Handle incoming HTTP requests
     */
    public static function onRequest(Request $request, Response $response): void
    {
        if (!config('websocket.enable_api')) {
            $response->end('API not enabled!');
            return;
        }
        // Handle incoming requests
        logger()->info("received request from broadcasting channel");

        Coroutine::create(function () use ($request, $response) {
            HttpServer::setContext($request);

            $callback = new RequestCallback(static::$app);
            $callback->handle($request, $response);
        });
    }

    public static function onHandshake(Request $request, Response $response): bool
    {
        return static::$middlewareManager->runHandshakePipeline(
            $request,
            $response,
            function (Request $request, Response $response) {
                $key = $request->header['sec-websocket-key'] ?? '';

                $response->header('Upgrade', 'websocket');
                $response->header('Connection', 'Upgrade');
                $response->header(
                    'Sec-WebSocket-Accept',
                    base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))
                );
                $response->header('Sec-WebSocket-Version', '13');

                $protocol = $request->header['sec-websocket-protocol'] ?? null;

                if ($protocol !== null) {
                    $response->header('Sec-WebSocket-Protocol', $protocol);
                }

                $response->status(101);
                $response->end();
                logger()->info("handshake done");

                return true;

                return true; // or false if handshake fails
            }
        );
    }

    public static function onOpen(Request $request, Response $response): void
    {
        $fd = $request->fd;
        $clientName = sprintf("Client-%'.06d", $request->fd);

        static::$fds->set((string) $fd, [
            'fd' => $fd,
            'name' => sprintf($clientName)
        ]);

        logger()->info("connection <{$fd}> open by {$clientName}. Total connections: " . static::$fds->count());

        // Send welcome message with connection info
        $welcomeMessage = json_encode([
            'event' => 'connection_established',
            'data' => [
                'socket_id' => $fd,
                'activity_timeout' => 120 // Seconds before connection is considered inactive
            ]
        ]);

        static::$server->push($fd, $welcomeMessage);
    }

    public static function onClose(Server $server, $fd): void
    {
        // Handle channel unsubscriptions if channel manager exists
        if (static::$channelManager) {
            static::$channelManager->handleDisconnection($fd);
        }

        static::$fds->del((string) $fd);
        logger()->info("connection close: {$fd}, total connections: " . static::$fds->count());
    }

    public static function onDisconnect(Server $server, int $fd): void
    {
        // Handle channel unsubscriptions if channel manager exists
        if (static::$channelManager) {
            static::$channelManager->handleDisconnection($fd);
        }

        static::$fds->del((string) $fd);
        logger()->info("disconnect: {$fd}, total connections: " . static::$fds->count());
    }

    public static function onMessage(Server $server, Frame $frame): void
    {
        static::$middlewareManager->runMessagePipeline(
            $server,
            $frame,
            function (Server $server, Frame $frame) {
                // Original message handling logic here
                $sender = static::$fds->get(strval($frame->fd), "name");
                logger()->info("received from " . $sender . ", message: {$frame->data}");

                // Process through channel manager if available
                if (static::$channelManager) {
                    try {
                        static::$channelManager->handleClientMessage($frame->fd, $frame);
                    } catch (\Throwable $e) {
                        logger()->error("Error handling client message: " . $e->getMessage(), [
                            'fd' => $frame->fd,
                            'data' => $frame->data,
                            'exception' => get_class($e)
                        ]);

                        // Send error back to client
                        $server->push($frame->fd, json_encode([
                            'event' => 'error',
                            'data' => [
                                'message' => $e->getMessage(),
                                'code' => $e->getCode()
                            ]
                        ]));
                    }
                }
            }
        );
    }

    private static function createFdsTable(): void
    {
        $fds = new Table(1024);
        $fds->column('fd', Table::TYPE_INT, 4);
        $fds->column('name', Table::TYPE_STRING, 16);
        $fds->create();

        static::$fds = $fds;
    }

    public static function onWorkerStart(Server $server, int $workerId): void
    {
        logger()->debug('WsServerCallbacks: onWorkerStart');

        if ($workerId == config('websocket.additional.worker_num') - 1){
            $workerIds = [];
            for ($i = 0; $i < config('websocket.additional.worker_num'); $i++){
                $workerIds[$i] = $server->getWorkerPid($i);
            }

            $serveState = WsServerState::getInstance();
            $serveState->setMasterProcessId($server->getMasterPid());
            $serveState->setManagerProcessId($server->getManagerPid());
            $serveState->setWorkerProcessIds($workerIds);
        }
    }
}