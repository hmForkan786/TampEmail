<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function webhookRequest(string $body, array $headers = []): array
{
    $provider = 'generic'; $timestamp = (string) time(); $secret = 'webhook-test-secret';
    return array_merge([
        'X-Inbound-Provider' => $provider, 'X-Inbound-Timestamp' => $timestamp,
        'X-Inbound-Signature' => hash_hmac('sha256', $provider.'.'.$timestamp.'.'.$body, $secret),
        'X-Inbound-Message-Id' => 'provider-message-1', 'Content-Type' => 'application/json',
    ], $headers);
}

beforeEach(function (): void {
    config(['inbound.providers.generic.secret' => 'webhook-test-secret', 'inbound.max_body_bytes' => 1000, 'queue.default' => 'database']);
});

it('accepts a valid signed canonical webhook without persisting email data', function (): void {
    $body = json_encode(['recipient' => 'user@example.test', 'sender' => 'sender@example.test', 'raw_mime_payload' => 'MIME']);
    $response = $this->withHeaders(webhookRequest($body))->postJson('/api/v1/inbound/webhook', json_decode($body, true));
    $response->assertStatus(202)->assertJsonPath('data.accepted', true)->assertJsonPath('data.provider_message_id', 'provider-message-1');
    expect($response->getContent())->not->toContain('MIME')->and($response->getContent())->not->toContain('secret');
});

it('rejects invalid, expired and missing signatures', function (): void {
    $body = json_encode(['recipient' => 'user@example.test']);
    $this->withHeaders(webhookRequest($body, ['X-Inbound-Signature' => 'invalid']))->postJson('/api/v1/inbound/webhook', json_decode($body, true))->assertUnauthorized();
    $headers = webhookRequest($body); $headers['X-Inbound-Timestamp'] = (string) (time() - 1000);
    $this->withHeaders($headers)->postJson('/api/v1/inbound/webhook', json_decode($body, true))->assertUnauthorized();
});

it('rejects invalid payload and oversized body', function (): void {
    $body = json_encode(['sender' => 'missing-recipient']);
    $this->withHeaders(webhookRequest($body))->postJson('/api/v1/inbound/webhook', json_decode($body, true))->assertStatus(422);
    $largePayload = ['recipient' => 'user@example.test', 'padding' => str_repeat('x', 1001)];
    $large = json_encode($largePayload); $headers = webhookRequest($large);
    $this->withHeaders($headers)->postJson('/api/v1/inbound/webhook', $largePayload)->assertStatus(413);
});
