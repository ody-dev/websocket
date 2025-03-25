<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Websocket\Channel;

use Ody\Websocket\Channel\Exceptions\ChannelException;

/**
 * Channel Client
 *
 * Server-side client for publishing to channels
 */
class ChannelClient
{
    /**
     * @var ChannelManager The channel manager instance
     */
    protected ChannelManager $channelManager;

    /**
     * ChannelClient constructor
     *
     * @param ChannelManager $channelManager
     */
    public function __construct(ChannelManager $channelManager)
    {
        $this->channelManager = $channelManager;
    }

    /**
     * Publish an event to a channel
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $data Event data
     * @return int Number of clients message was sent to
     * @throws ChannelException If channel doesn't exist
     */
    public function publish(string $channel, string $event, array $data): int
    {
        return $this->channelManager->broadcast($channel, $event, $data);
    }

    /**
     * Publish an event to multiple channels
     *
     * @param array $channels List of channel names
     * @param string $event Event name
     * @param array $data Event data
     * @return int Total number of clients message was sent to
     */
    public function publishToChannels(array $channels, string $event, array $data): int
    {
        return $this->channelManager->broadcastToChannels($channels, $event, $data);
    }

    /**
     * Send a message to a specific client
     *
     * @param int $fd Client connection ID
     * @param string $event Event name
     * @param array $data Event data
     * @param string|null $channel Optional channel context
     * @return bool Success status
     */
    public function whisper(int $fd, string $event, array $data, ?string $channel = null): bool
    {
        return $this->channelManager->whisper($fd, $event, $data, $channel);
    }

    /**
     * Get subscribers for a channel
     *
     * @param string $channel Channel name
     * @return array List of client FDs
     */
    public function getSubscribers(string $channel): array
    {
        return $this->channelManager->getChannelSubscribers($channel);
    }

    /**
     * Check if a channel exists
     *
     * @param string $channel Channel name
     * @return bool True if channel exists
     */
    public function channelExists(string $channel): bool
    {
        $channels = $this->channelManager->getChannels();
        return isset($channels[$channel]);
    }

    /**
     * Get all channels
     *
     * @return array List of channel information
     */
    public function getChannels(): array
    {
        return $this->channelManager->getChannels();
    }

    /**
     * Check if a client is subscribed to a channel
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @return bool True if subscribed
     */
    public function isSubscribed(int $fd, string $channel): bool
    {
        return $this->channelManager->isSubscribed($fd, $channel);
    }
}