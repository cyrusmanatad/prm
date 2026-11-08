<?php

namespace App\Services;

use Illuminate\Http\Request;

class PartnershipInvitation
{
    // Crockford base32 without ambiguous characters; ten characters give 50 bits for a throttled public code.
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Brand sign-up code and brand name retained in this session while they remain valid. */
    public static function join(Request $request): ?array
    {
        $join = $request->session()->get('partnership_join');

        return is_array($join) && $join['expires'] > time() ? ['code' => decrypt($join['encrypted']), 'brand' => decrypt($join['brand'])] : null;
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
