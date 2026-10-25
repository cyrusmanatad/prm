<script setup lang="ts">
import { confirmAction } from '../composables/useConfirmation';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import ActionGroup from '../components/ActionGroup.vue';
import ContentSection from '../components/ContentSection.vue';
import { Button } from '../components/ui/button';
import { mergeText, type MergeValues } from '../features/outreach/merge';
import { useNotify } from '../composables/useNotify';
import { useValidationFocus } from '../composables/useValidationFocus';

type Template = { id: string; name: string; subject: string; body: string; version: number };
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    templates: Template[];
    canWrite: boolean;
    errors?: Record<string, string>;
    status?: string;
}>();
const root = ref<HTMLElement>();
const busy = ref(false);
const selected = ref<string | null>(null);
const version = ref(0);
const name = ref('');
const subject = ref('');
const body = ref('');
const values = ref<MergeValues>({ brand_name: '', contact_name: '', creator_name: props.account.name });
const draftSubject = ref('');
const draftBody = ref('');
const generated = ref(false);
const copying = ref(false);
const copyError = ref('');
const notify = useNotify();
const errors = computed(() => props.errors ?? {});
useValidationFocus(
    root,
    () => errors.value,
    () => !busy.value,
);
const merged = computed(() => ({
    subject: mergeText(subject.value, values.value),
    body: mergeText(body.value, values.value),
}));
const unresolved = computed(() => [...new Set([...merged.value.subject.unresolved, ...merged.value.body.unresolved])]);
function choose(row?: Template): void {
    selected.value = row?.id ?? null;
    version.value = row?.version ?? 0;
    name.value = row?.name ?? '';
    subject.value = row?.subject ?? '';
    body.value = row?.body ?? '';
}
watch(
    () => props.workspace.id,
    () => {
        choose();
        values.value = { brand_name: '', contact_name: '', creator_name: props.account.name };
        draftSubject.value = '';
        draftBody.value = '';
        generated.value = false;
    },
);
watch(
    [subject, body, values],
    () => {
        generated.value = false;
        copyError.value = '';
    },
    { deep: true },
);
function save(): void {
    if (busy.value || errors.value.version) return;
    router.visit(selected.value ? `/outreach-templates/${selected.value}` : '/outreach-templates', {
        method: selected.value ? 'patch' : 'post',
        data: { name: name.value, subject: subject.value, body: body.value, version: version.value },
        preserveState: true,
        preserveScroll: true,
        onStart: () => {
            busy.value = true;
        },
        onFinish: () => {
            busy.value = false;
        },
        onSuccess: () => {
            choose();
        },
    });
}
function remove(): void {
    if (!selected.value || busy.value || errors.value.version) return;
    router.delete(`/outreach-templates/${selected.value}`, {
        data: { version: version.value },
        preserveScroll: true,
        onStart: () => {
            busy.value = true;
        },
        onFinish: () => {
            busy.value = false;
        },
        onSuccess: () => choose(),
    });
}
function generate(): void {
    if (unresolved.value.length || !body.value.trim()) return;
    draftSubject.value = merged.value.subject.text;
    draftBody.value = merged.value.body.text;
    generated.value = true;
}
async function copy(): Promise<void> {
    if (copying.value) return;
    copying.value = true;
    copyError.value = '';
    const text = `${draftSubject.value}\n\n${draftBody.value}`;
    const writing = Promise.resolve().then(() => {
        if (!navigator.clipboard) throw new Error('Clipboard unavailable');
        return navigator.clipboard.writeText(text);
    });
    try {
        await notify.promise(
            writing,
            {
                pending: 'Copying draft',
                success: 'Draft copied',
                error: "Couldn't copy draft. Select the draft text and copy it manually.",
            },
            'outreach-copy',
        );
    } catch {
        copyError.value = "Couldn't copy draft. Select the draft text and copy it manually.";
    } finally {
        copying.value = false;
    }
}
</script>

<template>
    <Head title="Outreach templates" />
    <FoundationShell :account-name="account.name" :account-email="account.email" @logout="router.post('/logout')">
        <div ref="root" class="wd-workspace-content wd-section-stack">
            <nav aria-label="Workspace"><Link class="wd-link" href="/partner/leads">Back to leads</Link></nav>
            <div class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspace.name }}</span>
                <h1>Outreach templates</h1>
                <p class="wd-description">Prepare a personal draft, review it, and copy it when ready.</p>
            </div>
            <div v-if="errors.version" role="alert" class="wd-banner">
                <p>{{ errors.version }}</p>
                <p>Copy unsaved changes before reloading.</p>
                <button
                    class="wd-link"
                    type="button"
                    @click="router.get('/partner/outreach-templates', {}, { preserveState: false })"
                >
                    Reload latest templates
                </button>
            </div>
            <div class="wd-split">
                <section class="wd-card" aria-labelledby="saved-title">
                    <h2 id="saved-title">Saved templates</h2>
                    <p v-if="!templates.length" class="wd-empty">No templates yet. Create one in the editor.</p>
                    <ul>
                        <li v-for="row in templates" :key="row.id">
                            <button
                                type="button"
                                class="wd-action"
                                :disabled="busy || !!errors.version"
                                @click="choose(row)"
                            >
                                {{ row.name }}
                            </button>
                        </li>
                    </ul>
                    <button class="wd-action" type="button" :disabled="busy || !!errors.version" @click="choose()">
                        New template
                    </button>
                </section>
                <form class="wd-card" :aria-busy="busy" @submit.prevent="save">
                    <fieldset class="wd-fields" :disabled="busy || !!errors.version">
                        <legend>{{ selected ? 'Selected template' : 'New template' }}</legend>
                        <FormField
                            id="template-name"
                            v-model="name"
                            label="Template name"
                            :maxlength="160"
                            :error="errors.name"
                            :required="canWrite"
                        />
                        <FormField
                            id="template-subject"
                            v-model="subject"
                            label="Subject"
                            :maxlength="240"
                            :required="false"
                            :error="errors.subject"
                        />
                        <div class="wd-field">
                            <label for="template-body">Template body</label
                            ><textarea
                                id="template-body"
                                v-model="body"
                                rows="6"
                                maxlength="5000"
                                :required="canWrite"
                                :aria-invalid="errors.body ? true : undefined"
                                :aria-describedby="errors.body ? 'template-body-error' : undefined"
                            />
                            <p v-if="errors.body" id="template-body-error" class="wd-field-error" role="alert">
                                {{ errors.body }}
                            </p>
                        </div>
                        <p class="wd-hint">
                            Plain text. Available placeholders:
                            <code v-pre>{{ brand_name }}, {{ contact_name }}, {{ creator_name }}</code
                            >.
                        </p>
                        <ActionGroup>
                            <Button v-if="canWrite" class="wd-button wd-button-primary" type="submit">{{
                                selected ? 'Save template' : 'Create template'
                            }}</Button>
                            <Button
                                v-if="canWrite && selected"
                                class="wd-button wd-button-secondary wd-button-danger-outline"
                                type="button"
                                :disabled="busy || !!errors.version"
                                @click="
                                    confirmAction(
                                        'Delete template',
                                        `Permanently delete ${name}? It will no longer be available for outreach drafts.`,
                                        () => remove(),
                                        $event,
                                        { tone: 'danger' },
                                    )
                                "
                                >Delete template</Button
                            >
                        </ActionGroup>
                        <p v-if="!canWrite" class="wd-hint">
                            Viewer access: you can prepare a local draft; saving templates requires an editing role.
                        </p>
                    </fieldset>
                </form>
            </div>
            <ContentSection title="Prepare a draft" heading-id="draft-title">
                <div class="wd-fields">
                    <FormField
                        id="merge-brand"
                        v-model="values.brand_name"
                        label="Brand name"
                        :required="false"
                        :maxlength="160"
                    /><FormField
                        id="merge-contact"
                        v-model="values.contact_name"
                        label="Contact name"
                        :required="false"
                        :maxlength="120"
                    /><FormField
                        id="merge-creator"
                        v-model="values.creator_name"
                        label="Creator name"
                        :required="false"
                        :maxlength="120"
                    />
                </div>
                <p v-if="unresolved.length" class="wd-hint">
                    Fill or correct these placeholders: {{ unresolved.join(', ') }}.
                </p>
                <Button
                    class="wd-button wd-button-secondary"
                    type="button"
                    :disabled="!!unresolved.length || !body.trim()"
                    @click="generate"
                    >Generate draft</Button
                >
                <div v-if="generated" class="wd-fields">
                    <FormField id="draft-subject" v-model="draftSubject" label="Draft subject" :required="false" />
                    <div class="wd-field">
                        <label for="draft-body">Draft body</label
                        ><textarea id="draft-body" v-model="draftBody" rows="8" />
                    </div>
                    <p class="wd-hint">
                        Review and edit before copying. Draft edits stay on this page and do not change the saved
                        template.
                    </p>
                    <p v-if="copyError" role="alert" class="wd-field-error">{{ copyError }}</p>
                    <Button class="wd-button wd-button-primary" type="button" :disabled="copying" @click="copy">{{
                        copying ? 'Copying…' : 'Copy draft'
                    }}</Button>
                </div>
            </ContentSection>
        </div>
    </FoundationShell>
</template>
