import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import OutreachTemplates from '../../resources/js/pages/OutreachTemplates.vue';
import { mergeSegments } from '../../resources/js/features/outreach/merge';
import { confirmation } from '../../resources/js/composables/useConfirmation';

const api = vi.hoisted(() => ({ visit: vi.fn(), delete: vi.fn(), get: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: api,
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('../../resources/js/composables/useNotify', () => ({
    useNotify: () => ({ promise: (writing: Promise<unknown>) => writing }),
}));
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    confirmation.value = null;
    vi.clearAllMocks();
});
const templates = [
    {
        id: 't1',
        name: 'First pitch',
        subject: 'Collab idea for {{brand_name}}',
        body: 'Hi {{contact_name}},\nfrom {{creator_name}}',
        version: 2,
        updated_at: new Date().toISOString(),
    },
    { id: 't2', name: 'Rate card reply', subject: 'My rates', body: 'Thanks!', version: 1 },
];
const leads = [
    { id: 'l1', brand_name: 'Glow Labs', contact_name: 'Mia', contact_email: 'mia@glow.test' },
    { id: 'l2', brand_name: 'Paperfold', contact_name: null, contact_email: null },
];
function render(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(OutreachTemplates, {
        props: {
            account: { name: 'Maya', email: 'maya@example.test' },
            workspace: { id: 'org', name: 'Studio' },
            templates,
            leads,
            canWrite: true,
            ...overrides,
        },
        global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
const button = (wrapper: VueWrapper, name: string) =>
    wrapper.findAll('button').find((node) => node.attributes('aria-label') === name || node.text() === name)!;

it('lists templates with subject and update time, searches and opens one for editing', async () => {
    const wrapper = render();
    const first = button(wrapper, 'First pitch');
    expect(first.attributes('aria-pressed')).toBe('false');
    expect(wrapper.get(`#${first.attributes('aria-describedby')}`).text()).toContain('Updated today');
    await wrapper.get('#template-search').setValue('rates');
    expect(wrapper.findAll('.out-items button').map((node) => node.text())).toEqual(['Rate card reply']);
    await wrapper.get('#template-search').setValue('');
    await button(wrapper, 'First pitch').trigger('click');
    expect(button(wrapper, 'First pitch').attributes('aria-pressed')).toBe('true');
    expect((wrapper.get('#template-name').element as HTMLInputElement).value).toBe('First pitch');
    expect(wrapper.text()).not.toContain('Unsaved changes');
    await wrapper.get('#template-name').setValue('Changed');
    expect(wrapper.text()).toContain('Unsaved changes');
    await button(wrapper, 'Discard').trigger('click');
    expect((wrapper.get('#template-name').element as HTMLInputElement).value).toBe('First pitch');
});

it('inserts placeholders at the caret of the field used last', async () => {
    const wrapper = render();
    const body = wrapper.get('#template-body');
    await body.setValue('Hi ,');
    (body.element as HTMLTextAreaElement).setSelectionRange(3, 3);
    await body.trigger('focusin');
    await button(wrapper, 'Insert Contact name placeholder').trigger('click');
    await flushPromises();
    expect((body.element as HTMLTextAreaElement).value).toBe('Hi {{contact_name}},');
    expect(wrapper.get('.wd-placeholder-mirror mark.is-known').text()).toBe('{{contact_name}}');
    const subject = wrapper.get('#template-subject');
    await subject.setValue('For ');
    await subject.trigger('focusin');
    expect(wrapper.get('#insert-label').text()).toBe('Insert into subject:');
    await button(wrapper, 'Insert Brand name placeholder').trigger('click');
    await flushPromises();
    expect((subject.element as HTMLInputElement).value).toBe('For {{brand_name}}');
});

it('previews a live draft, fills it from a lead and offers copy and email only when complete', async () => {
    const wrapper = render();
    await button(wrapper, 'First pitch').trigger('click');
    const preview = () => wrapper.get('[aria-label="Draft preview"]');
    expect(preview().get('.is-missing').text()).toBe('brand name');
    expect(wrapper.text()).toContain('To finish the draft, add a brand name; add a contact name.');
    expect(button(wrapper, 'Copy draft').attributes('disabled')).toBeDefined();
    await wrapper.get('#draft-lead').setValue('l1');
    expect((wrapper.get('#merge-brand').element as HTMLInputElement).value).toBe('Glow Labs');
    expect(
        preview()
            .findAll('.is-filled')
            .map((node) => node.text()),
    ).toEqual(['Glow Labs', 'Mia', 'Maya']);
    expect(button(wrapper, 'Copy draft').attributes('disabled')).toBeUndefined();
    const email = wrapper.get('a[href^="mailto:"]');
    expect(email.attributes('href')).toBe(
        'mailto:mia%40glow.test?subject=Collab%20idea%20for%20Glow%20Labs&body=Hi%20Mia%2C%0Afrom%20Maya',
    );
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    await button(wrapper, 'Edit before copying').trigger('click');
    await wrapper.get('#draft-body').setValue('Edited body');
    await button(wrapper, 'Copy draft').trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith('Collab idea for Glow Labs\n\nEdited body');
    await wrapper.get('#merge-contact').setValue('Sam');
    expect(wrapper.find('#draft-body').exists()).toBe(false);
});

it('saves, duplicates and confirms deletion of the open template', async () => {
    const wrapper = render();
    await button(wrapper, 'First pitch').trigger('click');
    await wrapper.get('form.out-editor').trigger('submit');
    expect(api.visit.mock.calls[0]![0]).toBe('/outreach-templates/t1');
    expect(api.visit.mock.calls[0]![1]).toMatchObject({ method: 'patch', data: { version: 2 } });
    await button(wrapper, 'Delete template').trigger('click');
    expect(confirmation.value?.title).toBe('Delete template');
    confirmation.value!.action();
    expect(api.delete.mock.calls[0]![0]).toBe('/outreach-templates/t1');
    await button(wrapper, 'Duplicate').trigger('click');
    expect((wrapper.get('#template-name').element as HTMLInputElement).value).toBe('Copy of First pitch');
    expect(wrapper.get('#editor-title').text()).toBe('New template');
    expect(button(wrapper, 'Create template')).toBeDefined();
});

it('lets viewers prepare drafts without saving', async () => {
    const wrapper = render({ canWrite: false });
    expect(wrapper.text()).toContain('saving templates requires an editing role');
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
    expect(button(wrapper, 'New template')).toBeUndefined();
});

it('splits merged text into plain, filled and missing parts', () => {
    expect(
        mergeSegments('Hi {{ contact_name }} at {{brand_name}} {{other}}', {
            contact_name: 'Sam',
            brand_name: ' ',
            creator_name: '',
        }),
    ).toEqual([
        { text: 'Hi ', kind: 'text' },
        { text: 'Sam', kind: 'filled' },
        { text: ' at ', kind: 'text' },
        { text: 'brand name', kind: 'missing' },
        { text: ' ', kind: 'text' },
        { text: '{{other}}', kind: 'missing' },
    ]);
});
