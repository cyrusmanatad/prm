/**
 * Key URI that authenticator apps read from a QR code. The parameters match the server's
 * verification: RFC 6238 with SHA-1, six digits and a thirty-second period.
 */
export function enrollmentUri(secret: string, account: string, issuer = 'WeavDash'): string {
    const label = `${encodeURIComponent(issuer)}:${encodeURIComponent(account)}`;
    const query = `secret=${encodeURIComponent(secret)}&issuer=${encodeURIComponent(issuer)}&algorithm=SHA1&digits=6&period=30`;
    return `otpauth://totp/${label}?${query}`;
}
