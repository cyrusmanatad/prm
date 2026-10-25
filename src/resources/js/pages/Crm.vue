<script setup lang="ts">
import { confirmAction } from '../composables/useConfirmation';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Calendar, Check, Link as LinkIcon, Lock } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import ActionGroup from '../components/ActionGroup.vue';
import ContentSection from '../components/ContentSection.vue';
import { Button } from '../components/ui/button';
import DialogClose from '../components/DialogClose.vue';
import { containDialogFocus } from '../composables/useDialogFocus';
import { useValidationFocus } from '../composables/useValidationFocus';
type Contact = {
    id: string;
    name: string;
    email: string | null;
    company: string;
    notes: string;
    version: number;
};
type Reminder = {
    id: string;
    title: string;
    due_at: string;
    completed_at: string | null;
    pipeline_entry_id: string | null;
    version: number;
};
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    contacts: { items: Contact[]; nextCursor: string | null };
    reminders: { items: Reminder[]; nextCursor: string | null };
    entry: { id: string; brand_name: string; version: number } | null;
    linked: { id: string; name: string }[];
    canWrite: boolean;
    filters: { q: string; state: string };
    errors?: Record<string, string>;
    status?: string;
}>();
const root = ref<HTMLElement>();
const busy = ref(false);
const errors = computed(() => props.errors ?? {});
useValidationFocus(
    root,
    () => errors.value,
    () => !busy.value,
);
const contact = ref({ id: '', name: '', email: '', company: '', notes: '', version: 0 });
const reminder = ref({
    id: '',
    title: '',
    due_at: '',
    pipeline_entry_id: props.entry?.id ?? '',
    version: 0,
});
const q = ref(props.filters.q);
const state = computed(() => props.filters.state);
watch(
    () => props.filters,
    (filters) => {
        q.value = filters.q;
    },
);
const snooze = ref('');
const addTab = ref<'reminder' | 'contact'>('reminder');
const editorOpen = ref(false);
const editorDialog = ref<HTMLDialogElement>();
const emptyContact = () => ({ id: '', name: '', email: '', company: '', notes: '', version: 0 });
const emptyReminder = () => ({
    id: '',
    title: '',
    due_at: '',
    pipeline_entry_id: props.entry?.id ?? '',
    version: 0,
});
const editorTitle = computed(() => {
    if (addTab.value === 'contact') return contact.value.id ? 'Edit contact' : 'New contact';
    return reminder.value.id ? 'Edit reminder' : 'New reminder';
});
const reminderFilters = [
    { value: 'pending', label: 'Pending' },
    { value: 'due', label: 'Due' },
    { value: 'completed', label: 'Completed' },
    { value: 'all', label: 'All' },
] as const;
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
            addTab.value === 'contact' ? '#contact-name' : '#reminder-title',
        );
        (invalid ?? fallback)?.focus();
        return;
    }
    if (dialog.open) dialog.close();
});
function act(method: 'post' | 'patch' | 'delete', path: string, data: Record<string, string | number | null>): void {
    if (busy.value || errors.value.version) return;
    router.visit(path, {
        method,
        data: { ...data, entry: props.entry?.id ?? null, crm_filters: props.filters },
        preserveState: true,
        preserveScroll: true,
        onStart: () => {
            busy.value = true;
        },
        onFinish: () => {
            busy.value = false;
        },
        onSuccess: () => {
            if (path === '/contacts' || path.startsWith('/contacts/')) {
                contact.value = emptyContact();
                editorOpen.value = false;
            }
            if (/^\/reminders(?:\/[^/]+)?$/.test(path)) {
                reminder.value = emptyReminder();
                snooze.value = '';
                editorOpen.value = false;
            } else if (
                reminder.value.id &&
                path.startsWith(`/reminders/${reminder.value.id}/`) &&
                reminder.value.version === data.version
            ) {
                reminder.value.version += 1;
                if (path.endsWith('/snooze') && typeof data.due_at === 'string')
                    reminder.value.due_at = localDate(data.due_at);
            }
        },
    });
}
function list(cursor?: string, kind?: string, selectedState = state.value): void {
    if (busy.value) return;
    const filtersChanged = q.value !== props.filters.q || selectedState !== state.value;
    router.get(
        '/partner/contacts',
        {
            q: q.value,
            state: selectedState,
            ...(props.entry ? { entry: props.entry.id } : {}),
            ...(!filtersChanged && cursor && kind ? { [`${kind}_cursor`]: cursor } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
const localDate = (value: string): string => {
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
const iso = (value: string): string => new Date(value).toISOString();
function saveContact(): void {
    act(contact.value.id ? 'patch' : 'post', '/contacts' + (contact.value.id ? `/${contact.value.id}` : ''), {
        ...contact.value,
    });
}
function saveReminder(): void {
    if (reminder.value.due_at)
        act(reminder.value.id ? 'patch' : 'post', '/reminders' + (reminder.value.id ? `/${reminder.value.id}` : ''), {
            ...reminder.value,
            due_at: iso(reminder.value.due_at),
            pipeline_entry_id: reminder.value.pipeline_entry_id || null,
        });
}
function initials(name: string): string {
    const letters = name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
    return letters || '?';
}
function contactRecord(id: string): Contact | undefined {
    return props.contacts.items.find((item) => item.id === id);
}
function dueLabel(value: string): string {
    const date = new Date(value);
    const day = date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }),
    });
    const time = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    return `${day} · ${time}`;
}
function reminderTone(row: Reminder): 'overdue' | 'today' | 'completed' | null {
    if (row.completed_at) return 'completed';
    const due = new Date(row.due_at);
    const now = new Date();
    if (due.getTime() < now.getTime()) return 'overdue';
    if (due.getFullYear() === now.getFullYear() && due.getMonth() === now.getMonth() && due.getDate() === now.getDate())
        return 'today';
    return null;
}
const reminderCounts = computed(() =>
    props.reminders.items.reduce(
        (counts, row) => {
            const tone = reminderTone(row);
            if (tone === 'overdue') counts.overdue += 1;
            if (tone === 'today') counts.today += 1;
            return counts;
        },
        { overdue: 0, today: 0 },
    ),
);
function contactCount(count: number): string {
    return `${count} ${count === 1 ? 'contact' : 'contacts'}`;
}
function editContact(row: Contact): void {
    contact.value = { ...row, email: row.email ?? '' };
    addTab.value = 'contact';
    editorOpen.value = true;
}
function editLinked(id: string): void {
    const row = contactRecord(id);
    if (row) editContact(row);
}
function editReminder(row: Reminder): void {
    reminder.value = {
        ...row,
        due_at: localDate(row.due_at),
        pipeline_entry_id: row.pipeline_entry_id ?? '',
    };
    addTab.value = 'reminder';
    editorOpen.value = true;
}
function newContact(): void {
    if (contact.value.id) contact.value = emptyContact();
    addTab.value = 'contact';
    editorOpen.value = true;
}
function newReminder(): void {
    if (reminder.value.id) {
        reminder.value = emptyReminder();
        snooze.value = '';
    }
    addTab.value = 'reminder';
    editorOpen.value = true;
}
const blocked = computed(() => busy.value || !!errors.value.version);
</script>
<template>
    <Head title="Contacts and reminders" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')">
        <div ref="root" class="wd-crm">
            <div class="crm-intro">
                <nav aria-label="Workspace">
                    <Link class="crm-back" :href="entry ? `/partner/leads?entry=${entry.id}` : '/partner/leads'">
                        <ArrowLeft :size="16" aria-hidden="true" />
                        Back to leads
                    </Link>
                </nav>
                <div class="crm-heading">
                    <div class="crm-heading-copy">
                        <p class="crm-eyebrow">{{ workspace.name }}</p>
                        <h1>Contacts and reminders</h1>
                    </div>
                    <div v-if="entry" class="crm-context">
                        <span class="crm-avatar crm-avatar-context" aria-hidden="true">{{
                            initials(entry.brand_name)
                        }}</span>
                        <span class="crm-context-copy">
                            <span class="crm-context-label">Working with</span>
                            <span class="crm-context-name">{{ entry.brand_name }}</span>
                        </span>
                    </div>
                </div>
            </div>
            <div v-if="errors.version" role="alert" class="wd-banner">
                <p>{{ errors.version }}</p>
                <button
                    type="button"
                    class="crm-text-button"
                    @click="
                        router.get(
                            '/partner/contacts',
                            { ...filters, ...(entry ? { entry: entry.id } : {}) },
                            { preserveState: false },
                        )
                    "
                >
                    Reload latest records
                </button>
                <p>Copy unsaved changes before reloading.</p>
            </div>
            <p v-if="!canWrite" class="crm-hint">Viewer access is read only.</p>
            <div class="crm-columns" :aria-busy="busy">
                <div class="crm-col-main">
                    <ContentSection
                        v-if="entry"
                        class="crm-linked-section"
                        :title="`Linked to ${entry.brand_name}`"
                        heading-id="linked-title"
                    >
                        <template #header>
                            <header class="crm-card-head">
                                <h2 id="linked-title">Linked to {{ entry.brand_name }}</h2>
                                <span class="crm-pill crm-pill-success">{{ contactCount(linked.length) }}</span>
                            </header>
                        </template>
                        <p v-if="!linked.length" class="crm-empty">No linked contacts.</p>
                        <ul v-else class="crm-linked">
                            <li v-for="row in linked" :key="row.id" class="crm-person">
                                <div class="crm-person-main">
                                    <span class="crm-avatar crm-avatar-linked" aria-hidden="true">{{
                                        initials(row.name)
                                    }}</span>
                                    <div class="crm-person-copy">
                                        <p class="crm-person-name">
                                            {{ row.name
                                            }}<span
                                                v-if="contactRecord(row.id)?.company"
                                                class="crm-person-company"
                                                v-text="contactRecord(row.id)?.company"
                                            ></span>
                                        </p>
                                        <p v-if="contactRecord(row.id)?.email" class="crm-email">
                                            {{ contactRecord(row.id)?.email }}
                                        </p>
                                    </div>
                                </div>
                                <ActionGroup v-if="canWrite">
                                    <Button
                                        v-if="contactRecord(row.id)"
                                        type="button"
                                        class="wd-button wd-button-secondary"
                                        :ariaLabel="`Edit ${row.name}`"
                                        :disabled="blocked"
                                        @click="editLinked(row.id)"
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        type="button"
                                        class="wd-button wd-button-danger-outline"
                                        :ariaLabel="`Unlink ${row.name}`"
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
                                        Unlink
                                    </Button>
                                </ActionGroup>
                            </li>
                        </ul>
                    </ContentSection>
                    <ContentSection class="crm-section" title="Reusable contacts" heading-id="contacts-title">
                        <template #header>
                            <div class="crm-card-block">
                                <header class="crm-card-head">
                                    <div class="crm-card-title">
                                        <h2 id="contacts-title">Reusable contacts</h2>
                                        <p class="crm-count">{{ contacts.items.length }} on this page</p>
                                    </div>
                                    <Button
                                        v-if="canWrite"
                                        type="button"
                                        class="wd-button wd-button-primary"
                                        :disabled="blocked"
                                        @click="newContact"
                                    >
                                        New contact
                                    </Button>
                                </header>
                                <form class="crm-search-row" aria-label="Filter contacts" @submit.prevent="list()">
                                    <div class="crm-search">
                                        <FormField
                                            id="crm-search"
                                            v-model="q"
                                            label="Search contacts"
                                            :required="false"
                                            :maxlength="160"
                                        />
                                    </div>
                                    <Button type="submit" class="wd-button wd-button-secondary" :disabled="busy"
                                        >Apply filters</Button
                                    >
                                </form>
                            </div>
                        </template>
                        <p v-if="!contacts.items.length" class="crm-empty">
                            No contacts match. Create a contact in the editor.
                        </p>
                        <ul v-else class="crm-list">
                            <li v-for="row in contacts.items" :key="row.id">
                                <div class="crm-person">
                                    <div class="crm-person-main">
                                        <span class="crm-avatar crm-avatar-saved" aria-hidden="true">{{
                                            initials(row.name)
                                        }}</span>
                                        <div class="crm-person-copy">
                                            <p class="crm-person-name">
                                                {{ row.name
                                                }}<span
                                                    v-if="row.company"
                                                    class="crm-person-company"
                                                    v-text="row.company"
                                                ></span>
                                            </p>
                                            <p v-if="row.email" class="crm-email">{{ row.email }}</p>
                                            <p v-if="row.notes" class="crm-note">
                                                <Lock :size="13" aria-hidden="true" />
                                                <span>{{ row.notes }}</span>
                                            </p>
                                        </div>
                                    </div>
                                    <ActionGroup v-if="canWrite">
                                        <Button
                                            type="button"
                                            class="wd-button wd-button-secondary"
                                            :ariaLabel="`Edit ${row.name}`"
                                            :disabled="blocked"
                                            @click="editContact(row)"
                                        >
                                            Edit
                                        </Button>
                                        <button
                                            v-if="entry && !linked.some((item) => item.id === row.id)"
                                            type="button"
                                            class="crm-btn crm-btn-primary"
                                            :aria-label="`Link ${row.name} to ${entry.brand_name}`"
                                            :disabled="blocked"
                                            @click="
                                                act('post', `/pipeline/${entry.id}/contacts`, {
                                                    contact_id: row.id,
                                                    version: entry.version,
                                                })
                                            "
                                        >
                                            <LinkIcon :size="16" aria-hidden="true" />
                                            Link
                                        </button>
                                    </ActionGroup>
                                </div>
                            </li>
                        </ul>
                        <button
                            v-if="contacts.nextCursor"
                            type="button"
                            class="crm-more"
                            :disabled="busy"
                            @click="list(contacts.nextCursor, 'contacts')"
                        >
                            More contacts
                        </button>
                    </ContentSection>
                </div>
                <div class="crm-col-side">
                    <ContentSection class="crm-section" title="Reminders" heading-id="reminders-title">
                        <template #header>
                            <div class="crm-card-block">
                                <header class="crm-card-head">
                                    <div class="crm-card-title">
                                        <h2 id="reminders-title">Reminders</h2>
                                        <p class="crm-count">Workspace private · your local time</p>
                                    </div>
                                    <Button
                                        v-if="canWrite"
                                        type="button"
                                        class="wd-button wd-button-primary"
                                        :disabled="blocked"
                                        @click="newReminder"
                                    >
                                        New reminder
                                    </Button>
                                </header>
                                <div v-if="reminderCounts.overdue || reminderCounts.today" class="crm-summary">
                                    <span v-if="reminderCounts.overdue" class="crm-pill crm-pill-danger"
                                        >{{ reminderCounts.overdue }} overdue on this page</span
                                    >
                                    <span v-if="reminderCounts.today" class="crm-pill crm-pill-warning"
                                        >{{ reminderCounts.today }} due today on this page</span
                                    >
                                </div>
                                <div class="crm-segment crm-segment-filter" role="group" aria-label="Reminder filter">
                                    <button
                                        v-for="option in reminderFilters"
                                        :key="option.value"
                                        type="button"
                                        :aria-pressed="state === option.value"
                                        :disabled="busy"
                                        @click="list(undefined, undefined, option.value)"
                                    >
                                        {{ option.label }}
                                    </button>
                                </div>
                            </div>
                        </template>
                        <p v-if="!reminders.items.length" class="crm-empty">No reminders match.</p>
                        <ul v-else class="crm-list crm-reminders">
                            <li v-for="row in reminders.items" :key="row.id">
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
                                        <Check v-if="row.completed_at" :size="14" />
                                    </span>
                                </button>
                                <span v-else class="crm-complete" aria-hidden="true">
                                    <span class="crm-check" :class="{ 'is-done': row.completed_at }">
                                        <Check v-if="row.completed_at" :size="14" />
                                    </span>
                                </span>
                                <div class="crm-reminder-copy">
                                    <p class="crm-reminder-title">{{ row.title }}</p>
                                    <p class="crm-reminder-meta">
                                        <span class="crm-when">
                                            <Calendar :size="14" aria-hidden="true" />
                                            <time :datetime="row.due_at">{{ dueLabel(row.due_at) }}</time>
                                        </span>
                                        <span v-if="reminderTone(row) === 'overdue'" class="crm-badge crm-badge-danger"
                                            >Overdue</span
                                        >
                                        <span
                                            v-else-if="reminderTone(row) === 'today'"
                                            class="crm-badge crm-badge-warning"
                                            >Due today</span
                                        >
                                        <span
                                            v-else-if="reminderTone(row) === 'completed'"
                                            class="crm-badge crm-badge-success"
                                            >Completed</span
                                        >
                                    </p>
                                    <div v-if="row.pipeline_entry_id || canWrite" class="crm-reminder-actions">
                                        <Link
                                            v-if="row.pipeline_entry_id"
                                            class="crm-inline-link"
                                            :href="`/partner/leads?entry=${row.pipeline_entry_id}`"
                                            >Open related lead</Link
                                        >
                                        <button
                                            v-if="canWrite"
                                            type="button"
                                            class="crm-text-button"
                                            :aria-label="`Edit ${row.title}`"
                                            :disabled="blocked"
                                            @click="editReminder(row)"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                        <button
                            v-if="reminders.nextCursor"
                            type="button"
                            class="crm-more"
                            :disabled="busy"
                            @click="list(reminders.nextCursor, 'reminders')"
                        >
                            More reminders
                        </button>
                    </ContentSection>
                </div>
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
                <div class="crm-segment" role="group" aria-label="What to add">
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
                        <Button type="submit" class="wd-button wd-button-primary crm-save">Save reminder</Button>
                        <template v-if="reminder.id">
                            <FormField
                                id="reminder-snooze"
                                v-model="snooze"
                                label="Snooze until (your local time)"
                                type="datetime-local"
                                :required="false"
                            />
                            <Button
                                type="button"
                                class="wd-button wd-button-secondary crm-save"
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
                            <Button
                                type="button"
                                class="wd-button wd-button-danger-outline crm-save"
                                @click="
                                    confirmAction(
                                        'Delete reminder',
                                        `Permanently delete reminder ${reminder.title}?`,
                                        () =>
                                            act('delete', `/reminders/${reminder.id}`, {
                                                version: reminder.version,
                                            }),
                                        $event,
                                        { tone: 'danger' },
                                    )
                                "
                            >
                                Delete reminder
                            </Button>
                        </template>
                    </fieldset>
                </form>
                <form v-show="addTab === 'contact'" :aria-busy="busy" @submit.prevent="saveContact">
                    <fieldset class="crm-fields" :disabled="blocked">
                        <legend class="wd-sr-only">{{ contact.id ? 'Edit contact' : 'New contact' }}</legend>
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
                        <Button type="submit" class="wd-button wd-button-primary crm-save">Save contact</Button>
                        <Button
                            v-if="contact.id"
                            type="button"
                            class="wd-button wd-button-danger-outline crm-save"
                            @click="
                                confirmAction(
                                    'Delete contact',
                                    `Permanently delete ${contact.name} and its private contact details?`,
                                    () => act('delete', `/contacts/${contact.id}`, { version: contact.version }),
                                    $event,
                                    { tone: 'danger' },
                                )
                            "
                        >
                            Delete contact
                        </Button>
                    </fieldset>
                </form>
            </dialog>
        </div>
    </FoundationShell>
</template>
<style scoped>
.wd-crm {
    width: 100%;
    max-width: 1200px;
    margin-inline: auto;
    display: flex;
    flex-direction: column;
    gap: 28px;
    padding-bottom: 16px;
    min-width: 0;
    color: var(--wd-ink);
    font-size: 15px;
    line-height: 1.45;
}
.wd-crm :focus-visible {
    outline: 2px solid var(--wd-brand);
    outline-offset: 2px;
}
.wd-crm > .wd-banner {
    margin-bottom: 0;
}
.crm-intro {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
}
.crm-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
    min-height: 44px;
    color: var(--wd-brand);
    font-size: 14px;
    font-weight: 500;
    line-height: 1.3;
    text-decoration: none;
}
.crm-back:hover {
    color: var(--wd-brand-hover);
}
.crm-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px 32px;
    min-width: 0;
}
.crm-heading-copy {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.crm-eyebrow {
    margin: 0;
    color: var(--wd-brand);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.08em;
    line-height: 1.3;
    text-transform: uppercase;
    overflow-wrap: anywhere;
}
.wd-crm h1 {
    margin: 0;
    color: var(--wd-ink);
    font-family: var(--wd-font-display);
    font-size: 44px;
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.05;
    overflow-wrap: anywhere;
}
.crm-context {
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: 100%;
    min-width: 0;
    padding: 8px 16px 8px 8px;
    border: 1px solid var(--wd-line);
    border-radius: 999px;
    background: var(--wd-surface);
}
.crm-context-copy {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.2;
}
.crm-context-label {
    color: var(--wd-muted);
    font-size: 12px;
}
.crm-context-name {
    font-weight: 600;
    overflow-wrap: anywhere;
}
.crm-hint {
    margin: 0;
    color: var(--wd-muted);
    font-size: 13px;
    line-height: 1.45;
}
.crm-columns {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 28px;
    min-width: 0;
}
.crm-col-main,
.crm-col-side {
    display: flex;
    flex-direction: column;
    gap: 20px;
    min-width: 0;
}
.crm-col-main {
    flex: 1.45 1 560px;
}
.crm-col-side {
    flex: 1 1 400px;
}
.crm-section {
    padding: 0;
    gap: 0;
}
.crm-linked-section {
    gap: 14px;
}
.crm-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 12px;
    min-width: 0;
}
.crm-card-title {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.crm-editor[open] {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.crm-editor .wd-dialog-header {
    margin-bottom: 0;
}
.crm-card-block {
    container-type: inline-size;
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 20px 24px 16px;
}
.crm-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 12px;
    min-width: 0;
}
.wd-crm h2 {
    margin: 0;
    color: var(--wd-ink);
    font-family: var(--wd-font-display);
    font-size: 20px;
    font-weight: 600;
    line-height: 1.25;
    overflow-wrap: anywhere;
}
.crm-count {
    margin: 0;
    color: var(--wd-muted);
    font-size: 14px;
}
.crm-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.crm-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.3;
    white-space: nowrap;
}
.crm-pill-success {
    background: var(--wd-success-bg);
    color: var(--wd-success);
}
.crm-pill-danger,
.crm-badge-danger {
    background: var(--wd-danger-bg);
    color: var(--wd-danger);
}
.crm-pill-warning,
.crm-badge-warning {
    background: var(--wd-warning-bg);
    color: var(--wd-warning);
}
.crm-badge-success {
    background: var(--wd-success-bg);
    color: var(--wd-success);
}
.crm-badge {
    border-radius: 6px;
    padding: 1px 8px;
    font-weight: 600;
}
.crm-segment {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 4px;
    border-radius: 12px;
    background: var(--wd-divider);
}
.crm-segment button {
    flex: 1 1 72px;
    min-width: 44px;
    min-height: 44px;
    padding: 8px 10px;
    border: 0;
    border-radius: 9px;
    background: transparent;
    color: var(--wd-muted);
    font-size: 14px;
    font-weight: 500;
    line-height: 1.2;
}
.crm-segment-filter {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}
.crm-segment-filter button {
    flex: none;
    width: 100%;
    min-width: 0;
    padding-inline: 6px;
}
@container (max-width: 360px) {
    .crm-segment-filter {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
.crm-segment button[aria-pressed='true'] {
    background: var(--wd-surface);
    box-shadow: 0 1px 2px rgb(34 26 38 / 12%);
    color: var(--wd-brand-hover);
    font-weight: 600;
}
.crm-search-row {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 10px;
    min-width: 0;
}
.crm-search {
    position: relative;
    flex: 1 1 220px;
    min-width: 0;
}
.crm-list,
.crm-linked {
    margin: 0;
    padding: 0;
    list-style: none;
}
.crm-list {
    border-top: 1px solid var(--wd-divider);
}
.crm-list > li {
    border-bottom: 1px solid var(--wd-divider);
}
.crm-list > li:last-child {
    border-bottom: 0;
}
.crm-list .crm-person {
    padding: 18px 24px;
}
.crm-person {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px 20px;
    min-width: 0;
}
.crm-person-main {
    display: flex;
    flex: 1 1 240px;
    gap: 14px;
    align-items: flex-start;
    min-width: 0;
}
.crm-person-copy,
.crm-reminder-copy {
    min-width: 0;
}
.crm-person-name,
.crm-reminder-title,
.crm-email,
.crm-note {
    margin: 0;
    overflow-wrap: anywhere;
}
.crm-person-name {
    font-size: 16px;
    font-weight: 600;
    line-height: 1.35;
}
.crm-person-company::before {
    content: ' · ';
}
.crm-person-company,
.crm-email,
.crm-when {
    color: var(--wd-muted);
    font-weight: 400;
}
.crm-person-company,
.crm-email {
    font-size: 14px;
}
.crm-note {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    max-width: 100%;
    margin-top: 6px;
    padding: 4px 10px;
    border-radius: 8px;
    background: var(--wd-divider);
    color: var(--wd-body);
    font-size: 13px;
    line-height: 1.4;
}
.crm-note svg {
    flex: none;
    margin-top: 2px;
}
.crm-avatar {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-weight: 600;
    line-height: 1;
}
.crm-avatar-linked {
    width: 48px;
    height: 48px;
    background: var(--wd-brand);
    color: white;
    font-size: 16px;
}
.crm-avatar-saved {
    width: 44px;
    height: 44px;
    background: var(--color-blush);
    color: var(--wd-brand-hover);
    font-size: 14px;
}
.crm-avatar-context {
    width: 32px;
    height: 32px;
    background: var(--wd-brand-hover);
    color: white;
    font-size: 13px;
}
.crm-save {
    width: 100%;
    min-height: 48px;
    font-size: 15px;
    font-weight: 600;
}
.crm-empty {
    margin: 0;
    padding: 32px 24px;
    color: var(--wd-muted);
    font-size: 15px;
    text-align: center;
}
.crm-linked-section .crm-empty {
    padding: 8px 0 0;
}
.crm-more,
.crm-inline-link,
.crm-text-button {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    border: 0;
    background: none;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.3;
}
.crm-more,
.crm-inline-link {
    color: var(--wd-brand);
    text-decoration: none;
}
.crm-more {
    margin: 0 24px 8px;
    padding: 0;
}
.crm-more:hover,
.crm-inline-link:hover,
.crm-back:hover {
    color: var(--wd-brand-hover);
}
.crm-text-button {
    padding: 0 2px;
    color: var(--wd-body);
    text-decoration: underline;
    text-underline-offset: 3px;
}
.crm-text-button:hover:not(:disabled) {
    color: var(--wd-ink);
}
.crm-reminders > li {
    display: flex;
    gap: 4px;
    align-items: flex-start;
    padding: 14px 24px 14px 12px;
}
.crm-complete {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    padding: 0;
    border: 0;
    border-radius: 10px;
    background: transparent;
}
.crm-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    box-sizing: border-box;
    border: 2px solid var(--wd-control-line);
    border-radius: 50%;
    background: var(--wd-surface);
    color: white;
}
.crm-check.is-done {
    border-color: var(--wd-success);
    background: var(--wd-success);
}
.crm-reminder-copy {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding-top: 2px;
}
.crm-reminder-title {
    font-size: 15px;
    font-weight: 600;
    line-height: 1.35;
}
.crm-reminder-meta,
.crm-when,
.crm-reminder-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
}
.crm-reminder-meta {
    gap: 6px 10px;
    color: var(--wd-muted);
    font-size: 13px;
}
.crm-when {
    gap: 5px;
}
.crm-reminder-actions {
    gap: 8px 16px;
}
.crm-fields {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.crm-fields legend {
    padding: 0;
    color: var(--wd-ink);
    font-size: 13px;
    font-weight: 600;
}
.wd-crm :deep(.wd-hint) {
    color: var(--wd-muted);
    font-size: 13px;
    line-height: 1.45;
}
.wd-crm :deep(.wd-field-error) {
    color: var(--wd-danger);
}
@media (max-width: 959px) {
    .crm-col-main,
    .crm-col-side {
        flex-basis: 100%;
    }
}
@media (max-width: 480px) {
    .wd-crm {
        padding: 8px 8px 24px;
    }
    .wd-crm h1 {
        font-size: 44px;
    }
}
</style>
