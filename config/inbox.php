<?php

return [
    'public_mail_server_pool' => (($pool = env('PUBLIC_MAIL_SERVER_POOL')) !== null && trim($pool) !== '')
        ? trim($pool)
        : null,

    // These platform addresses must never be allocated as customer aliases.
    'reserved_local_parts' => ['admin', 'administrator', 'api', 'abuse', 'billing', 'help', 'postmaster', 'root', 'security', 'support', 'system'],
];
