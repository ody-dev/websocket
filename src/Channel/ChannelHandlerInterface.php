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
 * Channel Handler Interface
 *
 * Defines the contract for channel type handlers
 */
interface ChannelHandlerInterface
{
    /**
     * Authorize a subscription request
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @param array $data Additional subscription data
     * @return bool True if authorized
     */
    public function authorize(int $fd, string $channel, array $data): bool;

    /**
     * Handle client subscription to a channel
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @param array $data Additional subscription data
     * @return bool Success status
     */
    public function onSubscribe(int $fd, string $channel, array $data): bool;

    /**
     * Handle client unsubscription from a channel
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @return bool Success status
     */
    public function onUnsubscribe(int $fd, string $channel): bool;

    /**
     * Check if a client is allowed to publish to a channel
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $data Event data
     * @return bool True if authorized
     */
    public function canClientPublish(int $fd, string $channel, string $event, array $data): bool;

    /**
     * Handle a client-triggered event on the channel
     *
     * @param int $fd Client connection ID
     * @param string $channel Channel name
     * @param string $event Event name
     * @param array $data Event data
     * @param ChannelManager $manager Channel manager instance
     * @return mixed
     */
    public function onClientEvent(int $fd, string $channel, string $event, array $data, ChannelManager $manager);
}