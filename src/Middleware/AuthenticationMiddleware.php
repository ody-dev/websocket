<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

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
//        if ($request->header["sec-websocket-protocol"] !== $this->secretKey) {
//            logger()->warning("not authenticated");
//            $response->status(401);
//            $response->end();
//            return false;
//        }

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

        // Authentication passed, continue the pipeline
        return $next($request, $response);
    }
}