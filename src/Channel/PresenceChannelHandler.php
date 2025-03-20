<?php

namespace Ody\Websocket\Channel;

use Swoole\Table;

/**
 * Presence Channel Handler
 *
 * Handles presence channels that track user presence
 */
class PresenceChannelHandler implements ChannelHandlerInterface
{
    /**
     * @var Table Store of user information for each presence channel
     */
    protected Table $presenceInfo;

    /**
     * PresenceChannelHandler constructor
     */
    public function __construct()
    {
        // Create a table to store presence information
        $this->presenceInfo = new Table(10240);
        $this->presenceInfo->column('fd', Table::TYPE_INT, 8);
        $this->presenceInfo->column('channel', Table::TYPE_STRING, 64);
        $this->presenceInfo->column('user_id', Table::TYPE_STRING, 64);
        $this->presenceInfo->column('user_info', Table::TYPE_STRING, 1024); // JSON user data
        $this->presenceInfo->create();
    }

    /**
     * {@inheritdoc}
     */
    /**
     * {@inheritdoc}
     */
    public function authorize(int $fd, string $channel, array $data): bool
    {
        // Check that we have user info and auth data
        if (!isset($data['auth']) || empty($data['auth'])) {
            return false;
        }

        // Ensure user info is provided
        if (!isset($data['channel_data']) || empty($data['channel_data'])) {
            return false;
        }

        // Validate channel_data contains user_id
        $channelData = is_string($data['channel_data'])
            ? json_decode($data['channel_data'], true)
            : $data['channel_data'];

        if (!isset($channelData['user_id']) || empty($channelData['user_id'])) {
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
        // Extract user data from subscription
        $channelData = is_string($data['channel_data'])
            ? json_decode($data['channel_data'], true)
            : $data['channel_data'];

        $userId = (string)$channelData['user_id'];
        $userInfo = json_encode($channelData['user_info'] ?? []);

        // Store presence information
        $presenceKey = $this->getPresenceKey($fd, $channel);
        $this->presenceInfo->set($presenceKey, [
            'fd' => $fd,
            'channel' => $channel,
            'user_id' => $userId,
            'user_info' => $userInfo
        ]);

        // Get current members list for the channel
        $members = $this->getChannelMembers($channel);

        // Send presence subscription succeeded with members data
        // We'll use the channel manager for this in the actual implementation
        return true;
    }

    /**
     * Get a unique key for presence info
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @return string Unique key
     */
    protected function getPresenceKey(int $fd, string $channel): string
    {
        return $fd . ':' . $channel;
    }

    /**
     * Get all members of a channel
     *
     * @param string $channel Channel name
     * @return array Array of members with user_id and user_info
     */
    public function getChannelMembers(string $channel): array
    {
        $members = [];

        foreach ($this->presenceInfo as $key => $row) {
            if ($row['channel'] === $channel) {
                $members[] = [
                    'user_id' => $row['user_id'],
                    'user_info' => json_decode($row['user_info'], true)
                ];
            }
        }

        return $members;
    }

    /**
     * {@inheritdoc}
     */
    public function onUnsubscribe(int $fd, string $channel): bool
    {
        // Remove presence information
        $presenceKey = $this->getPresenceKey($fd, $channel);

        // Get user info before removing
        $userInfo = null;
        if ($this->presenceInfo->exists($presenceKey)) {
            $row = $this->presenceInfo->get($presenceKey);
            $userInfo = [
                'user_id' => $row['user_id'],
                'user_info' => json_decode($row['user_info'], true)
            ];

            // Remove from presence table
            $this->presenceInfo->del($presenceKey);
        }

        // If we had user info, broadcast member_removed event
        // We'll use the channel manager for this in the actual implementation

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canClientPublish(int $fd, string $channel, string $event, array $data): bool
    {
        // Presence channels allow clients to publish
        // This can be customized with more granular permissions if needed
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onClientEvent(int $fd, string $channel, string $event, array $data, ChannelManager $manager)
    {
        // For client events, prefix with 'client-'
        if (strpos($event, 'client-') !== 0) {
            $event = 'client-' . $event;
        }

        // Get user information
        $presenceKey = $this->getPresenceKey($fd, $channel);
        if ($this->presenceInfo->exists($presenceKey)) {
            $row = $this->presenceInfo->get($presenceKey);

            // Add user information to the event data
            $data['user_id'] = $row['user_id'];
            $userInfo = json_decode($row['user_info'], true);
            $data['user_info'] = $userInfo;
        }

        // Broadcast to all subscribers except sender
        return $manager->broadcast($channel, $event, $data, $fd);
    }

    /**
     * Handle member added event
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @param ChannelManager $manager Channel manager
     * @return void
     */
    public function handleMemberAdded(int $fd, string $channel, ChannelManager $manager): void
    {
        // Get user information
        $presenceKey = $this->getPresenceKey($fd, $channel);
        if (!$this->presenceInfo->exists($presenceKey)) {
            return;
        }

        $row = $this->presenceInfo->get($presenceKey);
        $userId = $row['user_id'];
        $userInfo = json_decode($row['user_info'], true);

        // Broadcast member added event to all subscribers except the new member
        $manager->broadcast($channel, 'member_added', [
            'user_id' => $userId,
            'user_info' => $userInfo
        ], $fd);
    }

    /**
     * Handle member removed event
     *
     * @param string $channel Channel name
     * @param string $userId User ID
     * @param array $userInfo User information
     * @param ChannelManager $manager Channel manager
     * @return void
     */
    public function handleMemberRemoved(string $channel, string $userId, array $userInfo, ChannelManager $manager): void
    {
        // Broadcast member removed event to all subscribers
        $manager->broadcast($channel, 'member_removed', [
            'user_id' => $userId,
            'user_info' => $userInfo
        ]);
    }
}