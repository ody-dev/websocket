<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Websocket\Facades;

use Ody\Foundation\Facades\Facade;
use Ody\Websocket\Channel\ChannelClient;

/**
 * @method static int publish(string $channel, string $event, array $data)
 * @method static int publishToChannels(array $channels, string $event, array $data)
 * @method static bool whisper(int $fd, string $event, array $data, ?string $channel = null)
 * @method static array getChannels()
 * @method static array getSubscribers(string $channel)
 * @method static bool channelExists(string $channel)
 * @method static bool isSubscribed(int $fd, string $channel)
 *
 * @see \Ody\Websocket\Channel\ChannelClient
 */
class WebSocket extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return ChannelClient::class;
    }
}