<script setup lang="ts">
import { initials } from '../lib/initials';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import { confirmAction } from '../composables/useConfirmation';
import { Head, Link, router } from '@inertiajs/vue3';
import type { RequestPayload } from '@inertiajs/core';
import {
    AlarmClock,
    BellPlus,
    Check,
    ChevronRight,
    Copy,
    Download,
    Link as LinkIcon,
    Lock,
    Mail,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import DataTable, { type DataColumn } from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import Pagination from '../components/Pagination.vue';
import RowActionMenu, { type RowAction } from '../components/RowActionMenu.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import DialogClose from '../components/DialogClose.vue';
import { containDialogFocus } from '../composables/useDialogFocus';
import { useValidationFocus } from '../composables/useValidationFocus';
import SelectField from '../components/SelectField.vue';
import { crmPanel } from '../features/crm/panel';
import { dueLabel, reminderTone, snoozePresets, type ReminderTone } from '../features/crm/snooze';

type Contact = {
    id: string;
    name: string;
    email: string | null;
    company: string;
    notes: string;
    version: number;
    lead_count: number;
};
type Reminder = {
    id: string;
    title: string;
    due_at: string;
    completed_at: string | null;
    pipeline_entry_id: string | null;
    lead_name: string | null;
    version: number;
};
type Page<Row> = { items: Row[]; page: number; perPage: number; total: number };
type Filters = { q: string; state: string; sort: string; linked: string; per_page: string };
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    contacts: Page<Contact>;
    reminders: Page<Reminder>;
    summary: { contacts: number; overdue: number; dueDay: number; dueWeek: number };
    entry: { id: string; brand_name: string; version: number } | null;
    linked: { id: string; name: string }[];
    canWrite: boolean;
    filters: Filters;
    errors?: Record<string, string>;
    status?: string;
}>();
const { professional, path } = useWorkspaceExperience();
const root = ref<HTMLElement>();
const busy = ref(false);
const errors = computed(() => props.errors ?? {});
useValidationFocus(
    root,
    () => errors.value,
    () => !busy.value,
);
const blocked = computed(() => busy.value || !!errors.value.version);

// List addresses. Each list keeps its own page; changing a list's filters returns it to page 1.
const base = computed(() => path('/partner/contacts'));
type Change = Partial<Record<keyof Filters | 'contacts_page' | 'reminders_page', string | number>>;
function query(change: Change = {}, withEntry = true): Record<string, string> {
    const all: Record<string, string | number> = {
        ...props.filters,
        contacts_page: props.contacts.page,
        reminders_page: props.reminders.page,
        ...change,
    };
    if (withEntry && props.entry) all.entry = props.entry.id;
    return Object.fromEntries(
        Object.entries(all)
            .filter(([key, value]) => value !== '' && !(key.endsWith('_page') && Number(value) <= 1))
            .map(([key, value]) => [key, String(value)]),
    );
}
function listHref(change: Change = {}, withEntry = true): string {
    const text = new URLSearchParams(query(change, withEntry)).toString();
    return text ? `${base.value}?${text}` : base.value;
}
const pageHref = (kind: 'contacts' | 'reminders') => (page: number) => listHref({ [`${kind}_page`]: page });
function visit(change: Change): void {
    if (busy.value) return;
    router.get(base.value, query(change), {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}
const filterContacts = (change: Change) => visit({ ...change, contacts_page: 1 });
function filterReminders(state: string): void {
    crmPanel.value = 'reminders';
    visit({ state, reminders_page: 1 });
}

// The search box filters as the user types and keeps its own text while a response is pending.
const q = ref(props.filters.q);
let typing: ReturnType<typeof setTimeout> | undefined;
watch(
    () => props.filters.q,
    (value) => {
        if (document.activeElement?.id !== 'crm-search') q.value = value;
    },
);
watch(q, (value) => {
    clearTimeout(typing);
    if (value.trim() !== props.filters.q) typing = setTimeout(() => filterContacts({ q: value.trim() }), 350);
});
onBeforeUnmount(() => clearTimeout(typing));
function submitSearch(): void {
    clearTimeout(typing);
    if (q.value.trim() !== props.filters.q) filterContacts({ q: q.value.trim() });
}
function clearSearch(): void {
    clearTimeout(typing);
    q.value = '';
    filterContacts({ q: '' });
    document.getElementById('crm-search')?.focus();
}
const sortOptions = [
    { value: '', label: 'Recently added' },
    { value: 'name_asc', label: 'Name A–Z' },
    { value: 'name_desc', label: 'Name Z–A' },
    { value: 'company_asc', label: 'Company A–Z' },
];
const linkedOptions = [
    { value: '', label: 'Any' },
    { value: 'linked', label: 'Linked to a lead' },
    { value: 'unlinked', label: 'Not linked' },
];
const pageSizes = ['10', '25', '50'].map((size) => ({ value: size, label: size }));
const contactsFiltered = computed(() => !!(props.filters.q || props.filters.linked));
const reminderFilters = [
    { value: 'pending', label: 'Pending' },
    { value: 'due', label: 'Due' },
    { value: 'completed', label: 'Completed' },
    { value: 'all', label: 'All' },
] as const;

// Phones show one list at a time.
const panel = computed({
    get: () =>
        crmPanel.value ?? (props.filters.state !== 'pending' || props.reminders.page > 1 ? 'reminders' : 'contacts'),
    set: (value: 'contacts' | 'reminders') => (crmPanel.value = value),
});

// Mutations return to the same lists, pages and lead context.
type Payload = Record<string, unknown>;
function act(method: 'post' | 'patch' | 'delete', target: string, data: Payload, done?: () => void): void {
    if (busy.value || errors.value.version) return;
    router.visit(target, {
        method,
        data: { ...data, entry: props.entry?.id ?? null, crm_filters: query({}, false) } as RequestPayload,
        preserveState: true,
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
        onSuccess: () => {
            done?.();
            const draft = reminder.value;
            if (draft.id && target.startsWith(`/reminders/${draft.id}/`) && draft.version === data.version) {
                draft.version += 1;
                if (target.endsWith('/snooze') && typeof data.due_at === 'string')
                    draft.due_at = localDate(data.due_at);
            }
        },
    });
}

// Contacts: selection and actions.
const selected = ref<string[]>([]);
watch(
    () => props.contacts.items,
    (items) => (selected.value = selected.value.filter((id) => items.some((row) => row.id === id))),
);
const allSelected = computed(
    () => !!props.contacts.items.length && props.contacts.items.every((row) => selected.value.includes(row.id)),
);
const someSelected = computed(() => !!selected.value.length && !allSelected.value);
function toggleAll(): void {
    selected.value = allSelected.value ? [] : props.contacts.items.map((row) => row.id);
}
const linkedIds = computed(() => new Set(props.linked.map((row) => row.id)));
const selectedRows = computed(() => props.contacts.items.filter((row) => selected.value.includes(row.id)));
const unlinkedSelection = computed(() => selectedRows.value.filter((row) => !linkedIds.value.has(row.id)));
const plural = (count: number, noun: string) => `${count} ${noun}${count === 1 ? '' : 's'}`;
function bulkLink(): void {
    if (!props.entry || !unlinkedSelection.value.length) return;
    act(
        'post',
        '/contacts/bulk',
        {
            action: 'link',
            items: unlinkedSelection.value.map(({ id, version }) => ({ id, version })),
            link_entry: props.entry.id,
            entry_version: props.entry.version,
        },
        () => (selected.value = []),
    );
}
function bulkDelete(event: Event): void {
    const rows = selectedRows.value;
    confirmAction(
        'Delete contacts',
        `Permanently delete ${plural(rows.length, 'selected contact')} and their private contact details? Contacts linked to a lead must be unlinked first.`,
        () =>
            act(
                'post',
                '/contacts/bulk',
                { action: 'delete', items: rows.map(({ id, version }) => ({ id, version })) },
                () => (selected.value = []),
            ),
        event,
        { tone: 'danger' },
    );
}
function exportHref(resource: 'contacts' | 'reminders', ids: string[] = []): string {
    const search = new URLSearchParams();
    const keys = resource === 'contacts' ? (['q', 'sort', 'linked'] as const) : (['state'] as const);
    if (!ids.length) for (const key of keys) if (props.filters[key]) search.set(key, props.filters[key]);
    for (const id of ids) search.append('ids[]', id);
    const text = search.toString();
    return `/crm/exports/${resource}${text ? `?${text}` : ''}`;
}
const exportActions = computed<RowAction[]>(() => [
    {
        label: 'Contacts',
        detail: contactsFiltered.value ? 'Filtered' : 'All',
        href: exportHref('contacts'),
        download: true,
    },
    {
        label: 'Reminders',
        detail: reminderFilters.find((option) => option.value === props.filters.state)?.label,
        href: exportHref('reminders'),
        download: true,
    },
]);
const notice = ref('');
let noticeTimer: ReturnType<typeof setTimeout> | undefined;
async function copyEmail(email: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(email);
        notice.value = `Copied ${email}`;
    } catch {
        notice.value = 'Could not copy. Select the email address instead.';
    }
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => (notice.value = ''), 4000);
}
onBeforeUnmount(() => clearTimeout(noticeTimer));
function deleteContact(row: Contact, trigger: HTMLElement): void {
    confirmAction(
        'Delete contact',
        `Permanently delete ${row.name} and its private contact details?`,
        () => act('delete', `/contacts/${row.id}`, { version: row.version }, closeContact(row.id)),
        trigger,
        { tone: 'danger' },
    );
}
function contactActions(row: Contact): RowAction[] {
    const actions: RowAction[] = [];
    if (row.email) {
        const email = row.email;
        actions.push({ label: 'Copy email', icon: Copy, select: () => void copyEmail(email) });
        actions.push({ label: 'Send email', icon: Mail, href: `mailto:${email}` });
    }
    if (props.canWrite) {
        actions.push({
            label: 'Add reminder',
            icon: BellPlus,
            disabled: blocked.value,
            select: () => newReminder(`Follow up with ${row.name}`),
        });
        actions.push({
            label: row.lead_count ? 'Unlink from leads to delete' : 'Delete contact…',
            icon: Trash2,
            danger: true,
            separated: actions.length > 0,
            disabled: blocked.value || row.lead_count > 0,
            select: (trigger) => deleteContact(row, trigger),
        });
    }
    return actions;
}
const columns: DataColumn[] = [
    { key: 'name', label: 'Name' },
    { key: 'company', label: 'Company' },
    { key: 'notes', label: 'Notes' },
    { key: 'leads', label: 'Leads' },
    { key: 'actions', label: 'Actions' },
];

// Reminders, grouped by urgency on the current page.
const groupLabels: Record<ReminderTone, string> = {
    overdue: 'Overdue',
    today: 'Today',
    upcoming: 'Upcoming',
    completed: 'Completed',
};
const groups = computed(() => {
    const list: { tone: ReminderTone; items: Reminder[] }[] = [];
    for (const row of props.reminders.items) {
        const tone = reminderTone(row);
        const last = list.at(-1);
        if (last?.tone === tone) last.items.push(row);
        else list.push({ tone, items: [row] });
    }
    return list;
});
const badges: Partial<Record<ReminderTone, { label: string; tone: 'danger' | 'warning' | 'success' }>> = {
    overdue: { label: 'Overdue', tone: 'danger' },
    today: { label: 'Due today', tone: 'warning' },
    completed: { label: 'Completed', tone: 'success' },
};
function snoozeActions(row: Reminder): RowAction[] {
    return [
        ...snoozePresets().map((preset) => ({
            label: preset.label,
            detail: preset.detail,
            disabled: blocked.value,
            select: () =>
                act('post', `/reminders/${row.id}/snooze`, { version: row.version, due_at: preset.at.toISOString() }),
        })),
        {
            label: 'Pick date and time…',
            separated: true,
            disabled: blocked.value,
            select: () => editReminder(row, '#reminder-snooze'),
        },
    ];
}
const emptyReminders = computed(
    () =>
        ({
            pending: 'No pending reminders.',
            due: 'Nothing is due. You are all caught up.',
            completed: 'No completed reminders yet.',
            all: 'No reminders yet.',
        })[props.filters.state] ?? 'No reminders match.',
);

// The editor dialog.
const emptyContact = () => ({ id: '', name: '', email: '', company: '', notes: '', version: 0 });
const emptyReminder = () => ({
    id: '',
    title: '',
    due_at: '',
    pipeline_entry_id: props.entry?.id ?? '',
    version: 0,
});
const contact = ref(emptyContact());
const reminder = ref(emptyReminder());
const snooze = ref('');
const addTab = ref<'reminder' | 'contact'>('reminder');
const editorOpen = ref(false);
const editorDialog = ref<HTMLDialogElement>();
const editorFocus = ref('');
const editing = computed(() => !!(addTab.value === 'contact' ? contact.value.id : reminder.value.id));
const editorTitle = computed(() => {
    if (addTab.value === 'contact') return contact.value.id ? 'Edit contact' : 'New contact';
    return reminder.value.id ? 'Edit reminder' : 'New reminder';
});
watch(
    errors,
    (value) => {
        if (value.name || value.email || value.company || value.notes) addTab.value = 'contact';
        else if (value.title || value.due_at) addTab.value = 'reminder';
        else return;
        editorOpen.value = true;
        editorDialog.value?.showModal();
    },
    { flush: 'pre', immediate: true },
);
watch(
    () => errors.value.version,
    async (error) => {
        if (!error) return;
        await nextTick();
        if (editorDialog.value?.open) editorDialog.value.querySelector<HTMLElement>('#crm-editor-conflict')?.focus();
    },
);
watch(editorOpen, async (open) => {
    await nextTick();
    const dialog = editorDialog.value;
    if (!dialog) return;
    if (open) {
        if (!dialog.open) dialog.showModal();
        const invalid = [...dialog.querySelectorAll<HTMLElement>('[aria-invalid="true"]')].find(
            (node) => node.offsetParent !== null,
        );
        const fallback = dialog.querySelector<HTMLElement>(
            editorFocus.value || (addTab.value === 'contact' ? '#contact-name' : '#reminder-title'),
        );
        editorFocus.value = '';
        (invalid ?? fallback)?.focus();
        return;
    }
    if (dialog.open) dialog.close();
});
const localDate = (value: string): string => {
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
const iso = (value: string): string => new Date(value).toISOString();
/** Clears the contact draft and closes the editor when it held the contact just saved or deleted. */
const closeContact = (id: string) => () => {
    if (contact.value.id !== id) return;
    contact.value = emptyContact();
    editorOpen.value = false;
};
function saveContact(): void {
    const id = contact.value.id;
    act(id ? 'patch' : 'post', '/contacts' + (id ? `/${id}` : ''), { ...contact.value }, () => {
        contact.value = emptyContact();
        editorOpen.value = false;
        panel.value = 'contacts';
    });
}
function closeReminder(): void {
    reminder.value = emptyReminder();
    snooze.value = '';
    editorOpen.value = false;
    panel.value = 'reminders';
}
function saveReminder(): void {
    if (!reminder.value.due_at) return;
    const id = reminder.value.id;
    act(
        id ? 'patch' : 'post',
        '/reminders' + (id ? `/${id}` : ''),
        {
            ...reminder.value,
            due_at: iso(reminder.value.due_at),
            pipeline_entry_id: reminder.value.pipeline_entry_id || null,
        },
        closeReminder,
    );
}
function editContact(row: Contact): void {
    contact.value = { ...row, email: row.email ?? '' };
    addTab.value = 'contact';
    editorOpen.value = true;
}
function editReminder(row: Reminder, focus = ''): void {
    reminder.value = {
        id: row.id,
        title: row.title,
        due_at: localDate(row.due_at),
        pipeline_entry_id: row.pipeline_entry_id ?? '',
        version: row.version,
    };
    snooze.value = '';
    editorFocus.value = focus;
    addTab.value = 'reminder';
    editorOpen.value = true;
}
function newContact(): void {
    if (contact.value.id) contact.value = emptyContact();
    addTab.value = 'contact';
    editorOpen.value = true;
}
function newReminder(title = ''): void {
    if (reminder.value.id || title) {
        reminder.value = { ...emptyReminder(), title };
        snooze.value = '';
    }
    addTab.value = 'reminder';
    editorOpen.value = true;
}
</script>
<template>
    <Head title="Contacts and reminders" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')">
        <div ref="root" class="wd-crm">
            <header class="crm-header">
                <div class="crm-heading">
                    <nav aria-label="Breadcrumb">
                        <ol class="crm-crumbs">
                            <li>
                                <Link :href="path(entry ? `/partner/leads?entry=${entry.id}` : '/partner/leads')"
                                    >Leads</Link
                                ><ChevronRight :size="14" aria-hidden="true" />
                            </li>
                            <li aria-current="page">Contacts and reminders</li>
                        </ol>
                    </nav>
                    <div class="crm-title-row">
                        <h1>Contacts and reminders</h1>
                        <span v-if="entry" class="crm-context">
                            <span class="crm-avatar crm-avatar-context" aria-hidden="true">{{
                                initials(entry.brand_name)
                            }}</span>
                            <span
                                >Working with <strong>{{ entry.brand_name }}</strong></span
                            >
                            <Link
                                class="crm-context-clear"
                                :href="listHref({ contacts_page: 1, reminders_page: 1 }, false)"
                                v-bind="{ 'aria-label': `Stop working with ${entry.brand_name}` }"
                                ><X :size="14" aria-hidden="true"
                            /></Link>
                        </span>
                    </div>
                </div>
                <div class="crm-header-actions">
                    <RowActionMenu
                        label="Export CSV"
                        text="Export"
                        heading="Download CSV"
                        :icon="Download"
                        :actions="exportActions"
                    />
                    <template v-if="canWrite">
                        <button type="button" class="wd-table-button" :disabled="blocked" @click="newReminder()">
                            <Plus :size="16" aria-hidden="true" />New reminder
                        </button>
                        <button
                            type="button"
                            class="wd-table-button crm-primary"
                            :disabled="blocked"
                            @click="newContact"
                        >
                            <Plus :size="16" aria-hidden="true" />New contact
                        </button>
                    </template>
                </div>
            </header>
            <div v-if="errors.version" role="alert" class="wd-banner">
                <p>{{ errors.version }}</p>
                <button
                    type="button"
                    class="crm-text-button"
                    @click="router.get(base, query(), { preserveState: false })"
                >
                    Reload latest records
                </button>
                <p>Copy unsaved changes before reloading.</p>
            </div>
            <p v-if="errors.contact" role="alert" class="wd-banner">{{ errors.contact }}</p>
            <p v-if="!canWrite" class="crm-hint">Viewer access is read only.</p>
            <ul class="crm-stats" aria-label="Workspace summary">
                <li>
                    <span>Contacts</span><strong>{{ summary.contacts.toLocaleString() }}</strong>
                </li>
                <li :class="{ 'is-danger': summary.overdue }">
                    <Link
                        v-if="summary.overdue"
                        :href="listHref({ state: 'due', reminders_page: 1 })"
                        preserve-scroll
                        @click="panel = 'reminders'"
                        ><span>Overdue reminders</span><strong>{{ summary.overdue }}</strong></Link
                    >
                    <template v-else
                        ><span>Overdue reminders</span><strong>{{ summary.overdue }}</strong></template
                    >
                </li>
                <li :class="{ 'is-warning': summary.dueDay }">
                    <span>Due in 24 hours</span><strong>{{ summary.dueDay }}</strong>
                </li>
                <li>
                    <span>Due in 7 days</span><strong>{{ summary.dueWeek }}</strong>
                </li>
            </ul>
            <div class="crm-switch" role="group" aria-label="Show list">
                <button type="button" :aria-pressed="panel === 'contacts'" @click="panel = 'contacts'">
                    Contacts <span class="crm-count">{{ contacts.total }}</span>
                </button>
                <button type="button" :aria-pressed="panel === 'reminders'" @click="panel = 'reminders'">
                    Reminders <span class="crm-count">{{ reminders.total }}</span>
                </button>
            </div>
            <div class="crm-columns" :aria-busy="busy">
                <section
                    class="crm-card"
                    :class="{ 'is-hidden-small': panel !== 'contacts' }"
                    aria-labelledby="contacts-title"
                >
                    <header class="crm-card-head">
                        <h2 id="contacts-title">Contacts</h2>
                        <span class="crm-count">{{ contacts.total }}</span>
                        <p v-if="notice" class="crm-notice" role="status">{{ notice }}</p>
                    </header>
                    <form class="crm-toolbar" role="search" aria-label="Filter contacts" @submit.prevent="submitSearch">
                        <div class="crm-search">
                            <label for="crm-search" class="wd-sr-only">Search contacts</label>
                            <Search :size="16" aria-hidden="true" />
                            <input
                                id="crm-search"
                                v-model="q"
                                class="wd-table-input"
                                type="search"
                                maxlength="160"
                                placeholder="Search name, company or email"
                                autocomplete="off"
                            />
                            <button
                                v-if="q"
                                type="button"
                                class="crm-search-clear"
                                aria-label="Clear search"
                                @click="clearSearch"
                            >
                                <X :size="14" aria-hidden="true" />
                            </button>
                        </div>
                        <SelectField
                            id="crm-sort"
                            class="crm-inline-field"
                            :model-value="filters.sort"
                            label="Sort"
                            label-placement="inline"
                            compact
                            :options="sortOptions"
                            :searchable="false"
                            :disabled="busy"
                            @change="(sort) => filterContacts({ sort })"
                        />
                        <SelectField
                            id="crm-linked"
                            class="crm-inline-field"
                            :model-value="filters.linked"
                            label="Lead links"
                            label-placement="inline"
                            compact
                            :options="linkedOptions"
                            :searchable="false"
                            :disabled="busy"
                            @change="(linked) => filterContacts({ linked })"
                        />
                    </form>
                    <section v-if="entry" class="crm-linked" aria-labelledby="linked-title">
                        <h3 id="linked-title">Linked to {{ entry.brand_name }}</h3>
                        <p v-if="!linked.length" class="crm-hint">No linked contacts yet.</p>
                        <ul v-else>
                            <li v-for="row in linked" :key="row.id" class="crm-chip">
                                <span class="crm-avatar crm-avatar-chip" aria-hidden="true">{{
                                    initials(row.name)
                                }}</span>
                                {{ row.name }}
                                <button
                                    v-if="canWrite"
                                    type="button"
                                    :aria-label="`Unlink ${row.name}`"
                                    :disabled="blocked"
                                    @click="
                                        confirmAction(
                                            'Unlink contact',
                                            `Unlink ${row.name} from ${entry.brand_name}? The contact record is preserved.`,
                                            () =>
                                                act('delete', `/pipeline/${entry!.id}/contacts`, {
                                                    contact_id: row.id,
                                                    version: entry!.version,
                                                }),
                                            $event,
                                            { tone: 'danger' },
                                        )
                                    "
                                >
                                    <X :size="12" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                    </section>
                    <div v-if="selected.length" class="crm-bulk" role="region" aria-label="Selected contacts">
                        <p>{{ plural(selected.length, 'contact') }} selected</p>
                        <button type="button" class="crm-text-button" @click="selected = []">Clear</button>
                        <span class="crm-bulk-actions">
                            <button
                                v-if="canWrite && entry"
                                type="button"
                                class="wd-table-button"
                                :disabled="blocked || !unlinkedSelection.length"
                                @click="bulkLink"
                            >
                                <LinkIcon :size="14" aria-hidden="true" />Link to {{ entry.brand_name }}
                            </button>
                            <a class="wd-table-button" :href="exportHref('contacts', selected)" download
                                ><Download :size="14" aria-hidden="true" />Export</a
                            >
                            <button
                                v-if="canWrite"
                                type="button"
                                class="wd-table-button crm-danger"
                                :disabled="blocked"
                                @click="bulkDelete"
                            >
                                <Trash2 :size="14" aria-hidden="true" />Delete…
                            </button>
                        </span>
                    </div>
                    <DataTable
                        v-if="contacts.items.length"
                        class="crm-table"
                        caption="Contacts"
                        :columns="columns"
                        :rows="contacts.items"
                        :row-key="(row) => row.id"
                    >
                        <template #head-name>
                            <span class="crm-name-cell">
                                <input
                                    type="checkbox"
                                    class="crm-checkbox"
                                    aria-label="Select all contacts on this page"
                                    :checked="allSelected"
                                    :indeterminate="someSelected"
                                    @change="toggleAll"
                                />Name
                            </span>
                        </template>
                        <template #head-actions><span class="wd-sr-only">Actions</span></template>
                        <template #cell-name="{ row }">
                            <span class="crm-name-cell">
                                <input
                                    v-model="selected"
                                    type="checkbox"
                                    class="crm-checkbox"
                                    :value="row.id"
                                    :aria-label="`Select ${row.name}`"
                                />
                                <span class="crm-avatar" aria-hidden="true">{{ initials(row.name) }}</span>
                                <span class="crm-person">
                                    <span class="crm-person-name">{{ row.name }}</span>
                                    <span v-if="row.email" class="wd-data-secondary">{{ row.email }}</span>
                                </span>
                            </span>
                        </template>
                        <template #cell-company="{ row }">
                            <span v-if="row.company">{{ row.company }}</span>
                            <span v-else class="crm-none" aria-label="None">—</span>
                        </template>
                        <template #cell-notes="{ row }">
                            <span v-if="row.notes" class="crm-note" :title="row.notes"
                                ><Lock :size="12" aria-hidden="true" /><span>{{ row.notes }}</span></span
                            >
                            <span v-else class="crm-none" aria-label="None">—</span>
                        </template>
                        <template #cell-leads="{ row }">
                            <template v-if="entry">
                                <StatusBadge v-if="linkedIds.has(row.id)" label="Linked" tone="success" />
                                <button
                                    v-else-if="canWrite"
                                    type="button"
                                    class="wd-table-button"
                                    :aria-label="`Link ${row.name} to ${entry.brand_name}`"
                                    :disabled="blocked"
                                    @click="
                                        act('post', `/pipeline/${entry.id}/contacts`, {
                                            contact_id: row.id,
                                            version: entry.version,
                                        })
                                    "
                                >
                                    <LinkIcon :size="14" aria-hidden="true" /><span class="crm-link-text">Link</span>
                                </button>
                                <span v-else class="crm-none">Not linked</span>
                            </template>
                            <span v-else-if="row.lead_count">{{ plural(row.lead_count, 'lead') }}</span>
                            <span v-else class="crm-none" aria-label="None">—</span>
                        </template>
                        <template #cell-actions="{ row }">
                            <span class="crm-row-actions">
                                <button
                                    v-if="canWrite"
                                    type="button"
                                    class="wd-table-icon-button crm-ghost"
                                    :aria-label="`Edit ${row.name}`"
                                    :disabled="blocked"
                                    @click="editContact(row)"
                                >
                                    <Pencil :size="15" aria-hidden="true" />
                                </button>
                                <RowActionMenu
                                    v-if="contactActions(row).length"
                                    trigger-class="crm-ghost"
                                    :label="`More actions for ${row.name}`"
                                    :actions="contactActions(row)"
                                />
                            </span>
                        </template>
                    </DataTable>
                    <EmptyState
                        v-else
                        class="crm-empty"
                        :title="contactsFiltered ? 'No contacts match' : 'No contacts yet'"
                        :description="
                            contactsFiltered
                                ? 'Try another search or clear the filters.'
                                : 'Contacts you add here can be linked to any of your leads.'
                        "
                    />
                    <Pagination
                        v-if="contacts.total"
                        class="crm-pagination"
                        label="Contacts pages"
                        noun="contacts"
                        numbered
                        :page="contacts.page"
                        :per-page="contacts.perPage"
                        :count="contacts.items.length"
                        :total="contacts.total"
                        :href-for="pageHref('contacts')"
                    >
                        <SelectField
                            id="crm-per-page"
                            class="wd-page-size"
                            :model-value="String(contacts.perPage)"
                            label="Rows per page"
                            label-placement="inline"
                            compact
                            :options="pageSizes"
                            :searchable="false"
                            :disabled="busy"
                            @change="(size) => filterContacts({ per_page: size })"
                        />
                    </Pagination>
                </section>
                <section
                    class="crm-card"
                    :class="{ 'is-hidden-small': panel !== 'reminders' }"
                    aria-labelledby="reminders-title"
                >
                    <header class="crm-card-head">
                        <h2 id="reminders-title">Reminders</h2>
                        <span class="crm-count">{{ reminders.total }}</span>
                        <span class="crm-hint crm-card-note">Your local time</span>
                    </header>
                    <div class="crm-segment" role="group" aria-label="Reminder filter">
                        <button
                            v-for="option in reminderFilters"
                            :key="option.value"
                            type="button"
                            :aria-pressed="filters.state === option.value"
                            :disabled="busy"
                            @click="filterReminders(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <p v-if="!reminders.items.length" class="crm-empty-line">{{ emptyReminders }}</p>
                    <template v-for="group in groups" :key="group.tone">
                        <h3 class="crm-group" :class="`is-${group.tone}`">
                            {{ groupLabels[group.tone] }}
                        </h3>
                        <ul class="crm-reminders">
                            <li v-for="row in group.items" :key="row.id">
                                <button
                                    v-if="canWrite"
                                    type="button"
                                    class="crm-complete"
                                    :aria-label="
                                        row.completed_at ? `Mark pending: ${row.title}` : `Complete: ${row.title}`
                                    "
                                    :disabled="blocked"
                                    @click="
                                        act(
                                            'post',
                                            `/reminders/${row.id}/${row.completed_at ? 'reopen' : 'complete'}`,
                                            {
                                                version: row.version,
                                            },
                                        )
                                    "
                                >
                                    <span class="crm-check" :class="{ 'is-done': row.completed_at }" aria-hidden="true">
                                        <Check v-if="row.completed_at" :size="12" />
                                    </span>
                                </button>
                                <span v-else class="crm-complete" aria-hidden="true">
                                    <span class="crm-check" :class="{ 'is-done': row.completed_at }">
                                        <Check v-if="row.completed_at" :size="12" />
                                    </span>
                                </span>
                                <div class="crm-reminder-copy">
                                    <p class="crm-reminder-title">{{ row.title }}</p>
                                    <p class="crm-reminder-meta">
                                        <time :datetime="row.due_at">{{ dueLabel(row.due_at) }}</time>
                                        <StatusBadge
                                            v-if="badges[group.tone]"
                                            :label="badges[group.tone]!.label"
                                            :tone="badges[group.tone]!.tone"
                                        />
                                        <Link
                                            v-if="row.pipeline_entry_id"
                                            class="crm-inline-link"
                                            :href="path(`/partner/leads?entry=${row.pipeline_entry_id}`)"
                                            v-bind="{
                                                'aria-label': `Open related lead${row.lead_name ? ` ${row.lead_name}` : ''}`,
                                            }"
                                            >{{ row.lead_name || 'Related lead' }}</Link
                                        >
                                    </p>
                                </div>
                                <span v-if="canWrite" class="crm-row-actions">
                                    <RowActionMenu
                                        v-if="!row.completed_at"
                                        trigger-class="crm-ghost"
                                        :label="`Snooze ${row.title}`"
                                        heading="Snooze until"
                                        :icon="AlarmClock"
                                        :disabled="blocked"
                                        :actions="snoozeActions(row)"
                                    />
                                    <button
                                        type="button"
                                        class="wd-table-icon-button crm-ghost"
                                        :aria-label="`Edit ${row.title}`"
                                        :disabled="blocked"
                                        @click="editReminder(row)"
                                    >
                                        <Pencil :size="15" aria-hidden="true" />
                                    </button>
                                </span>
                            </li>
                        </ul>
                    </template>
                    <Pagination
                        v-if="reminders.total > reminders.perPage"
                        class="crm-pagination"
                        label="Reminders pages"
                        noun="reminders"
                        numbered
                        :page="reminders.page"
                        :per-page="reminders.perPage"
                        :count="reminders.items.length"
                        :total="reminders.total"
                        :href-for="pageHref('reminders')"
                    />
                </section>
            </div>
            <dialog
                v-if="canWrite"
                ref="editorDialog"
                class="wd-dialog crm-editor"
                aria-labelledby="editor-title"
                @close="editorOpen = false"
                @keydown="containDialogFocus($event, editorDialog)"
            >
                <div class="wd-dialog-header">
                    <h2 id="editor-title">{{ editorTitle }}</h2>
                    <DialogClose label="Close editor" :disabled="busy" @click="editorOpen = false" />
                </div>
                <div v-if="!editing" class="crm-segment" role="group" aria-label="What to add">
                    <button type="button" :aria-pressed="addTab === 'reminder'" @click="addTab = 'reminder'">
                        Reminder
                    </button>
                    <button type="button" :aria-pressed="addTab === 'contact'" @click="addTab = 'contact'">
                        Contact
                    </button>
                </div>
                <p v-if="errors.version" id="crm-editor-conflict" role="alert" tabindex="-1" class="wd-banner">
                    {{ errors.version }} Copy your unsaved changes, close this editor and reload the latest records.
                </p>
                <form v-show="addTab === 'reminder'" :aria-busy="busy" @submit.prevent="saveReminder">
                    <fieldset class="crm-fields" :disabled="blocked">
                        <legend class="wd-sr-only">{{ reminder.id ? 'Edit reminder' : 'New reminder' }}</legend>
                        <FormField
                            id="reminder-title"
                            v-model="reminder.title"
                            label="Reminder title"
                            :maxlength="240"
                            :error="errors.title"
                        />
                        <FormField
                            id="reminder-due"
                            v-model="reminder.due_at"
                            label="Due (your local time)"
                            type="datetime-local"
                            :hint="entry ? `New reminders here relate to ${entry.brand_name}.` : undefined"
                            :error="errors.due_at"
                        />
                        <div v-if="reminder.id" class="crm-snooze">
                            <FormField
                                id="reminder-snooze"
                                v-model="snooze"
                                label="Snooze until (your local time)"
                                type="datetime-local"
                                :required="false"
                            />
                            <Button
                                type="button"
                                class="wd-button wd-button-secondary"
                                :disabled="!snooze"
                                @click="
                                    act('post', `/reminders/${reminder.id}/snooze`, {
                                        version: reminder.version,
                                        due_at: iso(snooze),
                                    })
                                "
                            >
                                Snooze reminder
                            </Button>
                        </div>
                        <div class="wd-dialog-footer crm-editor-footer">
                            <Button
                                v-if="reminder.id"
                                type="button"
                                class="wd-button wd-button-danger-outline crm-editor-delete"
                                @click="
                                    confirmAction(
                                        'Delete reminder',
                                        `Permanently delete reminder ${reminder.title}?`,
                                        () =>
                                            act(
                                                'delete',
                                                `/reminders/${reminder.id}`,
                                                { version: reminder.version },
                                                closeReminder,
                                            ),
                                        $event,
                                        { tone: 'danger' },
                                    )
                                "
                            >
                                Delete reminder
                            </Button>
                            <Button type="button" class="wd-button wd-button-secondary" @click="editorOpen = false"
                                >Cancel</Button
                            ><Button type="submit" class="wd-button wd-button-primary">Save reminder</Button>
                        </div>
                    </fieldset>
                </form>
                <form v-show="addTab === 'contact'" :aria-busy="busy" @submit.prevent="saveContact">
                    <fieldset class="crm-fields" :disabled="blocked">
                        <legend class="wd-sr-only">{{ contact.id ? 'Edit contact' : 'New contact' }}</legend>
                        <div class="crm-field-pair">
                            <FormField
                                id="contact-name"
                                v-model="contact.name"
                                label="Contact name"
                                :maxlength="120"
                                :error="errors.name"
                            />
                            <FormField
                                id="contact-email"
                                v-model="contact.email"
                                label="Contact email"
                                type="email"
                                :required="false"
                                :maxlength="255"
                                :error="errors.email"
                            />
                        </div>
                        <FormField
                            id="contact-company"
                            v-model="contact.company"
                            label="Company"
                            :required="false"
                            :maxlength="160"
                            :error="errors.company"
                        />
                        <div class="wd-field">
                            <label for="contact-notes">Private notes</label>
                            <textarea
                                id="contact-notes"
                                v-model="contact.notes"
                                rows="3"
                                maxlength="5000"
                                :aria-invalid="errors.notes ? true : undefined"
                                :aria-describedby="
                                    errors.notes ? 'contact-notes-hint contact-notes-error' : 'contact-notes-hint'
                                "
                            />
                            <p id="contact-notes-hint" class="crm-hint">
                                Visible to authorized members of this workspace.
                            </p>
                            <p v-if="errors.notes" id="contact-notes-error" class="wd-field-error" role="alert">
                                {{ errors.notes }}
                            </p>
                        </div>
                        <div class="wd-dialog-footer crm-editor-footer">
                            <Button
                                v-if="contact.id"
                                type="button"
                                class="wd-button wd-button-danger-outline crm-editor-delete"
                                @click="
                                    confirmAction(
                                        'Delete contact',
                                        `Permanently delete ${contact.name} and its private contact details?`,
                                        () =>
                                            act(
                                                'delete',
                                                `/contacts/${contact.id}`,
                                                { version: contact.version },
                                                closeContact(contact.id),
                                            ),
                                        $event,
                                        { tone: 'danger' },
                                    )
                                "
                            >
                                Delete contact
                            </Button>
                            <Button type="button" class="wd-button wd-button-secondary" @click="editorOpen = false"
                                >Cancel</Button
                            ><Button type="submit" class="wd-button wd-button-primary">Save contact</Button>
                        </div>
                    </fieldset>
                </form>
            </dialog>
        </div>
    </FoundationShell>
</template>
<style scoped>
.wd-crm {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding-bottom: 16px;
    min-width: 0;
    color: var(--wd-ink);
    font-size: 14px;
    line-height: 1.4;
}
.wd-crm :focus-visible {
    outline: 2px solid var(--wd-brand);
    outline-offset: 2px;
}
.wd-crm > .wd-banner {
    margin-bottom: 0;
}
.crm-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px 24px;
}
.crm-heading {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.crm-crumbs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    margin: 0;
    padding: 0;
    list-style: none;
    color: var(--wd-muted);
    font-size: 13px;
}
.crm-crumbs li {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.crm-crumbs a {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    color: var(--wd-brand);
    font-weight: 600;
    text-decoration: none;
}
.crm-crumbs a:hover {
    color: var(--wd-brand-hover);
    text-decoration: underline;
}
.crm-title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
    min-width: 0;
}
.wd-crm h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.15;
    overflow-wrap: anywhere;
}
.crm-context {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 100%;
    padding: 3px 4px 3px 3px;
    border: 1px solid var(--wd-line);
    border-radius: 999px;
    background: var(--wd-surface);
    color: var(--wd-body);
    font-size: 13px;
}
.crm-context strong {
    color: var(--wd-ink);
    overflow-wrap: anywhere;
}
.crm-context-clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    color: var(--wd-muted);
}
.crm-context-clear:hover {
    background: var(--wd-divider);
    color: var(--wd-ink);
}
.crm-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.crm-primary {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.wd-crm .crm-primary:hover:not(:disabled) {
    border-color: var(--wd-brand-hover);
    background: var(--wd-brand-hover);
    color: #fff;
}
.crm-danger {
    color: var(--wd-danger);
}
.wd-crm .crm-danger:hover:not(:disabled) {
    background: var(--wd-danger-bg);
    color: var(--wd-danger);
}
.wd-table-button:disabled,
.wd-table-icon-button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}
.crm-hint {
    margin: 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.crm-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.crm-stats li,
.crm-stats a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-width: 0;
}
.crm-stats li {
    padding: 10px 14px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-surface);
}
.crm-stats a {
    flex: 1;
    margin: -10px -14px;
    padding: 10px 14px;
    border-radius: 10px;
    color: inherit;
    text-decoration: none;
}
.crm-stats a:hover {
    background: var(--wd-danger-bg);
}
.crm-stats span {
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.crm-stats strong {
    font-family: var(--wd-font-display);
    font-size: 20px;
    font-variant-numeric: tabular-nums;
    line-height: 1;
}
.crm-stats .is-danger strong {
    color: var(--wd-danger);
}
.crm-stats .is-warning strong {
    color: var(--wd-warning);
}
.crm-switch {
    display: none;
}
.crm-columns {
    display: grid;
    grid-template-columns: minmax(0, 1.75fr) minmax(320px, 1fr);
    align-items: start;
    gap: 16px;
    min-width: 0;
}
.crm-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    overflow: clip;
}
.crm-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 8px;
    padding: 12px 14px 10px;
}
.wd-crm h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
    line-height: 1.25;
}
.crm-count {
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.crm-card-note,
.crm-notice {
    margin: 0 0 0 auto;
}
.crm-notice {
    color: var(--wd-success);
    font-size: 13px;
    font-weight: 600;
}
.crm-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 0 14px 10px;
}
.crm-search {
    position: relative;
    display: flex;
    flex: 1 1 220px;
    align-items: center;
    min-width: 0;
}
.crm-search > svg {
    position: absolute;
    left: 10px;
    color: var(--wd-muted);
    pointer-events: none;
}
.crm-search input {
    width: 100%;
    padding-inline: 32px;
    font-size: 13px;
}
.crm-search input::-webkit-search-cancel-button {
    display: none;
}
.crm-search-clear {
    position: absolute;
    right: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 6px;
    background: none;
    color: var(--wd-muted);
}
.crm-search-clear:hover {
    background: var(--wd-divider);
    color: var(--wd-ink);
}
/* About as wide as the native selects they replaced, so Sort and Lead links stay beside the search box. */
.crm-inline-field :deep(.wd-select-anchor) {
    flex: none;
    width: 150px;
}
.crm-linked {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 8px;
    padding: 8px 14px;
    border-top: 1px solid var(--wd-divider);
    background: var(--wd-paper);
}
.crm-linked h3 {
    margin: 0;
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.crm-linked h3::after {
    content: ':';
}
.crm-linked ul {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.crm-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 26px;
    padding: 2px 4px 2px 3px;
    border-radius: 999px;
    background: var(--wd-success-bg);
    color: var(--wd-success);
    font-size: 12px;
    font-weight: 700;
}
.crm-chip button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: none;
    color: inherit;
}
.crm-chip button:hover:not(:disabled) {
    background: rgb(36 97 79 / 14%);
}
.crm-bulk {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 10px;
    padding: 6px 14px;
    border-top: 1px solid var(--wd-divider);
    background: var(--color-blush);
    color: var(--wd-brand-hover);
    font-size: 13px;
    font-weight: 700;
}
.crm-bulk p {
    margin: 0;
}
.crm-bulk-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-left: auto;
}
.crm-avatar {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--color-blush);
    color: var(--wd-brand-hover);
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}
.crm-avatar-context {
    width: 22px;
    height: 22px;
    background: var(--wd-brand-hover);
    color: #fff;
    font-size: 10px;
}
.crm-avatar-chip {
    width: 20px;
    height: 20px;
    background: var(--wd-success);
    color: #fff;
    font-size: 9px;
}
/* Lists use 30px row controls with a fine pointer; phones and touch screens keep 44px targets. */
@media (min-width: 600px) and (pointer: fine) {
    .crm-header-actions {
        --wd-table-control: 34px;
    }
    .crm-table,
    .crm-reminders {
        --wd-table-control: 30px;
    }
}
/* Contacts table at list density. */
/* The table sits inside the card's side padding with its own rounded border, as on other pages. */
.crm-table {
    margin: 0 14px 14px;
}
.crm-table :deep(.wd-data-table) {
    min-width: 640px;
    font-size: 13px;
}
.crm-table :deep(.wd-data-table th),
.crm-table :deep(.wd-data-table td) {
    padding: 6px 10px;
    vertical-align: middle;
}
.crm-table :deep(.wd-data-table thead th) {
    padding-block: 7px;
    border-bottom: 1px solid var(--wd-divider);
    font-size: 11px;
}
.crm-table :deep(.wd-data-table tr > :first-child) {
    padding-left: 14px;
}
.crm-table :deep(.wd-data-table tr > :last-child) {
    width: 1%;
    padding-right: 10px;
    text-align: right;
    white-space: nowrap;
}
.crm-table :deep(.wd-data-table tbody th) {
    min-width: 200px;
    font-weight: 600;
}
.crm-table :deep(.wd-data-table tbody tr:hover > *) {
    background: #fcfaf6;
}
.crm-name-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.crm-checkbox {
    flex: none;
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: var(--wd-brand);
}
.crm-person {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.crm-person-name {
    color: var(--wd-ink);
    overflow-wrap: anywhere;
}
.crm-person :deep(.wd-data-secondary),
.crm-person .wd-data-secondary {
    font-size: 12px;
    overflow-wrap: anywhere;
}
.crm-none {
    color: var(--wd-muted);
}
.crm-note {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    max-width: 200px;
    color: var(--wd-body);
}
.crm-note svg {
    flex: none;
    color: var(--wd-muted);
}
.crm-note span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.crm-row-actions {
    display: inline-flex;
    align-items: center;
    gap: 2px;
}
.crm-ghost,
.crm-row-actions :deep(.crm-ghost) {
    border-color: transparent;
    background: transparent;
    color: var(--wd-muted);
}
.crm-empty {
    margin: 0 14px 14px;
}
.crm-pagination {
    padding: 0 14px 14px;
}
/* Reminders. */
.crm-segment {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 2px;
    padding: 3px;
    border-radius: 9px;
    background: var(--wd-divider);
}
.crm-card > .crm-segment {
    margin: 0 14px 10px;
}
.crm-editor .crm-segment {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.crm-segment button {
    min-width: 0;
    min-height: 30px;
    padding: 4px 6px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: var(--wd-muted);
    font-size: 13px;
    font-weight: 600;
    line-height: 1.2;
}
.crm-segment button[aria-pressed='true'] {
    background: var(--wd-surface);
    box-shadow: 0 1px 2px rgb(34 26 38 / 12%);
    color: var(--wd-brand-hover);
}
.crm-empty-line {
    margin: 0;
    padding: 24px 14px;
    border-top: 1px solid var(--wd-divider);
    color: var(--wd-muted);
    text-align: center;
}
.wd-crm h3.crm-group {
    margin: 0;
    padding: 5px 14px;
    border-top: 1px solid var(--wd-divider);
    background: var(--wd-paper);
    color: var(--wd-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.wd-crm h3.crm-group.is-overdue {
    color: var(--wd-danger);
}
.crm-reminders {
    margin: 0;
    padding: 0;
    list-style: none;
}
.crm-reminders > li {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 52px;
    padding: 6px 8px 6px 6px;
    border-top: 1px solid var(--wd-divider);
}
.crm-reminders > li:hover {
    background: #fcfaf6;
}
.crm-complete {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    border-radius: 8px;
    background: transparent;
}
.crm-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    box-sizing: border-box;
    border: 2px solid var(--wd-control-line);
    border-radius: 50%;
    background: var(--wd-surface);
    color: #fff;
}
button.crm-complete:hover:not(:disabled) .crm-check {
    border-color: var(--wd-success);
}
.crm-check.is-done {
    border-color: var(--wd-success);
    background: var(--wd-success);
}
.crm-reminder-copy {
    flex: 1;
    min-width: 0;
}
.crm-reminder-title {
    margin: 0;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.crm-reminder-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px 8px;
    margin: 2px 0 0;
    color: var(--wd-muted);
    font-size: 12px;
}
.crm-reminder-meta :deep(.wd-badge) {
    padding-block: 0;
    font-size: 11px;
}
.crm-inline-link {
    color: var(--wd-brand);
    font-weight: 600;
    text-decoration: none;
}
.crm-inline-link:hover {
    text-decoration: underline;
}
.crm-text-button {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 0 2px;
    border: 0;
    background: none;
    color: inherit;
    font-size: 13px;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 3px;
}
/* Editor dialog. */
.crm-editor[open] {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.crm-editor .wd-dialog-header {
    margin-bottom: 0;
}
.crm-fields {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.crm-field-pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}
.crm-snooze {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 8px 10px;
    padding: 10px 12px;
    border-radius: 10px;
    background: var(--wd-paper);
}
.crm-snooze > :first-child {
    flex: 1 1 200px;
}
.crm-editor-footer {
    flex-wrap: wrap;
    margin-top: 4px;
}
.crm-editor-delete {
    margin-right: auto;
}
.wd-crm :deep(.wd-hint) {
    color: var(--wd-muted);
    font-size: 13px;
}
.wd-crm :deep(.wd-field-error) {
    color: var(--wd-danger);
}
@media (max-width: 1099px) {
    .crm-columns {
        grid-template-columns: minmax(0, 1fr);
    }
    .crm-switch {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 2px;
        padding: 3px;
        border-radius: 9px;
        background: var(--wd-divider);
    }
    .crm-switch button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 36px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: var(--wd-muted);
        font-weight: 700;
    }
    .crm-switch button[aria-pressed='true'] {
        background: var(--wd-surface);
        box-shadow: 0 1px 2px rgb(34 26 38 / 12%);
        color: var(--wd-brand-hover);
    }
    .crm-card.is-hidden-small {
        display: none;
    }
}
@media (max-width: 767px) {
    .crm-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 599px) {
    .wd-crm {
        gap: 12px;
    }
    .wd-crm h1 {
        font-size: 24px;
    }
    .crm-header-actions {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) minmax(0, 1fr);
        width: 100%;
    }
    .crm-header-actions :deep(.wd-row-menu-trigger span) {
        display: none;
    }
    .crm-header-actions :deep(.wd-row-menu-trigger) {
        min-width: 44px;
    }
    .crm-stats li {
        padding: 8px 12px;
    }
    .crm-stats strong {
        font-size: 18px;
    }
    .crm-inline-field {
        flex: 1 1 140px;
        flex-direction: column;
        align-items: stretch;
        gap: 2px;
    }
    .crm-inline-field select {
        width: 100%;
        min-width: 0;
    }
    .crm-person-name,
    .crm-person .wd-data-secondary {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        overflow-wrap: normal;
    }
    .crm-link-text {
        display: none;
    }
    .crm-table :deep(.wd-data-table tbody td[data-label='Leads'] .wd-table-button) {
        min-width: 44px;
        padding: 0;
    }
    .crm-field-pair {
        grid-template-columns: minmax(0, 1fr);
    }
    /* Phones show each contact as a compact row: name, lead link and actions, then the company. */
    .crm-table :deep(.wd-data-table) {
        min-width: 0;
    }
    .crm-table :deep(.wd-data-table tbody tr) {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        align-items: center;
        gap: 0 6px;
        margin: 0;
        padding: 6px 6px 6px 12px;
        border: 0;
        border-top: 1px solid var(--wd-divider);
        border-radius: 0;
    }
    .crm-table :deep(.wd-data-table tbody tr + tr) {
        margin-top: 0;
    }
    .crm-table :deep(.wd-data-table tbody tr > *) {
        padding: 0;
        background: none;
    }
    .crm-table :deep(.wd-data-table tbody th) {
        grid-row: 1;
        grid-column: 1;
        min-width: 0;
    }
    .crm-table :deep(.wd-data-table tbody td[data-label]::before) {
        display: none;
    }
    .crm-table :deep(.wd-data-table tbody td[data-label='Company']) {
        grid-row: 2;
        grid-column: 1;
        margin-top: -4px;
        padding-left: 64px;
        color: var(--wd-muted);
        font-size: 12px;
    }
    .crm-table :deep(.wd-data-table tbody td[data-label='Leads']) {
        grid-row: 1;
        grid-column: 2;
        font-size: 12px;
    }
    .crm-table :deep(.wd-data-table tbody td:last-child) {
        grid-row: 1;
        grid-column: 3;
        width: auto;
    }
    .crm-table :deep(.wd-data-table tbody td[data-label='Notes']),
    .crm-table :deep(.wd-data-table tbody td:has(> .crm-none)) {
        display: none;
    }
    .crm-pagination {
        padding-inline: 12px;
    }
}
</style>
