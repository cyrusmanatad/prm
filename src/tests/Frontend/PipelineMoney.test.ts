import { describe, expect, it } from 'vitest';
import { fromMinor, money, toMinor } from '../../resources/js/features/pipeline/money';

describe('pipeline money input and display', () => {
    it('preserves decimal amounts beyond JavaScript number precision', () => {
        expect(toMinor('90071992547409.93', 'PHP')).toBe('9007199254740993');
        expect(fromMinor('9007199254740993', 'PHP')).toBe('90071992547409.93');
        expect(money('26999999999999999973', 'PHP')).toBe('PHP 269,999,999,999,999,999.73');
    });
    it('uses currency precision without rounding submitted values', () => {
        expect(toMinor('123', 'JPY')).toBe('123');
        expect(toMinor('123.00', 'JPY')).toBeNull();
        expect(toMinor('1.234', 'KWD')).toBe('1234');
        expect(toMinor('1.234', 'USD')).toBeNull();
        expect(fromMinor('1', 'USD')).toBe('0.01');
    });
    it('rejects invalid amounts and values beyond the stored range', () => {
        for (const amount of ['-1', '1e3', '', '1,000', '10000000000000000.00']) expect(toMinor(amount, 'PHP')).toBeNull();
        expect(toMinor('1', 'bad')).toBeNull();
        expect(toMinor('0001.5', 'PHP')).toBe('150');
    });
});
