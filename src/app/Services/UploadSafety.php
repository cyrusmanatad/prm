<?php

namespace App\Services;

class UploadSafety
{
    public static function unscannedAllowed(): bool
    {
        return (bool) config('staging.enabled') && (bool) config('uploads.staging_unscanned');
    }

    public static function permits(string $state): bool
    {
        return $state === 'clean' || ($state === 'unscanned' && self::unscannedAllowed());
    }
}
