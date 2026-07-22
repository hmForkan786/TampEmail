Exit code: 0
Wall time: 0.7 seconds
Output:
<?php

use App\Models\ApiKey;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\ApiKey\ApiKeyResolver;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use App\Services\ApiKey\ApiKeyTokenHasher;

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'test-api-key-secret']);
});

it('generates deterministic v1 hashes and canonical tokens', function (): void {
    $hasher = new ApiKeyTokenHasher;
    $generator = new ApiKeyTokenGenerator($hasher);
    $credentials = $generator->generate();

    expect($credentials['plain_token'])->toMatch('/^te_live_[A-Za-z0-9_-]{43}$/')
        ->and($credentials['key_prefix'])->toBe(substr($credentials['plain_token'], 0, 16))
        ->and($credentials['key_hash'])->toBe($hasher->hash($credentials['plain_token']))
        ->and($credentials['key_hash'])->toStartWith('v1:');
});

it('resolves only active canonical keys', function (): void {
    $hasher = new ApiKeyTokenHasher;
    $credentials = (new ApiKeyTokenGenerator($hasher))->generate();
    $apiKey = new ApiKey(['key_hash' => $credentials['key_hash']]);
    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->expects('findActiveByPrefixAndHash')
        ->with($credentials['key_prefix'], $credentials['key_hash'])
        ->andReturn($apiKey);

    expect((new ApiKeyResolver($repository, $hasher))->resolve($credentials['plain_token']))->toBe($apiKey)
        ->and((new ApiKeyResolver($repository, $hasher))->resolve('invalid-token'))->toBeNull();
});

it('rejects wrong hashes and legacy records', function (): void {
    $hasher = new ApiKeyTokenHasher;
    $credentials = (new ApiKeyTokenGenerator($hasher))->generate();
    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->expects('findActiveByPrefixAndHash')->andReturn(new ApiKey(['key_hash' => 'legacy-hash']));

    expect((new ApiKeyResolver($repository, $hasher))->resolve($credentials['plain_token']))->toBeNull();
});
