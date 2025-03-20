<?php
namespace Ody\Websocket\Providers;

use Ody\Foundation\Providers\ServiceProvider;
use Ody\Websocket\Commands\StartCommand;
use Ody\Websocket\Commands\StopCommand;

class WebsocketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->registerCommands([
            StartCommand::class,
            StopCommand::class,
        ]);
    }
}