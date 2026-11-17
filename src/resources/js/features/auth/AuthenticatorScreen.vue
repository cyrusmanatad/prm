<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import ActionGroup from '../../components/ActionGroup.vue';
import CopyReference from '../../components/CopyReference.vue';
import EnrollmentQr from '../../components/EnrollmentQr.vue';
import FormField from '../../components/FormField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { useNotify } from '../../composables/useNotify';
import { useValidationFocus } from '../../composables/useValidationFocus';
import FoundationShell from '../../layouts/FoundationShell.vue';
import type { FieldErrors } from '../../types/foundation';
import { enrollmentUri } from './enrollment';

const props = withDefaults(
    defineProps<{
        accountName: string;
        accountEmail: string;
        enrolled: boolean;
        verified: boolean;
        required: boolean;
        operator: boolean;
        recoveryRemaining?: number | null;
        continueTo?: string | null;
        secret?: string | null;
        recoveryCodes?: string[] | null;
        errors?: FieldErrors;
        processing?: boolean;
    }>(),
    {
        recoveryRemaining: null,
        continueTo: null,
        secret: null,
        recoveryCodes: null,
        errors: () => ({}),
        processing: false,
    },
);
const emit = defineEmits<{
    enroll: [password: string];
    cancel: [];
    verify: [code: string];
    recover: [recoveryCode: string];
    regenerate: [password: string, code: string];
    logout: [];
}>();

type View = 'codes' | 'setup' | 'challenge' | 'manage' | 'start';
const view = computed<View>(() =>
    props.recoveryCodes
        ? 'codes'
        : props.secret
          ? 'setup'
          : props.enrolled
            ? props.verified
                ? 'manage'
                : 'challenge'
            : 'start',
);
const root = ref<HTMLElement>();
const fields = reactive({ password: '', code: '', recovery_code: '' });
const useRecoveryCode = ref(false);
const panel = ref<'replace' | 'codes' | null>(null);
const notify = useNotify();
const enrollment = computed(() => (props.secret ? enrollmentUri(props.secret, props.accountEmail) : null));
const title = computed(
    () =>
        ({
            codes: props.recoveryCodes?.length ? 'Save your recovery codes.' : 'Your authenticator is set up.',
            setup: 'Add WeavDash to your authenticator.',
            challenge: 'Enter your authenticator code.',
            manage: 'Your authenticator.',
            start: 'Set up an authenticator.',
        })[view.value],
);

function clear(): void {
    fields.password = '';
    fields.code = '';
    fields.recovery_code = '';
}
watch(view, () => {
    clear();
    panel.value = null;
    useRecoveryCode.value = false;
});
// A recovery-code error belongs to the recovery form, also after a full page load.
watch(
    () => props.errors.recovery_code,
    (error) => {
        if (error) useRecoveryCode.value = true;
    },
    { immediate: true },
);
useValidationFocus(
    root,
    () => props.errors,
    () => !props.processing,
);
async function show(next: 'replace' | 'codes' | null): Promise<void> {
    panel.value = next;
    clear();
    await nextTick();
    root.value?.querySelector<HTMLElement>('form input')?.focus();
}
async function toggleRecovery(): Promise<void> {
    useRecoveryCode.value = !useRecoveryCode.value;
    clear();
    await nextTick();
    root.value?.querySelector<HTMLElement>('form input')?.focus();
}
function replace(event: SubmitEvent): void {
    if (props.processing) return;
    confirmAction(
        'Replace authenticator',
        `Replace the authenticator for ${props.accountName}? Once you confirm the new one, the current authenticator and recovery codes stop working and your other sessions are signed out.`,
        () => emit('enroll', fields.password),
        event,
        { tone: 'danger' },
    );
}
function regenerate(event: SubmitEvent): void {
    if (props.processing) return;
    confirmAction(
        'Create new recovery codes',
        `Create new recovery codes for ${props.accountName}? Your current recovery codes stop working immediately.`,
        () => emit('regenerate', fields.password, fields.code),
        event,
        { tone: 'danger' },
    );
}
function copyCodes(): void {
    const task = navigator.clipboard
        ? navigator.clipboard.writeText((props.recoveryCodes ?? []).join('\n'))
        : Promise.reject(new Error('Clipboard unavailable'));
    void notify
        .promise(
            task,
            {
                pending: 'Copying recovery codes…',
                success: 'Copied recovery codes',
                error: "Couldn't copy the recovery codes. Select the text and copy it manually.",
            },
            'copy-recovery-codes',
        )
        .catch(() => undefined);
}
defineExpose({ clear });
</script>

<template>
    <FoundationShell :account-name="accountName" :account-email="accountEmail" @logout="emit('logout')">
        <div ref="root" class="wd-authenticator">
            <section class="wd-card wd-auth-card" aria-labelledby="authenticator-title">
                <span class="wd-eyebrow">Account security</span>
                <h1 id="authenticator-title">{{ title }}</h1>

                <template v-if="view === 'codes'">
                    <template v-if="recoveryCodes?.length">
                        <p class="wd-description">
                            Each code signs you in once if you lose your authenticator. Keep them somewhere safe and
                            separate from your password.
                        </p>
                        <p class="wd-warning" role="note">
                            These codes are shown only now. Save them before you continue.
                        </p>
                        <ul class="wd-recovery-codes" aria-label="Recovery codes">
                            <li v-for="code in recoveryCodes" :key="code">
                                <code>{{ code }}</code>
                            </li>
                        </ul>
                    </template>
                    <p v-else class="wd-description">
                        Accounts with platform access have no recovery codes. If you lose your authenticator, recovery
                        is performed offline by an authorized operator.
                    </p>
                    <ActionGroup>
                        <Button
                            v-if="recoveryCodes?.length"
                            type="button"
                            class="wd-button wd-button-secondary"
                            @click="copyCodes"
                            >Copy all codes</Button
                        >
                        <Link :href="continueTo ?? '/workspaces'" class="wd-button wd-button-primary">{{
                            recoveryCodes?.length ? 'I saved my codes' : 'Continue'
                        }}</Link>
                    </ActionGroup>
                </template>

                <template v-else-if="view === 'start'">
                    <p class="wd-description">
                        {{
                            required
                                ? 'Workspace owners sign in with their password and a six-digit code from an authenticator app. Set yours up to continue.'
                                : 'Add a six-digit code from an authenticator app to your sign-in. Once set up, the code is asked at every sign-in.'
                        }}
                    </p>
                    <form :aria-busy="processing" @submit.prevent="emit('enroll', fields.password)">
                        <fieldset class="wd-fields" :disabled="processing">
                            <legend class="wd-sr-only">Set up an authenticator</legend>
                            <FormField
                                id="password"
                                v-model="fields.password"
                                type="password"
                                autocomplete="current-password"
                                label="Password"
                                :error="errors.password"
                                hint="Confirm it is you before a setup key is created."
                            />
                            <Button type="submit" class="wd-button wd-button-primary">{{
                                processing ? 'Please wait…' : 'Generate setup key'
                            }}</Button>
                        </fieldset>
                    </form>
                </template>

                <template v-else-if="view === 'setup'">
                    <div class="wd-setup-key">
                        <p class="wd-description">
                            Scan this QR code with your authenticator app, then enter the code it shows. The setup key
                            expires 10 minutes after it was created.
                        </p>
                        <EnrollmentQr v-if="enrollment" :value="enrollment" label="QR code for authenticator setup" />
                        <p class="wd-description">
                            If you cannot scan the code, add the setup key to your app as a time-based, six-digit
                            account.
                        </p>
                        <p>Setup key: <CopyReference :value="secret ?? ''" label="setup key" /></p>
                    </div>
                    <form :aria-busy="processing" @submit.prevent="emit('verify', fields.code)">
                        <fieldset class="wd-fields" :disabled="processing">
                            <legend class="wd-sr-only">Confirm the authenticator</legend>
                            <FormField
                                id="code"
                                v-model="fields.code"
                                label="Authenticator code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                :maxlength="6"
                                pattern="[0-9]{6}"
                                :error="errors.code"
                                hint="Six digits. Each code works once."
                            />
                            <ActionGroup>
                                <Button type="submit" class="wd-button wd-button-primary">{{
                                    processing ? 'Please wait…' : 'Confirm authenticator'
                                }}</Button>
                                <Button type="button" class="wd-button wd-button-secondary" @click="emit('cancel')"
                                    >Cancel setup</Button
                                >
                            </ActionGroup>
                        </fieldset>
                    </form>
                </template>

                <template v-else-if="view === 'challenge'">
                    <p class="wd-description">
                        {{
                            useRecoveryCode
                                ? 'Enter one of the recovery codes you saved when you set up your authenticator. Each code works once.'
                                : 'Open your authenticator app and enter the current code for WeavDash.'
                        }}
                    </p>
                    <form v-if="!useRecoveryCode" :aria-busy="processing" @submit.prevent="emit('verify', fields.code)">
                        <fieldset class="wd-fields" :disabled="processing">
                            <legend class="wd-sr-only">Authenticator check</legend>
                            <FormField
                                id="code"
                                v-model="fields.code"
                                label="Authenticator code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                :maxlength="6"
                                pattern="[0-9]{6}"
                                :error="errors.code"
                                hint="Six digits. Each code works once."
                            />
                            <Button type="submit" class="wd-button wd-button-primary">{{
                                processing ? 'Please wait…' : 'Verify and continue'
                            }}</Button>
                        </fieldset>
                    </form>
                    <form v-else :aria-busy="processing" @submit.prevent="emit('recover', fields.recovery_code)">
                        <fieldset class="wd-fields" :disabled="processing">
                            <legend class="wd-sr-only">Recovery code</legend>
                            <FormField
                                id="recovery_code"
                                v-model="fields.recovery_code"
                                label="Recovery code"
                                autocomplete="off"
                                :maxlength="40"
                                :error="errors.recovery_code"
                                hint="Ten characters, for example ABCDE-FGHIJ."
                            />
                            <Button type="submit" class="wd-button wd-button-primary">{{
                                processing ? 'Please wait…' : 'Use recovery code'
                            }}</Button>
                        </fieldset>
                    </form>
                    <nav class="wd-auth-navigation" aria-label="Sign-in options">
                        <button
                            v-if="!operator"
                            type="button"
                            class="wd-link"
                            :disabled="processing"
                            @click="toggleRecovery"
                        >
                            {{ useRecoveryCode ? 'Use my authenticator instead' : 'Use a recovery code instead' }}
                        </button>
                        <button type="button" class="wd-link" :disabled="processing" @click="emit('logout')">
                            Sign out
                        </button>
                    </nav>
                    <p class="wd-hint">
                        {{
                            operator
                                ? 'Lost your authenticator? Recovery for accounts with platform access is performed offline by an authorized operator.'
                                : 'Lost your authenticator and your recovery codes? Contact support. After confirming your identity, an administrator can reset your authenticator so you can set up a new one.'
                        }}
                    </p>
                </template>

                <template v-else>
                    <p>
                        <StatusBadge label="Authenticator set up" tone="success" />
                    </p>
                    <p class="wd-description">A code from your authenticator app is asked at every sign-in.</p>
                    <p v-if="operator" class="wd-description">
                        Accounts with platform access have no recovery codes. Recovery is performed offline by an
                        authorized operator.
                    </p>
                    <template v-else>
                        <p v-if="(recoveryRemaining ?? 0) <= 3" class="wd-warning" role="note">
                            {{
                                recoveryRemaining
                                    ? `Only ${recoveryRemaining} recovery ${recoveryRemaining === 1 ? 'code is' : 'codes are'} left. Create new codes before they run out.`
                                    : 'You have no recovery codes left. Create new codes so you can sign in if you lose your authenticator.'
                            }}
                        </p>
                        <p v-else class="wd-description">{{ recoveryRemaining }} recovery codes left.</p>
                    </template>
                    <ActionGroup v-if="!panel" label="Authenticator actions">
                        <Link :href="continueTo ?? '/workspaces'" class="wd-button wd-button-primary">Continue</Link>
                        <Button
                            v-if="!operator"
                            type="button"
                            class="wd-button wd-button-secondary"
                            @click="show('codes')"
                            >New recovery codes</Button
                        >
                        <Button type="button" class="wd-button wd-button-danger-outline" @click="show('replace')"
                            >Replace authenticator</Button
                        >
                    </ActionGroup>
                    <form v-else-if="panel === 'replace'" :aria-busy="processing" @submit.prevent="replace">
                        <fieldset class="wd-fields" :disabled="processing">
                            <legend>Replace authenticator</legend>
                            <p class="wd-description">
                                Enter your password to create a new setup key. Your current authenticator keeps working
                                until you confirm the new one.
                            </p>
                            <FormField
                                id="password"
                                v-model="fields.password"
                                type="password"
                                autocomplete="current-password"
                                label="Password"
                                :error="errors.password"
                            />
                            <ActionGroup>
                                <Button type="submit" class="wd-button wd-button-danger">{{
                                    processing ? 'Please wait…' : 'Replace authenticator'
                                }}</Button>
                                <Button type="button" class="wd-button wd-button-secondary" @click="show(null)"
                                    >Cancel</Button
                                >
                            </ActionGroup>
                        </fieldset>
                    </form>
                    <form v-else :aria-busy="processing" @submit.prevent="regenerate">
                        <fieldset class="wd-fields" :disabled="processing">
                            <legend>New recovery codes</legend>
                            <p class="wd-description">
                                New codes replace the current ones. Enter your password and a fresh authenticator code.
                            </p>
                            <FormField
                                id="password"
                                v-model="fields.password"
                                type="password"
                                autocomplete="current-password"
                                label="Password"
                                :error="errors.password"
                            />
                            <FormField
                                id="code"
                                v-model="fields.code"
                                label="Authenticator code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                :maxlength="6"
                                pattern="[0-9]{6}"
                                :error="errors.code"
                                hint="Six digits. Each code works once."
                            />
                            <ActionGroup>
                                <Button type="submit" class="wd-button wd-button-danger">{{
                                    processing ? 'Please wait…' : 'Create new codes'
                                }}</Button>
                                <Button type="button" class="wd-button wd-button-secondary" @click="show(null)"
                                    >Cancel</Button
                                >
                            </ActionGroup>
                        </fieldset>
                    </form>
                </template>
            </section>
        </div>
    </FoundationShell>
</template>
