import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import InvitationList from '../../resources/js/features/portals/InvitationList.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';
import type { PartnerInvitation } from '../../resources/js/features/portals/types';

const router = vi.hoisted(() => ({ delete: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router }));

const now = new Date('2026-11-19T10:00:00');
const day = 86_400_000;
let wrapper: VueWrapper;
beforeEach(() => {
    vi.useFakeTimers({ now, toFake: ['Date'] });
    vi.clearAllMocks();
});
afterEach(() => {
    wrapper?.unmount();
    confirmation.value = null;
    vi.useRealTimers();
});
const row = (index: number, extra: Partial<PartnerInvitation> = {}): PartnerInvitation => ({
    id: `i${index}`,
    recipient_email: `partner-${index}@example.test`,
    status: 'accepted',
    created_at: new Date(now.getTime() - index * day).toISOString(),
    expires_at: new Date(now.getTime() + (7 - index) * day).toISOString(),
    email_state: 'sent',
    ...extra,
});
const invitations = [
    row(1, { status: 'pending', expires_at: new Date(now.getTime() + 5 * day).toISOString() }),
    row(2, { status: 'pending', email_state: null, expires_at: new Date(now.getTime() + day).toISOString() }),
    row(3, { status: 'pending', email_state: 'uncertain' }),
    ...Array.from({ length: 7 }, (_, index) => row(index + 4)),
    row(11, { status: 'revoked', email_state: 'cancelled' }),
    row(12, { status: 'expired' }),
];

it('opens on pending invitations with recipients, short delivery badges and counts', () => {
    wrapper = mount(InvitationList, { props: { invitations } });
    expect(wrapper.get('h2').text()).toBe('Invitations');
    expect(wrapper.text()).toContain('12 total');
    const chips = wrapper.get('[role="group"][aria-label="Filter invitations by status"]');
    expect(chips.get('[aria-pressed="true"]').text()).toBe('Pending 3');
    expect(chips.text()).toContain('Accepted 7');
    expect(chips.text()).toContain('All 12');
    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(3);
    expect(rows[0]!.get('th').text()).toBe('partner-1@example.test');
    expect(rows[0]!.text()).toContain('in 5 days');
    expect(rows[0]!.text()).toContain('Email sent');
    // The full delivery wording stays available to assistive technology.
    expect(rows[0]!.text()).toContain('Email accepted by mail server; receipt unconfirmed');
    expect(rows[1]!.get('.is-soon').text()).toBe('tomorrow');
    expect(rows[1]!.text()).toContain('Link only');
    expect(rows[2]!.text()).toContain('Delivery uncertain');
    expect(wrapper.text()).toContain('Showing 3 of 3 pending invitations');
    expect(wrapper.text()).not.toContain('Show all');
});

it('limits a long list to five rows until shown in full, and resets on another status', async () => {
    wrapper = mount(InvitationList, { props: { invitations } });
    const chip = (name: string) => wrapper.findAll('[role="group"] button').find((b) => b.text().startsWith(name))!;
    await chip('Accepted').trigger('click');
    expect(wrapper.findAll('tbody tr')).toHaveLength(5);
    expect(wrapper.find('tbody button').exists()).toBe(false); // Only pending invitations can be revoked.
    const more = wrapper.findAll('button').find((b) => b.text() === 'Show all 7')!;
    expect(more.attributes('aria-expanded')).toBe('false');
    await more.trigger('click');
    expect(wrapper.findAll('tbody tr')).toHaveLength(7);
    await chip('All').trigger('click');
    expect(wrapper.findAll('tbody tr')).toHaveLength(5);
    expect(wrapper.findAll('thead th').map((th) => th.text())).toContain('Status');
    await chip('Revoked').trigger('click');
    expect(wrapper.get('tbody tr').text()).toContain('Email cancelled');
});

it('shows a one-line empty state when nothing is pending', () => {
    wrapper = mount(InvitationList, { props: { invitations: [row(4)] } });
    expect(wrapper.find('table').exists()).toBe(false);
    expect(wrapper.text()).toContain('No pending invitations.');
});

it('confirms a revoke naming the recipient, and refreshes only the invitations', async () => {
    wrapper = mount(InvitationList, { props: { invitations } });
    const revoke = wrapper.get('button[aria-label="Revoke invitation to partner-2@example.test"]');
    await revoke.trigger('click');
    expect(confirmation.value?.title).toBe('Revoke invitation');
    expect(confirmation.value?.description).toContain('partner-2@example.test');
    expect(confirmation.value?.description).not.toContain('i2');
    expect(router.delete).not.toHaveBeenCalled();
    confirmation.value!.action();
    expect(router.delete).toHaveBeenCalledWith('/partner-invitations/i2', { preserveScroll: true });
    await wrapper
        .findAll('button')
        .find((b) => b.text() === 'Refresh status')!
        .trigger('click');
    expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['invitations'] }));
});
