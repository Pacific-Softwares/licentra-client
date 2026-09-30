<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Config;
use Pacific\Licentra\Exceptions\ActivationFailed;
use Pacific\Licentra\Exceptions\LicentraException;
use Pacific\Licentra\Exceptions\ServerUnreachable;
use Pacific\Licentra\Http\Response;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Status;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class LicentraTest extends TestCase
{
    private Signer $signer;
    private MemoryStore $store;
    private FakeTransport $http;

    protected function setUp(): void
    {
        $this->signer = new Signer();
        $this->store = new MemoryStore();
        $this->http = new FakeTransport();
    }

    private function client(string $appUrl = 'https://shop-one.com', string $version = '1.0.0', ?string $publicKey = null): Licentra
    {
        return new Licentra(new Config(
            product: 'quizora',
            publicKey: $publicKey ?? $this->signer->public,
            storagePath: '/unused',
            appUrl: $appUrl,
            productVersion: $version,
        ), $this->store, $this->http);
    }

    private function okResponse(array $token = [], array $extra = []): Response
    {
        return new Response(201, $extra + [
            'ok' => true,
            'instance_id' => 'iid-1',
            'status' => $token['status'] ?? 'valid',
            'token' => $this->signer->token($token),
            'license' => [
                'type' => 'Regular License', 'buyer' => 'happybuyer',
                'supported_until' => (new \DateTimeImmutable('+10 days'))->format(DATE_ATOM),
                'renew_url' => 'https://codecanyon.net/item/quizora/1',
            ],
            'update' => ['version' => '1.2.0', 'released_at' => null, 'changelog' => 'x', 'url' => 'https://u'],
        ]);
    }

    public function test_missing_before_activation(): void
    {
        $state = $this->client()->state();

        $this->assertSame(Status::Missing, $state->status);
        $this->assertFalse($state->isUsable());
    }

    public function test_activate_stores_token_and_reports_valid(): void
    {
        $this->http->queue[] = $this->okResponse();

        $state = $this->client()->activate(' 86781236-23d0-4b3c-7dfa-c1c147e0dece ');

        $this->assertSame(Status::Valid, $state->status);
        $this->assertSame('shop-one.com', $state->domain);
        $this->assertSame('86781236-23d0-4b3c-7dfa-c1c147e0dece', $this->http->sent[0]['body']['purchase_code']);
        $this->assertSame('quizora', $this->http->sent[0]['body']['product']);
        $this->assertSame('https://licentra.pacificsoftwares.com/api/v1/activate', $this->http->sent[0]['url']);
        $this->assertTrue($state->updateAvailable());
        $this->assertTrue($state->supportActive());
        $this->assertTrue($state->supportEndingSoon());
    }

    public function test_activate_sends_contact_email_and_exposes_it(): void
    {
        $response = $this->okResponse();
        $response = new Response(201, ['license' => $response->json['license'] + ['email' => 'owner@shop-one.com']] + $response->json);
        $this->http->queue[] = $response;

        $state = $this->client()->activate('code', ' owner@shop-one.com ');

        $this->assertSame('owner@shop-one.com', $this->http->sent[0]['body']['email']);
        $this->assertSame('owner@shop-one.com', $state->email);
        $this->assertSame('happybuyer', $state->buyer);
    }

    public function test_activate_without_email_sends_none(): void
    {
        $this->http->queue[] = $this->okResponse();
        $this->http->queue[] = $this->okResponse();

        $this->client()->activate('code');
        $this->client()->activate('code', '  ');

        $this->assertArrayNotHasKey('email', $this->http->sent[0]['body']);
        $this->assertArrayNotHasKey('email', $this->http->sent[1]['body']);
        $this->assertNull($this->client()->state()->email);
    }

    public function test_state_is_valid_offline_on_a_fresh_instance(): void
    {
        $this->http->queue[] = $this->okResponse();
        $this->client()->activate('code');

        $this->assertSame(Status::Valid, $this->client()->state()->status);
        $this->assertCount(1, $this->http->sent); // no network for the check
    }

    public function test_activation_error_carries_server_message(): void
    {
        $this->http->queue[] = new Response(409, [
            'ok' => false, 'error' => 'activation_limit_reached',
            'message' => 'This license is already active on a.com.', 'context' => ['active_domains' => ['a.com']],
        ]);

        try {
            $this->client()->activate('code');
            $this->fail('expected ActivationFailed');
        } catch (ActivationFailed $e) {
            $this->assertSame('activation_limit_reached', $e->errorCode);
            $this->assertSame(['a.com'], $e->context['active_domains']);
            $this->assertStringContainsString('a.com', $e->getMessage());
        }
    }

    public function test_validation_error_uses_first_field_message(): void
    {
        $this->http->queue[] = new Response(422, ['message' => 'x', 'errors' => ['purchase_code' => ['Bad format.']]]);

        $this->expectExceptionMessage('Bad format.');
        $this->client()->activate('nope');
    }

    public function test_server_5xx_is_unreachable(): void
    {
        $this->http->queue[] = new Response(502, null);

        $this->expectException(ServerUnreachable::class);
        $this->client()->activate('code');
    }

    public function test_token_signed_with_another_key_is_rejected_on_save(): void
    {
        $this->http->queue[] = $this->okResponse();
        $other = new Signer();

        $this->expectException(LicentraException::class);
        $this->client(publicKey: $other->public)->activate('code');
    }

    public function test_tampered_token_is_invalid(): void
    {
        $this->store->data = ['instance_id' => 'iid-1', 'token' => $this->signer->token() . 'x'];

        $this->assertSame(Status::Invalid, $this->client()->state()->status);
    }

    public function test_token_for_other_product_is_invalid(): void
    {
        $this->store->data = ['instance_id' => 'i', 'token' => $this->signer->token(['product' => 'slotara'])];

        $this->assertSame(Status::Invalid, $this->client()->state()->status);
    }

    public function test_copied_to_another_live_domain_is_mismatch_but_local_copy_is_fine(): void
    {
        $this->store->data = ['instance_id' => 'i', 'token' => $this->signer->token()];

        $this->assertSame(Status::DomainMismatch, $this->client('https://copycat.net')->state()->status);
        $this->assertSame(Status::Valid, $this->client('http://localhost:8000')->state()->status);
        $this->assertSame(Status::DomainMismatch, $this->client('https://staging.copycat.net')->state()->status);
        $this->assertSame(Status::Valid, $this->client('https://www.shop-one.com/admin')->state()->status);
    }

    public function test_expired_and_pending_tokens(): void
    {
        $this->store->data = ['instance_id' => 'i', 'token' => $this->signer->token(['exp' => time() - 1])];
        $this->assertSame(Status::Expired, $this->client()->state()->status);

        $this->store->data = ['instance_id' => 'i', 'token' => $this->signer->token(['status' => 'pending'])];
        $state = $this->client()->state();
        $this->assertSame(Status::Pending, $state->status);
        $this->assertTrue($state->isUsable());
    }

    public function test_heartbeat_skips_when_recent_and_refreshes_when_due(): void
    {
        $this->store->data = ['instance_id' => 'iid-1', 'token' => $this->signer->token(), 'last_heartbeat_at' => time()];
        $this->assertFalse($this->client()->heartbeat());
        $this->assertCount(0, $this->http->sent);

        $this->store->data['last_heartbeat_at'] = time() - 25 * 3600;
        $this->http->queue[] = $this->okResponse(extra: ['update' => ['version' => '9.0.0', 'released_at' => null, 'changelog' => null, 'url' => null]]);

        $client = $this->client();
        $this->assertTrue($client->heartbeat());
        $this->assertSame('9.0.0', $client->state()->update['version']);
        $this->assertSame('iid-1', $this->http->sent[0]['body']['instance_id']);
    }

    public function test_heartbeat_network_failure_keeps_license_and_backs_off(): void
    {
        $this->store->data = ['instance_id' => 'iid-1', 'token' => $this->signer->token(), 'last_heartbeat_at' => 0];
        $this->http->queue[] = new ServerUnreachable('down');

        $client = $this->client();
        $this->assertFalse($client->heartbeat());
        $this->assertSame(Status::Valid, $client->state()->status);

        $this->assertFalse($this->client()->heartbeat()); // within the 1h back-off: no request
        $this->assertCount(1, $this->http->sent);
    }

    public function test_heartbeat_revocation_locks_and_later_success_unlocks(): void
    {
        $this->store->data = ['instance_id' => 'iid-1', 'token' => $this->signer->token(), 'last_heartbeat_at' => 0];
        $this->http->queue[] = new Response(403, ['ok' => false, 'error' => 'license_blocked', 'message' => 'Blocked.']);

        $client = $this->client();
        $client->heartbeat();
        $this->assertSame(Status::Blocked, $client->state()->status);
        $this->assertArrayHasKey('instance_id', $this->store->data); // keeps pinging so an unblock can take effect

        $this->store->data['last_attempt_at'] = 0;
        $this->http->queue[] = $this->okResponse();
        $client->heartbeat();
        $this->assertSame(Status::Valid, $client->state()->status);
    }

    public function test_heartbeat_never_throws_on_unexpected_error_code(): void
    {
        $this->store->data = ['instance_id' => 'iid-1', 'token' => $this->signer->token(), 'last_heartbeat_at' => 0];
        $this->http->queue[] = new Response(429, ['message' => 'slow down']);

        $this->assertFalse($this->client()->heartbeat());
        $this->assertSame(Status::Valid, $this->client()->state()->status);
    }

    public function test_deactivate_clears_store(): void
    {
        $this->store->data = ['instance_id' => 'iid-1', 'token' => $this->signer->token()];
        $this->http->queue[] = new Response(200, ['ok' => true]);

        $client = $this->client();
        $client->deactivate('code');

        $this->assertSame([], $this->store->data);
        $this->assertSame(Status::Missing, $client->state()->status);
    }

    public function test_real_host_overrides_localhost_app_url_offline(): void
    {
        $this->store->data = ['instance_id' => 'i', 'token' => $this->signer->token()];
        $client = $this->client('http://localhost'); // buyer/pirate left APP_URL=localhost

        $this->assertSame(Status::Valid, $client->state()->status);

        $client->observeHost('pirate.net');
        $this->assertSame(Status::DomainMismatch, $client->state()->status);

        $client->observeHost('www.shop-one.com');
        $this->assertSame(Status::Valid, $client->state()->status);
    }

    public function test_request_host_is_sent_to_server(): void
    {
        $client = $this->client('http://localhost');
        $client->observeHost('shop-one.com');
        $this->http->queue[] = $this->okResponse();

        $client->activate('code');

        $this->assertSame('shop-one.com', $this->http->sent[0]['body']['request_host']);
        $this->assertSame('shop-one.com', $this->store->data['request_host']); // survives save()
    }

    public function test_deactivate_without_activation_says_so(): void
    {
        $this->expectException(ActivationFailed::class);
        $this->expectExceptionMessage('no activation');
        $this->client()->deactivate('code');
    }

    public function test_heartbeat_skipped_when_storage_is_unwritable(): void
    {
        $store = new class extends \Pacific\Licentra\Tests\ReadOnlyStore {};
        $store->data = ['instance_id' => 'iid-1', 'token' => $this->signer->token(), 'last_heartbeat_at' => 0];
        $client = new Licentra(new Config('quizora', $this->signer->public, '/x', 'https://shop-one.com'), $store, $this->http);

        $this->assertFalse($client->heartbeat());
        $this->assertCount(0, $this->http->sent);
        $this->assertSame(Status::Valid, $client->state()->status);
    }
}
