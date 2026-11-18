<?php

return [
    // Zero means no configured limit; provider/production budget acceptance remains separate.
    'workspace_per_minute' => env('NOTIFICATION_WORKSPACE_PER_MINUTE', 0),
    'global_per_minute' => env('NOTIFICATION_GLOBAL_PER_MINUTE', 0),
    // Account-scoped notices (Brand request outcomes) count against the global limit and this per-account limit.
    'account_per_minute' => env('NOTIFICATION_ACCOUNT_PER_MINUTE', 0),
];
