<?php
use Ody\Swoole\Event;

return [
    'host' => env('WEBSOCKET_HOST', '127.0.0.1'),
    'port' => env('WEBSOCKET_PORT', 9502),
    'sock_type' => SWOOLE_SOCK_TCP,
    'callbacks' => [
        Event::ON_HAND_SHAKE => [\Ody\Websocket\Server::class, 'onHandShake'],
        Event::ON_MESSAGE => [\Ody\Websocket\Server::class, 'onMessage'],
        Event::ON_CLOSE => [\Ody\Websocket\Server::class, 'onClose'],
        Event::ON_REQUEST => [\Ody\Websocket\Server::class, 'onRequest'],
        Event::ON_DISCONNECT => [\Ody\Websocket\Server::class, 'onDisconnect'],
    ],
    'secret_key' => env('WEBSOCKET_SECRET_KEY', '123123123'),
    "additional" => [
        "worker_num" => env('WEBSOCKET_WORKER_COUNT', cpu_count() * 2),
        /*
         * log level
         * SWOOLE_LOG_DEBUG (default)
         * SWOOLE_LOG_TRACE
         * SWOOLE_LOG_INFO
         * SWOOLE_LOG_NOTICE
         * SWOOLE_LOG_WARNING
         * SWOOLE_LOG_ERROR
         */
        'log_level' => SWOOLE_LOG_DEBUG ,
        'log_file' => storagePath('logs/ody_websockets.log') ,
    ]
];