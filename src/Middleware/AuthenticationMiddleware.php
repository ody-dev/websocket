<?php

namespace Ody\Websocket\Middleware;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class AuthenticationMiddleware implements WebSocketMiddlewareInterface
{
    /**
     * @var string
     */
    protected string $secretKey;

    public function __construct()
    {
        $this->secretKey = config('websocket.secret_key');
    }

    public function processMessage(Server $server, Frame $frame, callable $next)
    {
        // Messages don't need authentication checks since the connection is already authenticated
        return $next($server, $frame);
    }

    public function processHandshake(Request $request, Response $response, callable $next): bool
    {
        // Verify authentication token
        if ($request->header["sec-websocket-protocol"] !== $this->secretKey) {
            logger()->warning("not authenticated");
            $response->status(401);
            $response->end();
            return false;
        }

        // Authentication passed, continue the pipeline
        return $next($request, $response);
    }
}