<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
import { Button } from '../components/ui/button';
type Detail = {
    id: string;
    label: string;
    status: string;
    version: number;
    business_name: string;
    contact_name: string;
    business_email: string;
    website: string;
    brief: string;
    correction_note: string;
    documents: { id: string; name: string; scan_state: string }[];
};
const props = defineProps<{
    account: { name: string };
    workspace: { id: string; name: string };
    canWrite: boolean;
    requests: {
        id: string;
        label: string;
        status: string;
        version: number;
        pipeline_entry_id: string;
    }[];
    selected: Detail | null;
    submissions: {
        id: string;
        revision: number;
        snapshot: {
            business_name?: string;
            contact_name?: string;
            business_email?: string;
            website?: string;
            brief?: string;
            documents?: { id: string }[];
        };
        consent_at: string;
    }[];
    issuedLink: string | null;
    status?: string;
    errors?: Record<string, string>;
}>();
const note = ref('');
const busy = ref(false);
function review(action: string): void {
    if (!props.selected || busy.value || props.errors?.version) return;
    router.post(
        `/onboarding-requests/${props.selected.id}/${action}`,
        { version: props.selected.version, note: note.value },
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
</script>
<template>
    <Head title="Onboarding requests" /><FoundationShell :account-name="account.name" @logout="router.post('/logout')"
        ><div class="wd-workspace-content wd-onboarding-admin">
            <nav aria-label="Workspace">
                <Link class="wd-link" href="/partner/leads">Back to leads</Link>
            </nav>
            <div class="wd-page-heading">
                <span class="wd-eyebrow">{{ workspace.name }}</span>
                <h1>Onboarding requests</h1>
                <p class="wd-description">Submitted requests below are ready for your review.</p>
            </div>
            <p v-if="status" role="status" class="wd-status">{{ status }}</p>
            <div v-if="errors && Object.keys(errors).length" role="alert" class="wd-status">
                <p v-for="(msg, k) in errors" :key="k">{{ msg }}</p>
                <button
                    v-if="errors.version"
                    type="button"
                    class="wd-link"
                    @click="
                        router.get('/partner/onboarding', selected ? { request: selected.id } : {}, {
                            preserveState: false,
                        })
                    "
                >
                    Reload latest request
                </button>
            </div>
            <div v-if="issuedLink" class="wd-card">
                <label for="issued-invite">Private invitation link (copy now)</label
                ><input id="issued-invite" :value="issuedLink" readonly />
                <p class="wd-hint">
                    Expires in seven days. Opening it once creates a 24-hour session in that browser. Revocation ends
                    access immediately.
                </p>
            </div>
            <section class="wd-card" aria-labelledby="request-list">
                <h2 id="request-list">Recent requests</h2>
                <p v-if="!requests.length">Start onboarding from a lead in your pipeline.</p>
                <ul>
                    <li v-for="row in requests" :key="row.id">
                        <Link class="wd-link" :href="`/partner/onboarding?request=${row.id}`">{{ row.label }}</Link>
                        · {{ row.status }} <strong v-if="row.status === 'submitted'">Awaiting review</strong>
                    </li>
                </ul>
            </section>
            <section v-if="selected" class="wd-card wd-fields" aria-labelledby="selected-request">
                <h2 id="selected-request">{{ selected.label }}</h2>
                <dl>
                    <dt>Business</dt>
                    <dd>{{ selected.business_name }}</dd>
                    <dt>Contact</dt>
                    <dd>{{ selected.contact_name }} · {{ selected.business_email }}</dd>
                    <dt>Website</dt>
                    <dd>{{ selected.website }}</dd>
                    <dt>Brief</dt>
                    <dd class="wd-pipeline-note">{{ selected.brief }}</dd>
                </dl>
                <ul>
                    <li v-for="doc in selected.documents" :key="doc.id">
                        {{ doc.name }} · {{ doc.scan_state }}
                        <a
                            v-if="doc.scan_state === 'clean'"
                            class="wd-link"
                            :href="`/onboarding-requests/${selected.id}/documents/${doc.id}`"
                            >Download {{ doc.name }}</a
                        >
                        <button
                            v-if="canWrite && doc.scan_state === 'pending'"
                            type="button"
                            class="wd-link"
                            @click="router.post(`/onboarding-requests/${selected.id}/documents/${doc.id}`)"
                        >
                            Retry scan {{ doc.name }}
                        </button>
                    </li>
                </ul>
                <template v-if="canWrite"
                    ><FormField
                        v-if="selected.status === 'submitted'"
                        id="correction-note"
                        v-model="note"
                        label="Correction request"
                        :maxlength="2000"
                        :required="false"
                    /><Button
                        v-if="selected.status === 'submitted'"
                        type="button"
                        class="wd-button wd-button-secondary"
                        :disabled="busy || !note.trim() || !!errors?.version"
                        @click="review('correct')"
                        >Request correction</Button
                    ><Button
                        v-if="selected.status === 'submitted'"
                        type="button"
                        class="wd-button wd-button-primary"
                        :disabled="busy || !!errors?.version"
                        @click="review('accept')"
                        >Accept submission</Button
                    ><Button
                        v-if="selected.status !== 'revoked'"
                        type="button"
                        class="wd-button wd-button-secondary"
                        :disabled="busy || !!errors?.version"
                        @click="review('renew')"
                        >Create replacement invitation</Button
                    ><Button
                        v-if="selected.status !== 'revoked'"
                        type="button"
                        class="wd-button wd-button-secondary"
                        :disabled="busy || !!errors?.version"
                        @click="review('revoke')"
                        >Revoke invitation access</Button
                    ></template
                >
                <h3>Submission history</h3>
                <details v-for="submission in submissions" :key="submission.id">
                    <summary>Submission version {{ submission.revision }}</summary>
                    <p>Consent recorded: {{ submission.consent_at }}</p>
                    <dl class="wd-fields">
                        <div>
                            <dt>Business</dt>
                            <dd>{{ submission.snapshot.business_name }}</dd>
                        </div>
                        <div>
                            <dt>Contact</dt>
                            <dd>{{ submission.snapshot.contact_name }}</dd>
                        </div>
                        <div>
                            <dt>Business email</dt>
                            <dd>{{ submission.snapshot.business_email }}</dd>
                        </div>
                        <div>
                            <dt>Website</dt>
                            <dd>{{ submission.snapshot.website || 'Not provided' }}</dd>
                        </div>
                        <div>
                            <dt>Collaboration brief</dt>
                            <dd>{{ submission.snapshot.brief }}</dd>
                        </div>
                    </dl>
                    <p>{{ submission.snapshot.documents?.length ?? 0 }} reference files shared with this submission.</p>
                </details>
            </section>
        </div></FoundationShell
    >
</template>
<style scoped>
.wd-card {
    margin-bottom: 1rem;
}
li,
dd {
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}
input {
    width: 100%;
}
</style>
