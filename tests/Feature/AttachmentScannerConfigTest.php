<?php

it('defaults attachment scanning to disabled and bounded values', function (): void {
    expect(config('attachments.scanner_backend'))->toBe('disabled')
        ->and(config('attachments.clamav.timeout_seconds'))->toBe(30)
        ->and(config('attachments.max_bytes'))->toBe(26214400)
        ->and(config('attachments.max_count'))->toBe(20)
        ->and(config('attachments.max_total_bytes'))->toBe(52428800);
});

it('defines only explicit scanner result states', function (): void {
    expect(array_column(App\Enums\AttachmentScanResult::cases(), 'value'))->toBe(['clean', 'infected', 'failed']);
});
