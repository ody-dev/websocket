<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Websocket\Middleware;

use Ody\Logger\StreamLogger;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class LoggingMiddleware implements WebSocketMiddlewareInterface
{
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new StreamLogger('php://stdout');
    }

    public function processMessage(Server $server, Frame $frame, callable $next)
    {
        $startTime = microtime(true);

        // Generate a unique ID for this message
        $messageId = uniqid('msg_', true);

        // Log the incoming message
        $this->logger->info("Processing WebSocket message", [
            'message_id' => $messageId,
            'fd' => $frame->fd,
            'opcode' => $frame->opcode,
            'data_length' => strlen($frame->data)
        ]);

        // Continue to the next middleware and capture the result
        $result = $next($server, $frame);

        // Log the processing time
        $processingTime = (microtime(true) - $startTime) * 1000;
        $this->logger->info("WebSocket message processed", [
            'message_id' => $messageId,
            'fd' => $frame->fd,
            'processing_time_ms' => round($processingTime, 2)
        ]);

        return $result;
    }

    public function processHandshake(Request $request, Response $response, callable $next): bool
    {
        $startTime = microtime(true);

        // Generate a unique ID for this handshake
        $handshakeId = uniqid('hs_', true);

        // Log the incoming handshake
        $this->logger->info("Processing WebSocket handshake", [
            'handshake_id' => $handshakeId,
            'ip' => $request->server['remote_addr'],
            'uri' => $request->server['request_uri']
        ]);

        // Continue to the next middleware and capture the result
        $result = $next($request, $response);

        // Log the result
        $processingTime = (microtime(true) - $startTime) * 1000;
        $this->logger->info("WebSocket handshake processed", [
            'handshake_id' => $handshakeId,
            'success' => $result,
            'processing_time_ms' => round($processingTime, 2)
        ]);

        return $result;
    }
}