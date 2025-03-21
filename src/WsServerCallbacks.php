<?php
declare(strict_types=1);
namespace Ody\Websocket;

use Ody\Foundation\Bootstrap;
use Ody\Foundation\Http\RequestCallback;
use Ody\Foundation\HttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\Websocket\Server;

class WsServerCallbacks
{
    /**
     * @var WebSocketServer
     */
    private static WebSocketServer $wsServer;

    /**
     * Initialize the server
     */
    public static function init(Server $server): void
    {
        self::$wsServer = WebSocketServer::getInstance();
        self::$wsServer->initialize($server);

        self::onStart($server);

        if (config('websocket.enable_api')) {
            // Get existing application instance
            $app = Bootstrap::init();

            // Ensure the application is bootstrapped
            if (!$app->isBootstrapped()) {
                $app->bootstrap();
            }

            self::$wsServer->setApplication($app);
            logger()->debug("REST API initialized.");
        }
    }

    /**
     * Add middleware to both pipelines
     */
    public static function addMiddleware($middleware): void
    {
        self::$wsServer->addMiddleware($middleware);
    }

    /**
     * Add handshake middleware
     */
    public static function addHandshakeMiddleware($middleware): void
    {
        self::$wsServer->addHandshakeMiddleware($middleware);
    }

    /**
     * Add message middleware
     */
    public static function addMessageMiddleware($middleware): void
    {
        self::$wsServer->addMessageMiddleware($middleware);
    }

    /**
     * Get the channel manager instance
     */
    public static function getChannelManager()
    {
        return self::$wsServer->getChannelManager();
    }

    /**
     * Server started callback
     */
    public static function onStart(Server $server): void
    {
        $protocol = ($server->ssl) ? "https" : "http";
        logger()->info('websocket server started successfully');
        logger()->info("listening on $protocol://$server->host:$server->port");
        logger()->info('press Ctrl+C to stop the server');
    }

    /**
     * Handle incoming HTTP requests
     */
    public static function onRequest(Request $request, Response $response): void
    {
        if (!config('websocket.enable_api')) {
            $response->end('API not enabled!');
            return;
        }

        logger()->info("received request from broadcasting channel");

        HttpServer::setContext($request);

        $app = self::$wsServer->getApplication();
        if ($app) {
            $callback = new RequestCallback($app);
            $callback->handle($request, $response);
        } else {
            $response->end('Application not initialized');
        }
    }

    /**
     * Handle WebSocket handshake
     */
    public static function onHandShake(Request $request, Response $response): bool
    {
        $middlewareManager = self::$wsServer->getMiddlewareManager();

        return $middlewareManager->runHandshakePipeline(
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
            }
        );
    }

    /**
     * Handle WebSocket open
     */
    public static function onOpen(Request $request, Response $response): void
    {
        $fd = $request->fd;
        $clientName = sprintf("Client-%'.06d", $request->fd);

        self::$wsServer->setConnectionInfo($fd, $clientName);
        $fdsTable = self::$wsServer->getFdsTable();

        logger()->info("connection <{$fd}> open by {$clientName}. Total connections: " . $fdsTable->count());

        // Send welcome message with connection info
        $server = self::$wsServer->getServer();
        $welcomeMessage = json_encode([
            'event' => 'connection_established',
            'data' => [
                'socket_id' => $fd,
                'activity_timeout' => 120 // Seconds before connection is considered inactive
            ]
        ]);

        $server->push($fd, $welcomeMessage);
    }

    /**
     * Handle WebSocket close
     */
    public static function onClose(Server $server, $fd): void
    {
        // Handle channel unsubscriptions if channel manager exists
        $channelManager = self::$wsServer->getChannelManager();
        if ($channelManager) {
            $channelManager->handleDisconnection($fd);
        }

        self::$wsServer->removeConnectionInfo($fd);
        $fdsTable = self::$wsServer->getFdsTable();

        logger()->info("connection close: {$fd}, total connections: " . $fdsTable->count());
    }

    /**
     * Handle WebSocket disconnect
     */
    public static function onDisconnect(Server $server, int $fd): void
    {
        // Handle channel unsubscriptions if channel manager exists
        $channelManager = self::$wsServer->getChannelManager();
        if ($channelManager) {
            $channelManager->handleDisconnection($fd);
        }

        self::$wsServer->removeConnectionInfo($fd);
        $fdsTable = self::$wsServer->getFdsTable();

        logger()->info("disconnect: {$fd}, total connections: " . $fdsTable->count());
    }

    /**
     * Handle WebSocket message
     */
    public static function onMessage(Server $server, Frame $frame): void
    {
        // Handle ping/pong frames
        if ($frame->opcode === WEBSOCKET_OPCODE_PING) {
            // Client sent a ping, respond with pong
            $server->push($frame->fd, '', WEBSOCKET_OPCODE_PONG);
            return;
        }

//        if ($frame->opcode === WEBSOCKET_OPCODE_PONG) {
//            // Client responded to our ping
//            WebSocketServer::getInstance()->handlePong($frame->fd);
//            return;
//        }

        // Handle close frames
        if ($frame->opcode === WEBSOCKET_OPCODE_CLOSE) {
            logger()->info("Client {$frame->fd} sent close frame");
            // The server will automatically respond and trigger onClose
            return;
        }

        $middlewareManager = self::$wsServer->getMiddlewareManager();

        $middlewareManager->runMessagePipeline(
            $server,
            $frame,
            function (Server $server, Frame $frame) {
                // Get sender info
                $sender = self::$wsServer->getConnectionName($frame->fd) ?: "Unknown";
                logger()->info("received from " . $sender . ", message: {$frame->data}");

                // Process through channel manager if available
                $channelManager = self::$wsServer->getChannelManager();
                if ($channelManager) {
                    try {
                        $channelManager->handleClientMessage($frame->fd, $frame);
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

    /**
     * Handle worker start
     */
    public static function onWorkerStart(Server $server, int $workerId): void
    {
        logger()->debug('WsServerCallbacks: onWorkerStart');

        if ($workerId == config('websocket.additional.worker_num') - 1){
            $workerIds = [];
            for ($i = 0; $i < config('websocket.additional.worker_num'); $i++){
                $workerIds[$i] = $server->getWorkerPid($i);
            }

            $serverState = WsServerState::getInstance();
            $serverState->setMasterProcessId($server->getMasterPid());
            $serverState->setManagerProcessId($server->getManagerPid());
            $serverState->setWorkerProcessIds($workerIds);
        }
    }
}