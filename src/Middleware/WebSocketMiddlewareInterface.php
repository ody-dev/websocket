<?php

namespace Ody\Websocket\Middleware;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

interface WebSocketMiddlewareInterface
{
    /**
     * Process a WebSocket message
     *
     * @param Server $server The WebSocket server
     * @param Frame $frame The message frame
     * @param callable $next The next middleware in the pipeline
     * @return mixed
     */
    public function processMessage(Server $server, Frame $frame, callable $next);

    /**
     * Process a WebSocket connection request
     *
     * @param Request $request The HTTP request for the handshake
     * @param Response $response The HTTP response for the handshake
     * @param callable $next The next middleware in the pipeline
     * @return bool
     */
    public function processHandshake(Request $request, Response $response, callable $next): bool;
}