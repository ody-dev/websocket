<?php
declare(strict_types=1);
namespace Ody\Websocket;

use Ody\Swoole\RateLimiter;
use Ody\Websocket\Channel\ChannelManager;
use Ody\Websocket\Channel\Exceptions\ChannelException;
use Swoole\Event;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\Websocket\Server;

class WsServerCallbacks
{
    private static Server $server;
    public static Table $fds;
    private static RateLimiter $rateLimiter;
    private static ?ChannelManager $channelManager = null;

    public static function init($server): void
    {
        static::$server = $server;
        static::createFdsTable();
        static::initializeChannelManager($server);
        static::onStart(static::$server);
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
        // Handle incoming requests
        logger()->info("received request from broadcasting channel");
        if (isset($request->header["x-api-key"]) && $request->header["x-api-key"] !== config('websocket.secret_key')) {
            $response->status(401);
            $response->end();
            return;
        }

        // Parse request content
        $content = json_decode($request->getContent(), true);

        if (!$content || !isset($content['channel']) || !isset($content['event'])) {
            $response->status(400);
            $response->end(json_encode(['error' => 'Invalid request format']));
            return;
        }

        $channel = $content['channel'];
        $event = $content['event'];
        $data = $content['data'] ?? [];

        try {
            if (static::$channelManager) {
                // Use channel manager to broadcast to the channel
                $sentCount = static::$channelManager->broadcast($channel, $event, $data);

                $response->status(200);
                $response->end(json_encode([
                    'success' => true,
                    'sent_to' => $sentCount
                ]));
            } else {
                // Fall back to legacy broadcasting if channel manager not available
                foreach (static::$server->connections as $fd) {
                    if (static::$server->isEstablished($fd)) {
                        $clientName = sprintf("Client-%'.06d\n", $fd);
                        static::$server->push($fd, $request->getContent());
                    }
                }

                $response->status(200);
                $response->end(json_encode(['success' => true]));
            }
        } catch (ChannelException $e) {
            $response->status(404);
            $response->end(json_encode(['error' => $e->getMessage()]));
        } catch (\Throwable $e) {
            $response->status(500);
            $response->end(json_encode(['error' => 'Server error: ' . $e->getMessage()]));
        }
    }

    public static function onHandshake(Request $request, Response $response): bool
    {
        // Verify authentication token
        if ($request->header["sec-websocket-protocol"] !== config('websocket.secret_key')) {
            logger()->warning("not authenticated");
            $response->status(401);
            $response->end();
            return false;
        }

        // Complete WebSocket handshake
        $key = $request->header['sec-websocket-key'] ?? '';
        if (!preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $key)) {
            logger()->warning("handshake failed (1)");
            $response->end();
            return false;
        }

        if (strlen(base64_decode($key)) !== 16) {
            $response->end();
            logger()->warning("handshake failed (2)");
            return false;
        }

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

        Event::defer(function () use ($request, $response) {
            self::onOpen($request, $response);
        });

        return true;
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
        // Check for a ping event using the OpCode
        if($frame->opcode === WEBSOCKET_OPCODE_PING)
        {
            logger()->info("Ping frame received: Code {$frame->opcode}");
            $pongFrame = new Frame;
            $pongFrame->opcode = WEBSOCKET_OPCODE_PONG;
            $pongFrame->finish = true;
            $pongFrame->data = 'pong';

            // Send back a pong to the client
            $server->push($frame->fd, $pongFrame);
            return;
        }

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