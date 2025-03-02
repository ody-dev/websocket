<?php
declare(strict_types=1);

namespace Ody\Websocket;

class WsEvent
{
    /**
     * Websocket onRequest event.
     */
    public const ON_REQUEST = 'request';

    /**
     * Websocket onDisconnect event.
     */
    public const ON_DISCONNECT = 'disconnect';

    /**
     * Websocket onHandShake event.
     */
    public const ON_HAND_SHAKE = 'handshake';

    /**
     * Websocket onOpen event.
     *
     * TODO: Implement event
     */
//    public const ON_OPEN = 'open';

    /**
     * Websocket onMessage event.
     */
    public const ON_MESSAGE = 'message';

    /**
     * Websocket onClose event.
     */
    public const ON_CLOSE = 'close';
}