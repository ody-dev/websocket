<?php
namespace Ody\Websocket\ServiceProviders;

use Ody\Websocket\Commands\StartCommand;
use Ody\Websocket\Commands\StopCommand;

class WebsocketServiceProvider
{
    public function commands(): array
    {
        return [
            StartCommand::class,
            StopCommand::class,
        ];
    }
}