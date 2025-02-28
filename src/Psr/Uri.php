<?php
namespace Ody\Websocket\Psr;

use Psr\Http\Message\UriInterface;

class Uri implements UriInterface
{
    private mixed $scheme = '';
    private $host = '';
    private $port = '';
    private $user = '';
    private $pass = '';
    private $path = '';
    private $query = '';
    private $fragment = '';

    public function __construct(string $url)
    {
        $parts = self::parse($url);
        $this->scheme = $parts['scheme'] ?? '';
        $this->host = $parts['host'] ?? '';
        $this->port = $parts['port'] ?? '';
        $this->user = $parts['user'] ?? '';
        $this->pass = $parts['pass'] ?? '';
        $this->path = $parts['path'] ?? '';
        $this->query = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';
    }

    /**
     * UTF-8 aware \parse_url() replacement.
     *
     * The internal function produces broken output for non ASCII domain names
     * (IDN) when used with locales other than "C".
     *
     * On the other hand, cURL understands IDN correctly only when UTF-8 locale
     * is configured ("C.UTF-8", "en_US.UTF-8", etc.).
     *
     * @see https://bugs.php.net/bug.php?id=52923
     * @see https://www.php.net/manual/en/function.parse-url.php#114817
     * @see https://curl.haxx.se/libcurl/c/CURLOPT_URL.html#ENCODING
     *
     * @return array|false
     */
    private static function parse(string $url)
    {
        // If IPv6
        $prefix = '';
        if (preg_match('%^(.*://\[[0-9:a-f]+\])(.*?)$%', $url, $matches)) {
            /** @var array{0:string, 1:string, 2:string} $matches */
            $prefix = $matches[1];
            $url = $matches[2];
        }

        /** @var string $encodedUrl */
        $encodedUrl = preg_replace_callback(
            '%[^:/@?&=#]+%usD',
            static function ($matches) {
                return urlencode($matches[0]);
            },
            $url
        );

        $result = parse_url($prefix . $encodedUrl);

        if ($result === false) {
            return false;
        }

        return array_map('urldecode', $result);
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getPass(): string
    {
        return $this->pass;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function getAuthority(): string
    {
        $authority = $this->host;
        $user_info = $this->getUserInfo();
        if (!empty($user_info)) {
            $authority = $user_info . '@' . $authority;
        }

        if (!empty($this->port)) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        $out = $this->user;
        if (!empty($this->pass)) {
            $out .= ':' . $this->pass;
        }
        return $out;
    }

    public function withScheme($scheme): UriInterface
    {
        // TODO: Implement withScheme() method.
    }

    public function withUserInfo($user, $password = null): UriInterface
    {
        // TODO: Implement withUserInfo() method.
    }

    public function withHost($host): UriInterface
    {
        // TODO: Implement withHost() method.
    }

    public function withPort($port): UriInterface
    {
        // TODO: Implement withPort() method.
    }

    public function withPath($path): UriInterface
    {
        // TODO: Implement withPath() method.
    }

    public function withQuery($query): UriInterface
    {
        // TODO: Implement withQuery() method.
    }

    public function withFragment($fragment): UriInterface
    {
        // TODO: Implement withFragment() method.
    }

    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme != '') {
            $uri .= $this->scheme . ':';
        }

        if ($this->getAuthority() != '' || $this->scheme === 'file') {
            $uri .= '//' . $this->getAuthority();
        }

        $uri .= $this->path;

        if ($this->query != '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment != '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }
}