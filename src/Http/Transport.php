<?php

namespace Pacific\Licentra\Http;

use Pacific\Licentra\Exceptions\ServerUnreachable;

interface Transport
{
    /**
     * @param array<string, mixed>|null $body JSON body; null for GET
     * @throws ServerUnreachable on network failure
     */
    public function send(string $method, string $url, ?array $body, int $timeout): Response;
}
