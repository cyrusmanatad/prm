<script setup lang="ts">
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import { confirmAction } from '../composables/useConfirmation';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronRight, Copy, CopyPlus, Mail, Pencil, Plus, Search, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import EmptyState from '../components/EmptyState.vue';
import { Button } from '../components/ui/button';
import PlaceholderTextarea from '../features/outreach/PlaceholderTextarea.vue';
import { mergeSegments, mergeText, type MergeValues } from '../features/outreach/merge';
import { useNotify } from '../composables/useNotify';
import { useValidationFocus } from '../composables/useValidationFocus';

type Template = { id: string; name: string; subject: string; body: string; version: number; updated_at?: string };
type Lead = { id: string; brand_name: string; contact_name: string | null; contact_email: string | null };
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    templates: Template[];
    leads?: Lead[];
    canWrite: boolean;
    errors?: Record<string, string>;
    status?: string;
}>();
const { professional, path, clientLabel } = useWorkspaceExperience();
const root = ref<HTMLElement>();
const busy = ref(false);
const errors = computed(() => props.errors ?? {});
useValidationFocus(
    root,
    () => errors.value,
    () => !busy.value,
);
const blocked = computed(() => busy.value || !!errors.value.version);
const notify = useNotify();

// Template list.
const query = ref('');
const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return props.templates;
    return props.templates.filter((row) => `${row.name} ${row.subject}`.toLowerCase().includes(needle));
});
function updatedLabel(value?: string): string {
    if (!value) return '';
    const date = new Date(value);
    const days = Math.floor((Date.now() - date.getTime()) / 86_400_000);
    if (days < 1) return 'Updated today';
    if (days === 1) return 'Updated yesterday';
    if (days < 7) return `Updated ${days} days ago`;
    return `Updated ${date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }) })}`;
}

// Editor. Viewers may change the fields to prepare a local draft but cannot save.
const selected = ref<string | null>(null);
const version = ref(0);
const name = ref('');
const subject = ref('');
const body = ref('');
const baseline = ref('');
const snapshot = () => JSON.stringify([name.value, subject.value, body.value]);
const dirty = computed(() => snapshot() !== baseline.value);
const current = computed(() => props.templates.find((row) => row.id === selected.value));
function choose(row?: Template): void {
    selected.value = row?.id ?? null;
    version.value = row?.version ?? 0;
    name.value = row?.name ?? '';
    subject.value = row?.subject ?? '';
    body.value = row?.body ?? '';
    baseline.value = snapshot();
    editingDraft.value = false;
}
function startNew(): void {
    choose();
    panel.value = 'edit';
    void nextTick(() => document.getElementById('template-name')?.focus());
}
function open(row: Template): void {
    choose(row);
    panel.value = 'edit';
}
function duplicate(): void {
    const source = { name: name.value, subject: subject.value, body: body.value };
    choose();
    name.value = `Copy of ${source.name}`.slice(0, 160);
    subject.value = source.subject;
    body.value = source.body;
    void nextTick(() => document.getElementById('template-name')?.focus());
}
function discard(): void {
    choose(current.value);
}
watch(
    () => props.workspace.id,
    () => {
        choose();
        values.value = { brand_name: '', contact_name: '', creator_name: props.account.name };
        leadId.value = '';
    },
);
function save(): void {
    if (blocked.value) return;
    router.visit(selected.value ? `/outreach-templates/${selected.value}` : '/outreach-templates', {
        method: selected.value ? 'patch' : 'post',
        data: { name: name.value, subject: subject.value, body: body.value, version: version.value },
        preserveState: true,
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
        onSuccess: () => {
            // Keep the saved template open: a new one is found by name, an edited one by id.
            const saved = selected.value
                ? props.templates.find((row) => row.id === selected.value)
                : props.templates.find((row) => row.name === name.value && row.body === body.value);
            choose(saved);
        },
    });
}
function remove(): void {
    if (!selected.value || blocked.value) return;
    router.delete(`/outreach-templates/${selected.value}`, {
        data: { version: version.value },
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
        onSuccess: () => choose(),
    });
}

// Placeholders are inserted into the subject or body, whichever was used last.
const target = ref<'subject' | 'body'>('body');
const bodyField = ref<InstanceType<typeof PlaceholderTextarea>>();
const placeholders = computed(() => [
    { token: '{{brand_name}}', label: professional.value ? 'Client name' : 'Brand name' },
    { token: '{{contact_name}}', label: 'Contact name' },
    { token: '{{creator_name}}', label: professional.value ? 'Professional name' : 'Creator name' },
]);
async function insertPlaceholder(token: string): Promise<void> {
    if (target.value === 'body') return bodyField.value?.insert(token);
    const input = document.getElementById('template-subject') as HTMLInputElement | null;
    if (!input) return;
    const start = input.selectionStart ?? subject.value.length;
    const end = input.selectionEnd ?? start;
    subject.value = (subject.value.slice(0, start) + token + subject.value.slice(end)).slice(0, 240);
    await nextTick();
    input.focus();
    input.setSelectionRange(start + token.length, start + token.length);
}

// Draft: a live merge of the current editor text, never saved.
const values = ref<MergeValues>({ brand_name: '', contact_name: '', creator_name: props.account.name });
const leadId = ref('');
const lead = computed(() => props.leads?.find((row) => row.id === leadId.value));
watch(leadId, () => {
    if (!lead.value) return;
    values.value = {
        ...values.value,
        brand_name: lead.value.brand_name,
        contact_name: lead.value.contact_name ?? '',
    };
});
const merged = computed(() => ({
    subject: mergeText(subject.value, values.value),
    body: mergeText(body.value, values.value),
}));
const unresolved = computed(() => [...new Set([...merged.value.subject.unresolved, ...merged.value.body.unresolved])]);
const missingHint = computed(() =>
    unresolved.value
        .map((item) =>
            item === 'malformed placeholder'
                ? 'fix a placeholder that is missing a brace'
                : ['brand_name', 'contact_name', 'creator_name'].includes(item)
                  ? `add a ${item.replace('_', ' ')}`
                  : `remove or rename {{${item}}}`,
        )
        .join('; '),
);
const ready = computed(() => !unresolved.value.length && !!body.value.trim());
const subjectSegments = computed(() => mergeSegments(subject.value, values.value));
const bodySegments = computed(() => mergeSegments(body.value, values.value));
// "Edit before copying" turns the preview into editable draft fields seeded from the merge.
const editingDraft = ref(false);
const draftSubject = ref('');
const draftBody = ref('');
function editDraft(): void {
    if (!ready.value) return;
    draftSubject.value = merged.value.subject.text;
    draftBody.value = merged.value.body.text;
    editingDraft.value = true;
    void nextTick(() => document.getElementById('draft-body')?.focus());
}
watch([subject, body, values], () => (editingDraft.value = false), { deep: true });
const draftText = computed(() =>
    editingDraft.value
        ? { subject: draftSubject.value, body: draftBody.value }
        : { subject: merged.value.subject.text, body: merged.value.body.text },
);
const mailto = computed(() => {
    const email = lead.value?.contact_email;
    if (!email || !ready.value) return '';
    const params = new URLSearchParams({ subject: draftText.value.subject, body: draftText.value.body });
    return `mailto:${encodeURIComponent(email)}?${params.toString().replace(/\+/g, '%20')}`;
});
const copying = ref(false);
const copyError = ref('');
watch([subject, body, values, draftSubject, draftBody], () => (copyError.value = ''), { deep: true });
async function writeClipboard(text: string, labels: { pending: string; success: string; error: string }) {
    if (copying.value) return;
    copying.value = true;
    copyError.value = '';
    const writing = Promise.resolve().then(() => {
        if (!navigator.clipboard) throw new Error('Clipboard unavailable');
        return navigator.clipboard.writeText(text);
    });
    try {
        await notify.promise(writing, labels, 'outreach-copy');
    } catch {
        copyError.value = labels.error;
    } finally {
        copying.value = false;
    }
}
function copyDraft(): void {
    if (!ready.value) return;
    void writeClipboard(`${draftText.value.subject}\n\n${draftText.value.body}`, {
        pending: 'Copying draft',
        success: 'Draft copied',
        error: "Couldn't copy draft. Select the draft text and copy it manually.",
    });
}
function copyTemplate(): void {
    void writeClipboard(`${subject.value}\n\n${body.value}`, {
        pending: 'Copying template',
        success: 'Template text copied',
        error: "Couldn't copy the template. Select its text and copy it manually.",
    });
}

// Narrow screens show one panel at a time.
const panel = ref<'list' | 'edit' | 'draft'>('edit');
</script>

<template>
    <Head title="Outreach templates" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')">
        <div ref="root" class="wd-outreach">
            <header class="out-header">
                <div>
                    <nav aria-label="Breadcrumb">
                        <ol class="out-crumbs">
                            <li>
                                <Link :href="path('/partner/leads')">Leads</Link
                                ><ChevronRight :size="14" aria-hidden="true" />
                            </li>
                            <li aria-current="page">Outreach templates</li>
                        </ol>
                    </nav>
                    <div class="out-title-row">
                        <h1>Outreach templates</h1>
                        <span class="out-count">{{ templates.length }}</span>
                    </div>
                </div>
                <button
                    v-if="canWrite"
                    type="button"
                    class="wd-table-button out-primary"
                    :disabled="blocked"
                    @click="startNew"
                >
                    <Plus :size="16" aria-hidden="true" />New template
                </button>
            </header>
            <div v-if="errors.version" role="alert" class="wd-banner">
                <p>{{ errors.version }}</p>
                <p>Copy unsaved changes before reloading.</p>
                <button
                    class="wd-link"
                    type="button"
                    @click="router.get(path('/partner/outreach-templates'), {}, { preserveState: false })"
                >
                    Reload latest templates
                </button>
            </div>
            <div class="out-switch" role="group" aria-label="Show panel">
                <button type="button" :aria-pressed="panel === 'list'" @click="panel = 'list'">
                    Templates <span class="out-count">{{ templates.length }}</span>
                </button>
                <button type="button" :aria-pressed="panel === 'edit'" @click="panel = 'edit'">Edit</button>
                <button type="button" :aria-pressed="panel === 'draft'" @click="panel = 'draft'">Draft</button>
            </div>
            <div class="out-grid">
                <section
                    class="out-card out-list"
                    :class="{ 'is-hidden-small': panel !== 'list' }"
                    aria-labelledby="saved-title"
                >
                    <header class="out-card-head">
                        <h2 id="saved-title">Saved templates</h2>
                        <span class="wd-hint">A–Z</span>
                    </header>
                    <div class="out-search">
                        <label for="template-search" class="wd-sr-only">Search templates</label>
                        <Search :size="15" aria-hidden="true" />
                        <input
                            id="template-search"
                            v-model="query"
                            class="wd-table-input"
                            type="search"
                            placeholder="Search templates"
                            autocomplete="off"
                        />
                    </div>
                    <ul v-if="visible.length" class="out-items">
                        <li v-for="row in visible" :key="row.id" :class="{ 'is-selected': selected === row.id }">
                            <!-- The button names the template; its stretched hit area keeps the whole row clickable. -->
                            <button
                                type="button"
                                class="out-item-name"
                                :aria-describedby="`template-meta-${row.id}`"
                                :aria-pressed="selected === row.id"
                                :disabled="blocked"
                                @click="open(row)"
                            >
                                {{ row.name }}
                            </button>
                            <span :id="`template-meta-${row.id}`" class="out-item-meta">
                                <span class="out-item-subject">{{ row.subject || 'No subject' }}</span>
                                <span v-if="row.updated_at" class="out-item-date">{{
                                    updatedLabel(row.updated_at)
                                }}</span>
                            </span>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        class="out-empty"
                        :title="templates.length ? 'No templates match' : 'No templates yet'"
                        :description="
                            templates.length
                                ? 'Try another search.'
                                : 'Create a template to reuse your best outreach messages.'
                        "
                    />
                </section>
                <form
                    class="out-card out-editor"
                    :class="{ 'is-hidden-small': panel !== 'edit' }"
                    :aria-busy="busy"
                    aria-labelledby="editor-title"
                    @submit.prevent="save"
                >
                    <header class="out-card-head">
                        <h2 id="editor-title">{{ selected ? 'Edit template' : 'New template' }}</h2>
                        <span class="out-head-actions">
                            <button
                                v-if="canWrite && (selected || body)"
                                type="button"
                                class="wd-table-button"
                                :disabled="blocked"
                                @click="duplicate"
                            >
                                <CopyPlus :size="15" aria-hidden="true" />Duplicate
                            </button>
                            <button
                                type="button"
                                class="wd-table-icon-button out-ghost"
                                aria-label="Copy template text"
                                title="Copy template text"
                                :disabled="!body.trim() || copying"
                                @click="copyTemplate"
                            >
                                <Copy :size="15" aria-hidden="true" />
                            </button>
                        </span>
                    </header>
                    <fieldset class="out-fields" :disabled="blocked">
                        <legend class="wd-sr-only">{{ selected ? 'Selected template' : 'New template' }}</legend>
                        <FormField
                            id="template-name"
                            v-model="name"
                            label="Template name"
                            :maxlength="160"
                            :error="errors.name"
                            :required="canWrite"
                        />
                        <div @focusin="target = 'subject'">
                            <FormField
                                id="template-subject"
                                v-model="subject"
                                label="Subject"
                                :hint="`${subject.length} / 240 characters`"
                                :maxlength="240"
                                :required="false"
                                :error="errors.subject"
                            />
                        </div>
                        <div
                            @focusin="
                                (event) => !(event.target as HTMLElement).closest('.out-insert') && (target = 'body')
                            "
                        >
                            <PlaceholderTextarea
                                id="template-body"
                                ref="bodyField"
                                v-model="body"
                                label="Template body"
                                :maxlength="5000"
                                :rows="9"
                                :required="canWrite"
                                :error="errors.body"
                                describedby="template-body-hint"
                            >
                                <template #tools>
                                    <div class="out-insert" role="group" aria-labelledby="insert-label">
                                        <span id="insert-label"
                                            >Insert into {{ target === 'body' ? 'body' : 'subject' }}:</span
                                        >
                                        <button
                                            v-for="item in placeholders"
                                            :key="item.token"
                                            type="button"
                                            :aria-label="`Insert ${item.label} placeholder`"
                                            @mousedown.prevent
                                            @click="insertPlaceholder(item.token)"
                                        >
                                            <Plus :size="12" aria-hidden="true" />{{ item.label }}
                                        </button>
                                    </div>
                                </template>
                            </PlaceholderTextarea>
                        </div>
                        <p id="template-body-hint" class="wd-hint">
                            Plain text. Placeholders are filled when you prepare a draft.
                        </p>
                    </fieldset>
                    <footer class="out-savebar">
                        <Button
                            v-if="canWrite && selected"
                            class="wd-button wd-button-danger-outline out-delete"
                            type="button"
                            :disabled="blocked"
                            @click="
                                confirmAction(
                                    'Delete template',
                                    `Permanently delete ${current?.name ?? name}? It will no longer be available for outreach drafts.`,
                                    () => remove(),
                                    $event,
                                    { tone: 'danger' },
                                )
                            "
                            >Delete template</Button
                        >
                        <p v-if="dirty" class="out-unsaved" role="status">
                            <span aria-hidden="true">●</span> Unsaved changes
                        </p>
                        <span class="out-spacer"></span>
                        <Button
                            v-if="dirty"
                            class="wd-button wd-button-secondary"
                            type="button"
                            :disabled="busy"
                            @click="discard"
                            >Discard</Button
                        >
                        <Button v-if="canWrite" class="wd-button wd-button-primary" type="submit" :disabled="blocked">{{
                            selected ? 'Save template' : 'Create template'
                        }}</Button>
                        <p v-else class="wd-hint out-viewer">
                            Viewer access: you can prepare a local draft; saving templates requires an editing role.
                        </p>
                    </footer>
                </form>
                <section
                    class="out-card out-draft"
                    :class="{ 'is-hidden-small': panel !== 'draft' }"
                    aria-labelledby="draft-title"
                >
                    <header class="out-card-head">
                        <h2 id="draft-title">Prepare a draft</h2>
                        <span class="wd-hint">Not saved</span>
                    </header>
                    <div class="out-fields">
                        <div v-if="templates.length" class="wd-field out-small-only">
                            <label for="draft-template">Template</label>
                            <select
                                id="draft-template"
                                class="wd-table-input"
                                :value="selected ?? ''"
                                @change="
                                    choose(
                                        templates.find((row) => row.id === ($event.target as HTMLSelectElement).value),
                                    )
                                "
                            >
                                <option value="">{{ selected ? 'New template' : 'Choose a template' }}</option>
                                <option v-for="row in templates" :key="row.id" :value="row.id">{{ row.name }}</option>
                            </select>
                        </div>
                        <div v-if="leads?.length" class="wd-field">
                            <label for="draft-lead">Fill from a lead <span class="out-optional">Optional</span></label>
                            <select id="draft-lead" v-model="leadId" class="wd-table-input">
                                <option value="">Choose a lead</option>
                                <option v-for="row in leads" :key="row.id" :value="row.id">
                                    {{ row.brand_name }}{{ row.contact_name ? ` · ${row.contact_name}` : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="out-pair">
                            <FormField
                                id="merge-brand"
                                v-model="values.brand_name"
                                :label="clientLabel"
                                :required="false"
                                :maxlength="160"
                            />
                            <FormField
                                id="merge-contact"
                                v-model="values.contact_name"
                                label="Contact name"
                                :required="false"
                                :maxlength="120"
                            />
                        </div>
                        <FormField
                            id="merge-creator"
                            v-model="values.creator_name"
                            :label="professional ? 'Professional name' : 'Creator name'"
                            :required="false"
                            :maxlength="120"
                        />
                    </div>
                    <p v-if="body.trim() && unresolved.length" class="out-warning" role="status">
                        To finish the draft, {{ missingHint }}.
                    </p>
                    <div v-if="!body.trim()" class="out-preview out-preview-empty">
                        <p class="wd-hint">Choose or write a template to see the draft here.</p>
                    </div>
                    <div v-else-if="!editingDraft" class="out-preview" aria-label="Draft preview" role="region">
                        <p class="out-preview-label">Subject</p>
                        <p class="out-preview-subject">
                            <template v-for="(part, index) in subjectSegments" :key="index"
                                ><span v-if="part.kind !== 'text'" :class="`is-${part.kind}`">{{ part.text }}</span
                                ><template v-else>{{ part.text }}</template></template
                            ><span v-if="!subject" class="wd-hint">No subject</span>
                        </p>
                        <p class="out-preview-body">
                            <template v-for="(part, index) in bodySegments" :key="index"
                                ><span v-if="part.kind !== 'text'" :class="`is-${part.kind}`">{{ part.text }}</span
                                ><template v-else>{{ part.text }}</template></template
                            >
                        </p>
                    </div>
                    <div v-else class="out-fields out-draft-edit">
                        <FormField id="draft-subject" v-model="draftSubject" label="Draft subject" :required="false" />
                        <div class="wd-field">
                            <label for="draft-body">Draft body</label
                            ><textarea id="draft-body" v-model="draftBody" rows="9" />
                        </div>
                        <p class="wd-hint">
                            Draft edits stay on this page and do not change the saved template.
                            <button type="button" class="wd-link" @click="editingDraft = false">Back to preview</button>
                        </p>
                    </div>
                    <p v-if="copyError" role="alert" class="wd-field-error out-pad">{{ copyError }}</p>
                    <footer class="out-actions">
                        <Button
                            class="wd-button wd-button-primary"
                            type="button"
                            :disabled="!ready || copying"
                            @click="copyDraft"
                            >{{ copying ? 'Copying…' : 'Copy draft' }}</Button
                        >
                        <a
                            v-if="lead?.contact_email"
                            class="wd-table-button"
                            :class="{ 'is-disabled': !mailto }"
                            :href="mailto || undefined"
                            :aria-disabled="!mailto ? 'true' : undefined"
                            ><Mail :size="15" aria-hidden="true" />Open in email app</a
                        >
                        <button
                            v-if="!editingDraft"
                            type="button"
                            class="wd-table-button"
                            :disabled="!ready"
                            @click="editDraft"
                        >
                            <Pencil :size="15" aria-hidden="true" />Edit before copying
                        </button>
                        <button
                            v-if="leadId"
                            type="button"
                            class="wd-table-icon-button out-ghost"
                            aria-label="Clear the chosen lead"
                            @click="leadId = ''"
                        >
                            <X :size="15" aria-hidden="true" />
                        </button>
                    </footer>
                </section>
            </div>
        </div>
    </FoundationShell>
</template>

<style scoped>
.wd-outreach {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
    padding-bottom: 16px;
    color: var(--wd-ink);
    font-size: 14px;
}
.out-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px 24px;
}
.out-crumbs {
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
.out-crumbs li {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.out-crumbs a {
    color: var(--wd-brand);
    font-weight: 600;
    text-decoration: none;
}
.out-crumbs a:hover {
    text-decoration: underline;
}
.out-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 4px;
}
.wd-outreach h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.15;
}
.out-count {
    padding: 1px 8px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 700;
}
.out-primary {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.wd-outreach .out-primary:hover:not(:disabled) {
    border-color: var(--wd-brand-hover);
    background: var(--wd-brand-hover);
    color: #fff;
}
.out-switch {
    display: none;
}
.out-grid {
    display: grid;
    grid-template-columns: 270px minmax(0, 1.25fr) minmax(300px, 1fr);
    align-items: start;
    gap: 14px;
}
.out-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    overflow: clip;
}
.out-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 6px 10px;
    padding: 12px 14px;
}
.wd-outreach h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
}
.out-head-actions {
    display: flex;
    gap: 4px;
}
.out-ghost {
    border-color: transparent;
    background: transparent;
    color: var(--wd-muted);
}
.out-search {
    position: relative;
    display: flex;
    align-items: center;
    margin: 0 12px 10px;
}
.out-search svg {
    position: absolute;
    left: 10px;
    color: var(--wd-muted);
    pointer-events: none;
}
.out-search input {
    width: 100%;
    padding-left: 32px;
    font-size: 13px;
}
.out-items {
    margin: 0;
    padding: 0;
    list-style: none;
}
.out-items li {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 1px;
    padding: 9px 14px 9px 11px;
    border-top: 1px solid var(--wd-divider);
    border-left: 3px solid transparent;
}
.out-items li:hover {
    background: #fcfaf6;
}
.out-items li.is-selected {
    border-left-color: var(--wd-brand);
    background: #fdf6f9;
}
.out-item-name {
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    font-weight: 700;
    text-align: left;
    overflow-wrap: anywhere;
    cursor: pointer;
}
.out-item-name::after {
    content: '';
    position: absolute;
    inset: 0;
}
.out-item-name:focus-visible {
    outline: none;
}
.out-item-name:focus-visible::after {
    outline: 2px solid var(--wd-focus);
    outline-offset: -2px;
}
.out-items li.is-selected .out-item-name {
    color: var(--wd-brand-hover);
}
.out-item-meta {
    pointer-events: none;
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.out-item-subject {
    overflow: hidden;
    color: var(--wd-muted);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.out-item-date {
    color: var(--wd-muted);
    font-size: 11px;
}
.out-empty {
    margin: 0 12px 12px;
}
.out-fields {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
    margin: 0;
    padding: 12px 14px 14px;
    border: 0;
    border-top: 1px solid var(--wd-divider);
}
.out-fields :deep(.wd-field input) {
    min-height: 38px;
    padding: 7px 10px;
    font-size: 14px;
}
.out-fields > .wd-hint {
    margin-top: -6px;
}
.out-insert {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    padding: 5px 6px;
    border-bottom: 1px solid var(--wd-divider);
    color: var(--wd-muted);
    font-size: 12px;
}
.out-insert span {
    margin-right: 2px;
}
.out-insert button {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    min-height: 26px;
    padding: 0 8px;
    border: 1px solid var(--wd-line);
    border-radius: 999px;
    background: var(--wd-paper);
    color: var(--wd-brand-hover);
    font: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}
.out-insert button:hover:not(:disabled) {
    background: var(--color-blush);
}
.out-insert button:focus-visible {
    outline: 2px solid var(--wd-focus);
    outline-offset: 1px;
}
.out-savebar {
    position: sticky;
    bottom: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-top: 1px solid var(--wd-divider);
    background: var(--wd-paper);
}
.out-savebar p {
    margin: 0;
}
.out-unsaved {
    color: var(--wd-warning);
    font-size: 13px;
    font-weight: 700;
}
.out-spacer {
    flex: 1;
}
.out-viewer {
    flex-basis: 100%;
}
.out-optional {
    float: right;
    color: var(--wd-muted);
    font-weight: 500;
}
.out-draft select {
    width: 100%;
    min-height: 38px;
    padding: 6px 10px;
    font-size: 13px;
}
.out-pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}
.out-small-only {
    display: none;
}
.out-warning {
    margin: 0 14px 10px;
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--wd-warning-bg);
    color: var(--wd-warning);
    font-size: 12.5px;
    font-weight: 600;
}
.out-preview {
    margin: 0 14px 12px;
    padding: 12px 14px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-paper);
}
.out-preview p {
    margin: 0;
}
.out-preview-label {
    color: var(--wd-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.out-preview-subject {
    margin-top: 2px !important;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--wd-line);
    font-weight: 700;
    overflow-wrap: anywhere;
}
.out-preview-body {
    padding-top: 8px;
    color: var(--wd-body);
    line-height: 1.6;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.out-preview .is-filled {
    border-radius: 4px;
    background: var(--wd-success-bg);
    color: var(--wd-success);
    font-weight: 600;
}
.out-preview .is-missing {
    border-radius: 4px;
    background: var(--wd-warning-bg);
    color: var(--wd-warning);
    font-weight: 700;
    box-shadow: inset 0 -2px 0 var(--wd-warning);
}
.out-draft-edit {
    border-top: 0;
    padding-top: 0;
}
.out-draft-edit textarea {
    font: inherit;
    font-size: 14px;
    line-height: 1.6;
}
.out-pad {
    margin: 0 14px 10px;
}
.out-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-top: 1px solid var(--wd-divider);
}
.out-actions a.is-disabled {
    color: var(--wd-muted);
    pointer-events: none;
    opacity: 0.6;
}
@media (min-width: 600px) and (pointer: fine) {
    .out-search,
    .out-actions,
    .out-head-actions,
    .out-header,
    .out-draft {
        --wd-table-control: 34px;
    }
}
@media (max-width: 1099px) {
    .out-grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .out-switch {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 2px;
        padding: 3px;
        border-radius: 9px;
        background: var(--wd-divider);
    }
    .out-switch button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 36px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: var(--wd-muted);
        font: inherit;
        font-weight: 700;
    }
    .out-switch button[aria-pressed='true'] {
        background: var(--wd-surface);
        box-shadow: 0 1px 2px rgb(34 26 38 / 12%);
        color: var(--wd-brand-hover);
    }
    .out-card.is-hidden-small {
        display: none;
    }
    .out-small-only {
        display: flex;
        flex-direction: column;
    }
}
@media (max-width: 599px) {
    .wd-outreach h1 {
        font-size: 24px;
    }
    .out-pair {
        grid-template-columns: minmax(0, 1fr);
    }
    .out-savebar .out-unsaved {
        flex-basis: 100%;
    }
    .out-savebar .out-spacer {
        display: none;
    }
    .out-savebar :deep(.wd-button) {
        flex: 1;
    }
    .out-actions > :not(.out-ghost) {
        flex: 1 1 auto;
        justify-content: center;
    }
}
</style>
