<script setup lang="ts">
import { confirmAction } from '../composables/useConfirmation';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import { Button } from '../components/ui/button';
type Form = {
    id: string;
    label: string;
    status: string;
    business_name: string;
    contact_name: string;
    business_email: string;
    website: string;
    brief: string;
    correction_note: string;
    version: number;
    documents: { id: string; name: string; size: number; scan_state: string }[];
};
const props = defineProps<{ form: Form; errors?: Record<string, string>; status?: string }>();
const draft = ref({
    business_name: props.form.business_name,
    contact_name: props.form.contact_name,
    business_email: props.form.business_email,
    website: props.form.website,
    brief: props.form.brief,
});
const loadedVersion = ref(props.form.version);
const key = ref(crypto.randomUUID());
const consent = ref(false);
const busy = ref(false);
const file = ref<File | null>(null);
const editable = computed(() => ['draft', 'correction'].includes(props.form.status));
function act(
    method: 'post' | 'patch' | 'delete',
    path: string,
    data: Record<string, string | number | boolean | File | null>,
): void {
    if (busy.value || props.errors?.version) return;
    const submittedVersion = loadedVersion.value;
    router.visit(path, {
        method,
        data: { ...data, version: submittedVersion },
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            busy.value = true;
        },
        onFinish: () => {
            busy.value = false;
        },
        onSuccess: () => {
            // A redirect may already contain another writer's later edit.
            loadedVersion.value = submittedVersion + 1;
            key.value = crypto.randomUUID();
            file.value = null;
        },
    });
}
function pick(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}
</script>
<template>
    <Head title="Private onboarding form" /><FoundationShell
        ><div class="wd-workspace-content wd-onboarding">
            <div class="wd-page-heading">
                <span class="wd-eyebrow">Private onboarding</span>
                <h1>{{ form.label }}</h1>
                <p class="wd-description">
                    Progress is saved when you choose Save draft. Return to this page in the same browser to resume.
                </p>
            </div>
            <p v-if="status" role="status" class="wd-status">{{ status }}</p>
            <p class="wd-hint">Status: {{ form.status }}</p>
            <div v-if="errors && Object.keys(errors).length" role="alert" class="wd-banner">
                <p v-for="(message, k) in errors" :key="k">{{ message }}</p>
                <button
                    v-if="errors.version"
                    type="button"
                    class="wd-link"
                    @click="router.get(`/onboarding/form/${form.id}`, {}, { preserveState: false })"
                >
                    Reload latest form
                </button>
                <p v-if="errors.version">Copy unsaved changes before reloading.</p>
            </div>
            <p v-if="form.correction_note" class="wd-warning">Requested correction: {{ form.correction_note }}</p>
            <form
                class="wd-card"
                :aria-busy="busy"
                @submit.prevent="act('patch', `/onboarding/form/${form.id}`, draft)"
            >
                <fieldset class="wd-fields" :disabled="busy || !editable || !!errors?.version">
                    <legend>Business and collaboration details</legend>
                    <FormField
                        id="onboard-business"
                        v-model="draft.business_name"
                        label="Business name"
                        :required="false"
                        :maxlength="160"
                        :error="errors?.business_name"
                    /><FormField
                        id="onboard-contact"
                        v-model="draft.contact_name"
                        label="Contact name"
                        :required="false"
                        :maxlength="120"
                        :error="errors?.contact_name"
                    /><FormField
                        id="onboard-email"
                        v-model="draft.business_email"
                        label="Business email"
                        type="email"
                        :required="false"
                        :maxlength="255"
                        :error="errors?.business_email"
                    /><FormField
                        id="onboard-website"
                        v-model="draft.website"
                        label="Website (optional, https:// or http://)"
                        :required="false"
                        :maxlength="500"
                        :error="errors?.website"
                    />
                    <div class="wd-field">
                        <label for="onboard-brief">Collaboration brief</label
                        ><textarea id="onboard-brief" v-model="draft.brief" rows="6" maxlength="5000" />
                    </div>
                    <p class="wd-hint">
                        Keep tax IDs, permits, shipping addresses and other sensitive details out of this form.
                    </p>
                    <Button v-if="editable" type="submit" class="wd-button wd-button-primary">Save draft</Button>
                </fieldset>
            </form>
            <section class="wd-card" aria-labelledby="files-title">
                <h2 id="files-title">Reference files</h2>
                <p class="wd-hint">
                    Optional PDF, PNG, JPEG or plain text; 2 MB each, up to five files. Use non-sensitive collaboration
                    references.
                </p>
                <ul>
                    <li v-for="doc in form.documents" :key="doc.id">
                        {{ doc.name }} · {{ doc.scan_state }}
                        <a
                            v-if="doc.scan_state === 'clean'"
                            class="wd-link"
                            :href="`/onboarding/form/${form.id}/documents/${doc.id}`"
                            >Download {{ doc.name }}</a
                        >
                        <button
                            v-if="editable"
                            class="wd-action-danger"
                            type="button"
                            :disabled="busy || !!errors?.version"
                            @click="
                                confirmAction(
                                    'Remove document',
                                    `Remove ${doc.name} from this onboarding request? It will no longer be shared with the reviewer.`,
                                    () => act('delete', `/onboarding/form/${form.id}/documents/${doc.id}`, {}),
                                    $event,
                                    { tone: 'danger' },
                                )
                            "
                        >
                            Remove {{ doc.name }}
                        </button>
                    </li>
                </ul>
                <p v-if="!form.documents.length" class="wd-empty">No files uploaded.</p>
                <form
                    v-if="editable"
                    :aria-busy="busy"
                    class="wd-fields"
                    @submit.prevent="act('post', `/onboarding/form/${form.id}/documents`, { file })"
                >
                    <div class="wd-field">
                        <label for="reference-file">Choose reference file</label
                        ><input
                            id="reference-file"
                            type="file"
                            accept=".pdf,.png,.jpg,.jpeg,.txt"
                            :disabled="busy || !!errors?.version"
                            @change="pick"
                        />
                    </div>
                    <Button
                        type="submit"
                        class="wd-button wd-button-secondary"
                        :disabled="!file || busy || !!errors?.version"
                        >Upload reference</Button
                    >
                </form>
            </section>
            <section v-if="editable" class="wd-card wd-fields" aria-label="Submit for review">
                <label class="wd-checkbox"
                    ><input v-model="consent" type="checkbox" />I agree to share this form and its reference files with
                    the requesting creator for this collaboration.</label
                ><Button
                    type="button"
                    class="wd-button wd-button-primary"
                    :disabled="!consent || busy || !!errors?.version"
                    @click="
                        act('post', `/onboarding/form/${form.id}/submit`, {
                            ...draft,
                            consent,
                            idempotency_key: key,
                        })
                    "
                    >Submit for review</Button
                >
            </section>
        </div></FoundationShell
    >
</template>
<style scoped>
.wd-card {
    margin-bottom: 1rem;
}
li {
    overflow-wrap: anywhere;
}
</style>
