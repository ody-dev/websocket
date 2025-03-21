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
    'secret_key' => env('WEBSOCKET_SECRET_KEY', '123123123'),
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
    'ssl' => [
        'ssl_cert_file' => null,
        'ssl_key_file' => null,
    ],
];