<?php

return [
    // Zero means no configured limit; provider/production budget acceptance remains separate.
    'workspace_per_minute' => env('NOTIFICATION_WORKSPACE_PER_MINUTE', 0),
    'global_per_minute' => env('NOTIFICATION_GLOBAL_PER_MINUTE', 0),
];
