<?php

namespace Ody\Websocket\Channel;

/**
 * Channel Auth Generator
 *
 * Generates authentication signatures for private and presence channels
 */
class ChannelAuthGenerator
{
    /**
     * @var string Application secret key
     */
    protected string $secretKey;

    /**
     * ChannelAuthGenerator constructor
     *
     * @param string $secretKey Application secret key
     */
    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * Generate auth signature for a presence channel
     *
     * @param string $socketId Unique socket ID
     * @param string $channel Channel name
     * @param array $userData User data for presence channel
     * @return string Auth signature
     */
    public function generatePresenceAuth(string $socketId, string $channel, array $userData): string
    {
        // Encode user data as JSON
        $encodedData = json_encode($userData);

        // Create the string to sign
        $string = $socketId . ':' . $channel . ':' . $encodedData;

        // Generate the HMAC
        $signature = hash_hmac('sha256', $string, $this->secretKey);

        // Return the auth signature
        return $signature . ':' . $encodedData;
    }

    /**
     * Validate an auth signature for a private channel
     *
     * @param string $socketId Unique socket ID
     * @param string $channel Channel name
     * @param string $authSignature Auth signature to validate
     * @return bool True if valid
     */
    public function validatePrivateAuth(string $socketId, string $channel, string $authSignature): bool
    {
        $expectedSignature = $this->generatePrivateAuth($socketId, $channel);
        return hash_equals($expectedSignature, $authSignature);
    }

    /**
     * Generate auth signature for a private channel
     *
     * @param string $socketId Unique socket ID
     * @param string $channel Channel name
     * @return string Auth signature
     */
    public function generatePrivateAuth(string $socketId, string $channel): string
    {
        $string = $socketId . ':' . $channel;
        return hash_hmac('sha256', $string, $this->secretKey);
    }

    /**
     * Validate an auth signature for a presence channel
     *
     * @param string $socketId Unique socket ID
     * @param string $channel Channel name
     * @param string $authSignature Auth signature to validate
     * @return array|false User data if valid, false otherwise
     */
    public function validatePresenceAuth(string $socketId, string $channel, string $authSignature)
    {
        // Split signature and data
        $parts = explode(':', $authSignature, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$signature, $encodedData] = $parts;

        // Decode user data
        $userData = json_decode($encodedData, true);
        if (!$userData) {
            return false;
        }

        // Validate signature
        $expectedSignature = hash_hmac('sha256', $socketId . ':' . $channel . ':' . $encodedData, $this->secretKey);

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        return $userData;
    }
}