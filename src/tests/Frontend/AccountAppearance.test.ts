import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import AccountAppearance from '../../resources/js/pages/AccountAppearance.vue';
import { applyAppearance, normalizeAppearance, syncAppearance } from '../../resources/js/composables/useAppearance';

const { post, page, form, on, unsubscribe } = vi.hoisted(() => ({
    post: vi.fn(),
    unsubscribe: vi.fn(),
    on: vi.fn(),
    page: { value: undefined as unknown as { url: string; props: Record<string, unknown> } },
    form: { value: undefined as unknown as Record<string, unknown> },
}));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive: make } = await import('vue');
    return {
        usePage: () => page.value,
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        Head: { template: '<span />' },
        router: { post: vi.fn(), on: on.mockImplementation(() => unsubscribe) },
        useForm: (data: Record<string, unknown>) => {
            form.value = make({
                ...data,
                errors: {} as Record<string, string>,
                processing: false,
                post,
                defaults: vi.fn(),
            });
            return form.value;
        },
    };
});

const wrappers: VueWrapper[] = [];
beforeEach(() => {
    page.value = reactive({
        url: '/account/appearance',
        props: { account: { name: 'Ana', email: 'ana@example.test' } },
    });
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    delete document.documentElement.dataset.appearance;
    vi.clearAllMocks();
});
function render(appearance: string) {
    const wrapper = mount(AccountAppearance, {
        props: { appearance },
        global: { stubs: { FoundationShell: { template: '<main><slot /></main>' } } },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}

it('offers both accents as a labelled radio group with the saved choice marked in text', () => {
    const wrapper = render('purple');
    expect(wrapper.get('legend').text()).toBe('Accent color');
    const radios = wrapper.findAll<HTMLInputElement>('input[type="radio"]');
    expect(radios.map((radio) => radio.element.value)).toEqual(['burgundy', 'purple']);
    expect(radios.map((radio) => radio.element.checked)).toEqual([false, true]);
    expect(wrapper.findAll('label').map((label) => label.text())).toEqual([
        'BurgundyThe WeavDash default',
        'PurpleCurrentA deeper violet accent',
    ]);
    // Each radio is named by its label and described by its hint.
    expect(radios[0].element.closest('label')?.textContent).toContain('Burgundy');
    expect(document.getElementById(radios[1].attributes('aria-describedby')!)?.textContent).toBe(
        'A deeper violet accent',
    );
});

it('falls back to Burgundy for unknown values', () => {
    const wrapper = render('teal');
    expect(wrapper.get<HTMLInputElement>('input[value="burgundy"]').element.checked).toBe(true);
    expect(normalizeAppearance('teal')).toBe('burgundy');
    expect(normalizeAppearance(undefined)).toBe('burgundy');
    expect(normalizeAppearance('purple')).toBe('purple');
});

it('saves once with an explicit action and does not change the page accent before the server confirms', async () => {
    document.documentElement.dataset.appearance = 'burgundy';
    const wrapper = render('burgundy');
    await wrapper.get('input[value="purple"]').setValue(true);
    expect(form.value.appearance).toBe('purple');
    expect(document.documentElement.dataset.appearance).toBe('burgundy');
    await wrapper.get('form').trigger('submit');
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][0]).toBe('/account/appearance');

    // A pending save disables the controls and ignores repeat submissions.
    form.value.processing = true;
    await nextTick();
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
    expect(wrapper.get('button[type="submit"]').text()).toBe('Saving…');
    await wrapper.get('form').trigger('submit');
    expect(post).toHaveBeenCalledTimes(1);
});

it('announces validation errors next to the group and keeps the saved marker', async () => {
    const wrapper = render('burgundy');
    form.value.errors = { appearance: 'The selected appearance is invalid.' };
    await nextTick();
    const error = wrapper.get('#appearance-error');
    expect(error.attributes('role')).toBe('alert');
    expect(wrapper.get('fieldset.ap-options').attributes('aria-describedby')).toBe('appearance-error');
    expect(wrapper.get('fieldset.ap-options').attributes('aria-invalid')).toBe('true');
    expect(wrapper.find('label:first-of-type .ap-current').exists()).toBe(true);
});

it('applies only server responses to the document root and ignores omitted values', () => {
    applyAppearance('purple');
    expect(document.documentElement.dataset.appearance).toBe('purple');
    applyAppearance('<script>');
    expect(document.documentElement.dataset.appearance).toBe('burgundy');

    const stop = syncAppearance();
    expect(on).toHaveBeenCalledWith('success', expect.any(Function));
    const respond = (props: Record<string, unknown>) =>
        (on.mock.calls[0][1] as (event: unknown) => void)({ detail: { page: { props } } });
    respond({ appearance: 'purple' });
    expect(document.documentElement.dataset.appearance).toBe('purple');
    // A partial reload without the prop keeps the applied value.
    respond({ other: 1 });
    expect(document.documentElement.dataset.appearance).toBe('purple');
    // Sign-out and guest pages send the default; unknown values fall back to it.
    respond({ appearance: 'burgundy' });
    expect(document.documentElement.dataset.appearance).toBe('burgundy');
    respond({ appearance: 'purple' });
    respond({ appearance: 'teal' });
    expect(document.documentElement.dataset.appearance).toBe('burgundy');
    expect(stop).toBe(unsubscribe);
});
