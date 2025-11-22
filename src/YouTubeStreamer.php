<?php

namespace YouTube;

class YouTubeStreamer
{
    // 4096
    protected int $buffer_size = 256 * 1024;

    protected array $headers = array();
    protected bool $headers_sent = false;
    protected array $options = array();

    protected bool $debug = false;

    protected function sendHeader(string $header): void
    {
        if ($this->debug) {
            var_dump($header);
        } else {
            header($header);
        }
    }

    /**
     * @param resource $ch
     * @param string $data
     * @return int
     */
    public function headerCallback($ch, string $data): int
    {
        // this should be first line
        if (preg_match('/HTTP\/[\d.]+\s*(\d+)/', $data, $matches)) {
            $status_code = $matches[1];

            // if Forbidden or Not Found -> those are "valid" statuses too
            if ($status_code == 200 || $status_code == 206 || $status_code == 403 || $status_code == 404) {
                $this->headers_sent = true;
                $this->sendHeader(rtrim($data));
            }

        } else {

            // only headers we wish to forward back to the client
            $forward = array('content-type', 'content-length', 'accept-ranges', 'content-range', 'last-modified');

            $parts = explode(':', $data, 2);

            if ($this->headers_sent && count($parts) == 2 && in_array(trim(strtolower($parts[0])), $forward)) {
                $this->sendHeader(rtrim($data));
            }
        }

        return strlen($data);
    }

    /**
     * @param resource $ch
     * @param string $data
     * @return int
     */
    public function bodyCallback($ch, string $data): int
    {
        echo $data;
        flush();
        return strlen($data);
    }

    public function stream(string $url, array $headers = []): void
    {
        $ch = curl_init();

        if (count(array_filter($headers, function($v) { return (strpos(strtolower($v), 'user-agent:') !== False); })) == 0)
            $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36';

        if (isset($_SERVER['HTTP_RANGE'])) {
            $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }

        // otherwise you get weird "OpenSSL SSL_read: No error"
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        curl_setopt($ch, CURLOPT_BUFFERSIZE, $this->buffer_size);
        curl_setopt($ch, CURLOPT_URL, $url);

        // https://php.watch/articles/php-curl-security-hardening#curl-protocols
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

        //curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        // we deal with this ourselves
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, 'headerCallback']);

        // if response is empty - this never gets called
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, [$this, 'bodyCallback']);

        if (!empty($this->options))
            curl_setopt_array($ch, $this->options);

        $ret = curl_exec($ch);

        // TODO: $this->logError($ch);
        $error = ($ret === false) ? sprintf('curl error: %s, num: %s', curl_error($ch), curl_errno($ch)) : null;

        curl_close($ch);
    }

    /**
     * Format ip:port or null to use direct connection
     * @param $proxy_server
     * @param $proxy_type
     */
    public function setProxy($proxy_server, $proxy_type = CURLPROXY_HTTP)
    {
        $this->options[CURLOPT_PROXY] = $proxy_server;
        $this->options[CURLOPT_PROXYTYPE] = $proxy_type;
    }

    public function setCookieFile($cookie_file)
    {
        // read & write cookies
        $this->options[CURLOPT_COOKIEJAR] = $cookie_file;
        $this->options[CURLOPT_COOKIEFILE] = $cookie_file;
    }
}
