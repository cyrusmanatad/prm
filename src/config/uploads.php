<?php

return [
    'enabled' => env('UPLOADS_ENABLED', true),
    'staging_unscanned' => env('STAGING_UNSCANNED_UPLOADS', false),
    'scanner_host' => env('CLAMAV_HOST', 'clamav'),
];
