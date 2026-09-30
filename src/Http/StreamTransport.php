<?php

namespace Pacific\Licentra\Http;

use Pacific\Licentra\Exceptions\ServerUnreachable;

/**
 * Dependency-free HTTP: curl when available, PHP streams otherwise.
 * Deliberately no Guzzle so the package never fights a product's own HTTP stack.
 */
final class StreamTransport implements Transport
{
    public function send(string $method, string $url, ?array $body, int $timeout): Response
    {
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/json', 'User-Agent: licentra-client/1'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        return function_exists('curl_init')
            ? $this->curl($method, $url, $payload, $headers, $timeout)
            : $this->stream($method, $url, $payload, $headers, $timeout);
    }

    private function curl(string $method, string $url, ?string $payload, array $headers, int $timeout): Response
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status === 0) {
            throw new ServerUnreachable('License server unreachable: ' . ($error ?: 'no response'));
        }

        return new Response($status, $this->decode((string) $raw));
    }

    private function stream(string $method, string $url, ?string $payload, array $headers, int $timeout): Response
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $raw = @file_get_contents($url, false, $ctx);
        $statusLine = $http_response_header[0] ?? '';
        if ($raw === false || !preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
            throw new ServerUnreachable('License server unreachable: ' . (error_get_last()['message'] ?? 'no response'));
        }

        return new Response((int) $m[1], $this->decode($raw));
    }

    private function decode(string $raw): ?array
    {
        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }
}
