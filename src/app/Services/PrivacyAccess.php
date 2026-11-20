<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/** Account notification admission shares the same durable barrier as closure. */
class PrivacyAccess
{
    public function mail(int $account, Closure $send): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $send();

            return;
        }
        DB::transaction(function () use ($account, $send): void {
            if (DB::selectOne('SELECT app_privacy_mail_allowed(?) AS allowed', [$account])->allowed) {
                $send();
            }
        });
    }
}
