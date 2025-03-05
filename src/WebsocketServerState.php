<?php

namespace Ody\Websocket;

use Ody\Swoole\ServerState;

class WebsocketServerState extends ServerState
{
    /**
     * @var WebsocketServerState|null
     */
    protected static ?self $instance = null;

    protected string $serverType = 'websocketServer';

    /**
     * @var string
     */
    protected readonly string $path;

    /**
     * @return self
     */
    public static function getInstance(): self
    {
        if (isset(self::$instance)) {
            return self::$instance;
        }

        return self::$instance = new self();
    }

    public function websocketServerIsRunning(): bool
    {
        $managerProcessId = $this->getManagerProcessId();
        $masterProcessId = $this->getMasterProcessId();
        if (
            !is_null($managerProcessId) &&
            !is_null($masterProcessId)
        ){
            return (
                posix_kill($managerProcessId, SIG_DFL) &&
                posix_kill($masterProcessId, SIG_DFL)
            );
        }

        return false;
    }
}