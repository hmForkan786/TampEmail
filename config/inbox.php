<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Mail Server Pool
    |--------------------------------------------------------------------------
    |
    | Explicit pool key for anonymous/public inbox provisioning. When null,
    | anonymous mail-server assignment is disabled. Only servers whose pool_key
    | exactly matches this value may be selected for anonymous flows.
    |
    | Servers with pool_key = null are never eligible for anonymous provisioning,
    | regardless of other attributes. This configuration is the sole authority
    | for public mail-server exposure.
    |
    */

    'public_mail_server_pool' => (($pool = env('PUBLIC_MAIL_SERVER_POOL')) !== null
        && ($trimmed = trim((string) $pool)) !== '')
        ? $trimmed
        : null,

];
