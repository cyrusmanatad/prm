import { fromMinor, money, toMinor } from '../pipeline/money';

/** A well-formed ISO currency code the browser can format. */
export function validCurrency(currency: string): boolean {
    if (!/^[A-Z]{3}$/.test(currency)) return false;
    try {
        new Intl.NumberFormat('en', { style: 'currency', currency });
        return true;
    } catch {
        return false;
    }
}
/** "USD 2,500.00" from an exact minor-unit string; falls back to the raw minor units for unknown codes. */
export function formatMoney(minor: string, currency: string): string {
    return validCurrency(currency) ? money(minor, currency) : `${currency} ${minor} minor units`;
}
/** "2,500.00" without the currency code, for line items under a currency heading. */
export function formatAmount(minor: string, currency: string): string {
    return validCurrency(currency) ? money(minor, currency).slice(currency.length + 1) : minor;
}
/** The amount as typed in the currency's units ("2500.00"), from exact minor units. */
export function amountInput(minor: string, currency: string): string {
    return validCurrency(currency) ? fromMinor(minor, currency) : minor;
}
/** Exact minor units from "2,500.00" in the currency's units, or null when the amount is not valid for it. */
export function parseAmount(input: string, currency: string): string | null {
    if (!validCurrency(currency)) return null;
    return toMinor(input.replace(/[,\s]/g, ''), currency);
}
export const subtract = (a: string, b: string): string => (BigInt(a) - BigInt(b)).toString();
export const multiply = (quantity: number, unit: string): string => (BigInt(quantity) * BigInt(unit)).toString();
export const smaller = (a: string, b: string): string => (BigInt(a) < BigInt(b) ? a : b);
/** Share of `part` in `whole`, as a whole percentage for a progress bar. */
export function percent(part: string, whole: string): number {
    const total = BigInt(whole);
    return total > 0n ? Number((BigInt(part) * 100n) / total) : 0;
}
export function dateLabel(value: string | null): string {
    if (!value) return '—';
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);
    return new Date(year!, month! - 1, day!).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
export function today(): string {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}
