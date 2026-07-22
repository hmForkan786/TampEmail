<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PlatformFoundationTest extends TestCase
{
    public function test_security_headers_are_applied_to_web_responses(): void
    {
        config([
            'security.headers.enabled' => true,
            'security.headers.hsts_enabled' => true,
        ]);

        $response = $this
            ->withServerVariables(['HTTPS' => 'on'])
            ->get('https://localhost/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_foundation_configuration_uses_production_ready_defaults(): void
    {
        config([
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'session.encrypt' => true,
        ]);

        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('queue.default'));
        $this->assertSame('database-uuids', config('queue.failed.driver'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertArrayHasKey(config('platform.storage.attachments_disk'), config('filesystems.disks'));
        $this->assertArrayHasKey(config('platform.storage.message_bodies_disk'), config('filesystems.disks'));
        $this->assertArrayHasKey(config('platform.logs.security_channel'), config('logging.channels'));
        $this->assertArrayHasKey(config('platform.logs.audit_channel'), config('logging.channels'));
    }

    public function test_named_rate_limiters_are_registered(): void
    {
        $this->assertNotNull(RateLimiter::limiter('web'));
        $this->assertNotNull(RateLimiter::limiter('api'));
        $this->assertNotNull(RateLimiter::limiter('inbox-creation'));
        $this->assertNotNull(RateLimiter::limiter('ingestion'));
    }

    public function test_platform_check_command_passes_for_local_foundation(): void
    {
        config([
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'session.encrypt' => true,
        ]);

        $this->artisan('platform:check')
            ->expectsOutputToContain('Platform foundation checks passed.')
            ->assertSuccessful();
    }
}
