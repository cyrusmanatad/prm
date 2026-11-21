// Shared by the Add lead form and the lead drawer so both offer the same currencies and follow-up shortcuts.

/** Common currencies offered first; any valid three-letter code already on a lead is kept as an option. */
export const currencyCodes = [
    'PHP',
    'USD',
    'JPY',
    'EUR',
    'GBP',
    'SGD',
    'AUD',
    'CAD',
    'HKD',
    'CNY',
    'KRW',
    'THB',
    'MYR',
    'IDR',
    'VND',
    'INR',
    'AED',
];

const currencyNames = new Intl.DisplayNames(['en'], { type: 'currency' });

export function currencyHint(code: string): string {
    try {
        return currencyNames.of(code) ?? '';
    } catch {
        return '';
    }
}

export function currencyOptions(current: string): { value: string; label: string; hint: string }[] {
    const codes = [...currencyCodes];
    if (/^[A-Z]{3}$/.test(current) && !codes.includes(current)) codes.push(current);
    return codes.map((code) => ({ value: code, label: code, hint: currencyHint(code) }));
}

/** An ISO instant as a `datetime-local` value in the viewer's time zone. */
export function localInput(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}

/** 9:00 local time a number of days from now, as a `datetime-local` value. */
export function snoozeValue(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);
    date.setHours(9, 0, 0, 0);
    return localInput(date.toISOString());
}

export const snoozeChoices = [
    { days: 1, label: 'Tomorrow' },
    { days: 3, label: 'In 3 days' },
    { days: 7, label: 'Next week' },
];
