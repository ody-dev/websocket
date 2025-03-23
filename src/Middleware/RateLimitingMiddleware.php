<?php

namespace Ody\Websocket\Middleware;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class RateLimitingMiddleware implements WebSocketMiddlewareInterface
{
    /**
     * @var Table
     */
    protected Table $rateLimits;

    /**
     * @var int
     */
    protected int $maxMessagesPerMinute;

    public function __construct(int $maxMessagesPerMinute = 60)
    {
        $this->maxMessagesPerMinute = $maxMessagesPerMinute;

        // Initialize Swoole table for tracking rate limits
        $this->rateLimits = new Table(1024);
        $this->rateLimits->column('fd', Table::TYPE_INT, 1024);
        $this->rateLimits->column('count', Table::TYPE_INT, 1024);
        $this->rateLimits->column('reset_time', Table::TYPE_INT, 1024);
        $this->rateLimits->create();
    }

    public function processMessage(Server $server, Frame $frame, callable $next)
    {
        $fd = $frame->fd;
        $now = time();

        // Check if client exists in the rate limit table
        if (!$this->rateLimits->exists((string)$fd)) {
            // Initialize rate limit for new client
            $this->rateLimits->set((string)$fd, [
                'fd' => $fd,
                'count' => 1,
                'reset_time' => $now + 60 // Reset after 1 minute
            ]);
        } else {
            // Get current rate limit data
            $limitData = $this->rateLimits->get((string)$fd);

            // Check if reset time has passed
            if ($now > $limitData['reset_time']) {
                // Reset the counter
                $this->rateLimits->set((string)$fd, [
                    'fd' => $fd,
                    'count' => 1,
                    'reset_time' => $now + 60
                ]);
            } else {
                // Increment the counter
                $newCount = $limitData['count'] + 1;

                // Check if rate limit exceeded
                if ($newCount > $this->maxMessagesPerMinute) {
                    // Rate limit exceeded, send error and drop message
                    $server->push($fd, json_encode([
                        'event' => 'error',
                        'data' => [
                            'message' => 'Rate limit exceeded. Please slow down.',
                            'code' => 429
                        ]
                    ]));

                    logger()->warning("Rate limit exceeded for client {$fd}");
                    return null; // Skip the next middleware
                }

                // Update the counter
                $this->rateLimits->set((string)$fd, [
                    'fd' => $fd,
                    'count' => $newCount,
                    'reset_time' => $limitData['reset_time']
                ]);
            }
        }

        // Continue to the next middleware
        return $next($server, $frame);
    }

    public function processHandshake(Request $request, Response $response, callable $next): bool
    {
        // No rate limiting for handshakes (or implement IP-based limiting if needed)
        return $next($request, $response);
    }
}