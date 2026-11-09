<?php

// Staging FPM is reachable only from its private Nginx container, which replaces forwarded headers.
return [
    'proxies' => env('STAGING_MODE', false) ? '*' : null,
];
