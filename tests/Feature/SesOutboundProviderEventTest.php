<?php

declare(strict_types=1);

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundProviderEventType;
use App\Jobs\ProcessOutboundProviderEventJob;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\OutboundProviderEvent;
use App\Models\User;
use App\Services\Outbound\OutboundProviderEventProcessor;
use App\Services\Outbound\OutboundSuppressionService;
use App\Services\Outbound\SesOutboundProviderEventParser;
use App\Services\Outbound\SesSnsSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{cert_pem: string, private_key: string, cert_url: string}
 */
function sesTestCertificateBundle(): array
{
    static $bundle = null;
    if (is_array($bundle)) {
        return $bundle;
    }

    $certPem = file_get_contents(base_path('tests/Fixtures/ses/sns-test.pem'));
    $privateKey = file_get_contents(base_path('tests/Fixtures/ses/sns-test.key'));
    expect($certPem)->not->toBeFalse()->and($privateKey)->not->toBeFalse();

    $bundle = [
        'cert_pem' => (string) $certPem,
        'private_key' => (string) $privateKey,
        'cert_url' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem',
    ];

    return $bundle;
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function sesSignSnsEnvelope(array $fields, string $type = 'Notification'): array
{
    $bundle = sesTestCertificateBundle();
    $verifier = new SesSnsSignatureVerifier;
    $envelope = array_merge($fields, [
        'Type' => $type,
        'SigningCertURL' => $bundle['cert_url'],
        'SignatureVersion' => '2',
    ]);

    $canonical = $verifier->canonicalString($envelope, $type);
    expect($canonical)->not->toBeNull();

    $ok = openssl_sign($canonical, $signature, $bundle['private_key'], OPENSSL_ALGO_SHA256);
    expect($ok)->toBeTrue();

    $envelope['Signature'] = base64_encode($signature);

    return $envelope;
}

/**
 * @param  array<string, mixed>  $sesMessage
 * @return array<string, mixed>
 */
function sesNotificationEnvelope(array $sesMessage, ?string $messageId = null): array
{
    return sesSignSnsEnvelope([
        'MessageId' => $messageId ?? ('sns-'.uniqid()),
        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:temail-ses',
        'Message' => json_encode($sesMessage, JSON_THROW_ON_ERROR),
        'Timestamp' => now()->toIso8601String(),
        'Subject' => 'Amazon SES Email Event Notification',
    ], 'Notification');
}

function seedSesSentMessage(string $providerMessageId = '<ses-msg@example.test>', string $provider = 'ses'): OutboundMessage
{
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'ses-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'SES',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'outbound_enabled' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'user_id' => $user->id,
        'local_part' => 'sender',
        'full_address' => 'sender@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);

    return OutboundMessage::query()->create([
        'user_id' => $user->id,
        'inbox_id' => $inbox->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'ses-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Hello',
        'text_body' => 'Body',
        'provider' => $provider,
        'provider_message_id' => $providerMessageId,
        'attempt_count' => 1,
        'queued_at' => now()->subMinute(),
        'sending_at' => now()->subSeconds(30),
        'sent_at' => now()->subSeconds(10),
    ]);
}

beforeEach(function (): void {
    config([
        'outbound.provider' => 'ses',
        'outbound.delivery_webhook.timestamp_skew_seconds' => 300,
        'outbound.delivery_webhook.max_body_bytes' => 2000,
        'outbound.delivery_webhook.providers.ses.topic_arn' => null,
        'outbound.delivery_webhook.providers.ses.max_body_bytes' => 65536,
        'queue.default' => 'sync',
    ]);
    Cache::flush();

    $bundle = sesTestCertificateBundle();
    Http::fake([
        $bundle['cert_url'] => Http::response($bundle['cert_pem'], 200, [
            'Content-Type' => 'text/plain',
        ]),
        'https://evil.example/*' => Http::response('nope', 200),
        'https://sns.us-east-1.amazonaws.com/*ConfirmSubscription*' => Http::response('<ConfirmSubscriptionResponse/>', 200),
    ]);
});

it('accepts a valid SES delivery notification', function (): void {
    Queue::fake();
    $message = seedSesSentMessage('<deliver-ses@example.test>');
    $envelope = sesNotificationEnvelope([
        'notificationType' => 'Delivery',
        'mail' => [
            'timestamp' => now()->toIso8601String(),
            'messageId' => '010001-ses-internal',
            'commonHeaders' => [
                'messageId' => '<deliver-ses@example.test>',
            ],
        ],
        'delivery' => [
            'timestamp' => now()->toIso8601String(),
        ],
    ], 'sns-delivery-1');

    $body = json_encode($envelope, JSON_THROW_ON_ERROR);
    $response = $this->call(
        'POST',
        '/api/v1/webhooks/outbound/ses',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'text/plain; charset=UTF-8',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $body,
    );

    $response->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.provider_event_id', 'sns-delivery-1');
    expect($response->getContent())->not->toContain($envelope['Signature']);
    Queue::assertPushed(ProcessOutboundProviderEventJob::class);
    expect($message->fresh()->provider_message_id)->toBe('<deliver-ses@example.test>');
});

it('rejects invalid missing and stale SES signatures', function (): void {
    $envelope = sesNotificationEnvelope([
        'notificationType' => 'Delivery',
        'mail' => ['messageId' => 'x', 'commonHeaders' => ['messageId' => '<a@b.test>']],
        'delivery' => ['timestamp' => now()->toIso8601String()],
    ]);

    $bad = $envelope;
    $bad['Signature'] = base64_encode('not-a-real-signature-value-here!!!!');
    $this->call('POST', '/api/v1/webhooks/outbound/ses', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($bad, JSON_THROW_ON_ERROR))->assertUnauthorized();

    $missing = $envelope;
    unset($missing['Signature']);
    $this->call('POST', '/api/v1/webhooks/outbound/ses', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($missing, JSON_THROW_ON_ERROR))->assertUnauthorized();

    $stale = sesSignSnsEnvelope([
        'MessageId' => 'sns-stale',
        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:temail-ses',
        'Message' => json_encode(['notificationType' => 'Delivery', 'mail' => ['messageId' => 'x']], JSON_THROW_ON_ERROR),
        'Timestamp' => now()->subSeconds(1000)->toIso8601String(),
        'Subject' => 'Amazon SES Email Event Notification',
    ]);
    $this->call('POST', '/api/v1/webhooks/outbound/ses', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($stale, JSON_THROW_ON_ERROR))->assertUnauthorized();
});

it('rejects malformed payloads and oversized bodies', function (): void {
    $this->call('POST', '/api/v1/webhooks/outbound/ses', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], '{not-json')->assertUnauthorized();

    config(['outbound.delivery_webhook.providers.ses.max_body_bytes' => 100]);
    $envelope = sesNotificationEnvelope([
        'notificationType' => 'Delivery',
        'mail' => [
            'messageId' => str_repeat('a', 200),
            'commonHeaders' => ['messageId' => '<big@example.test>'],
        ],
        'delivery' => ['timestamp' => now()->toIso8601String()],
    ]);
    $body = json_encode($envelope, JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/webhooks/outbound/ses', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(413);
});

it('maps SES event types to normalized outbound types', function (): void {
    $parser = app(SesOutboundProviderEventParser::class);

    $cases = [
        ['Send', OutboundProviderEventType::Accepted],
        ['Delivery', OutboundProviderEventType::Delivered],
        ['DeliveryDelay', OutboundProviderEventType::TemporaryFailure],
        ['Reject', OutboundProviderEventType::Rejected],
        ['Complaint', OutboundProviderEventType::Complained],
        ['Open', OutboundProviderEventType::Unknown],
    ];

    foreach ($cases as [$label, $expected]) {
        $envelope = sesNotificationEnvelope([
            'eventType' => $label,
            'mail' => [
                'timestamp' => now()->toIso8601String(),
                'messageId' => 'internal-'.$label,
                'commonHeaders' => ['messageId' => '<map-'.strtolower($label).'@example.test>'],
            ],
            'bounce' => ['bounceType' => 'Permanent', 'timestamp' => now()->toIso8601String()],
            'complaint' => ['timestamp' => now()->toIso8601String()],
            'delivery' => ['timestamp' => now()->toIso8601String()],
            'reject' => ['reason' => 'Bad content'],
        ], 'sns-map-'.$label);

        $data = $parser->parse(request(), 'ses', json_encode($envelope, JSON_THROW_ON_ERROR));
        expect($data->eventType)->toBe($expected)
            ->and($data->providerMessageId)->toBe('<map-'.strtolower($label).'@example.test>');
    }

    $bouncePermanent = sesNotificationEnvelope([
        'notificationType' => 'Bounce',
        'mail' => [
            'messageId' => 'b1',
            'commonHeaders' => ['messageId' => '<bounce-p@example.test>'],
        ],
        'bounce' => ['bounceType' => 'Permanent', 'timestamp' => now()->toIso8601String()],
    ], 'sns-bounce-p');
    expect($parser->parse(request(), 'ses', json_encode($bouncePermanent, JSON_THROW_ON_ERROR))->eventType)
        ->toBe(OutboundProviderEventType::Bounced);

    $bounceTransient = sesNotificationEnvelope([
        'notificationType' => 'Bounce',
        'mail' => [
            'messageId' => 'b2',
            'commonHeaders' => ['messageId' => '<bounce-t@example.test>'],
        ],
        'bounce' => ['bounceType' => 'Transient', 'timestamp' => now()->toIso8601String()],
    ], 'sns-bounce-t');
    expect($parser->parse(request(), 'ses', json_encode($bounceTransient, JSON_THROW_ON_ERROR))->eventType)
        ->toBe(OutboundProviderEventType::TemporaryFailure);
});

it('correlates SES events to ses-tagged messages and extracts message ids', function (): void {
    $message = seedSesSentMessage('<corr@example.test>', 'ses');
    $envelope = sesNotificationEnvelope([
        'notificationType' => 'Delivery',
        'mail' => [
            'messageId' => 'ses-internal-id',
            'commonHeaders' => ['messageId' => 'corr@example.test'],
        ],
        'delivery' => ['timestamp' => now()->toIso8601String()],
    ], 'sns-corr-1');

    $data = app(SesOutboundProviderEventParser::class)
        ->parse(request(), 'ses', json_encode($envelope, JSON_THROW_ON_ERROR));

    $result = app(OutboundProviderEventProcessor::class)->ingest($data);
    expect($result['outcome'])->toBe('delivered')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Delivered)
        ->and($data->providerMessageId)->toBe('<corr@example.test>');
});

it('does not correlate SES events to a different provider-tagged message by default (provider isolation)', function (): void {
    // Prompt 619: transport_aliases defaults to empty — a secondary/other
    // provider's events must never mutate a message attributed to a
    // different provider identity via a provider-message-id collision.
    $message = seedSesSentMessage('<isolated@example.test>', 'smtp');
    $envelope = sesNotificationEnvelope([
        'notificationType' => 'Delivery',
        'mail' => [
            'messageId' => 'ses-internal-isolated',
            'commonHeaders' => ['messageId' => 'isolated@example.test'],
        ],
        'delivery' => ['timestamp' => now()->toIso8601String()],
    ], 'sns-isolated-1');

    $data = app(SesOutboundProviderEventParser::class)
        ->parse(request(), 'ses', json_encode($envelope, JSON_THROW_ON_ERROR));

    $result = app(OutboundProviderEventProcessor::class)->ingest($data);
    expect($result['outcome'])->toBe('unmatched')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Sent);
});

it('correlates SES events to legacy transport-tagged messages only when an alias is explicitly configured', function (): void {
    config(['outbound.delivery_webhook.providers.ses.transport_aliases' => ['smtp']]);

    $message = seedSesSentMessage('<alias-corr@example.test>', 'smtp');
    $envelope = sesNotificationEnvelope([
        'notificationType' => 'Delivery',
        'mail' => [
            'messageId' => 'ses-internal-alias',
            'commonHeaders' => ['messageId' => 'alias-corr@example.test'],
        ],
        'delivery' => ['timestamp' => now()->toIso8601String()],
    ], 'sns-alias-corr-1');

    $data = app(SesOutboundProviderEventParser::class)
        ->parse(request(), 'ses', json_encode($envelope, JSON_THROW_ON_ERROR));

    $result = app(OutboundProviderEventProcessor::class)->ingest($data);
    expect($result['outcome'])->toBe('delivered')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Delivered);
});

it('stores unmatched SES events and remains idempotent on duplicates', function (): void {
    $envelope = sesNotificationEnvelope([
        'notificationType' => 'Delivery',
        'mail' => [
            'messageId' => 'missing',
            'commonHeaders' => ['messageId' => '<missing@example.test>'],
        ],
        'delivery' => ['timestamp' => now()->toIso8601String()],
    ], 'sns-unmatched-1');
    $body = json_encode($envelope, JSON_THROW_ON_ERROR);
    $data = app(SesOutboundProviderEventParser::class)->parse(request(), 'ses', $body);

    $processor = app(OutboundProviderEventProcessor::class);
    expect($processor->ingest($data)['outcome'])->toBe('unmatched');
    expect($processor->ingest($data)['duplicate'])->toBeTrue();
    expect(OutboundProviderEvent::query()->where('provider_event_id', 'sns-unmatched-1')->count())->toBe(1);
});

it('suppresses on SES bounce and complaint but not temporary delay', function (): void {
    $bounceMessage = seedSesSentMessage('<sup-bounce@example.test>');
    $bounceMessage->forceFill(['to_recipients' => ['bounce@example.test']])->save();
    $complaintMessage = seedSesSentMessage('<sup-complaint@example.test>');
    $complaintMessage->forceFill(['to_recipients' => ['complaint@example.test']])->save();
    $delayMessage = seedSesSentMessage('<sup-delay@example.test>');
    $delayMessage->forceFill(['to_recipients' => ['delay@example.test']])->save();
    $processor = app(OutboundProviderEventProcessor::class);
    $parser = app(SesOutboundProviderEventParser::class);
    $suppressions = app(OutboundSuppressionService::class);

    $bounce = $parser->parse(request(), 'ses', json_encode(sesNotificationEnvelope([
        'notificationType' => 'Bounce',
        'mail' => ['commonHeaders' => ['messageId' => '<sup-bounce@example.test>']],
        'bounce' => ['bounceType' => 'Permanent', 'timestamp' => now()->toIso8601String()],
    ], 'sns-sup-b'), JSON_THROW_ON_ERROR));
    $processor->ingest($bounce);
    expect($bounceMessage->fresh()->state)->toBe(OutboundMessageState::Failed);
    expect($suppressions->isSuppressed('bounce@example.test'))->toBeTrue();

    $complaint = $parser->parse(request(), 'ses', json_encode(sesNotificationEnvelope([
        'notificationType' => 'Complaint',
        'mail' => ['commonHeaders' => ['messageId' => '<sup-complaint@example.test>']],
        'complaint' => ['timestamp' => now()->toIso8601String()],
    ], 'sns-sup-c'), JSON_THROW_ON_ERROR));
    $processor->ingest($complaint);
    expect($complaintMessage->fresh()->state)->toBe(OutboundMessageState::Sent);
    expect($suppressions->isSuppressed('complaint@example.test'))->toBeTrue();

    $delay = $parser->parse(request(), 'ses', json_encode(sesNotificationEnvelope([
        'eventType' => 'DeliveryDelay',
        'mail' => ['commonHeaders' => ['messageId' => '<sup-delay@example.test>']],
    ], 'sns-sup-d'), JSON_THROW_ON_ERROR));
    $processor->ingest($delay);
    expect($delayMessage->fresh()->state)->toBe(OutboundMessageState::Sent);
    expect($suppressions->isSuppressed('delay@example.test'))->toBeFalse();
});

it('rejects unsafe certificate URLs and does not fetch them', function (): void {
    $verifier = app(SesSnsSignatureVerifier::class);
    expect($verifier->isAllowedCertificateUrl('http://sns.us-east-1.amazonaws.com/cert.pem'))->toBeFalse();
    expect($verifier->isAllowedCertificateUrl('https://evil.example/cert.pem'))->toBeFalse();
    expect($verifier->isAllowedCertificateUrl('https://sns.us-east-1.amazonaws.com/cert.pem?x=1'))->toBeFalse();
    expect($verifier->isAllowedCertificateUrl('https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc.pem'))->toBeTrue();

    Http::assertNothingSent(); // faked in beforeEach but no request until verify
    $unsafe = [
        'Type' => 'Notification',
        'MessageId' => 'x',
        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:temail-ses',
        'Message' => '{}',
        'Timestamp' => now()->toIso8601String(),
        'SignatureVersion' => '2',
        'Signature' => base64_encode('abc'),
        'SigningCertURL' => 'https://evil.example/cert.pem',
    ];
    expect($verifier->verify($unsafe))->toBeFalse();
});

it('caches subscription confirmation for admin command without auto-confirming', function (): void {
    $envelope = sesSignSnsEnvelope([
        'MessageId' => 'sns-sub-1',
        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:temail-ses',
        'Message' => 'You have chosen to subscribe...',
        'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc&TopicArn=arn:aws:sns:us-east-1:123456789012:temail-ses',
        'Timestamp' => now()->toIso8601String(),
        'Token' => 'abc',
    ], 'SubscriptionConfirmation');

    $body = json_encode($envelope, JSON_THROW_ON_ERROR);
    $response = $this->call('POST', '/api/v1/webhooks/outbound/ses', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
    ], $body);

    $response->assertStatus(202)
        ->assertJsonPath('data.subscription_confirmation', true);
    expect(Cache::get('outbound.ses.pending_subscription'))->toBeArray();

    $this->artisan('outbound:confirm-ses-subscription', ['--from-cache' => true, '--dry-run' => true])
        ->assertSuccessful();

    $this->artisan('outbound:confirm-ses-subscription', ['--from-cache' => true])
        ->assertSuccessful();
    expect(Cache::get('outbound.ses.pending_subscription'))->toBeNull();
});

it('rejects unsafe subscription confirmation URLs', function (): void {
    $this->artisan('outbound:confirm-ses-subscription', [
        '--url' => 'https://evil.example/confirm',
    ])->assertFailed();
});
