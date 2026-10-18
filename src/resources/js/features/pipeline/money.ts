export function fractionDigits(currency: string): number {
    return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
}

export function toMinor(amount: string, currency: string): string | null {
    if (!/^[A-Z]{3}$/.test(currency)) return null;
    const digits = fractionDigits(currency);
    if (!(new RegExp(`^\\d+${digits ? `(?:\\.\\d{1,${digits}})?` : ''}$`)).test(amount.trim())) return null;
    const [whole = '0', fraction = ''] = amount.trim().split('.');
    const minor = BigInt(whole + fraction.padEnd(digits, '0')).toString();
    return minor.length <= 18 ? minor : null;
}

export function fromMinor(minor: string, currency: string): string {
    const digits = fractionDigits(currency);
    if (!digits) return minor;
    const padded = minor.padStart(digits + 1, '0');
    return `${padded.slice(0, -digits)}.${padded.slice(-digits)}`;
}

export function money(minor: string, currency: string): string {
    const [whole = '0', fraction] = fromMinor(minor, currency).split('.');
    return `${currency} ${new Intl.NumberFormat('en').format(BigInt(whole))}${fraction === undefined ? '' : `.${fraction}`}`;
}
