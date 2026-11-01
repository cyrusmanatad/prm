<?php

namespace App\Services;

use Illuminate\Http\Request;

class PartnershipInvitation
{
    /** Recipient of the invitation retained in this session while it remains valid. */
    public static function recipient(Request $request): ?string
    {
        $token = $request->session()->get('partnership_token');

        return is_array($token) && isset($token['recipient']) && $token['expires'] > time() ? decrypt($token['recipient']) : null;
    }

    /** Partner sign-in/registration props that lock the email field to the invited recipient. */
    public static function authProps(Request $request, string $portal): array
    {
        $recipient = $portal === 'partner' ? self::recipient($request) : null;

        return $recipient ? ['email' => $recipient, 'invitation' => true] : [];
    }
}
