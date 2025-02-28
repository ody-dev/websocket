<?php
namespace Ody\Swoole\Websockets;

use Swoole\Coroutine\Http\Client;
use function Swoole\Coroutine\run;

require_once "../../vendor/autoload.php";

run(function () {
    $cli = new Client('127.0.0.1', 9502);
    $cli->upgrade('/');
    $cli->push('hello', WEBSOCKET_OPCODE_PING);
    $frame = $cli->recv();
});