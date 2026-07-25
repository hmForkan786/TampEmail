<?php

declare(strict_types=1);

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundMessageData;
use App\Enums\OutboundTransportResult;
use App\Exceptions\OutboundSendException;
use App\Services\Outbound\LaravelMailOutboundTransport;
use App\Services\Outbound\OutboundHeaderGuard;
use App\Services\Outbound\OutboundTransportConfigValidator;
use App\Services\Outbound\OutboundTransportFailureMapper;
use App\Services\Outbound\OutboundTransportManager;
use App\Services\Outbound\UnavailableOutboundTransport;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'outbound.enabled' => true,
        'outbound.transport' => 'unavailable',
        'outbound.mailer' => 'outbound',
        'outbound.smtp.host' => null,
        'outbound.smtp.port' => 587,
        'outbound.smtp.username' => null,
        'outbound.smtp.password' => null,
        'outbound.smtp.encryption' => 'tls',
        'outbound.smtp.timeout' => 30,
        'outbound.smtp.require_auth' => true,
        'outbound.smtp.verify_peer' => true,
        'mail.mailers.outbound' => [
            'transport' => 'smtp',
            'host' => null,
            'port' => 587,
            'username' => null,
            'password' => null,
            'timeout' => 30,
        ],
        'mail.mailers.array' => [
            'transport' => 'array',
        ],
    ]);
});

it('keeps unavailable transport as the default binding', function (): void {
    config(['outbound.transport' => 'unavailable']);
    $transport = app(OutboundTransportManager::class)->resolve();

    expect($transport)->toBeInstanceOf(UnavailableOutboundTransport::class);
    expect(app(OutboundTransportInterface::class))->toBeInstanceOf(UnavailableOutboundTransport::class);
});

it('binds the smtp mailer adapter when configuration is valid', function (): void {
    config([
        'outbound.transport' => 'smtp',
        'outbound.mailer' => 'outbound',
        'outbound.smtp.host' => 'smtp.example.test',
        'outbound.smtp.port' => 587,
        'outbound.smtp.username' => 'user',
        'outbound.smtp.password' => 'secret-password',
        'outbound.smtp.encryption' => 'tls',
        'mail.mailers.outbound.host' => 'smtp.example.test',
        'mail.mailers.outbound.username' => 'user',
        'mail.mailers.outbound.password' => 'secret-password',
    ]);

    $transport = app(OutboundTransportManager::class)->resolve();
    expect($transport)->toBeInstanceOf(LaravelMailOutboundTransport::class);
});

it('fails closed for unsupported transport drivers', function (): void {
    config(['outbound.transport' => 'log']);
    $result = app(OutboundTransportManager::class)->resolve()->send(sampleMessage());

    expect($result->result)->toBe(OutboundTransportResult::ConfigurationFailure)
        ->and($result->failureCode)->toBe('invalid_config');
});

it('accepts valid smtp configuration without sending mail', function (): void {
    config([
        'outbound.transport' => 'smtp',
        'outbound.smtp.host' => 'smtp.example.test',
        'outbound.smtp.port' => 465,
        'outbound.smtp.username' => 'user',
        'outbound.smtp.password' => 'secret',
        'outbound.smtp.encryption' => 'ssl',
    ]);

    $validation = app(OutboundTransportConfigValidator::class)->validate();
    expect($validation['valid'])->toBeTrue()
        ->and($validation['failure_code'])->toBeNull()
        ->and(json_encode($validation))->not->toContain('secret');
});

it('rejects missing smtp host', function (): void {
    config([
        'outbound.transport' => 'smtp',
        'outbound.smtp.host' => '',
        'outbound.smtp.username' => 'user',
        'outbound.smtp.password' => 'secret',
    ]);

    $validation = app(OutboundTransportConfigValidator::class)->validate();
    expect($validation['valid'])->toBeFalse()
        ->and($validation['failure_code'])->toBe('missing_host');
});

it('rejects invalid smtp port', function (): void {
    config([
        'outbound.transport' => 'smtp',
        'outbound.smtp.host' => 'smtp.example.test',
        'outbound.smtp.port' => 99999,
        'outbound.smtp.username' => 'user',
        'outbound.smtp.password' => 'secret',
    ]);

    $validation = app(OutboundTransportConfigValidator::class)->validate();
    expect($validation['valid'])->toBeFalse()
        ->and($validation['failure_code'])->toBe('invalid_port');
});

it('rejects invalid encryption values', function (): void {
    config([
        'outbound.transport' => 'smtp',
        'outbound.smtp.host' => 'smtp.example.test',
        'outbound.smtp.username' => 'user',
        'outbound.smtp.password' => 'secret',
        'outbound.smtp.encryption' => 'magic',
    ]);

    $validation = app(OutboundTransportConfigValidator::class)->validate();
    expect($validation['valid'])->toBeFalse()
        ->and($validation['failure_code'])->toBe('invalid_encryption');
});

it('rejects missing credentials when auth is required', function (): void {
    config([
        'outbound.transport' => 'smtp',
        'outbound.smtp.host' => 'smtp.example.test',
        'outbound.smtp.username' => '',
        'outbound.smtp.password' => '',
        'outbound.smtp.require_auth' => true,
    ]);

    $validation = app(OutboundTransportConfigValidator::class)->validate();
    expect($validation['valid'])->toBeFalse()
        ->and($validation['failure_code'])->toBe('missing_credentials');
});

it('maps recipients headers and bodies through the array mailer adapter', function (): void {
    config([
        'outbound.transport' => 'array',
        'outbound.mailer' => 'array',
        'mail.default' => 'array',
    ]);

    $transport = new LaravelMailOutboundTransport('array', 'array');
    $result = $transport->send(new OutboundMessageData(
        messageId: '11111111-1111-1111-1111-111111111111',
        fromAddress: 'inbox@example.test',
        fromDisplayName: 'Inbox',
        to: ['to@example.test'],
        cc: ['cc@example.test'],
        bcc: ['bcc-secret@example.test'],
        subject: 'Hello',
        textBody: 'Plain text',
        htmlBody: '<p>Hello</p>',
        inReplyTo: '<parent@example.test>',
        references: '<root@example.test> <parent@example.test>',
    ));

    expect($result->result)->toBe(OutboundTransportResult::Accepted)
        ->and($result->providerMessageId)->toStartWith('<11111111-1111-1111-1111-111111111111@')
        ->and($result->providerMessageId)->toEndWith('>');

    /** @var ArrayTransport $arrayTransport */
    $arrayTransport = Mail::mailer('array')->getSymfonyTransport();
    $messages = $arrayTransport->messages();
    expect($messages)->toHaveCount(1);
    $raw = $messages[0]->toString();
    expect($raw)->toContain('to@example.test')
        ->and($raw)->toContain('cc@example.test')
        ->and($raw)->toContain('Plain text')
        ->and($raw)->toContain('In-Reply-To')
        ->and($raw)->toContain('References')
        ->and($raw)->toContain('Message-ID');
});

it('submits through the array mailer and does not leak bcc into result metadata', function (): void {
    config([
        'mail.default' => 'array',
        'outbound.transport' => 'array',
    ]);

    $transport = new LaravelMailOutboundTransport('array', 'array');
    $result = $transport->send(sampleMessage([
        'bcc' => ['hidden@example.test'],
        'subject' => 'Safe subject',
    ]));

    expect($result->result)->toBe(OutboundTransportResult::Accepted)
        ->and($result->toArray())->not->toHaveKey('raw_smtp')
        ->and(json_encode($result->toArray()))->not->toContain('hidden@example.test');
});

it('streams a clean attachment from private storage', function (): void {
    Storage::fake('attachments');
    Storage::disk('attachments')->put('keep/me.txt', 'attachment-bytes');

    config(['mail.default' => 'array']);
    $transport = new LaravelMailOutboundTransport('array', 'array');
    $result = $transport->send(sampleMessage([
        'attachments' => [[
            'filename' => 'me.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 16,
            'storage_disk' => 'attachments',
            'storage_path' => 'keep/me.txt',
        ]],
    ]));

    expect($result->result)->toBe(OutboundTransportResult::Accepted);
    /** @var ArrayTransport $arrayTransport */
    $arrayTransport = Mail::mailer('array')->getSymfonyTransport();
    expect($arrayTransport->messages()[0]->toString())->toContain('me.txt');
});

it('fails closed when an attachment object is missing', function (): void {
    Storage::fake('attachments');
    config(['mail.default' => 'array']);

    $transport = new LaravelMailOutboundTransport('array', 'array');
    $result = $transport->send(sampleMessage([
        'attachments' => [[
            'filename' => 'gone.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'storage_disk' => 'attachments',
            'storage_path' => 'missing/file.txt',
        ]],
    ]));

    expect($result->result)->toBe(OutboundTransportResult::PermanentFailure)
        ->and($result->failureCode)->toBe('attachment_unavailable');
});

it('maps temporary and permanent transport exceptions safely', function (): void {
    $mapper = new OutboundTransportFailureMapper;

    $temporary = $mapper->map(new RuntimeException('Connection timed out to smtp host'), 'smtp');
    expect($temporary->result)->toBe(OutboundTransportResult::TemporaryFailure)
        ->and($temporary->failureCode)->toBe('timeout')
        ->and($temporary->failureMessage)->not->toContain('smtp host');

    $permanent = $mapper->map(new RuntimeException('550 invalid recipient'), 'smtp');
    expect($permanent->result)->toBe(OutboundTransportResult::PermanentFailure)
        ->and($permanent->failureCode)->toBe('smtp_550');

    $config = $mapper->map(new RuntimeException('535 Authentication failed password=super-secret'), 'smtp');
    expect($config->result)->toBe(OutboundTransportResult::ConfigurationFailure)
        ->and($config->failureCode)->toBe('credentials_rejected')
        ->and(json_encode($config->toArray()))->not->toContain('super-secret');
});

it('guards against header injection and invalid message ids', function (): void {
    $guard = new OutboundHeaderGuard;

    expect(fn () => $guard->sanitizeEnvelope(
        fromAddress: "inbox@example.test\r\nBcc: evil@example.test",
        fromDisplayName: null,
        subject: 'Hi',
        outboundMessageId: 'abc',
        inReplyTo: null,
        references: null,
    ))->toThrow(OutboundSendException::class);

    $envelope = $guard->sanitizeEnvelope(
        fromAddress: 'inbox@example.test',
        fromDisplayName: 'Inbox',
        subject: 'Hi',
        outboundMessageId: 'abc-123',
        inReplyTo: '<parent@example.test>',
        references: '<root@example.test> <parent@example.test>',
    );

    expect($envelope['message_id'])->toStartWith('<abc-123@')
        ->and($envelope['in_reply_to'])->toBe('<parent@example.test>');
});

it('does not send mail during readiness validation', function (): void {
    Mail::fake();
    config([
        'outbound.enabled' => true,
        'outbound.transport' => 'smtp',
        'outbound.smtp.host' => 'smtp.example.test',
        'outbound.smtp.username' => 'user',
        'outbound.smtp.password' => 'secret',
    ]);

    $validation = app(OutboundTransportConfigValidator::class)->validate();
    expect($validation['valid'])->toBeTrue()
        ->and($validation['failure_code'])->toBeNull();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function sampleMessage(array $overrides = []): OutboundMessageData
{
    return new OutboundMessageData(
        messageId: (string) ($overrides['messageId'] ?? '22222222-2222-2222-2222-222222222222'),
        fromAddress: (string) ($overrides['fromAddress'] ?? 'inbox@example.test'),
        fromDisplayName: $overrides['fromDisplayName'] ?? 'Inbox',
        to: $overrides['to'] ?? ['to@example.test'],
        cc: $overrides['cc'] ?? [],
        bcc: $overrides['bcc'] ?? [],
        subject: (string) ($overrides['subject'] ?? 'Subject'),
        textBody: $overrides['textBody'] ?? 'Body',
        htmlBody: $overrides['htmlBody'] ?? null,
        inReplyTo: $overrides['inReplyTo'] ?? null,
        references: $overrides['references'] ?? null,
        attachments: $overrides['attachments'] ?? [],
    );
}
