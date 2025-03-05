<?php
declare(strict_types=1);
namespace Ody\Websocket;

use Ody\Swoole\RateLimiter;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\Websocket\Server;

class WsServer
{
    private static Server $server;
    public static Table $fds;
    private static RateLimiter $rateLimiter;

    public static function init(): self
    {
        return new self();
    }

    public function start(): void
    {
        static::onStart(static::$server);
        static::$server->start();
    }

    public function createServer($config): static
    {
        $this->createFdsTable();
//        $this->createRatelimiter();
        static::$server = new Server(
            $config['host'],
            (int) $config['port'],
            $this->getSslConfig($config['ssl'], $config['mode']),
            $config["sock_type"]
        );
      
        return $this;
    }

    public static function onStart (Server $server): void
    {
        $protocol = ($server->ssl) ? "https" : "http";
        echo "   \033[1mSUCCESS\033[0m  Websocket started successfully\n";
        echo "   \033[1mINFO\033[0m  listen on " . $protocol . "://" . $server->host . ':' . $server->port . PHP_EOL;
        echo "   \033[1mINFO\033[0m  press Ctrl+C to stop the server\n";
    }

    /*
     * Loop through all the WebSocket connections to
     * send back a response to all clients. Broadcast
     * a message back to every WebSocket client.
     *
     * https://openswoole.com/docs/modules/swoole-websocket-server-on-request
     */
    public static function onRequest(Request $request,  Response $response): void
    {
        // Handle incoming requests
        // TODO: Implement routes
        echo "Received request from broadcasting channel.\n";
        if ($request->header["x-api-key"] !== config('websockets.secret_key')) {
            $response->status(401);
            $response->end();
        }

        foreach(static::$server->connections as $fd)
        {
            // Validate a correct WebSocket connection otherwise a push may fail
            if(static::$server->isEstablished($fd))
            {
                $clientName = sprintf("Client-%'.06d\n", $fd);
                echo "Pushing event to $clientName...\n";
                static::$server->push($fd, $request->getContent());
            }
        }

        $response->status(200);
        $response->end();
    }

    public static function onHandshake(Request $request, Response $response): bool
    {
//        $ip = $request->server['remote_addr'];
//        if (!empty($request->server['HTTP_X_FORWARDED_FOR'])) {
//            $ip = $request->server['HTTP_X_FORWARDED_FOR'];
//        }
//
//        if(
//            static::$rateLimiter->isRateLimited(
//                $ip,
//                'websocket',
//                25,
//                20)
//        ) {
//            $response->status(429);
//            $response->end();
//            return false;
//        }

        if ($request->header["sec-websocket-protocol"] !== config('websocket.secret_key')) {
            echo "Not authenticated\n";
            $response->status(401);
            $response->end();
            return false;
        }

        $key = $request->header['sec-websocket-key'] ?? '';
        if (!preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $key)) {
            echo "Handshake failed (1)\n";
            $response->end();
            return false;
        }

        if (strlen(base64_decode($key)) !== 16) {
            $response->end();
            echo "Handshake failed (2)\n";
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
        echo "Handshake done\n";

        \Swoole\Event::defer(function () use ($request, $response) {
            echo "Client connected\n";
            self::onOpen($request, $response);
        });

        return true;
    }

    public static function onOpen(Request $request, Response $response): void
    {
        $fd = $request->fd;
        $clientName = sprintf("Client-%'.06d\n", $request->fd);

        static::$fds->set((string) $fd, [
            'fd' => $fd,
            'name' => sprintf($clientName)
        ]);
        echo "Connection <{$fd}> open by {$clientName}. Total connections: " . static::$fds->count() . "\n";
    }

    public static function onClose(Server $server, $fd): void
    {
        static::$fds->del((string) $fd);
        echo "Connection close: {$fd}, total connections: " . static::$fds->count() . PHP_EOL;
    }

    public static function onDisconnect(Server $server, int $fd): void
    {
        static::$fds->del((string) $fd);
        echo "Disconnect: {$fd}, total connections: " . static::$fds->count() . "\n\n";
    }

    public static function onMessage (Server $server, Frame $frame): void
    {
        // Check for a ping event using the OpCode
        if($frame->opcode === WEBSOCKET_OPCODE_PING)
        {
            echo "Ping frame received: Code {$frame->opcode}\n";
            $pongFrame = new Frame;
            $pongFrame->opcode = WEBSOCKET_OPCODE_PONG;
            $pongFrame->finish = true;
            $pongFrame->data = 'hello';

            // Send back a pong to the client
            $server->push($frame->fd, $pongFrame);
        }

        $sender = static::$fds->get(strval($frame->fd), "name");
        echo "Received from " . $sender . ", message: {$frame->data}" . PHP_EOL;
    }


    private function createFdsTable(): void
    {
        $fds = new Table(1024);
        $fds->column('fd', Table::TYPE_INT, 4);
        $fds->column('name', Table::TYPE_STRING, 16);
        $fds->create();

        static::$fds = $fds;
    }

    private static function validateHandshake($request, $response): void
    {
        $key = $request->header['sec-websocket-key'] ?? '';

        if (!preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $key)) {
            echo "Handshake failed (1)\n";
            $response->end();
            return;
        }

        if (strlen(base64_decode($key)) !== 16) {
            $response->end();
            echo "Handshake failed (2)\n";
            return;
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
        echo "Handshake done\n";
    }

    public static function onWorkerStart(Server $server, int $workerId): void
    {
        if ($workerId == config('websocket.additional.worker_num') - 1){
            $workerIds = [];
            for ($i = 0; $i < config('websocket.additional.worker_num'); $i++){
                $workerIds[$i] = $server->getWorkerPid($i);
            }

            $serveState = WebsocketServerState::getInstance();
            $serveState->setMasterProcessId($server->getMasterPid());
            $serveState->setManagerProcessId($server->getManagerPid());
            $serveState->setWorkerProcessIds($workerIds);
        }
    }

    private function createRatelimiter()
    {
        static::$rateLimiter = new RateLimiter();
    }

    /**
     * @param array $config
     * @param $serverMode
     * @return int
     */
    private function getSslConfig(array $config, $serverMode): int
    {
        if (
            !is_null($config["ssl_cert_file"]) &&
            !is_null($config["ssl_key_file"])
        ) {
            return !is_null($serverMode) ? $serverMode : SWOOLE_SSL;
        }

        return $serverMode;
    }

    public function registerCallbacks(array $callbacks): static
    {
        foreach ($callbacks as $event => $callback) {
            static::$server->on($event, [...$callback]);
        }

        return $this;
    }

    public function setServerConfig(array $config, int $daemonize = 0): static
    {
        static::$server->set([
            ...$config,
            'daemonize' => (int) $daemonize,
            'enable_coroutine' => true // must be set on false for Runtime::enableCoroutine
        ]);

        static::$server->set([
            ...config('websocket.additional'),
            'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true,
        ]);

        return $this;
    }
}