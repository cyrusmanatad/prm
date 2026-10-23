<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import { Button } from '../components/ui/button';
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
    account: { name: string };
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
const state = ref(props.filters.state);
const confirm = ref('');
const snooze = ref('');
function act(method: 'post' | 'patch' | 'delete', path: string, data: Record<string, string | number | null>): void {
    if (busy.value || errors.value.version) return;
    router.visit(path, {
        method,
        data: { ...data, entry: props.entry?.id ?? null },
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
                contact.value = { id: '', name: '', email: '', company: '', notes: '', version: 0 };
            }
            if (/^\/reminders(?:\/[^/]+)?$/.test(path)) {
                reminder.value = {
                    id: '',
                    title: '',
                    due_at: '',
                    pipeline_entry_id: props.entry?.id ?? '',
                    version: 0,
                };
            } else if (
                reminder.value.id &&
                path.startsWith(`/reminders/${reminder.value.id}/`) &&
                reminder.value.version === data.version
            ) {
                reminder.value.version += 1;
                if (path.endsWith('/snooze') && typeof data.due_at === 'string')
                    reminder.value.due_at = localDate(data.due_at);
            }
            confirm.value = '';
        },
    });
}
function list(cursor?: string, kind?: string): void {
    router.get(
        '/partner/contacts',
        {
            q: q.value,
            state: state.value,
            ...(props.entry ? { entry: props.entry.id } : {}),
            ...(cursor && kind ? { [`${kind}_cursor`]: cursor } : {}),
        },
        { preserveState: false },
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
</script>
<template>
    <Head title="Contacts and reminders" />
    <FoundationShell :account-name="account.name" @logout="router.post('/logout')"
        ><div ref="root" class="wd-workspace-content wd-crm">
            <nav aria-label="Workspace">
                <Link class="wd-link" :href="entry ? `/partner/leads?entry=${entry.id}` : '/partner/leads'"
                    >Back to leads</Link
                >
            </nav>
            <div class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspace.name }}</span>
                <h1>Contacts and reminders</h1>
                <p v-if="entry" class="wd-description">Working with {{ entry.brand_name }}</p>
            </div>
            <p v-if="status" role="status" class="wd-status">{{ status }}</p>
            <div v-if="Object.keys(errors).length" role="alert" class="wd-status">
                <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
                <button
                    v-if="errors.version"
                    type="button"
                    class="wd-link"
                    @click="router.get('/partner/contacts', entry ? { entry: entry.id } : {}, { preserveState: false })"
                >
                    Reload latest records
                </button>
                <p v-if="errors.version">Copy unsaved changes before reloading.</p>
            </div>
            <p v-if="!canWrite" class="wd-hint">Viewer access is read only.</p>
            <section v-if="entry" class="wd-card" aria-labelledby="linked-title">
                <h2 id="linked-title">Contacts for {{ entry.brand_name }}</h2>
                <p v-if="!linked.length">No linked contacts.</p>
                <ul>
                    <li v-for="row in linked" :key="row.id">
                        {{ row.name }}
                        <button
                            v-if="canWrite"
                            type="button"
                            class="wd-link"
                            :disabled="busy || !!errors.version"
                            @click="
                                act('delete', `/pipeline/${entry.id}/contacts`, {
                                    contact_id: row.id,
                                    version: entry.version,
                                })
                            "
                        >
                            Unlink {{ row.name }}
                        </button>
                    </li>
                </ul>
            </section>
            <form class="wd-card wd-fields" aria-label="Filter contacts and reminders" @submit.prevent="list()">
                <FormField id="crm-search" v-model="q" label="Search contacts" :required="false" :maxlength="160" />
                <div class="wd-field">
                    <label for="reminder-state">Reminder filter</label
                    ><select id="reminder-state" v-model="state">
                        <option value="pending">Pending</option>
                        <option value="due">Due</option>
                        <option value="completed">Completed</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <Button type="submit" class="wd-button wd-button-secondary">Apply filters</Button>
            </form>
            <section class="wd-card" aria-labelledby="contacts-title">
                <h2 id="contacts-title">Reusable contacts</h2>
                <p v-if="!contacts.items.length">No contacts match.</p>
                <ul class="wd-fields">
                    <li v-for="row in contacts.items" :key="row.id">
                        <strong>{{ row.name }}</strong> · {{ row.company }} <span>{{ row.email }}</span>
                        <p class="wd-pipeline-note">{{ row.notes }}</p>
                        <div v-if="canWrite">
                            <button
                                type="button"
                                class="wd-link"
                                :disabled="busy || !!errors.version"
                                @click="contact = { ...row, email: row.email ?? '' }"
                            >
                                Edit {{ row.name }}
                            </button>
                            <button
                                v-if="entry && !linked.some((item) => item.id === row.id)"
                                type="button"
                                class="wd-link"
                                :disabled="busy || !!errors.version"
                                @click="
                                    act('post', `/pipeline/${entry.id}/contacts`, {
                                        contact_id: row.id,
                                        version: entry.version,
                                    })
                                "
                            >
                                Link {{ row.name }}
                            </button>
                        </div>
                    </li>
                </ul>
                <button
                    v-if="contacts.nextCursor"
                    type="button"
                    class="wd-link"
                    @click="list(contacts.nextCursor, 'contacts')"
                >
                    More contacts
                </button>
            </section>
            <form v-if="canWrite" class="wd-card" :aria-busy="busy" @submit.prevent="saveContact">
                <fieldset class="wd-fields" :disabled="busy || !!errors.version">
                    <legend>{{ contact.id ? 'Edit contact' : 'New contact' }}</legend>
                    <FormField
                        id="contact-name"
                        v-model="contact.name"
                        label="Contact name"
                        :maxlength="120"
                        :error="errors.name"
                    /><FormField
                        id="contact-email"
                        v-model="contact.email"
                        label="Contact email"
                        type="email"
                        :required="false"
                        :maxlength="255"
                        :error="errors.email"
                    /><FormField
                        id="contact-company"
                        v-model="contact.company"
                        label="Company"
                        :required="false"
                        :maxlength="160"
                    />
                    <div class="wd-field">
                        <label for="contact-notes">Private contact notes</label
                        ><textarea id="contact-notes" v-model="contact.notes" rows="3" maxlength="5000" />
                    </div>
                    <Button type="submit" class="wd-button wd-button-primary">Save contact</Button
                    ><template v-if="contact.id"
                        ><label class="wd-checkbox"
                            ><input
                                type="checkbox"
                                :checked="confirm === contact.id"
                                @change="confirm = confirm === contact.id ? '' : contact.id"
                            />Delete selected contact permanently</label
                        ><Button
                            type="button"
                            class="wd-button wd-button-secondary"
                            :disabled="confirm !== contact.id"
                            @click="act('delete', `/contacts/${contact.id}`, { version: contact.version })"
                            >Delete contact</Button
                        ></template
                    >
                </fieldset>
            </form>
            <section class="wd-card" aria-labelledby="reminders-title">
                <h2 id="reminders-title">Reminders</h2>
                <p class="wd-hint">Private tasks. Dates use your local time.</p>
                <p v-if="!reminders.items.length">No reminders match.</p>
                <ul class="wd-fields">
                    <li v-for="row in reminders.items" :key="row.id">
                        <strong>{{ row.title }}</strong> ·
                        <time :datetime="row.due_at">{{ new Date(row.due_at).toLocaleString() }}</time> ·
                        {{ row.completed_at ? 'Completed' : 'Pending' }}
                        <Link
                            v-if="row.pipeline_entry_id"
                            class="wd-link"
                            :href="`/partner/leads?entry=${row.pipeline_entry_id}`"
                            >Open related lead</Link
                        >
                        <div v-if="canWrite">
                            <button
                                type="button"
                                class="wd-link"
                                :disabled="busy || !!errors.version"
                                @click="
                                    reminder = {
                                        ...row,
                                        due_at: localDate(row.due_at),
                                        pipeline_entry_id: row.pipeline_entry_id ?? '',
                                    }
                                "
                            >
                                Edit {{ row.title }}
                            </button>
                            <button
                                type="button"
                                class="wd-link"
                                :disabled="busy || !!errors.version"
                                @click="
                                    act('post', `/reminders/${row.id}/${row.completed_at ? 'reopen' : 'complete'}`, {
                                        version: row.version,
                                    })
                                "
                            >
                                {{ row.completed_at ? 'Reopen' : 'Complete' }} {{ row.title }}
                            </button>
                        </div>
                    </li>
                </ul>
                <button
                    v-if="reminders.nextCursor"
                    type="button"
                    class="wd-link"
                    @click="list(reminders.nextCursor, 'reminders')"
                >
                    More reminders
                </button>
            </section>
            <form v-if="canWrite" class="wd-card" :aria-busy="busy" @submit.prevent="saveReminder">
                <fieldset class="wd-fields" :disabled="busy || !!errors.version">
                    <legend>{{ reminder.id ? 'Edit reminder' : 'New reminder' }}</legend>
                    <FormField
                        id="reminder-title"
                        v-model="reminder.title"
                        label="Reminder title"
                        :maxlength="240"
                        :error="errors.title"
                    /><FormField
                        id="reminder-due"
                        v-model="reminder.due_at"
                        label="Reminder due (your local time)"
                        type="datetime-local"
                        :error="errors.due_at"
                    />
                    <p v-if="entry" class="wd-hint">New reminders here relate to {{ entry.brand_name }}.</p>
                    <Button type="submit" class="wd-button wd-button-primary">Save reminder</Button
                    ><template v-if="reminder.id"
                        ><FormField
                            id="reminder-snooze"
                            v-model="snooze"
                            label="Snooze until (your local time)"
                            type="datetime-local"
                            :required="false"
                        /><Button
                            type="button"
                            class="wd-button wd-button-secondary"
                            :disabled="!snooze"
                            @click="
                                act('post', `/reminders/${reminder.id}/snooze`, {
                                    version: reminder.version,
                                    due_at: iso(snooze),
                                })
                            "
                            >Snooze reminder</Button
                        ><label class="wd-checkbox"
                            ><input
                                type="checkbox"
                                :checked="confirm === reminder.id"
                                @change="confirm = confirm === reminder.id ? '' : reminder.id"
                            />Delete selected reminder permanently</label
                        ><Button
                            type="button"
                            class="wd-button wd-button-secondary"
                            :disabled="confirm !== reminder.id"
                            @click="act('delete', `/reminders/${reminder.id}`, { version: reminder.version })"
                            >Delete reminder</Button
                        ></template
                    >
                </fieldset>
            </form>
        </div></FoundationShell
    >
</template>
<style scoped>
.wd-crm > .wd-card {
    margin-bottom: 1rem;
}
li {
    overflow-wrap: anywhere;
}
</style>
