<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
const props = defineProps<{
    account: { name: string; email: string } | null;
    brand: string | null;
    workspace: { name: string; experience: string } | null;
    verified: boolean;
    errors?: Record<string, string>;
}>();
const enteringCode = ref(false);
const codeForm = useForm({ code: '' });
const joinForm = useForm({ consent: false });
function prepare(): void {
    if (codeForm.processing) return;
    codeForm.post('/join/prepare', {
        onSuccess: () => {
            enteringCode.value = false;
            codeForm.reset();
        },
    });
}
onMounted(() => {
    // Fragment codes never reach access logs; exchange them for an encrypted session copy.
    const fragment = window.location.hash.slice(1);
    history.replaceState(history.state, '', window.location.pathname + window.location.search);
    let code = fragment;
    try {
        code = decodeURIComponent(fragment);
    } catch {
        /* Invalid encoding is handled as an invalid code. */
    }
    if (code) {
        codeForm.code = code;
        prepare();
    }
});
</script>
<template>
    <Head title="Join a brand" />
    <FoundationShell>
        <div class="wd-workspace-content">
            <div class="wd-page-heading">
                <h1>{{ brand ? `Join ${brand}` : 'Join a brand' }}</h1>
                <p class="wd-description">
                    {{
                        !brand
                            ? 'Enter the sign-up code the brand shared with you.'
                            : account
                              ? 'Choose your workspace and confirm the partnership.'
                              : 'Create a WeavDash account, or sign in if you already have one, to become a partner.'
                    }}
                </p>
            </div>
            <p v-if="errors?.code" role="alert" class="wd-field-error">{{ errors.code }}</p>
            <section class="wd-card">
                <form
                    v-if="!brand || enteringCode"
                    class="wd-fields"
                    :aria-busy="codeForm.processing"
                    @submit.prevent="prepare"
                >
                    <FormField
                        id="join-code"
                        v-model="codeForm.code"
                        label="Sign-up code"
                        autocomplete="off"
                        :maxlength="32"
                        hint="For example, 7KQ4M-9XH2P."
                        :error="codeForm.errors.code"
                    />
                    <button class="wd-button wd-button-primary" :disabled="codeForm.processing">
                        {{ codeForm.processing ? 'Please wait…' : 'Continue' }}
                    </button>
                </form>
                <div v-else-if="!account" class="wd-fields">
                    <p class="wd-hint">
                        Your sign-up is retained securely for 15 minutes while you create your account or sign in.
                    </p>
                    <Link href="/partner/register" class="wd-button wd-button-primary">Create account</Link>
                    <Link href="/partner/login" class="wd-button wd-button-secondary">I already have an account</Link>
                    <p class="wd-hint">Your Partner workspace keeps every brand you work with in one place.</p>
                </div>
                <div v-else-if="!verified" class="wd-fields">
                    <p>Verify your email before joining {{ brand }}.</p>
                    <Link href="/verify-email" class="wd-button wd-button-primary">Verify email</Link>
                </div>
                <div v-else-if="!workspace" class="wd-fields">
                    <p>Select or create a Partner or Professional workspace you own or administer.</p>
                    <Link href="/join/select" class="wd-button wd-button-primary">Choose workspace</Link>
                </div>
                <form
                    v-else
                    class="wd-fields"
                    :aria-busy="joinForm.processing"
                    @submit.prevent="joinForm.post('/join/accept')"
                >
                    <p>
                        Join <strong>{{ brand }}</strong> with <strong>{{ workspace.name }}</strong> ({{
                            workspace.experience === 'professional' ? 'Professional' : 'Partner'
                        }}).
                    </p>
                    <Link href="/workspaces" class="wd-link">Change workspace</Link>
                    <label class="wd-checkbox" for="join-consent"
                        ><input id="join-consent" v-model="joinForm.consent" type="checkbox" required /> I accept this
                        partnership and agree to share my workspace display name and relationship status with
                        {{ brand }}. Private CRM data stays private.</label
                    >
                    <p v-for="(error, key) in joinForm.errors" :key="key" role="alert" class="wd-field-error">
                        {{ error }}
                    </p>
                    <button class="wd-button wd-button-primary" :disabled="joinForm.processing">
                        {{ joinForm.processing ? 'Please wait…' : 'Join partnership' }}
                    </button>
                </form>
                <button
                    v-if="brand && !enteringCode"
                    type="button"
                    class="wd-button wd-button-secondary mt-4"
                    @click="enteringCode = true"
                >
                    Enter a different code
                </button>
            </section>
        </div>
    </FoundationShell>
</template>
