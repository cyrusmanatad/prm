import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import LeadManager from '../../resources/js/features/pipeline/LeadManager.vue';
import PipelineBoard from '../../resources/js/features/pipeline/PipelineBoard.vue';

const { post, get, visit } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: { post, get, visit, on: () => () => {} },
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('../../resources/js/composables/useNotify', () => ({
    useNotify: () => ({ error: vi.fn(), dismiss: vi.fn() }),
}));
const lead = {
    id: 'one',
    brand_name: 'One studio',
    contact_name: null,
    contact_email: null,
    stage: 'lead',
    currency: 'USD',
    value_minor: '999999999999999999',
    version: 7,
    next_action_due: null,
};
HTMLDialogElement.prototype.close = vi.fn();
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.clearAllMocks();
});
function manager(
    canWrite = true,
    state?: string,
    registration?: { canRegister: boolean; brands: { id: string; brand_name: string; paused: boolean }[] },
) {
    const wrapper = mount(LeadManager, {
        props: {
            entries: [lead],
            counts: [{ stage: 'lead', count: 1 }],
            totals: [{ currency: 'USD', amount_minor: lead.value_minor, count: 1 }],
            filters: { q: 'One', stage: '', due: true },
            stages: [],
            selected: null,
            nextCursor: null,
            canWrite,
            workspace: { id: 'workspace', name: 'Workspace' },
            registration,
        },
        global: {
            provide: {
                workspaceExperience: ref(state ? 'professional' : 'partner'),
                professionalSubscription: ref(state ? { state } : undefined),
            },
        },
    });
    wrappers.push(wrapper);
    return wrapper;
}
it('moves optimistically once using the displayed version and filters, then rolls back rejected moves', async () => {
    const wrapper = manager();
    const board = wrapper.getComponent(PipelineBoard);
    board.vm.$emit('move', lead, 'pitched');
    await wrapper.vm.$nextTick();
    expect(wrapper.get('section[aria-label="Pitched"]').text()).toContain('One studio');
    expect(wrapper.get('section[aria-label="Lead"]').findAll('.lead-card')).toHaveLength(0);
    expect(wrapper.get('.pipeline-total').text()).toBe('USD 9,999,999,999,999,999.99');
    board.vm.$emit('move', lead, 'active');
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][1]).toEqual({
        stage: 'pitched',
        version: 7,
        board: true,
        pipeline_filters: { q: 'One', stage: '', due: true },
    });
    const options = post.mock.calls[0][2];
    options.onError({ stage: 'Access was denied.' });
    options.onFinish();
    await wrapper.vm.$nextTick();
    expect(wrapper.get('section[aria-label="Lead"]').text()).toContain('One studio');
    expect(wrapper.get('[role="alert"]').text()).toContain('Access was denied.');
    expect(get).not.toHaveBeenCalled();
});
it('recovers stale versions with a filtered reload and no automatic mutation retry', async () => {
    const wrapper = manager();
    wrapper.getComponent(PipelineBoard).vm.$emit('move', lead, 'pitched');
    const options = post.mock.calls[0][2];
    options.onError({ version: 'This lead changed.' });
    options.onFinish();
    await wrapper.vm.$nextTick();
    expect(get).toHaveBeenCalledWith('/partner/leads', { q: 'One', due: '1' }, expect.anything());
    expect(post).toHaveBeenCalledTimes(1);
    expect(wrapper.get('[role="alert"]').text()).toContain('This lead changed.');
});
it('replaces optimistic cards with authoritative response data', async () => {
    const wrapper = manager(true, 'active');
    wrapper.getComponent(PipelineBoard).vm.$emit('move', lead, 'pitched');
    await wrapper.setProps({ entries: [{ ...lead, stage: 'active', version: 9 }] });
    post.mock.calls[0][2].onSuccess();
    post.mock.calls[0][2].onFinish();
    await wrapper.vm.$nextTick();
    expect(wrapper.get('section[aria-label="Active"]').text()).toContain('One studio');
});
it.each([
    [false, undefined],
    [true, 'expired'],
    [true, 'pending_activation'],
])('denies moves for viewer/inactive workspace %j %j', async (canWrite, state) => {
    const wrapper = manager(canWrite as boolean, state as string | undefined);
    expect(wrapper.get('.lead-card').attributes('draggable')).toBe('false');
    wrapper.getComponent(PipelineBoard).vm.$emit('move', lead, 'pitched');
    expect(post).not.toHaveBeenCalled();
});

it('offers the optional Brand only to roles that can register, and sends it only when chosen', async () => {
    HTMLDialogElement.prototype.showModal = vi.fn();
    const brands = [
        { id: 'p1', brand_name: 'Northline Living', paused: false },
        { id: 'p2', brand_name: 'Paused Brand', paused: true },
    ];
    // Members who cannot register, and workspaces with no connected Brand, see the form as before.
    for (const hidden of [undefined, { canRegister: false, brands }, { canRegister: true, brands: [] }]) {
        const plain = manager(true, undefined, hidden);
        (plain.vm as unknown as { openCreate: () => void }).openCreate();
        await plain.vm.$nextTick();
        expect(plain.find('#create-register-with').exists()).toBe(false);
    }
    const wrapper = manager(true, undefined, { canRegister: true, brands });
    (wrapper.vm as unknown as { openCreate: () => void }).openCreate();
    await wrapper.vm.$nextTick();
    const field = wrapper.get('#create-register-with');
    expect((field.element as HTMLSelectElement).value).toBe('');
    expect(field.text()).toContain('No Brand — keep private');
    expect(field.text()).toContain('Northline Living');
    expect(field.text()).not.toContain('Paused Brand');
    expect(wrapper.text()).toContain('Share with a Brand (optional)');
    expect(wrapper.text()).toContain('Notes, value and contact stay private.');
    await wrapper.get('#create-brand').setValue('Kanto Cafe');
    // Left empty: the request is exactly the earlier one, with no Brand.
    await wrapper.get('#create-brand').element.closest('form')!.dispatchEvent(new Event('submit'));
    expect(visit.mock.calls[0][0]).toBe('/pipeline');
    expect(visit.mock.calls[0][1].method).toBe('post');
    expect(visit.mock.calls[0][1].data).not.toHaveProperty('register_with');
    visit.mock.calls[0][1].onFinish();
    await wrapper.vm.$nextTick();
    await field.setValue('p1');
    await wrapper.get('#create-brand').element.closest('form')!.dispatchEvent(new Event('submit'));
    expect(visit.mock.calls[1][1].data).toMatchObject({ brand_name: 'Kanto Cafe', register_with: 'p1' });
});

it('adds a lead with a blank value as zero and explains a missing name without submitting', async () => {
    HTMLDialogElement.prototype.showModal = vi.fn();
    const wrapper = manager(true);
    (wrapper.vm as unknown as { openCreate: () => void }).openCreate();
    await wrapper.vm.$nextTick();
    const form = wrapper.get('#create-brand').element.closest('form')!;
    expect(form.noValidate).toBe(true);
    expect(wrapper.get('.create-footer').text()).toContain('Add lead');
    expect((wrapper.get('#create-amount').element as HTMLInputElement).value).toBe('');
    form.dispatchEvent(new Event('submit'));
    await wrapper.vm.$nextTick();
    expect(visit).not.toHaveBeenCalled();
    expect(wrapper.get('#create-brand-error').text()).toBe('Enter the Brand name.');
    expect(wrapper.get('#create-brand').attributes('aria-invalid')).toBe('true');
    // A follow-up shortcut fills the next action at 9:00 local time.
    const tomorrow = wrapper.findAll('.entry-chip').find((chip) => chip.text() === 'Tomorrow')!;
    await tomorrow.trigger('click');
    expect((wrapper.get('#create-due').element as HTMLInputElement).value).toMatch(/T09:00$/);
    await wrapper.get('#create-brand').setValue('Kanto Cafe');
    form.dispatchEvent(new Event('submit'));
    expect(visit.mock.calls[0][1].data).toMatchObject({ brand_name: 'Kanto Cafe', value_minor: '0', currency: 'PHP' });
    expect(visit.mock.calls[0][1].data.next_action_due).toMatch(/Z$/);
});
