<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Websocket\Channel;

/**
 * Public Channel Handler
 *
 * Handles public channels that are open to all clients
 */
class PublicChannelHandler implements ChannelHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function authorize(int $fd, string $channel, array $data): bool
    {
        // Public channels are open to everyone
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onSubscribe(int $fd, string $channel, array $data): bool
    {
        // No special handling for public channel subscriptions
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onUnsubscribe(int $fd, string $channel): bool
    {
        // No special handling for public channel unsubscriptions
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canClientPublish(int $fd, string $channel, string $event, array $data): bool
    {
        // Allow client-to-client events in public channels
        // This can be restricted for specific events if needed
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onClientEvent(int $fd, string $channel, string $event, array $data, ChannelManager $manager)
    {
        // By default, broadcast the event to all subscribers except the sender
        return $manager->broadcast($channel, $event, $data, $fd);
    }
}