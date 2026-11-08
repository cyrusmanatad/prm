import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import LeadManager from '../../resources/js/features/pipeline/LeadManager.vue';
import PipelineBoard from '../../resources/js/features/pipeline/PipelineBoard.vue';

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: { post, get, on: () => () => {} },
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
function manager(canWrite = true, state?: string) {
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
