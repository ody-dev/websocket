<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Websocket\Providers;

use Ody\Foundation\Providers\ServiceProvider;
use Ody\Websocket\Channel\ChannelClient;
use Ody\Websocket\Commands\StartCommand;
use Ody\Websocket\Commands\StopCommand;
use Ody\Websocket\Middleware\WebSocketMiddlewareInterface;
use Ody\Websocket\WebSocketServer;

class WebsocketServiceProvider extends ServiceProvider
{
    /**
     * @var bool
     */
    private static bool $middlewareRegistered = false;

    public function register(): void
    {
        if ($this->isRunningInConsole()) {
            return;
        }

        // Register the channel client as a singleton
        $this->container->singleton(ChannelClient::class, function ($container) {
            // Get the channel manager from the WebSocket server
            $wsServer = WebSocketServer::getInstance();
            $channelManager = $wsServer->getChannelManager();

            // If no channel manager is available yet, return null or create a new one
            if (!$channelManager) {
                return null;
            }

            return new ChannelClient($channelManager);
        });

        // Register an alias for the channel client
        $this->container->alias(ChannelClient::class, 'websocket.channel');

        // Register WebSocketServer singleton
        $this->container->singleton(WebSocketServer::class, function () {
            return WebSocketServer::getInstance();
        });
    }

    public function boot(): void
    {
        $this->loadRoutes(__dir__ . '/../routes.php');

        // Publish configuration
        $this->publishes([
            __DIR__ . '/../../config/websocket.php' => 'websocket.php'
        ], 'ody/websocket');

        $this->registerCommands([
            StartCommand::class,
            StopCommand::class,
        ]);

        // Only register middleware once per process lifetime
        if (!self::$middlewareRegistered) {
            $this->registerMiddleware();
            self::$middlewareRegistered = true;
        }
    }

    protected function registerMiddleware(): void
    {
        $config = config('websocket.middleware');
        $params = config('websocket.middleware_params');

        $wsServer = WebSocketServer::getInstance();

        // Register global middleware (applies to both pipelines)
        foreach ($config['global'] ?? [] as $middlewareClass) {
            $middleware = $this->resolveMiddleware($middlewareClass, $params[$middlewareClass] ?? []);
            $wsServer->addMiddleware($middleware);
            logger()->debug('Websocket global middleware registered');
        }

        // Register handshake-specific middleware
        foreach ($config['handshake'] ?? [] as $middlewareClass) {
            $middleware = $this->resolveMiddleware($middlewareClass, $params[$middlewareClass] ?? []);
            $wsServer->addHandshakeMiddleware($middleware);
            logger()->debug('Websocket handshake middleware registered');
        }

        // Register message-specific middleware
        foreach ($config['message'] ?? [] as $middlewareClass) {
            $middleware = $this->resolveMiddleware($middlewareClass, $params[$middlewareClass] ?? []);
            $wsServer->addMessageMiddleware($middleware);
            logger()->debug('Websocket message middleware registered');
        }
    }

    /**
     * Resolve a middleware instance from its class name
     *
     * @param string $middlewareClass
     * @param array $params
     * @return WebSocketMiddlewareInterface
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