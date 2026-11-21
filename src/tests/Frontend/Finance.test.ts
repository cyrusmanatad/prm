import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import Invoices from '../../resources/js/pages/Invoices.vue';
import Payments from '../../resources/js/pages/Payments.vue';
import { confirmation } from '../../resources/js/composables/useConfirmation';
import { formatMoney, parseAmount, percent, subtract } from '../../resources/js/features/finance/amounts';

const { visit, get } = vi.hoisted(() => ({ visit: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit, get, post: vi.fn() },
    usePage: () => ({ url: '/finance/invoices', props: {} }),
}));
HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.open = true;
};
HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    this.open = false;
    this.dispatchEvent(new Event('close'));
};
let wrapper: VueWrapper;
beforeEach(() => {
    vi.stubGlobal('crypto', { randomUUID: () => 'command-key' });
});
afterEach(() => {
    wrapper?.unmount();
    confirmation.value = null;
    vi.clearAllMocks();
});
const stub = { FoundationShell: { template: '<main><slot /></main>' } };
const global = (professional = false) => ({
    stubs: stub,
    provide: { workspaceExperience: ref(professional ? 'professional' : 'partner') },
});
const invoice = (id: string, extra: Record<string, unknown> = {}) => ({
    id,
    recipient_name: `Client ${id}`,
    currency: 'USD',
    due_date: '2026-12-05',
    status: 'draft',
    total_minor: '370000',
    received_minor: '200000',
    version: 1,
    line_count: 2,
    ...extra,
});
const summary = {
    currencies: [
        { currency: 'USD', drafts: 2, total_minor: '550000', received_minor: '200000', balance_minor: '350000' },
    ],
    open: 2,
    voided: 1,
    pastDue: 1,
};

it('converts amounts exactly between currency units and minor units', () => {
    expect(parseAmount('90,071,992,547,409.93', 'USD')).toBe('9007199254740993');
    expect(parseAmount('1250.5', 'USD')).toBe('125050');
    expect(parseAmount('12.345', 'USD')).toBeNull();
    expect(parseAmount('1500', 'JPY')).toBe('1500');
    expect(parseAmount('10', 'US')).toBeNull();
    expect(formatMoney('9007199254740993', 'USD')).toBe('USD 90,071,992,547,409.93');
    expect(subtract('9007199254740993', '3')).toBe('9007199254740990');
    expect(percent('200000', '370000')).toBe(54);
});

it('lists drafts with formatted totals, balances and per-currency tiles', () => {
    wrapper = mount(Invoices, {
        props: {
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { id: 'w', name: 'Studio' },
            invoices: {
                items: [invoice('a'), invoice('b', { due_date: '2020-01-01', received_minor: '0' })],
                nextCursor: 'b',
            },
            summary,
            filters: { status: 'open', cursor: null },
        },
        global: global(true),
    });
    const tiles = wrapper.get('[aria-label="Invoice draft summary"]').text();
    expect(tiles).toContain('USD 5,500.00');
    expect(tiles).toContain('USD 3,500.00');
    expect(tiles).toContain('1 past due');
    const rows = wrapper.findAll('tbody tr');
    expect(rows[0]!.text()).toContain('USD 3,700.00');
    expect(rows[0]!.text()).toContain('USD 2,000.00 · balance USD 1,700.00');
    expect(rows[1]!.text()).toContain('past due');
    expect(rows[1]!.text()).toContain('Nothing received');
    expect(wrapper.find('a[href="/professional/finance/invoices?cursor=b"]').exists()).toBe(true);
    expect(wrapper.find('a[href="/professional/finance/invoices?status=voided"]').exists()).toBe(true);
});

it('creates a draft from currency units with an exact total and an idempotency key', async () => {
    wrapper = mount(Invoices, {
        props: {
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { id: 'w', name: 'Studio' },
            invoices: { items: [], nextCursor: null },
            summary: { currencies: [], open: 0, voided: 0, pastDue: 0 },
            filters: { status: 'open', cursor: null },
        },
        global: global(),
        attachTo: document.body,
    });
    await wrapper
        .findAll('button')
        .find((b) => b.text() === 'New draft')!
        .trigger('click');
    const editor = wrapper.get('dialog.fin-editor');
    const save = () => editor.findAll('button').find((b) => b.text() === 'Save draft')!;
    expect(save().attributes('disabled')).toBeDefined();
    await editor.get('#invoice-recipient').setValue('Juniper Dental');
    await editor.get('#invoice-currency').setValue('usd');
    await editor.get('#invoice-line-0').setValue('Audit');
    await editor.get('[aria-label="Line 1 unit price"]').setValue('1,250.50');
    await editor
        .findAll('button')
        .find((b) => b.text().includes('Add line'))!
        .trigger('click');
    await editor.get('#invoice-line-1').setValue('Copy');
    await editor.get('[aria-label="Line 2 quantity"]').setValue('2');
    await editor.get('[aria-label="Line 2 unit price"]').setValue('12.345');
    expect(editor.text()).toContain('Unsaved changes');
    await editor.get('form').trigger('submit');
    expect(visit).not.toHaveBeenCalled();
    expect(editor.text()).toContain('Enter an amount such as 2500.00.');
    await editor.get('[aria-label="Line 2 unit price"]').setValue('150');
    expect(editor.text()).toContain('USD 1,550.50');
    expect(editor.text()).toContain('Exact total: 155050 minor units');
    await editor.get('form').trigger('submit');
    expect(visit).toHaveBeenCalledWith(
        '/finance/invoices',
        expect.objectContaining({
            method: 'post',
            data: {
                recipient_name: 'Juniper Dental',
                currency: 'USD',
                due_date: null,
                lines: [
                    { description: 'Audit', quantity: 1, unit_price_minor: '125050' },
                    { description: 'Copy', quantity: 2, unit_price_minor: '15000' },
                ],
                command_id: 'command-key',
            },
        }),
    );
});

it('opens a draft in the drawer and locks edits while receipts are applied', async () => {
    wrapper = mount(Invoices, {
        props: {
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { id: 'w', name: 'Studio' },
            invoice: invoice('a', {
                lines: [{ description: 'Workshop', quantity: 1, unit_price_minor: '250000', total_minor: '250000' }],
                allocations: [
                    {
                        id: 'x',
                        amount_minor: '200000',
                        evidence_reference: 'Transfer',
                        received_on: '2026-11-18',
                        reversal_reason: null,
                    },
                ],
            }),
            invoices: { items: [invoice('a')], nextCursor: null },
            summary,
            filters: { status: 'open', cursor: null },
        },
        global: global(),
        attachTo: document.body,
    });
    await flushPromises();
    const drawer = wrapper.get('dialog.rec-drawer');
    expect(drawer.text()).toContain('Draft · Version 1');
    expect(drawer.text()).toContain('2,500.00');
    expect(drawer.text()).toContain('Balance');
    expect(drawer.text()).toContain('USD 1,700.00');
    expect(drawer.text()).toContain('Reverse this draft’s allocations');
    const button = (label: string) => drawer.findAll('button').find((b) => b.text().includes(label))!;
    expect(button('Edit draft').attributes('disabled')).toBeDefined();
    expect(button('Void draft').attributes('disabled')).toBeDefined();
    expect(drawer.get('a.rec-primary').attributes('href')).toBe('/finance/payments?status=unallocated');
    await drawer.get('.wd-dialog-close').trigger('click');
    expect(get).toHaveBeenCalledWith('/finance/invoices', {}, expect.objectContaining({ preserveScroll: true }));
});

const payment = (id: string, extra: Record<string, unknown> = {}) => ({
    id,
    currency: 'USD',
    amount_minor: '90000',
    received_on: '2026-11-20',
    evidence_reference: `Receipt ${id}`,
    status: 'recorded',
    version: 1,
    correction_of: null,
    allocated_minor: '0',
    allocations: [],
    ...extra,
});
function payments(extra: Record<string, unknown> = {}) {
    wrapper = mount(Payments, {
        props: {
            account: { name: 'Sam', email: 'sam@example.test' },
            workspace: { name: 'Studio' },
            payments: [
                payment('p1'),
                payment('p2', {
                    allocated_minor: '90000',
                    allocations: [
                        {
                            id: 'al',
                            invoice_id: 'inv',
                            amount_minor: '90000',
                            reversal_reason: null,
                            recipient_name: 'Harbor',
                        },
                    ],
                }),
                payment('p3', { status: 'voided' }),
            ],
            nextCursor: null,
            openDrafts: [
                {
                    id: 'd1',
                    recipient_name: 'Northline',
                    currency: 'USD',
                    version: 3,
                    due_date: null,
                    total_minor: '180000',
                    received_minor: '100000',
                },
                {
                    id: 'd2',
                    recipient_name: 'Euro',
                    currency: 'EUR',
                    version: 1,
                    due_date: null,
                    total_minor: '1000',
                    received_minor: '0',
                },
            ],
            summary: { currencies: [], recorded: 2, voided: 1, unallocated: 1 },
            filters: { status: 'all', cursor: null },
            ...extra,
        },
        global: global(),
        attachTo: document.body,
    });
    return wrapper;
}

it('offers Allocate only where it can apply and pre-fills the largest allowed amount', async () => {
    payments();
    const rows = wrapper.findAll('tbody tr');
    expect(rows[0]!.find('[aria-label="Allocate Receipt p1"]').exists()).toBe(true);
    expect(rows[1]!.find('[aria-label="Allocate Receipt p2"]').exists()).toBe(false);
    expect(rows[1]!.text()).toContain('Fully allocated');
    expect(rows[1]!.get('a').attributes('href')).toBe('/finance/invoices/inv');
    expect(rows[2]!.text()).toContain('Voided');
    await rows[0]!.get('[aria-label="Allocate Receipt p1"]').trigger('click');
    await flushPromises();
    const dialog = wrapper.findAll('dialog.rec-dialog').find((d) => d.text().includes('Allocate receipt'))!;
    expect(dialog.text()).toContain('USD 900.00 unallocated');
    // Only the USD draft is offered, so it is chosen and the smaller balance (800.00) fills the amount.
    expect((dialog.get('#allocation-amount').element as HTMLInputElement).value).toBe('800.00');
    await dialog.get('#allocation-amount').setValue('900.00');
    await dialog.get('form').trigger('submit');
    expect(dialog.text()).toContain('Allocate at most USD 800.00.');
    expect(visit).not.toHaveBeenCalled();
    await dialog.get('#allocation-amount').setValue('500');
    await dialog.get('form').trigger('submit');
    expect(visit).toHaveBeenCalledWith(
        '/finance/payments/p1/allocate',
        expect.objectContaining({
            data: {
                version: 1,
                invoice_id: 'd1',
                invoice_version: 3,
                amount_minor: '50000',
                command_id: 'command-key',
            },
        }),
    );
});

it('asks for a reversal reason before the shared confirmation', async () => {
    payments();
    const reverse = wrapper.findAll('dialog.rec-dialog').find((d) => d.text().includes('Reverse allocation'))!;
    const vm = wrapper.findComponent({ name: 'ReverseDialog' });
    (vm.vm as unknown as { open: (t: null, p: unknown, a: unknown) => void }).open(null, payment('p2'), {
        id: 'al',
        invoice_id: 'inv',
        amount_minor: '90000',
        reversal_reason: null,
        recipient_name: 'Harbor',
    });
    await flushPromises();
    await reverse.get('form').trigger('submit');
    expect(reverse.text()).toContain('Enter why this allocation is being reversed.');
    expect(confirmation.value).toBeNull();
    await reverse.get('#reverse-reason').setValue('Wrong draft');
    await reverse.get('form').trigger('submit');
    expect(confirmation.value?.title).toBe('Reverse allocation');
    expect(confirmation.value?.description).toContain('USD 900.00 from receipt Receipt p2 allocated to Harbor');
    expect(confirmation.value?.description).toContain('Reason: Wrong draft');
    confirmation.value!.action();
    expect(visit).toHaveBeenCalledWith(
        '/finance/allocations/al/reverse',
        expect.objectContaining({ data: { reason: 'Wrong draft', command_id: 'command-key' } }),
    );
});
