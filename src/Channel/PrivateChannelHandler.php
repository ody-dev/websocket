<?php

namespace Ody\Websocket\Channel;

/**
 * Private Channel Handler
 *
 * Handles private channels that require authentication
 */
class PrivateChannelHandler implements ChannelHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function authorize(int $fd, string $channel, array $data): bool
    {
        // In a real implementation, verify authentication
        // For now, we'll allow all connections for testing
        // Later will be replaced with actual auth logic

        // Check for auth signature in data
        if (!isset($data['auth']) || empty($data['auth'])) {
            return false;
        }

        // TODO: Verify signature against server-generated auth token

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onSubscribe(int $fd, string $channel, array $data): bool
    {
        // Private channel subscription was authorized
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onUnsubscribe(int $fd, string $channel): bool
    {
        // No special handling for private channel unsubscriptions
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canClientPublish(int $fd, string $channel, string $event, array $data): bool
    {
        // By default, clients can publish to private channels they're subscribed to
        // This can be customized with more granular permissions if needed
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