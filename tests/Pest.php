<?php

use Tests\TestCase;

require_once __DIR__.'/Support/outbound_helpers.php';
require_once __DIR__.'/Support/mail_server_helpers.php';

pest()->extend(TestCase::class)
    ->in('Feature');
