<?php

namespace Pacific\Licentra\Tests;

use Pacific\Licentra\Domain;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    public function test_normalize(): void
    {
        $this->assertSame('example.com', Domain::normalize('https://WWW.Example.com:8443/x'));
        $this->assertSame('example.com', Domain::normalize('example.com'));
        $this->assertNull(Domain::normalize(''));
        $this->assertNull(Domain::normalize('https://'));
    }

    public function test_is_dev(): void
    {
        foreach (['localhost', 'app.test', 'app.local', '192.168.0.4', '10.1.2.3'] as $h) {
            $this->assertTrue(Domain::isDev($h), $h);
        }
        // Staging-looking hosts are public sites: a copied token must not work there.
        foreach (['shop.com', 'devshop.com', '8.8.8.8', 'staging.shop.com', 'dev.pirate.net'] as $h) {
            $this->assertFalse(Domain::isDev($h), $h);
        }
    }
}
