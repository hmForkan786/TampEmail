<?php

return [
    'public_mail_server_pool' => (($pool = env('PUBLIC_MAIL_SERVER_POOL')) !== null && trim($pool) !== '')
        ? trim($pool)
        : null,
];
