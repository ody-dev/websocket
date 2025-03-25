<?php
/*
 *  This file is part of ODY framework.
 *
 *  @link     https://ody.dev
 *  @document https://ody.dev/docs
 *  @license  https://github.com/ody-dev/ody-foundation/blob/master/LICENSE
 */

namespace Ody\Websocket\Http\Controllers;

use Ody\Foundation\Http\Response;
use Ody\Websocket\Channel\ChannelAuthGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ChannelAuthController
{
    /**
     * @var ChannelAuthGenerator
     */
    protected ChannelAuthGenerator $authGenerator;

    /**
     * ChannelAuthController constructor
     *
     * @param ChannelAuthGenerator $authGenerator
     */
    public function __construct(ChannelAuthGenerator $authGenerator)
    {
        $this->authGenerator = $authGenerator;
    }

    /**
     * Generate auth signature for a channel
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function auth(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();

        // Validate required parameters
        if (empty($data['socket_id']) || empty($data['channel_name'])) {
            return $this->errorResponse($response, 'Socket ID and channel name are required', 422);
        }

        $socketId = $data['socket_id'];
        $channelName = $data['channel_name'];

        // Check if user is authorized to access this channel
        // In a real application, you would check user permissions here
        // For now, we'll assume the user is authorized

        // Generate auth signature based on channel type
        if (strpos($channelName, 'private-') === 0) {
            // Private channel
            $auth = $this->authGenerator->generatePrivateAuth($socketId, $channelName);

            return $this->jsonResponse($response, [
                'auth' => $auth
            ]);
        } elseif (strpos($channelName, 'presence-') === 0) {
            // Presence channel requires user information

            // Get current user data (would come from auth system in real app)
            $user = $this->getCurrentUser($request);

            if (!$user) {
                return $this->errorResponse($response, 'Unauthorized', 403);
            }

            // Create user data for presence channel
            $presenceData = [
                'user_id' => (string)$user['id'],
                'user_info' => [
                    'name' => $user['name'] ?? 'User ' . $user['id'],
                    'email' => $user['email'] ?? null,
                    // Add any other user info needed for the channel
                ]
            ];

            // Generate presence auth
            $auth = $this->authGenerator->generatePresenceAuth($socketId, $channelName, $presenceData);

            return $this->jsonResponse($response, [
                'auth' => $auth,
                'channel_data' => json_encode($presenceData)
            ]);
        } else {
            // Public channels don't need authentication
            return $this->errorResponse($response, 'Public channels do not need authentication', 400);
        }
    }

    /**
     * Create a JSON error response
     *
     * @param ResponseInterface $response
     * @param string $message Error message
     * @param int $status HTTP status code
     * @return ResponseInterface
     */
    protected function errorResponse(ResponseInterface $response, string $message, int $status): ResponseInterface
    {
        $response = $response->withStatus($status);

        if ($response instanceof Response) {
            return $response->json()->withJson([
                'error' => $message
            ]);
        }

        $response->getBody()->write(json_encode([
            'error' => $message
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Create a JSON response
     *
     * @param ResponseInterface $response
     * @param array $data Response data
     * @return ResponseInterface
     */
    protected function jsonResponse(ResponseInterface $response, array $data): ResponseInterface
    {
        if ($response instanceof Response) {
            return $response->json()->withJson($data);
        }

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get the current authenticated user
     *
     * In a real application, this would come from your auth system
     *
     * @param ServerRequestInterface $request
     * @return array|null User data or null if not authenticated
     */
    protected function getCurrentUser(ServerRequestInterface $request): ?array
    {
        // In a real application, get the user from the auth system
        // For example:
        // return $request->getAttribute('user');

        // For demo purposes, we'll return a mock user
        return [
            'id' => 1,
            'name' => 'Example User',
            'email' => 'user@example.com'
        ];
    }
}