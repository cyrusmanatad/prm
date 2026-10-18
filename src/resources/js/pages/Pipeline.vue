<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '../components/ui/button';
import FormField from '../components/FormField.vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import EntryFields from '../features/pipeline/EntryFields.vue';
import { fromMinor, money, toMinor } from '../features/pipeline/money';
import type { Entry, EntryDetail, LeadForm } from '../features/pipeline/types';
import { useValidationFocus } from '../composables/useValidationFocus';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    entries: Entry[];
    stages: { value: string; label: string }[];
    counts: { stage: string; count: number }[];
    totals: { currency: string; amount_minor: string; count: number }[];
    nextCursor: string | null;
    selected: EntryDetail | null;
    canWrite: boolean;
    filters: { q: string; stage: string; due: boolean };
    errors?: Record<string, string>;
    status?: string;
}>();
const root = ref<HTMLElement>();
const busy = ref(false);
const formAction = ref('');
const loadedVersion = ref(0);
const formErrors = ref<Record<string, string>>({});
const errors = computed(() => ({ ...(props.errors ?? {}), ...formErrors.value }));
useValidationFocus(root, () => errors.value, () => !busy.value);
const blank = (): LeadForm => ({ brand_name: '', contact_name: '', contact_email: '', notes: '', currency: 'PHP', amount: '0', next_action_due: '' });
const createForm = ref(blank());
const editForm = ref(blank());
const stage = ref('lead');
const snooze = ref('');
const activityKind = ref('note');
const activityBody = ref('');
const confirmDelete = ref(false);
const query = ref(props.filters.q);
const stageFilter = ref(props.filters.stage);
const dueOnly = ref(props.filters.due);
const stamp = (value: string | null): string => value ? new Date(value).toLocaleString() : 'No next action';
const localInput = (value: string | null): string => {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
};
watch([() => props.workspace.id, () => props.selected?.id], () => {
    const row = props.selected;
    editForm.value = row ? { brand_name: row.brand_name, contact_name: row.contact_name ?? '', contact_email: row.contact_email ?? '',
        notes: row.notes, amount: fromMinor(row.value_minor, row.currency), currency: row.currency, next_action_due: localInput(row.next_action_due) } : blank();
    loadedVersion.value = row?.version ?? 0;
    stage.value = row?.stage ?? 'lead';
    activityBody.value = ''; snooze.value = ''; confirmDelete.value = false; formErrors.value = {};
}, { immediate: true });
watch(() => props.workspace.id, () => { createForm.value = blank(); });
// Keep unsaved edits after stale-version errors. A successful save refreshes the form.
function refreshEdit(): void {
    const row = props.selected;
    if (row) editForm.value = { brand_name: row.brand_name, contact_name: row.contact_name ?? '', contact_email: row.contact_email ?? '', notes: row.notes,
        amount: fromMinor(row.value_minor, row.currency), currency: row.currency, next_action_due: localInput(row.next_action_due) };
}
function visit(method: 'post' | 'patch' | 'delete', path: string, data: Record<string, string | number | null>, done?: () => void): void {
    if (busy.value || errors.value.version) return;
    formErrors.value = {};
    router.visit(path, { method, data, preserveScroll: true, preserveState: true,
        onStart: () => { busy.value = true; }, onFinish: () => { busy.value = false; }, onSuccess: () => { loadedVersion.value = props.selected?.version ?? 0; refreshEdit(); done?.(); },
    });
}
function details(form: LeadForm): Record<string, string | null> | null {
    const amount = toMinor(form.amount, form.currency);
    if (amount === null) { formErrors.value = { value_minor: 'Enter a valid value for this currency and a three-letter uppercase currency code.' }; return null; }
    return { brand_name: form.brand_name, contact_name: form.contact_name, contact_email: form.contact_email, notes: form.notes,
        value_minor: amount, currency: form.currency, next_action_due: form.next_action_due ? new Date(form.next_action_due).toISOString() : null };
}
function create(): void {
    formAction.value = 'create';
    const data = details(createForm.value);
    if (data) visit('post', '/pipeline', data, () => { createForm.value = blank(); });
}
function update(): void {
    formAction.value = 'update';
    const data = details(editForm.value);
    if (data && props.selected) visit('patch', `/pipeline/${props.selected.id}`, { ...data, version: loadedVersion.value });
}
function command(action: string, data: Record<string, string | null>, done?: () => void): void {
    formAction.value = action;
    if (props.selected) visit('post', `/pipeline/${props.selected.id}/${action}`, { ...data, version: loadedVersion.value }, done);
}
function filter(cursor?: string): void {
    const params: Record<string, string> = {};
    if (query.value) params.q = query.value;
    if (stageFilter.value) params.stage = stageFilter.value;
    if (dueOnly.value) params.due = '1';
    if (cursor) params.cursor = cursor;
    router.get('/pipeline', params, { preserveState: false });
}
function reloadSelected(): void {
    if (props.selected) router.get('/pipeline', { entry: props.selected.id }, { preserveState: false });
}
</script>

<template>
    <Head title="Creator pipeline" />
    <FoundationShell :account-name="account.name" @logout="router.post('/logout')">
        <div ref="root" class="wd-workspace-content wd-pipeline">
            <nav aria-label="Workspace"><Link class="wd-link" href="/workspaces">Switch workspace</Link></nav>
            <div class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspace.name }}</span>
                <h1>Your next collaboration starts here.</h1>
                <p class="wd-description">Keep brand conversations, private notes, and next actions together.</p>
            </div>
            <p v-if="status" role="status" class="wd-status">{{ status }}</p>
            <div v-if="errors.version" class="wd-status" role="alert">
                <p>{{ errors.version }}</p>
                <button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button>
                <p class="wd-hint">Copy any unsaved changes before reloading.</p>
            </div>
            <ul v-if="Object.keys(errors).some(key => key !== 'version')" class="wd-field-error" role="alert">
                <li v-for="(message, field) in errors" :key="field">{{ message }}</li>
            </ul>
            <section aria-label="Pipeline totals" class="wd-pipeline-totals">
                <p v-if="!totals.length" class="wd-hint">No estimated value yet.</p>
                <p v-for="total in totals" :key="total.currency"><strong>{{ money(total.amount_minor, total.currency) }}</strong><br><span class="wd-hint">{{ total.count }} {{ total.count === 1 ? 'lead' : 'leads' }} · excludes Lost · current filters</span></p>
            </section>
            <form class="wd-card wd-pipeline-filters" aria-label="Filter leads" @submit.prevent="filter()">
                <FormField id="pipeline-search" v-model="query" :required="false" label="Search brands" :maxlength="160" />
                <div class="wd-field"><label for="stage-filter">Stage filter</label><select id="stage-filter" v-model="stageFilter"><option value="">All stages</option><option v-for="option in stages" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
                <label class="wd-checkbox"><input v-model="dueOnly" type="checkbox">Due next actions only</label>
                <Button class="wd-button wd-button-secondary" type="submit">Apply filters</Button>
                <Link class="wd-link" href="/pipeline">Clear filters</Link>
            </form>
            <p v-if="!canWrite" class="wd-hint">Your viewer role provides read-only access to this pipeline.</p>
            <details v-if="canWrite && !selected" class="wd-card wd-pipeline-create">
                <summary>Capture a lead</summary>
                <form :aria-busy="busy" @submit.prevent="create">
                    <fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend class="wd-sr-only">New lead</legend>
                        <EntryFields v-model="createForm" prefix="create" :errors="formAction === 'create' ? errors : {}" />
                        <Button type="submit" class="wd-button wd-button-primary">{{ busy ? 'Saving…' : 'Capture lead' }}</Button>
                    </fieldset>
                </form>
            </details>
            <p v-if="!entries.length" class="wd-empty" role="status">{{ filters.q || filters.stage || filters.due ? 'No leads match these filters.' : 'Your pipeline is empty. Capture your first lead to get started.' }}</p>
            <div class="wd-pipeline-board" aria-label="Seven-stage pipeline">
                <section v-for="option in stages" :key="option.value" class="wd-pipeline-stage" :aria-labelledby="`stage-${option.value}`">
                    <h2 :id="`stage-${option.value}`">{{ option.label }} <span class="wd-hint">{{ counts.find(count => count.stage === option.value)?.count ?? 0 }}</span></h2>
                    <ul class="wd-pipeline-cards">
                        <li v-for="entry in entries.filter(row => row.stage === option.value)" :key="entry.id" class="wd-card">
                            <Link class="wd-link" :href="`/pipeline?entry=${entry.id}`">{{ entry.brand_name }}</Link>
                            <p>{{ money(entry.value_minor, entry.currency) }}</p>
                            <p class="wd-hint">{{ entry.contact_name || 'No contact yet' }}</p>
                            <p class="wd-hint">{{ stamp(entry.next_action_due) }}</p>
                        </li>
                    </ul>
                </section>
            </div>
            <p v-if="nextCursor" class="wd-hint">Showing 25 leads. Stage counts and totals include every matching lead.</p>
            <button v-if="nextCursor" class="wd-link" type="button" @click="filter(nextCursor)">Next 25 leads</button>
            <section v-if="selected" class="wd-card wd-pipeline-detail" aria-labelledby="lead-detail-title">
                <div class="wd-page-heading"><h2 id="lead-detail-title">{{ selected.brand_name }}</h2><Link class="wd-link" href="/pipeline">Close lead</Link></div>
                <template v-if="canWrite">
                    <form :aria-busy="busy" @submit.prevent="update"><fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend>Lead details</legend>
                        <EntryFields v-model="editForm" prefix="edit" :errors="formAction === 'update' ? errors : {}" />
                        <Button class="wd-button wd-button-primary" type="submit">{{ busy ? 'Saving…' : 'Save lead' }}</Button>
                    </fieldset></form>
                    <div class="wd-pipeline-actions">
                        <form :aria-busy="busy" @submit.prevent="command('stage', { stage })"><fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend>Move stage</legend>
                            <div class="wd-field"><label for="lead-stage">Stage</label><select id="lead-stage" v-model="stage"><option v-for="option in stages" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
                            <Button class="wd-button wd-button-secondary" type="submit">Move lead</Button>
                        </fieldset></form>
                        <form :aria-busy="busy" @submit.prevent="command('snooze', { next_action_due: snooze ? new Date(snooze).toISOString() : '' })"><fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend>Snooze next action</legend>
                            <FormField id="snooze-due" v-model="snooze" label="Snooze until (your local time)" type="datetime-local" :error="formAction === 'snooze' ? errors.next_action_due : undefined" />
                            <Button class="wd-button wd-button-secondary" type="submit">Snooze next action</Button>
                        </fieldset></form>
                    </div>
                    <form :aria-busy="busy" @submit.prevent="command('activities', { kind: activityKind, body: activityBody }, () => { activityBody = ''; })"><fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend>Log an activity</legend>
                        <div class="wd-field"><label for="activity-kind">Activity type</label><select id="activity-kind" v-model="activityKind"><option value="note">Note</option><option value="email">Email</option><option value="chat">Chat</option><option value="call">Call</option></select></div>
                        <div class="wd-field"><label for="activity-body">Activity details</label><textarea id="activity-body" v-model="activityBody" maxlength="5000" rows="3" /></div>
                        <p class="wd-hint">Records a past conversation or private note. No message is sent.</p>
                        <Button class="wd-button wd-button-secondary" type="submit">Record activity</Button>
                    </fieldset></form>
                    <details><summary>Delete lead</summary><p>Deletes this lead, its private notes, and its activity history.</p>
                        <label class="wd-checkbox"><input v-model="confirmDelete" type="checkbox">I want to delete this lead</label>
                        <Button type="button" class="wd-button wd-button-secondary" :disabled="busy || !!errors.version || !confirmDelete" @click="visit('delete', `/pipeline/${selected.id}`, { version: loadedVersion })">Delete permanently</Button>
                    </details>
                </template>
                <div v-else class="wd-fields"><p>{{ selected.contact_name }} {{ selected.contact_email }}</p><p>{{ money(selected.value_minor, selected.currency) }}</p><p class="wd-pipeline-note">{{ selected.notes || 'No private notes yet.' }}</p><p>{{ stamp(selected.next_action_due) }}</p></div>
                <h3>Activity history</h3>
                <p class="wd-hint">Most recent 50 events.</p>
                <ol class="wd-pipeline-history"><li v-for="item in selected.activities" :key="item.id"><strong>{{ item.kind }}</strong> · <time :datetime="item.occurred_at">{{ stamp(item.occurred_at) }}</time><p>{{ item.body }}</p></li></ol>
            </section>
        </div>
    </FoundationShell>
</template>
