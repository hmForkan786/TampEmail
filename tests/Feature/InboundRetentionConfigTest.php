<?php

it('loads bounded inbound retention defaults and keeps cleanup disabled', function (): void {
    expect(config('inbound_retention.email_days'))->toBe(30)
        ->and(config('inbound_retention.attachment.pending_days'))->toBe(7)
        ->and(config('inbound_retention.batch_size'))->toBe(500)
        ->and(config('inbound_retention.cleanup_enabled'))->toBeFalse()
        ->and(config('inbound_retention.legal_hold_required'))->toBeTrue();
});

it('fails closed for invalid inbound retention values', function (): void {
    config()->set('inbound_retention.email_days', 0);
    config()->set('inbound_retention.attachment.clean_days', 0);
    config()->set('inbound_retention.failure_days', 0);
    expect(config('inbound_retention.email_days'))->toBe(0)
        ->and(config('inbound_retention.attachment.clean_days'))->toBe(0)
        ->and(config('inbound_retention.failure_days'))->toBe(0);
});
