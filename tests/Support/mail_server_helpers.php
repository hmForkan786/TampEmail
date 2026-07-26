<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\PlatformRole;
use App\Models\ApiKey;
use App\Models\User;

if (! function_exists('issueMailServerApiKey')) {
    /**
     * @return array{0: User, 1: string, 2: ApiKey}
     */
    function issueMailServerApiKey(array $scopes = ['mail_servers:read']): array
    {
        $role = in_array('mail_servers:admin', $scopes, true)
            ? PlatformRole::Admin
            : PlatformRole::Operator;

        $user = User::factory()->create(['platform_role' => $role]);
        $issued = app(CreateApiKeyAction::class)->issue(
            userId: $user->id,
            name: 'mail-server-test',
            permissions: $scopes,
            user: $user,
        );

        return [$user, $issued->plainToken, $issued->apiKey];
    }
}

if (! function_exists('mailServerPayload')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function mailServerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Primary inbound',
            'hostname' => 'mail.example.test',
            'provider' => 'smtp',
            'protocol' => 'smtp',
            'pool_key' => 'standard',
            'max_inboxes' => 25,
        ], $overrides);
    }
}
