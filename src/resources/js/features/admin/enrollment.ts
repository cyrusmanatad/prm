const ISSUER = 'WeavDash Admin';

/**
 * Key URI that authenticator apps read from a QR code. The parameters match the server's
 * verification: RFC 6238 with SHA-1, six digits and a thirty-second period.
 */
export function enrollmentUri(secret: string, account: string): string {
    const label = `${encodeURIComponent(ISSUER)}:${encodeURIComponent(account)}`;
    const query = `secret=${encodeURIComponent(secret)}&issuer=${encodeURIComponent(ISSUER)}&algorithm=SHA1&digits=6&period=30`;
    return `otpauth://totp/${label}?${query}`;
}
