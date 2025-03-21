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

        $this->registerMiddleware();
    }

    protected function registerMiddleware(): void
    {
        $config = config('websocket.middleware');
        $params = config('websocket.middleware_params');

        // Register global middleware (applies to both pipelines)
        foreach ($config['global'] ?? [] as $middlewareClass) {
            $middleware = $this->resolveMiddleware($middlewareClass, $params[$middlewareClass] ?? []);
            WsServerCallbacks::addMiddleware($middleware);
        }

        // Register handshake-specific middleware
        foreach ($config['handshake'] ?? [] as $middlewareClass) {
            $middleware = $this->resolveMiddleware($middlewareClass, $params[$middlewareClass] ?? []);
            WsServerCallbacks::addHandshakeMiddleware($middleware);
        }

        // Register message-specific middleware
        foreach ($config['message'] ?? [] as $middlewareClass) {
            $middleware = $this->resolveMiddleware($middlewareClass, $params[$middlewareClass] ?? []);
            WsServerCallbacks::addMessageMiddleware($middleware);
        }
    }

    /**
     * Resolve a middleware instance from its class name
     *
     * @param string $middlewareClass
     * @param array $params
     * @return object
     */
    protected function resolveMiddleware(string $middlewareClass, array $params = []): object
    {
        // If the middleware is registered in the container, resolve it
        if ($this->container->has($middlewareClass)) {
            return $this->container->make($middlewareClass);
        }

        // Otherwise, create instance with parameters
        if (empty($params)) {
            return new $middlewareClass();
        }

        // Create a reflection class to handle constructor parameters
        $reflector = new \ReflectionClass($middlewareClass);

        // If we have a container, try to resolve dependencies first
        return $reflector->newInstanceArgs($this->resolveDependencies($reflector, $params));
    }

    /**
     * Resolve the constructor dependencies with container and parameters
     *
     * @param \ReflectionClass $reflector
     * @param array $params
     * @return array
     */
    protected function resolveDependencies(\ReflectionClass $reflector, array $params): array
    {
        $constructor = $reflector->getConstructor();

        // If no constructor or no parameters, return the parameters as is
        if (!$constructor) {
            return $params;
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $paramName = $parameter->getName();

            // If we have a value in our params, use it
            if (array_key_exists($paramName, $params)) {
                $dependencies[] = $params[$paramName];
                continue;
            }

            // If parameter has a type, try to resolve it from the container
            if ($parameter->getType() && !$parameter->getType()->isBuiltin()) {
                $typeName = $parameter->getType()->getName();
                if ($this->container->has($typeName)) {
                    $dependencies[] = $this->container->make($typeName);
                    continue;
                }
            }

            // If parameter has a default value, use it
            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }

            // If we can't resolve it but it's optional, pass null
            if ($parameter->isOptional()) {
                $dependencies[] = null;
                continue;
            }

            // We can't resolve this parameter
            throw new \RuntimeException(
                "Unable to resolve parameter '{$paramName}' for middleware '{$reflector->getName()}'"
            );
        }

        return $dependencies;
    }
}