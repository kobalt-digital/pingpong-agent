<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Posts JSON to PingPong with the machine key. With ext-curl when PHP has
 * it, otherwise through PHP's own HTTP stream, which every PHP has. A
 * redirect is not followed: it would carry the key to wherever it points.
 */
class Client
{
    public const TIMEOUT_SECONDS = 10;

    public function __construct(private Config $config, private string $userAgent, private bool $useCurl = true) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<array-key, mixed>}|null null when PingPong could not be reached
     */
    public function post(string $path, array $payload): ?array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            return null;
        }

        $url = $this->config->url.'/'.ltrim($path, '/');
        $headers = [
            'Authorization: Bearer '.$this->config->key,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: '.$this->userAgent,
        ];

        $answer = $this->useCurl && function_exists('curl_init') ? $this->withCurl($url, $headers, $json) : $this->withStream($url, $headers, $json);

        if ($answer === null) {
            return null;
        }

        [$status, $body] = $answer;
        $decoded = json_decode($body, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @param  list<string>  $headers
     * @return array{int, string}|null
     */
    private function withCurl(string $url, array $headers, string $json): ?array
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (! is_string($body) || $status === 0) {
            return null;
        }

        return [$status, $body];
    }

    /**
     * @param  list<string>  $headers
     * @return array{int, string}|null
     */
    private function withStream(string $url, array $headers, string $json): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $json,
            'timeout' => self::TIMEOUT_SECONDS,
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);

        $handle = @fopen($url, 'r', false, $context);

        if ($handle === false) {
            return null;
        }

        /* The status line is the first header the HTTP wrapper keeps. */
        $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
        $body = stream_get_contents($handle);
        fclose($handle);

        $statusLine = is_array($headers) && is_string($headers[0] ?? null) ? $headers[0] : '';

        if ($body === false || preg_match('#^HTTP/\S+ (\d{3})#', $statusLine, $match) !== 1) {
            return null;
        }

        return [(int) $match[1], $body];
    }
}
