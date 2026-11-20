import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import PublicPresence from '../../resources/js/pages/PublicPresence.vue';
import RichTextEditor from '../../resources/js/components/RichTextEditor.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';

const api = vi.hoisted(() => ({
    put: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
    get: vi.fn(),
    on: vi.fn(() => () => {}),
}));
vi.mock('@inertiajs/vue3', () => ({
    router: api,
    usePage: () => ({ url: '/partner/media-kit', props: {} }),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    confirmation.value = null;
    vi.clearAllMocks();
});
const day = 86_400_000;
const link = (id: string, overrides = {}) => ({
    id,
    label: '',
    created_at: new Date(Date.now() - 2 * day).toISOString(),
    expires_at: new Date(Date.now() + 28 * day).toISOString(),
    revoked_at: null,
    status: 'active' as const,
    leads: 0,
    ...overrides,
});
function render(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(PublicPresence, {
        props: {
            account: { name: 'Creator', email: 'creator@example.test' },
            workspace: { id: 'org', name: 'Studio' },
            canWrite: true,
            kit: {
                id: 'kit',
                title: 'Maya Santos',
                bio: '<p>Hello <strong>brands</strong></p>',
                website: 'https://maya.example.test',
                published: true,
                version: 4,
                views: 12,
                social_links: [{ label: 'Instagram', url: 'https://instagram.example.test/maya' }],
                public_url: 'https://app.example.test/kits/kit',
            },
            links: {
                items: [
                    link('l1', {
                        label: 'Instagram bio',
                        leads: 9,
                        expires_at: new Date(Date.now() + 3 * day).toISOString(),
                    }),
                    link('l2'),
                ],
                page: 1,
                perPage: 10,
                total: 12,
                counts: { active: 12, expired: 1, revoked: 2, all: 15 },
            },
            summary: { views: 12, activeLinks: 12, leads: 9 },
            filters: { status: 'active' },
            issuedLink: null,
            ...overrides,
        },
        global: { stubs: { PartnerChrome: { template: '<div><slot /></div>' } } },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
const button = (wrapper: VueWrapper, name: string) => wrapper.findAll('button').find((node) => node.text() === name);

it('shows publication state, the public link, figures and a live preview of saved formatting', async () => {
    const wrapper = render();
    await flushPromises();
    expect(wrapper.get('h1').text()).toBe('Media kit');
    expect(wrapper.text()).toContain('Published');
    expect(wrapper.get('a[target="_blank"]').attributes('href')).toBe('https://app.example.test/kits/kit');
    expect(wrapper.get('#kit-public-url').element).toHaveProperty('value', 'https://app.example.test/kits/kit');
    expect(wrapper.get('.kit-stats').text()).toContain('Leads received9');
    const preview = wrapper.get('[aria-label="Public preview"]');
    expect(preview.html()).toContain('<strong>brands</strong>');
    expect(preview.text()).toContain('Visit website');
    expect(preview.text()).toContain('Instagram');
    expect(wrapper.text()).toContain('All changes saved');
});

it('tracks unsaved edits, discards them and saves with the submitted version', async () => {
    const wrapper = render();
    await wrapper.get('#kit-title').setValue('New title');
    expect(wrapper.text()).toContain('Unsaved changes');
    expect(wrapper.get('[aria-label="Public preview"]').text()).toContain('New title');
    await button(wrapper, 'Discard')!.trigger('click');
    expect((wrapper.get('#kit-title').element as HTMLInputElement).value).toBe('Maya Santos');
    await wrapper.get('#kit-title').setValue('Saved title');
    await wrapper.get('form.kit-editor').trigger('submit');
    expect(api.put.mock.calls[0]![1]).toMatchObject({
        title: 'Saved title',
        bio: '<p>Hello <strong>brands</strong></p>',
        version: 4,
    });
    api.put.mock.calls[0]![2].onSuccess();
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain('All changes saved');
});

it('lists lead links with labels, status and leads, and confirms revocation by name', async () => {
    const wrapper = render();
    const table = wrapper.get('table');
    expect(table.text()).toContain('Instagram bio');
    expect(table.text()).toContain('Expires soon');
    expect(table.text()).toContain('Untitled link');
    expect(wrapper.get('nav[aria-label="Filter lead links by status"]').text()).toContain('Revoked 2');
    expect(wrapper.get('nav[aria-label="Lead link pages"] a[aria-label="Page 2"]').attributes('href')).toBe(
        '/partner/media-kit?page=2',
    );
    await wrapper.get('button[aria-label="Revoke lead link Instagram bio"]').trigger('click');
    expect(confirmation.value?.description).toContain('“Instagram bio”');
    confirmation.value!.action();
    expect(api.delete.mock.calls[0]![0]).toBe('/lead-links/l1');
    expect(api.delete.mock.calls[0]![1].data).toEqual({ status: 'active', page: 1 });
});

it('creates labelled links and shows a newly issued link once with a copy control', async () => {
    const wrapper = render({ issuedLink: 'https://app.example.test/lead#token=abc' });
    expect(wrapper.get('#issued-lead-link').element).toHaveProperty('value', 'https://app.example.test/lead#token=abc');
    expect(wrapper.text()).toContain('It is shown only once.');
    await wrapper.get('#lead-link-label').setValue('  Newsletter  ');
    await wrapper.get('form.kit-create').trigger('submit');
    expect(api.post).toHaveBeenCalledWith('/lead-links', { label: 'Newsletter' }, expect.any(Object));
});

it('keeps viewers read only', () => {
    const wrapper = render({ canWrite: false });
    expect(wrapper.text()).toContain('Viewer access is read only.');
    expect(wrapper.find('form.kit-create').exists()).toBe(false);
    expect(wrapper.find('button[aria-label^="Revoke lead link"]').exists()).toBe(false);
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
});

it('edits a limited set of formatting with an accessible toolbar and visible character count', async () => {
    const wrapper = mount(
        {
            components: { RichTextEditor },
            data: () => ({ value: '<p>Hi <script>x</script><u>under</u></p>' }),
            template: '<RichTextEditor id="intro" v-model="value" label="Public introduction" :max-length="10" />',
        },
        { attachTo: document.body },
    );
    wrappers.push(wrapper);
    await flushPromises();
    const box = wrapper.get('[role="textbox"]');
    expect(box.attributes('aria-labelledby')).toBe('intro-label');
    expect(document.getElementById('intro-label')?.textContent).toBe('Public introduction');
    expect(box.html()).not.toContain('<script');
    expect(box.html()).not.toContain('<u>');
    const toolbar = wrapper.get('[role="toolbar"]');
    expect(toolbar.attributes('aria-label')).toBe('Public introduction formatting');
    expect(toolbar.findAll('button').map((node) => node.attributes('aria-label'))).toEqual([
        'Bold',
        'Italic',
        'Subheading',
        'Bulleted list',
        'Numbered list',
        'Link',
        'Undo',
        'Redo',
    ]);
    expect(wrapper.get('#intro-count').text()).toContain('8 / 10');
    await wrapper.setData({ value: '<p>Far too long for ten</p>' });
    await flushPromises();
    expect(wrapper.get('#intro-count').classes()).toContain('is-over');
    expect(wrapper.text()).toContain('Shorten the text to 10 characters to save.');
    expect(box.attributes('aria-invalid')).toBe('true');
    await wrapper.get('button[aria-label="Link"]').trigger('click');
    await flushPromises();
    await wrapper.get('#intro-link').setValue('javascript:alert(1)');
    await button(wrapper, 'Apply')!.trigger('click');
    expect(wrapper.text()).toContain('Enter a web address or an email link (mailto:).');
});
