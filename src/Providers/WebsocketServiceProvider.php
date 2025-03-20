<?php
namespace Ody\Websocket\Providers;

use Ody\Foundation\Providers\ServiceProvider;
use Ody\Websocket\Channel\ChannelClient;
use Ody\Websocket\Channel\ChannelManager;
use Ody\Websocket\Commands\StartCommand;
use Ody\Websocket\Commands\StopCommand;
use Ody\Websocket\WsServerCallbacks;

class WebsocketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register the channel client as a singleton
        $this->container->singleton(ChannelClient::class, function ($container) {
            // Get the channel manager from the WebSocket server
            $channelManager = WsServerCallbacks::getChannelManager();

            // If no channel manager is available yet, create a new one
            if (!$channelManager) {
                $channelManager = $container->has(ChannelManager::class)
                    ? $container->make(ChannelManager::class)
                    : null;
            }

            // We can't create a channel client without a channel manager
            if (!$channelManager) {
                throw new \RuntimeException('Cannot create ChannelClient without a ChannelManager');
            }

            return new ChannelClient($channelManager);
        });

        // Register an alias for the channel client
        $this->container->alias(ChannelClient::class, 'websocket.channel');
    }

    public function boot(): void
    {
        $this->loadRoutes(__dir__ . '/../routes.php');

        $this->registerCommands([
            StartCommand::class,
            StopCommand::class,
        ]);
    }
}