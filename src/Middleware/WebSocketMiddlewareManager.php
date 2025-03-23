<?php

namespace Ody\Websocket\Middleware;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class WebSocketMiddlewareManager
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
     * @var bool
     */
    protected bool $initialized = false;

    /**
     * Queue of middleware to be registered after initialization
     */
    protected array $middlewareQueue = [
        'handshake' => [],
        'message' => [],
        'global' => []
    ];

    /**
     * Initialize the middleware manager
     */
    public function initialize(): void
    {
        $this->initialized = true;
        $this->processMiddlewareQueue();
    }

    /**
     * Process queued middleware after initialization
     */
    protected function processMiddlewareQueue(): void
    {
        // Add handshake middleware
        foreach ($this->middlewareQueue['handshake'] as $middleware) {
            $this->addHandshakeMiddleware($middleware);
        }

        // Add message middleware
        foreach ($this->middlewareQueue['message'] as $middleware) {
            $this->addMessageMiddleware($middleware);
        }

        // Add middleware to both pipelines
        foreach ($this->middlewareQueue['global'] as $middleware) {
            $this->addMiddleware($middleware);
        }

        // Clear the queue
        $this->middlewareQueue = [
            'handshake' => [],
            'message' => [],
            'global' => []
        ];
    }

    /**
     * Queue or add handshake middleware
     */
    public function addHandshakeMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        if ($this->initialized) {
            $this->handshakeMiddleware[] = $middleware;
        } else {
            $this->middlewareQueue['handshake'][] = $middleware;
        }

        return $this;
    }

    /**
     * Queue or add message middleware
     */
    public function addMessageMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        if ($this->initialized) {
            $this->messageMiddleware[] = $middleware;
        } else {
            $this->middlewareQueue['message'][] = $middleware;
        }

        return $this;
    }

    /**
     * Queue or add middleware for both pipelines
     */
    public function addMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        if ($this->initialized) {
            $this->handshakeMiddleware[] = $middleware;
            $this->messageMiddleware[] = $middleware;
        } else {
            $this->middlewareQueue['global'][] = $middleware;
        }

        return $this;
    }

    /**
     * Run the handshake middleware pipeline
     */
    public function runHandshakePipeline(Request $request, Response $response, callable $finalHandler): bool
    {
        $pipeline = $this->createHandshakePipeline($finalHandler);
        return $pipeline($request, $response);
    }

    /**
     * Create the handshake middleware pipeline
     */
    protected function createHandshakePipeline(callable $finalHandler): callable
    {
        // Start with the final handler
        $pipeline = $finalHandler;

        // Build the pipeline from the last middleware to the first
        foreach (array_reverse($this->handshakeMiddleware) as $middleware) {
            $next = $pipeline;
            $pipeline = function (Request $request, Response $response) use ($middleware, $next) {
                try {
                    return $middleware->processHandshake($request, $response, $next);
                } catch (\Throwable $e) {
                    logger()->error('Handshake middleware error: ' . $e->getMessage(), [
                        'middleware' => get_class($middleware),
                        'exception' => $e
                    ]);
                    throw $e;
                }
            };
        }

        return $pipeline;
    }

    /**
     * Run the message middleware pipeline
     */
    public function runMessagePipeline(Server $server, Frame $frame, callable $finalHandler)
    {
        $pipeline = $this->createMessagePipeline($finalHandler);
        return $pipeline($server, $frame);
    }

    /**
     * Create the message middleware pipeline
     */
    protected function createMessagePipeline(callable $finalHandler): callable
    {
        // Start with the final handler
        $pipeline = $finalHandler;

        // Build the pipeline from the last middleware to the first
        foreach (array_reverse($this->messageMiddleware) as $middleware) {
            $next = $pipeline;
            $pipeline = function (Server $server, Frame $frame) use ($middleware, $next) {
                try {
                    return $middleware->processMessage($server, $frame, $next);
                } catch (\Throwable $e) {
                    logger()->error('Message middleware error: ' . $e->getMessage(), [
                        'middleware' => get_class($middleware),
                        'exception' => $e
                    ]);
                    throw $e;
                }
            };
        }

        return $pipeline;
    }
}