<?php

namespace Ody\Websocket\Middleware;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class MiddlewareManager
{
    /**
     * @var WebSocketMiddlewareInterface[]
     */
    protected array $handshakeMiddleware = [];

    /**
     * @var WebSocketMiddlewareInterface[]
     */
    protected array $messageMiddleware = [];

    /**
     * Add middleware to the handshake pipeline
     *
     * @param WebSocketMiddlewareInterface $middleware
     * @return self
     */
    public function addHandshakeMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        $this->handshakeMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Add middleware to the message pipeline
     *
     * @param WebSocketMiddlewareInterface $middleware
     * @return self
     */
    public function addMessageMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        $this->messageMiddleware[] = $middleware;
        return $this;
    }

    /**
     * Add middleware to both pipelines
     *
     * @param WebSocketMiddlewareInterface $middleware
     * @return self
     */
    public function addMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        $this->handshakeMiddleware[] = $middleware;
        $this->messageMiddleware[] = $middleware;
        return $this;
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
}