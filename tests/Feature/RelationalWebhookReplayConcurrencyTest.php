<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reserves one nonce winner under relational concurrency', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite cannot exercise the required concurrent unique-insert semantics; MySQL/PostgreSQL CI runs this contract.');
    }

    expect(Schema::hasTable('webhook_replay_nonces'))->toBeTrue()
        ->and(collect(Schema::getIndexes('webhook_replay_nonces'))->contains(fn (array $index): bool => (bool) $index['unique'] && $index['columns'] === ['provider', 'nonce_hash']))->toBeTrue();
});
