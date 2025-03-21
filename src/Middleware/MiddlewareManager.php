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

    /**
     * Execute the middleware pipeline for a handshake
     *
     * @param Request $request
     * @param Response $response
     * @param callable $finalHandler
     * @return bool
     */
    public function runHandshakePipeline(Request $request, Response $response, callable $finalHandler): bool
    {
        // Create the pipeline by nesting each middleware
        $pipeline = $this->createHandshakePipeline($finalHandler);

        // Execute the pipeline
        return $pipeline($request, $response);
    }

    /**
     * Execute the middleware pipeline for a message
     *
     * @param Server $server
     * @param Frame $frame
     * @param callable $finalHandler
     * @return mixed
     */
    public function runMessagePipeline(Server $server, Frame $frame, callable $finalHandler)
    {
        // Create the pipeline by nesting each middleware
        $pipeline = $this->createMessagePipeline($finalHandler);

        // Execute the pipeline
        return $pipeline($server, $frame);
    }

    /**
     * Create the handshake middleware pipeline
     *
     * @param callable $finalHandler
     * @return callable
     */
    protected function createHandshakePipeline(callable $finalHandler): callable
    {
        // Start with the final handler
        $pipeline = $finalHandler;

        // Build the pipeline from the last middleware to the first
        foreach (array_reverse($this->handshakeMiddleware) as $middleware) {
            $next = $pipeline;
            $pipeline = function (Request $request, Response $response) use ($middleware, $next) {
                return $middleware->processHandshake($request, $response, $next);
            };
        }

        return $pipeline;
    }

    /**
     * Create the message middleware pipeline
     *
     * @param callable $finalHandler
     * @return callable
     */
    protected function createMessagePipeline(callable $finalHandler): callable
    {
        // Start with the final handler
        $pipeline = $finalHandler;

        // Build the pipeline from the last middleware to the first
        foreach (array_reverse($this->messageMiddleware) as $middleware) {
            $next = $pipeline;
            $pipeline = function (Server $server, Frame $frame) use ($middleware, $next) {
                return $middleware->processMessage($server, $frame, $next);
            };
        }

        return $pipeline;
    }
}