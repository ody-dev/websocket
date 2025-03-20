<?php

namespace Ody\Websocket\Channel;

use Ody\Websocket\Channel\Exceptions\ChannelException;
use Ody\Websocket\Channel\Exceptions\SubscriptionException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

/**
 * Channel Manager
 *
 * Manages WebSocket channels and message distribution
 */
class ChannelManager
{
    /**
     * @var Server The WebSocket server instance
     */
    protected Server $server;

    /**
     * @var Table Channel subscription storage
     */
    protected Table $subscriptions;

    /**
     * @var Table Channel information storage
     */
    protected Table $channels;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var array Event handlers for each channel type
     */
    protected array $channelHandlers = [];

    /**
     * ChannelManager constructor
     *
     * @param Server $server The WebSocket server instance
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(Server $server, ?LoggerInterface $logger = null)
    {
        $this->server = $server;
        $this->logger = $logger ?? new NullLogger();

        $this->initializeTables();
        $this->registerDefaultHandlers();
    }

    /**
     * Initialize Swoole tables for storing channel data
     */
    protected function initializeTables(): void
    {
        // Table to store channel subscriptions (fd -> channels)
        $this->subscriptions = new Table(10240); // Support up to 10k connections
        $this->subscriptions->column('fd', Table::TYPE_INT, 8);
        $this->subscriptions->column('channels', Table::TYPE_STRING, 1024); // JSON array of channels
        $this->subscriptions->create();

        // Table to store channel information (channel -> data)
        $this->channels = new Table(1024); // Support up to 1k channels
        $this->channels->column('name', Table::TYPE_STRING, 64);
        $this->channels->column('type', Table::TYPE_STRING, 16); // public, private, presence
        $this->channels->column('subscribers', Table::TYPE_INT, 8); // Count of subscribers
        $this->channels->column('metadata', Table::TYPE_STRING, 1024); // JSON metadata
        $this->channels->create();
    }

    /**
     * Register default channel handlers
     */
    protected function registerDefaultHandlers(): void
    {
        // Register handlers for different channel types
        $this->registerChannelHandler('public', new PublicChannelHandler());
        $this->registerChannelHandler('private', new PrivateChannelHandler());
        $this->registerChannelHandler('presence', new PresenceChannelHandler());
    }

    /**
     * Register a channel handler
     *
     * @param string $channelType Channel type
     * @param ChannelHandlerInterface $handler Handler instance
     * @return self
     */
    public function registerChannelHandler(string $channelType, ChannelHandlerInterface $handler): self
    {
        $this->channelHandlers[$channelType] = $handler;
        return $this;
    }

    /**
     * Handle client disconnection
     *
     * @param int $fd Client connection ID
     * @return void
     */
    public function handleDisconnection(int $fd): void
    {
        // Get all channels the client is subscribed to
        $channels = $this->getSubscriptions($fd);

        // Unsubscribe from each channel
        foreach ($channels as $channel) {
            $this->unsubscribe($fd, $channel);
        }

        // Remove client from subscriptions table
        $this->subscriptions->del((string)$fd);

        $this->logger->info("Removed all subscriptions for disconnected client {$fd}");
    }

    /**
     * Get all subscriptions for a client
     *
     * @param int $fd Client connection ID
     * @return array List of channel names
     */
    protected function getSubscriptions(int $fd): array
    {
        $fdKey = (string)$fd;

        if (!$this->subscriptions->exists($fdKey)) {
            return [];
        }

        $row = $this->subscriptions->get($fdKey);
        return json_decode($row['channels'], true) ?: [];
    }

    /**
     * Handle unsubscription from a channel
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @return bool Success status
     */
    public function unsubscribe(int $fd, string $channel): bool
    {
        // Remove channel from client's subscriptions
        $this->removeSubscription($fd, $channel);

        // Update channel info
        $this->updateChannelCounts($channel);

        // Determine channel type and get handler
        $channelType = $this->getChannelType($channel);
        if (isset($this->channelHandlers[$channelType])) {
            $handler = $this->channelHandlers[$channelType];
            $handler->onUnsubscribe($fd, $channel);
        }

        $this->logger->info("Client {$fd} unsubscribed from {$channel}");

        return true;
    }

    /**
     * Remove a channel subscription for a client
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @return void
     */
    protected function removeSubscription(int $fd, string $channel): void
    {
        $fdKey = (string)$fd;

        // Get existing subscriptions if any
        if (!$this->subscriptions->exists($fdKey)) {
            return;
        }

        $row = $this->subscriptions->get($fdKey);
        $subscriptions = json_decode($row['channels'], true) ?: [];

        // Remove channel if subscribed
        $index = array_search($channel, $subscriptions);
        if ($index !== false) {
            array_splice($subscriptions, $index, 1);

            // Update subscriptions table
            $this->subscriptions->set($fdKey, [
                'fd' => $fd,
                'channels' => json_encode($subscriptions)
            ]);

            // Update channel counts
            $this->updateChannelCounts($channel);
        }
    }

    /**
     * Broadcast a message to multiple channels
     *
     * @param array $channels List of channel names
     * @param string $event Event name
     * @param array $payload Message payload
     * @param int|null $except Optional client to exclude
     * @return int Number of clients message was sent to
     */
    public function broadcastToChannels(array $channels, string $event, array $payload, ?int $except = null): int
    {
        $totalSent = 0;

        foreach ($channels as $channel) {
            try {
                $totalSent += $this->broadcast($channel, $event, $payload, $except);
            } catch (ChannelException $e) {
                $this->logger->warning("Failed to broadcast to {$channel}: {$e->getMessage()}");
            }
        }

        return $totalSent;
    }

    /**
     * Broadcast a message to a channel
     *
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $payload Message payload
     * @param int|null $except Optional client to exclude
     * @return int Number of clients message was sent to
     * @throws ChannelException If channel doesn't exist
     */
    public function broadcast(string $channel, string $event, array $payload, ?int $except = null): int
    {
        // Check if channel exists
        if (!$this->channels->exists($channel)) {
            $this->logger->warning("Attempted to broadcast to non-existent channel: {$channel}");
            throw new ChannelException("Channel {$channel} does not exist");
        }

        // Format the message
        $message = json_encode([
            'event' => $event,
            'channel' => $channel,
            'data' => $payload
        ]);

        // Get all clients subscribed to this channel
        $subscribers = $this->getChannelSubscribers($channel);
        $sentCount = 0;

        $this->logger->debug("Broadcasting to channel '{$channel}', event '{$event}' with payload: " . json_encode($payload));
        $this->logger->debug("Found " . count($subscribers) . " subscribers: " . implode(', ', $subscribers));

        // Send message to each subscribed client
        foreach ($subscribers as $fd) {
            // Skip the excluded client if specified
            if ($except !== null && $fd === $except) {
                $this->logger->debug("Skipping client {$fd} (excluded)");
                continue;
            }

            // Ensure connection is still active
            if ($this->server->isEstablished($fd)) {
                $this->logger->debug("Sending message to client {$fd}");
                $this->server->push($fd, $message);
                $sentCount++;
            } else {
                $this->logger->debug("Client {$fd} is no longer connected, skipping");
            }
        }

        $this->logger->debug("Successfully sent message to {$sentCount} clients on {$channel}");

        return $sentCount;
    }

    /**
     * Get all subscribers for a channel
     *
     * @param string $channel Channel name
     * @return array List of client FDs
     */
    public function getChannelSubscribers(string $channel): array
    {
        $subscribers = [];

        foreach ($this->subscriptions as $fdKey => $row) {
            $subscriptions = json_decode($row['channels'], true) ?: [];
            if (in_array($channel, $subscriptions)) {
                $subscribers[] = (int)$row['fd'];
            }
        }

        return $subscribers;
    }

    /**
     * Handle an incoming message from a client
     *
     * @param int $fd Client connection ID
     * @param Frame $frame Message frame
     * @return void
     */
    public function handleClientMessage(int $fd, Frame $frame): void
    {
        try {
            // Parse message JSON
            $message = json_decode($frame->data, true);

            if (!$message || !isset($message['event'])) {
                throw new \InvalidArgumentException("Invalid message format");
            }

            // Handle different event types
            switch ($message['event']) {
                case 'subscribe':
                    $this->handleSubscribeMessage($fd, $message);
                    break;

                case 'unsubscribe':
                    $this->handleUnsubscribeMessage($fd, $message);
                    break;

                case 'message':
                    $this->handleClientPublishMessage($fd, $message);
                    break;

                default:
                    // For custom events, check if this is a channel event
                    if (isset($message['channel'])) {
                        $this->handleChannelEvent($fd, $message);
                    } else {
                        $this->logger->warning("Unhandled event type: {$message['event']}");
                    }
                    break;
            }
        } catch (\Throwable $e) {
            $this->logger->error("Error handling client message: " . $e->getMessage(), [
                'fd' => $fd,
                'data' => $frame->data,
                'exception' => get_class($e)
            ]);

            // Send error back to client
            $this->whisper($fd, 'error', [
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
    }

    /**
     * Handle subscribe message from client
     *
     * @param int $fd Client connection ID
     * @param array $message Message data
     * @return void
     */
    protected function handleSubscribeMessage(int $fd, array $message): void
    {
        if (!isset($message['channel'])) {
            throw new \InvalidArgumentException("Channel name is required for subscription");
        }

        $channel = $message['channel'];
        $data = $message['data'] ?? [];

        try {
            $this->subscribe($fd, $channel, $data);

            // Send subscription success message
            $this->whisper($fd, 'subscription_succeeded', [
                'channel' => $channel
            ]);
        } catch (SubscriptionException $e) {
            // Send subscription error message
            $this->whisper($fd, 'subscription_error', [
                'channel' => $channel,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle a subscription request from a client
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @param array $data Additional subscription data
     * @return bool Success status
     * @throws SubscriptionException If subscription fails
     */
    public function subscribe(int $fd, string $channel, array $data = []): bool
    {
        // Determine channel type from name
        $channelType = $this->getChannelType($channel);

        // Check if handler exists for this channel type
        if (!isset($this->channelHandlers[$channelType])) {
            throw new SubscriptionException("Unsupported channel type: {$channelType}");
        }

        // Let the handler authorize the subscription
        $handler = $this->channelHandlers[$channelType];
        if (!$handler->authorize($fd, $channel, $data)) {
            throw new SubscriptionException("Subscription to {$channel} not authorized");
        }

        // Add channel to client's subscriptions
        $this->addSubscription($fd, $channel);

        // Create or update channel info
        $this->updateChannelInfo($channel, $channelType);

        // Let the handler handle subscription events
        $result = $handler->onSubscribe($fd, $channel, $data);

        $this->logger->info("Client {$fd} subscribed to {$channel}");

        return $result;
    }

    /**
     * Determine channel type from name
     *
     * @param string $channel Channel name
     * @return string Channel type (public, private, presence)
     */
    protected function getChannelType(string $channel): string
    {
        if (strpos($channel, 'presence-') === 0) {
            return 'presence';
        }

        if (strpos($channel, 'private-') === 0) {
            return 'private';
        }

        return 'public';
    }

    /**
     * Add a channel subscription for a client
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @return void
     */
    protected function addSubscription(int $fd, string $channel): void
    {
        $fdKey = (string)$fd;
        $subscriptions = [];

        // Get existing subscriptions if any
        if ($this->subscriptions->exists($fdKey)) {
            $row = $this->subscriptions->get($fdKey);
            $subscriptions = json_decode($row['channels'], true) ?: [];
        }

        // Add channel if not already subscribed
        if (!in_array($channel, $subscriptions)) {
            $subscriptions[] = $channel;

            // Update subscriptions table
            $this->subscriptions->set($fdKey, [
                'fd' => $fd,
                'channels' => json_encode($subscriptions)
            ]);

            // Update channel counts
            $this->updateChannelCounts($channel);
        }
    }

    /**
     * Update channel subscriber counts
     *
     * @param string $channel Channel name
     * @return void
     */
    protected function updateChannelCounts(string $channel): void
    {
        // Count subscribers for this channel
        $count = 0;

        foreach ($this->subscriptions as $fdKey => $row) {
            $subscriptions = json_decode($row['channels'], true) ?: [];
            if (in_array($channel, $subscriptions)) {
                $count++;
            }
        }

        // Update channel info
        if ($this->channels->exists($channel)) {
            $row = $this->channels->get($channel);

            if ($count > 0) {
                $this->channels->set($channel, [
                    'name' => $channel,
                    'type' => $row['type'],
                    'subscribers' => $count,
                    'metadata' => $row['metadata']
                ]);
            } else {
                // Remove channel if no subscribers
                $this->channels->del($channel);
            }
        }
    }

    /**
     * Update channel information
     *
     * @param string $channel Channel name
     * @param string $type Channel type
     * @param array $metadata Additional metadata
     * @return void
     */
    protected function updateChannelInfo(string $channel, string $type, array $metadata = []): void
    {
        // Create or update channel info
        if (!$this->channels->exists($channel)) {
            $this->channels->set($channel, [
                'name' => $channel,
                'type' => $type,
                'subscribers' => 1,
                'metadata' => json_encode($metadata)
            ]);
        } else {
            $row = $this->channels->get($channel);
            $currentMetadata = json_decode($row['metadata'], true) ?: [];
            $updatedMetadata = array_merge($currentMetadata, $metadata);

            $this->channels->set($channel, [
                'name' => $channel,
                'type' => $type,
                'subscribers' => $row['subscribers'],
                'metadata' => json_encode($updatedMetadata)
            ]);
        }
    }

    /**
     * Send a message to a specific client
     *
     * @param int $fd Client connection ID
     * @param string $event Event name
     * @param array $payload Message payload
     * @param string|null $channel Optional channel context
     * @return bool Success status
     */
    public function whisper(int $fd, string $event, array $payload, ?string $channel = null): bool
    {
        // Skip if client is not connected
        if (!$this->server->isEstablished($fd)) {
            return false;
        }

        // Format the message
        $message = json_encode([
            'event' => $event,
            'data' => $payload,
            'channel' => $channel
        ]);

        // Send message to the client
        $this->server->push($fd, $message);

        $this->logger->debug("Whispered {$event} to client {$fd}" . ($channel ? " on {$channel}" : ""));

        return true;
    }

    /**
     * Handle unsubscribe message from client
     *
     * @param int $fd Client connection ID
     * @param array $message Message data
     * @return void
     */
    protected function handleUnsubscribeMessage(int $fd, array $message): void
    {
        if (!isset($message['channel'])) {
            throw new \InvalidArgumentException("Channel name is required for unsubscription");
        }

        $channel = $message['channel'];

        if ($this->unsubscribe($fd, $channel)) {
            // Send unsubscription confirmation
            $this->whisper($fd, 'unsubscribed', [
                'channel' => $channel
            ]);
        }
    }

    /**
     * Handle client publish message
     *
     * @param int $fd Client connection ID
     * @param array $message Message data
     * @return void
     */
    protected function handleClientPublishMessage(int $fd, array $message): void
    {
        if (!isset($message['channel']) || !isset($message['data'])) {
            throw new \InvalidArgumentException("Channel and data are required for publishing");
        }

        $channel = $message['channel'];
        $event = $message['name'] ?? 'message';
        $payload = $message['data'];

        // Check if client is subscribed to the channel
        if (!$this->isSubscribed($fd, $channel)) {
            throw new ChannelException("Client is not subscribed to {$channel}");
        }

        // Determine channel type
        $channelType = $this->getChannelType($channel);
        $handler = $this->channelHandlers[$channelType] ?? null;

        // Check if clients can publish to this channel
        if ($handler && !$handler->canClientPublish($fd, $channel, $event, $payload)) {
            throw new ChannelException("Client is not authorized to publish to {$channel}");
        }

        // Broadcast the message
        $this->broadcast($channel, $event, $payload, $fd); // Exclude sender
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
        $subscriptions = $this->getSubscriptions($fd);
        return in_array($channel, $subscriptions);
    }

    /**
     * Handle custom channel event
     *
     * @param int $fd Client connection ID
     * @param array $message Message data
     * @return void
     */
    protected function handleChannelEvent(int $fd, array $message): void
    {
        $channel = $message['channel'];
        $event = $message['event'];
        $payload = $message['data'] ?? [];

        // Check if client is subscribed to the channel
        if (!$this->isSubscribed($fd, $channel)) {
            throw new ChannelException("Client is not subscribed to {$channel}");
        }

        // Determine channel type
        $channelType = $this->getChannelType($channel);
        $handler = $this->channelHandlers[$channelType] ?? null;

        // Process channel event if handler exists
        if ($handler) {
            $handler->onClientEvent($fd, $channel, $event, $payload, $this);
        }
    }

    /**
     * Get all channels
     *
     * @return array List of channel information
     */
    public function getChannels(): array
    {
        $channels = [];

        foreach ($this->channels as $channelName => $row) {
            $channels[$channelName] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'subscribers' => $row['subscribers'],
                'metadata' => json_decode($row['metadata'], true) ?: []
            ];
        }

        return $channels;
    }
}