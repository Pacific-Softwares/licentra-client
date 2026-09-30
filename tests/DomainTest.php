<?php

namespace Ishalabs\Licentra\Tests;

use Ishalabs\Licentra\Domain;
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
        foreach (['localhost', 'app.test', 'staging.shop.com', '192.168.0.4'] as $h) {
            $this->assertTrue(Domain::isDev($h), $h);
        }
        foreach (['shop.com', 'devshop.com', '8.8.8.8'] as $h) {
            $this->assertFalse(Domain::isDev($h), $h);
        }
    }
}
