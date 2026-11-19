import { expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import PartnerAvatar from '../../resources/js/components/PartnerAvatar.vue';
import { initials, pick } from '../../resources/js/lib/initials';

it('takes up to two initials and falls back for an empty name', () => {
    expect(initials('Bea Santos')).toBe('BS');
    expect(initials('  desert light   collective ')).toBe('DL');
    expect(initials('Atelier')).toBe('A');
    expect(initials('   ')).toBe('?');
    expect(initials('', '')).toBe('');
});

it('picks a stable tint per partnership, independent of the name', () => {
    const seed = '6f1c2a0e-1111-4222-8333-944445555666';
    expect(pick(seed, 6)).toBe(pick(seed, 6));
    const tint = (name: string) =>
        mount(PartnerAvatar, { props: { name, seed } })
            .classes()
            .find((name) => /^is-(blush|mint|sand|lilac|sky|clay)$/.test(name));
    expect(tint('Bea Santos')).toBeDefined();
    expect(tint('Bea Santos Studio')).toBe(tint('Bea Santos'));
    // Different partnerships spread across the palette.
    const used = new Set(Array.from({ length: 30 }, (_, index) => pick(`partnership-${index}`, 6)));
    expect(used.size).toBeGreaterThan(3);
});

it('is decorative, sized, and neutral while paused', () => {
    const avatar = mount(PartnerAvatar, { props: { name: 'Bea Santos', seed: 'p1', size: 48, paused: true } });
    expect(avatar.text()).toBe('BS');
    expect(avatar.attributes('aria-hidden')).toBe('true');
    expect(avatar.classes()).toEqual(expect.arrayContaining(['wd-partner-avatar', 'is-48', 'is-paused']));
});
