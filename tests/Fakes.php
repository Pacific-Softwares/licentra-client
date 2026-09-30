<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Exceptions\ServerUnreachable;
use Pacific\Licentra\Http\Response;
use Pacific\Licentra\Http\Transport;
use Pacific\Licentra\Store\Store;

final class MemoryStore implements Store
{
    public array $data = [];

    public function read(): array
    {
        return $this->data;
    }

    public function write(array $data): void
    {
        $this->data = $data;
    }
}

final class FakeTransport implements Transport
{
    /** @var list<Response|ServerUnreachable> */
    public array $queue = [];

    /** @var list<array{method: string, url: string, body: ?array}> */
    public array $sent = [];

    public function send(string $method, string $url, ?array $body, int $timeout): Response
    {
        $this->sent[] = compact('method', 'url', 'body');
        $next = array_shift($this->queue) ?? throw new \LogicException('No fake response queued for ' . $url);
        if ($next instanceof ServerUnreachable) {
            throw $next;
        }

        return $next;
    }
}

/** Same format as licentra-server app/Licentra/TokenSigner.php. */
final class Signer
{
    public readonly string $secret;
    public readonly string $public;

    public function __construct()
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    public function token(array $overrides = []): string
    {
        $payload = $overrides + [
            'v' => 1, 'iid' => 'iid-1', 'product' => 'quizora', 'domain' => 'shop-one.com', 'dev' => false,
            'status' => 'valid', 'type' => 'Regular License', 'supported_until' => null,
            'iat' => time(), 'exp' => time() + 30 * 86400,
        ];
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $body = $b64(json_encode($payload));

        return $body . '.' . $b64(sodium_crypto_sign_detached($body, $this->secret));
    }
}

class ReadOnlyStore implements Store
{
    public array $data = [];

    public function read(): array
    {
        return $this->data;
    }

    public function write(array $data): void
    {
        throw new \Pacific\Licentra\Exceptions\LicentraException('read-only');
    }
}

/** Serves a prepared zip as if the license server had streamed it. */
final class FakeDownloader implements \Pacific\Licentra\Http\Downloader
{
    public ?string $zip = null;

    public int $status = 200;

    public ?array $error = null;

    /** @var list<array{url: string, body: array}> */
    public array $sent = [];

    public function download(string $url, array $body, string $destination, int $timeout): Response
    {
        $this->sent[] = compact('url', 'body');
        if ($this->status !== 200) {
            return new Response($this->status, $this->error);
        }
        copy($this->zip, $destination);

        return new Response(200, null);
    }
}

/** Records hook calls; can be told to fail in finish() to exercise rollback. */
final class RecordingHooks implements \Pacific\Licentra\Update\UpdateHooks
{
    public array $calls = [];

    public bool $failFinish = false;

    public function beforeApply(\Pacific\Licentra\Update\Updater $updater): void
    {
        $this->calls[] = 'beforeApply';
    }

    public function finish(\Pacific\Licentra\Update\Updater $updater): void
    {
        $this->calls[] = 'finish';
        if ($this->failFinish) {
            throw new \RuntimeException('migration exploded');
        }
    }

    public function afterRollback(\Pacific\Licentra\Update\Updater $updater): void
    {
        $this->calls[] = 'afterRollback';
    }
}
