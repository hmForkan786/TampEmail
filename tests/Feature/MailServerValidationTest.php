<?php

use App\Http\Requests\MailServer\CreateMailServerRequest;
use App\Http\Requests\MailServer\UpdateMailServerRequest;
use Illuminate\Support\Facades\Validator;

it('accepts nullable pool and unlimited capacity on create', function (): void {
    $validator = Validator::make(
        [
            'name' => 'Inbound',
            'hostname' => 'mail.example.test',
            'provider' => 'smtp',
            'protocol' => 'smtp',
            'pool_key' => null,
            'max_inboxes' => null,
        ],
        (new CreateMailServerRequest)->rules(),
    );

    expect($validator->passes())->toBeTrue();
});

it('accepts missing provisioning fields on update', function (): void {
    $validator = Validator::make([], (new UpdateMailServerRequest)->rules());

    expect($validator->passes())->toBeTrue();
});

it('rejects blank pools and non-positive capacities', function (): void {
    $validator = Validator::make(
        ['pool_key' => '   ', 'max_inboxes' => 0],
        (new CreateMailServerRequest)->rules(),
    );

    expect($validator->fails())->toBeTrue();
});
