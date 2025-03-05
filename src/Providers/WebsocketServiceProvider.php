<?php
namespace Ody\Websocket\Providers;

use Ody\Core\Foundation\Providers\ServiceProvider;
use Ody\Websocket\Commands\StartCommand;
use Ody\Websocket\Commands\StopCommand;

class WebsocketServiceProvider extends ServiceProvider
{
    public function register()
    {
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands = [
                StartCommand::class,
                StopCommand::class,
            ];
        }
    }
}