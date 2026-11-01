<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class PartnershipInvitation
{
    // Crockford base32 without ambiguous characters; ten characters give 50 bits for a throttled public code.
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Recipient of the invitation retained in this session while it remains valid. */
    public static function recipient(Request $request): ?string
    {
        $token = $request->session()->get('partnership_token');

        return is_array($token) && isset($token['recipient']) && $token['expires'] > time() ? decrypt($token['recipient']) : null;
    }

    /** Brand sign-up code and brand name retained in this session while they remain valid. */
    public static function join(Request $request): ?array
    {
        $join = $request->session()->get('partnership_join');

        return is_array($join) && $join['expires'] > time() ? ['code' => decrypt($join['encrypted']), 'brand' => decrypt($join['brand'])] : null;
    }

    /** Whether this identity may continue a retained invitation or brand sign-up. */
    public static function pendingFor(Request $request, User $user): bool
    {
        return self::join($request) !== null || self::recipient($request) === strtolower($user->email);
    }

    /** Partner sign-in/registration props: invitations lock the email; brand sign-ups name the brand. */
    public static function authProps(Request $request, string $portal): array
    {
        if ($portal !== 'partner') {
            return [];
        }
        $recipient = self::recipient($request);
        if ($recipient) {
            return ['email' => $recipient, 'invitation' => true];
        }
        $join = self::join($request);

        return $join ? ['joining' => $join['brand']] : [];
    }

    public static function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    /** Accepts typed codes with spaces, hyphens, lowercase and commonly confused characters. */
    public static function normalizeCode(string $input): ?string
    {
        $code = strtr(strtoupper(preg_replace('/[\s-]+/', '', $input)), ['O' => '0', 'I' => '1', 'L' => '1']);

        return preg_match('/^['.self::ALPHABET.']{10}$/', $code) ? $code : null;
    }

    public static function displayCode(string $code): string
    {
        return substr($code, 0, 5).'-'.substr($code, 5);
    }
}
