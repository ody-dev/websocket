<?php

namespace Ody\Websocket;

use Ody\Foundation\Application;
use Ody\Websocket\Channel\ChannelManager;
use Ody\Websocket\Middleware\WebSocketMiddlewareInterface;
use Ody\Websocket\Middleware\WebSocketMiddlewareManager;
use Swoole\Table;
use Swoole\Websocket\Server;

class WebSocketServer
{
    /**
     * @var WebSocketServer
     */
    private static ?WebSocketServer $instance = null;

    /**
     * @var Server
     */
    private ?Server $server = null;

    /**
     * @var WebSocketMiddlewareManager
     */
    private WebSocketMiddlewareManager $middlewareManager;

    /**
     * @var ChannelManager
     */
    private ?ChannelManager $channelManager = null;

    /**
     * @var Table
     */
    private ?Table $fds = null;

    /**
     * @var Application
     */
    private ?Application $app = null;

    /**
     * Private constructor for singleton
     */
    private function __construct()
    {
        $this->middlewareManager = new WebSocketMiddlewareManager();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize the server
     */
    public function initialize(Server $server): self
    {
        $this->server = $server;
        $this->middlewareManager->initialize();

        $this->createFdsTable();
        $this->initializeChannelManager($server);

        return $this;
    }

    /**
     * Create the FDs table
     */
    private function createFdsTable(): void
    {
        $fds = new Table(1024);
        $fds->column('fd', Table::TYPE_INT, 4);
        $fds->column('name', Table::TYPE_STRING, 16);
        $fds->create();

        $this->fds = $fds;
    }

    /**
     * Initialize the channel manager
     */
    public function initializeChannelManager(Server $server): self
    {
        $this->channelManager = new ChannelManager($server, logger());
        return $this;
    }

    /**
     * Get the WebSocket server instance
     */
    public function getServer(): ?Server
    {
        return $this->server;
    }

    /**
     * Get the middleware manager
     */
    public function getMiddlewareManager(): WebSocketMiddlewareManager
    {
        return $this->middlewareManager;
    }

    /**
     * Add middleware to both handshake and message pipelines
     */
    public function addMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        $this->middlewareManager->addMiddleware($middleware);
        return $this;
    }

    /**
     * Add middleware to handshake pipeline only
     */
    public function addHandshakeMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        $this->middlewareManager->addHandshakeMiddleware($middleware);
        return $this;
    }

    /**
     * Add middleware to message pipeline only
     */
    public function addMessageMiddleware(WebSocketMiddlewareInterface $middleware): self
    {
        $this->middlewareManager->addMessageMiddleware($middleware);
        return $this;
    }

    /**
     * Set the application instance
     */
    public function setApplication(Application $app): self
    {
        $this->app = $app;
        return $this;
    }

    /**
     * Get the application instance
     */
    public function getApplication(): ?Application
    {
        return $this->app;
    }

    /**
     * Get the channel manager
     */
    public function getChannelManager(): ?ChannelManager
    {
        return $this->channelManager;
    }

    /**
     * Get the FDs table
     */
    public function getFdsTable(): ?Table
    {
        return $this->fds;
    }

    /**
     * Set connection information in the FDs table
     */
    public function setConnectionInfo(int $fd, string $name): void
    {
        if ($this->fds) {
            $this->fds->set((string)$fd, [
                'fd' => $fd,
                'name' => $name
            ]);
        }
    }

    /**
     * Remove connection information from the FDs table
     */
    public function removeConnectionInfo(int $fd): void
    {
        if ($this->fds) {
            $this->fds->del((string)$fd);
        }
    }

    /**
     * Get connection name from FDs table
     */
    public function getConnectionName(int $fd): ?string
    {
        if ($this->fds && $this->fds->exists((string)$fd)) {
            $data = $this->fds->get((string)$fd);
            return $data['name'];
        }

        return null;
    }
}