<?php

return [

    'storage' => [
        'private_disk' => env('FILESYSTEM_PRIVATE_DISK', 'local'),
        'public_disk' => env('FILESYSTEM_PUBLIC_DISK', 'public'),
        'attachments_disk' => env('FILESYSTEM_ATTACHMENTS_DISK', 'attachments'),
        'message_bodies_disk' => env('FILESYSTEM_MESSAGE_BODIES_DISK', 'message_bodies'),
    ],

    'logs' => [
        'security_channel' => env('LOG_SECURITY_CHANNEL', 'security'),
        'audit_channel' => env('LOG_AUDIT_CHANNEL', 'audit'),
        'queue_channel' => env('LOG_QUEUE_CHANNEL', 'queue'),
        'ingestion_channel' => env('LOG_INGESTION_CHANNEL', 'ingestion'),
    ],

];
