<?php
/*
 * This file is part of ODY framework
 *
 * @link https://ody.dev
 * @documentation https://ody.dev/docs
 * @license https://github.com/ody-dev/ody-core/blob/master/LICENSE
 */

use Ody\Websocket\WsEvent;

return [
    'host' => env('WEBSOCKET_HOST', '127.0.0.1'),
    'port' => env('WEBSOCKET_PORT', 9502),
    'secret_key' => env('WEBSOCKET_SECRET_KEY', '123123123'),
    'sock_type' => SWOOLE_SOCK_TCP,
    'enable_api' => true,
    'callbacks' => [
        WsEvent::ON_HAND_SHAKE => [\Ody\Websocket\WsServerCallbacks::class, 'onHandShake'],
        WsEvent::ON_WORKER_START => [\Ody\Websocket\WsServerCallbacks::class, 'onWorkerStart'],
        WsEvent::ON_MESSAGE => [\Ody\Websocket\WsServerCallbacks::class, 'onMessage'],
        WsEvent::ON_CLOSE => [\Ody\Websocket\WsServerCallbacks::class, 'onClose'],
        WsEvent::ON_DISCONNECT => [\Ody\Websocket\WsServerCallbacks::class, 'onDisconnect'],
        // if enable_api is set to true, the Application class will be
        // bootstrapped and expose a REST API. This enables all normal
        // functionality of ODY framework including route middleware.
        WsEvent::ON_REQUEST => [\Ody\Websocket\WsServerCallbacks::class, 'onRequest'],
    ],

    "additional" => [
        "worker_num" => env('WEBSOCKET_WORKER_COUNT', swoole_cpu_num() * 2),
        /*
         * log level
         * SWOOLE_LOG_DEBUG (default)
         * SWOOLE_LOG_TRACE
         * SWOOLE_LOG_INFO
         * SWOOLE_LOG_NOTICE
         * SWOOLE_LOG_WARNING
         * SWOOLE_LOG_ERROR
         */
        'log_level' => SWOOLE_LOG_DEBUG,
        'log_file' => base_path('storage/logs/ody_websockets.log'),
    ],

    'runtime' => [
        'enable_coroutine' => true,
        /**
         * SWOOLE_HOOK_TCP - Enable TCP hook only
         * SWOOLE_HOOK_TCP | SWOOLE_HOOK_UDP | SWOOLE_HOOK_SOCKETS - Enable TCP, UDP and socket hooks
         * SWOOLE_HOOK_ALL - Enable all runtime hooks
         * SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_FILE ^ SWOOLE_HOOK_STDIO - Enable all runtime hooks except file and stdio hooks
         * 0 - Disable runtime hooks
         */
        'hook_flag' => SWOOLE_HOOK_ALL,
    ],

    'middleware' => [
        // Common middleware applied to both pipelines
        'global' => [
            \Ody\Websocket\Middleware\LoggingMiddleware::class,
//            \Ody\Websocket\Middleware\MetricsMiddleware::class,
        ],

        // Handshake-specific middleware
        'handshake' => [
            \Ody\Websocket\Middleware\AuthenticationMiddleware::class,
//            \Ody\Websocket\Middleware\OriginValidationMiddleware::class,
//            \Ody\Websocket\Middleware\ConnectionRateLimitMiddleware::class,
        ],

        // Message-specific middleware
        'message' => [
//            \Ody\Websocket\Middleware\MessageRateLimitMiddleware::class,
//            \Ody\Websocket\Middleware\MessageValidationMiddleware::class,
//            \Ody\Websocket\Middleware\MessageSizeLimitMiddleware::class,
        ],
    ],

    // Middleware parameters
    'middleware_params' => [
        // Authentication middleware parameters
        \Ody\Websocket\Middleware\AuthenticationMiddleware::class => [
            'header_name' => 'sec-websocket-protocol',
        ],

//        // Rate limit middleware parameters
//        \Ody\Websocket\Middleware\MessageRateLimitMiddleware::class => [
//            'messages_per_minute' => env('WEBSOCKET_RATE_LIMIT', 60),
//            'table_size' => 1024,
//        ],
//
//        // Origin validation middleware parameters
//        \Ody\Websocket\Middleware\OriginValidationMiddleware::class => [
//            'allowed_origins' => [
//                env('APP_URL', 'http://localhost'),
//                // Add additional allowed origins
//            ],
//        ],
    ],

    'rate_limits' => [
        'messages_per_minute' => env('WEBSOCKET_RATE_LIMIT', 60),
        'connections_per_minute' => env('WEBSOCKET_CONNECTION_LIMIT', 10),
    ],

    'ssl' => [
        'ssl_cert_file' => null,
        'ssl_key_file' => null,
    ],
];