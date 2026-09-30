<?php

namespace Pacific\Licentra\Http;

use Pacific\Licentra\Exceptions\ServerUnreachable;

/** Streams a large response (a release zip) straight to disk instead of into memory. */
interface Downloader
{
    /**
     * POST $body as JSON and save a 200 response to $destination.
     * On any other status the file is not written and the decoded JSON error is returned.
     *
     * @param array<string, mixed> $body
     * @throws ServerUnreachable on network failure
     */
    public function download(string $url, array $body, string $destination, int $timeout): Response;
}
