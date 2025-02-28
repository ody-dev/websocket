<?php
namespace Ody\Websocket;

use Ody\Swoole\Websockets\Exceptions\InvalidUrl;
use Ody\Swoole\Websockets\Psr\Uri;
use Ody\Swoole\Websockets\Exceptions\WebSocketException;
use Psr\Http\Message\UriInterface;
use Swoole\Coroutine\Http\Client as WsClient;
use Swoole\Http\Status;
use Swoole\WebSocket\Frame;

class Client
{
    public WsClient $client;

    /**
     * @var UriInterface
     */
    private UriInterface $uri;


    /**
     * @param Uri|string $uri
     * @throws WebSocketException|InvalidUrl
     */
    public function __construct($uri)
    {
        if (is_string($uri))
        {
            $this->uri = new Uri($uri);
        }
        else if (class_implements($uri, UriInterface::class))
        {
            $this->uri = $uri;
        }
        else
        {
            throw new InvalidUrl('Url must be a string or implemented UriInterface.');
        }

        $host = $this->uri->getHost();
        $port = $this->uri->getPort();
        $ssl  = $this->uri->getScheme() === 'wss';
        if (empty($host))
        {
            throw new InvalidUrl('The WebSocket host should not be empty.');
        }

        if (empty($port))
        {
            $port = $ssl ? 443 : 80;
        }

        $this->client = new WsClient($host, $port, $ssl);

        parse_str($this->uri->getQuery(), $query);
        $query = http_build_query($query);

        $path = $this->uri->getPath() ?: '/';
        $path = empty($query) ? $path : $path . '?' . $query;

        $ret = $this->client->upgrade($path);
        if (!$ret)
        {
            if ($this->client->errCode !== 0)
            {
                $errCode = $this->client->errCode;
                $errMsg  = $this->client->errMsg;
            }
            else
            {
                $errCode = $this->client->statusCode;
                $errMsg  = Status::getReasonPhrase($errCode);
            }

            throw new WebSocketException('Websocket upgrade failed by [' . $errMsg . '(' . $errCode . ')' . '].', $errCode);
        }
    }

    /**
     * @param float $timeout
     * @return Frame
     */
    public function recv(float $timeout = -1)
    {
        return $this->client->recv($timeout);
    }

    public function push(string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, bool $finish = true): bool
    {
        return $this->client->push($data, $opcode, $finish);
    }

    public function close(): bool
    {
        return $this->client->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}