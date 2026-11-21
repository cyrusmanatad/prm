<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { confirmAction } from '../composables/useConfirmation';
import type { AccountSecurityStatus } from '../types/foundation';
import FormField from './FormField.vue';
import StatusBadge from './StatusBadge.vue';
import { Button } from './ui/button';

const props = defineProps<{
    accountName: string;
    busy: boolean;
    pending: boolean;
    error?: string;
    security: AccountSecurityStatus;
}>();
const password = defineModel<string>({ required: true });
const emit = defineEmits<{ revoke: [] }>();
const authenticator = computed(() =>
    props.security.enrolled
        ? {
              label: 'Set up',
              tone: 'success' as const,
              text: 'Your authenticator protects sign-in. Replace it or create new recovery codes here.',
              action: 'Manage authenticator',
          }
        : props.security.required
          ? {
                label: 'Not set up',
                tone: 'warning' as const,
                text: 'Required because you own a workspace.',
                action: 'Set up authenticator',
            }
          : {
                label: 'Optional',
                tone: 'neutral' as const,
                text: 'Add an authenticator app for a second sign-in step.',
                action: 'Set up authenticator',
            },
);
</script>

<template>
    <div class="ws-security">
        <section class="ws-security-card" aria-labelledby="security-authenticator-title">
            <header>
                <h2 id="security-authenticator-title">Authenticator and recovery codes</h2>
                <StatusBadge :label="authenticator.label" :tone="authenticator.tone" />
            </header>
            <p>{{ authenticator.text }}</p>
            <Link
                href="/account/authenticator"
                class="wd-table-button"
                :class="{ 'ws-primary': !security.enrolled && security.required }"
                >{{ authenticator.action }}</Link
            >
        </section>
        <section class="ws-security-card" aria-labelledby="security-sessions-title">
            <header><h2 id="security-sessions-title">Sign out all sessions</h2></header>
            <p>Signs out every device, including this one. Enter your current password to continue.</p>
            <form
                class="ws-revoke"
                :aria-busy="pending"
                @submit.prevent="
                    confirmAction(
                        'Sign out all sessions',
                        `Sign out every session for ${accountName}, including this one? You will need to sign in again.`,
                        () => emit('revoke'),
                        $event,
                        { tone: 'danger' },
                    )
                "
            >
                <fieldset class="wd-fields" :disabled="busy">
                    <legend class="wd-sr-only">Revoke account sessions</legend>
                    <FormField
                        id="revoke_password"
                        v-model="password"
                        label="Current password"
                        type="password"
                        autocomplete="current-password"
                        :error="error"
                    />
                    <Button type="submit" class="wd-button wd-button-danger">{{
                        pending ? 'Signing out…' : 'Sign out all sessions'
                    }}</Button>
                </fieldset>
            </form>
        </section>
        <section class="ws-security-card ws-security-link" aria-labelledby="security-exports-title">
            <header>
                <h2 id="security-exports-title">
                    <Link href="/account/exports">Download your data</Link>
                </h2>
                <ChevronRight :size="16" aria-hidden="true" />
            </header>
            <p>Private account and workspace exports.</p>
        </section>
        <section class="ws-security-card ws-security-link" aria-labelledby="security-closure-title">
            <header>
                <h2 id="security-closure-title">
                    <Link href="/account/closure">Account and workspace closure</Link>
                </h2>
                <ChevronRight :size="16" aria-hidden="true" />
            </header>
            <p>Close your account or a workspace you own.</p>
        </section>
    </div>
</template>

<style scoped>
.ws-security {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
    gap: 12px;
}
.ws-security-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    min-width: 0;
    padding: 14px;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
}
.ws-security-card header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
}
.ws-security-card header :deep(.wd-badge) {
    flex: none;
    white-space: nowrap;
}
.ws-security-card h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 15px;
    font-weight: 600;
}
.ws-security-card p {
    margin: 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.ws-security-card .wd-fields {
    gap: 10px;
}
.ws-revoke {
    width: 100%;
}
.ws-revoke .wd-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
}
.ws-primary {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.ws-security .ws-primary:hover {
    border-color: var(--wd-brand-hover);
    background: var(--wd-brand-hover);
    color: #fff;
}
.ws-security-link a {
    color: var(--wd-ink);
    text-decoration: none;
}
/* The whole card opens the link; the link keeps its own accessible name and focus ring. */
.ws-security-link a::after {
    position: absolute;
    inset: 0;
    border-radius: 12px;
    content: '';
}
.ws-security-link:hover {
    background: var(--wd-paper);
}
.ws-security-link:hover a {
    color: var(--wd-brand);
}
.ws-security-link a:focus-visible {
    outline: none;
}
.ws-security-link:has(a:focus-visible) {
    outline: 3px solid var(--wd-focus);
    outline-offset: 2px;
}
.ws-security-link svg {
    color: var(--wd-muted);
}
@media (min-width: 600px) and (pointer: fine) {
    .ws-security {
        --wd-table-control: 32px;
    }
}
@media (max-width: 899px) {
    .ws-security {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 599px) {
    .ws-revoke .wd-fields {
        grid-template-columns: minmax(0, 1fr);
    }
    .ws-security-link {
        min-height: 44px;
    }
}
</style>
