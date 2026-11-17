<?php

return [
    // Workspace-owner MFA (D35). Production always enforces it; this only applies to other environments.
    'owner_mfa' => (bool) env('OWNER_MFA_ENFORCED', true),
];
