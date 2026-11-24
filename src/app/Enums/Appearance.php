<?php

namespace App\Enums;

use Illuminate\Http\Request;

/** A signed-in account's personal accent color. Presentation only; it never changes access or shared content. */
enum Appearance: string
{
    case Burgundy = 'burgundy';
    case Purple = 'purple';

    /** Public and shared pages (invitation, onboarding and lead forms) always keep the default accent. */
    private const PUBLIC_PATHS = ['join', 'join/*', 'partnerships/accept', 'onboarding', 'onboarding/*', 'lead', 'kits/*'];

    /** Any absent or unrecognized stored value falls back to the default. */
    public static function normalize(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::Burgundy;
    }

    /** The accent for this response: the signed-in account's saved choice, or the default for guests and public pages. */
    public static function for(Request $request): self
    {
        $user = $request->user();

        return $user && ! $request->is(...self::PUBLIC_PATHS) ? self::normalize($user->appearance) : self::Burgundy;
    }
}
