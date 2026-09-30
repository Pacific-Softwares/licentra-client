<?php

namespace Pacific\Licentra\Http;

use Pacific\Licentra\Exceptions\ServerUnreachable;

/**
 * Dependency-free HTTP: curl when available, PHP streams otherwise.
 * Deliberately no Guzzle so the package never fights a product's own HTTP stack.
 */
final class StreamTransport implements Transport, Downloader
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

    public function download(string $url, array $body, string $destination, int $timeout): Response
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/zip, application/json', 'User-Agent: licentra-client/1', 'Content-Type: application/json'];
        $part = $destination . '.part';

        $out = @fopen($part, 'wb');
        if ($out === false) {
            throw new ServerUnreachable("Cannot write the download to {$part}. Check folder permissions.");
        }

        try {
            $status = function_exists('curl_init')
                ? $this->curlDownload($url, $payload, $headers, $timeout, $out)
                : $this->streamDownload($url, $payload, $headers, $timeout, $out);
        } finally {
            fclose($out);
        }

        if ($status !== 200) {
            $error = $this->decode((string) @file_get_contents($part));
            @unlink($part);

            return new Response($status, $error);
        }
        if (!@rename($part, $destination)) {
            @unlink($part);
            throw new ServerUnreachable("Cannot move the download to {$destination}.");
        }

        return new Response(200, null);
    }

    /** @param resource $out */
    private function curlDownload(string $url, string $payload, array $headers, int $timeout, $out): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FILE => $out,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $status === 0) {
            throw new ServerUnreachable('Download failed: ' . ($error ?: 'no response'));
        }

        return $status;
    }

    /** @param resource $out */
    private function streamDownload(string $url, string $payload, array $headers, int $timeout, $out): int
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $in = @fopen($url, 'rb', false, $ctx);
        $statusLine = $http_response_header[0] ?? '';
        if ($in === false || !preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
            throw new ServerUnreachable('Download failed: ' . (error_get_last()['message'] ?? 'no response'));
        }
        stream_copy_to_stream($in, $out);
        fclose($in);

        return (int) $m[1];
    }

    private function decode(string $raw): ?array
    {
        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }
}
