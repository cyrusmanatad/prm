<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import FoundationShell from '../layouts/FoundationShell.vue';
import FormField from '../components/FormField.vue';
const props = defineProps<{ account: { name: string; email: string } | null; brand: string | null }>();
const codeForm = useForm({ code: '' });
const joinForm = useForm({ consent: false });
onMounted(() => {
    // Fragment codes never reach access logs; exchange them for an encrypted session copy.
    const code = decodeURIComponent(window.location.hash.slice(1));
    history.replaceState(null, '', window.location.pathname);
    if (code && !props.brand) {
        codeForm.code = code;
        codeForm.post('/join/prepare');
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
                              ? 'Confirm the partnership to connect this brand to your Partner workspace.'
                              : 'Create a WeavDash account, or sign in if you already have one, to become a partner.'
                    }}
                </p>
            </div>
            <section class="wd-card">
                <form
                    v-if="!brand"
                    class="wd-fields"
                    :aria-busy="codeForm.processing"
                    @submit.prevent="codeForm.post('/join/prepare')"
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
                <form
                    v-else
                    class="wd-fields"
                    :aria-busy="joinForm.processing"
                    @submit.prevent="joinForm.post('/join/accept')"
                >
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
            </section>
        </div>
    </FoundationShell>
</template>
