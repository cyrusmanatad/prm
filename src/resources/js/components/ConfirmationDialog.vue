<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { confirmation } from '../composables/useConfirmation';
import { Button } from './ui/button';

const dialog = ref<HTMLDialogElement>();
const cancel = ref<HTMLButtonElement>();
const feedback = ref<HTMLElement>();
const pending = ref(false);
const errors = ref<string[]>([]);
const stale = ref(false);
let started = false;
let cleanup: (() => void)[] = [];

function close(): void {
    if (pending.value) return;
    const trigger = confirmation.value?.trigger;
    dialog.value?.close();
    confirmation.value = null;
    nextTick(() => {
        if (trigger?.isConnected && !trigger.closest('[inert]')) trigger.focus();
        // A successful deletion can remove the trigger (and its lead drawer).
        if (!document.activeElement || document.activeElement === document.body) {
            const target = document.querySelector<HTMLElement>('main h1, h1');
            target?.setAttribute('tabindex', '-1');
            target?.focus();
        }
    });
}
function submit(): void {
    if (pending.value || stale.value || !confirmation.value) return;
    pending.value = true;
    started = false;
    errors.value = [];
    try {
        confirmation.value.action();
        // Existing guards may decline a request. Leave the dialog cancellable.
        if (!started) {
            pending.value = false;
            errors.value = ['This action is unavailable. Cancel and check the latest record and required fields.'];
        }
    } catch {
        pending.value = false;
        errors.value = ['Unable to submit. Cancel and try again.'];
    }
}
function containFocus(event: KeyboardEvent): void {
    if (event.key !== 'Tab') return;
    const controls = [...(dialog.value?.querySelectorAll<HTMLElement>('button:not(:disabled), [tabindex="0"]') ?? [])];
    const first = controls[0];
    const last = controls.at(-1);
    if (
        !first ||
        (event.shiftKey && document.activeElement === first) ||
        (!event.shiftKey && document.activeElement === last)
    ) {
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
    }
}
watch(confirmation, async (value) => {
    if (!value) return;
    errors.value = [];
    stale.value = false;
    await nextTick();
    dialog.value?.showModal();
    cancel.value?.focus();
});
onMounted(() => {
    cleanup = [
        router.on('start', () => {
            if (pending.value) started = true;
        }),
        router.on('error', (event) => {
            if (!pending.value) return;
            errors.value = Object.values(event.detail.errors).flat().map(String);
            stale.value = !!event.detail.errors.version;
        }),
        router.on('httpException', (event) => {
            if (!pending.value) return;
            event.preventDefault();
            errors.value = [
                'The server could not complete this action. Cancel and reload the latest record before trying again.',
            ];
            stale.value = true;
        }),
        router.on('networkError', (event) => {
            if (!pending.value) return;
            event.preventDefault();
            errors.value = [
                'The connection was interrupted. Cancel and reload to check whether the action completed before trying again.',
            ];
            stale.value = true;
        }),
        router.on('success', () => {
            if (!pending.value) return;
            pending.value = false;
            close();
        }),
        router.on('finish', async () => {
            if (!pending.value) return;
            pending.value = false;
            if (!errors.value.length) errors.value = ['The request did not complete. Cancel or try again.'];
            await nextTick();
            feedback.value?.focus();
        }),
    ];
});
onUnmounted(() => cleanup.forEach((off) => off()));
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="wd-card wd-confirmation"
            aria-labelledby="confirmation-title"
            aria-describedby="confirmation-description"
            @cancel.prevent="close"
            @keydown="containFocus"
        >
            <form v-if="confirmation" class="grid gap-4" :aria-busy="pending" @submit.prevent="submit">
                <h2 id="confirmation-title">{{ confirmation.title }}?</h2>
                <p id="confirmation-description">{{ confirmation.description }}</p>
                <div v-if="errors.length" ref="feedback" role="alert" tabindex="-1" class="wd-field-error">
                    <p v-for="error in errors" :key="error">{{ error }}</p>
                    <p v-if="stale">Cancel and reload the latest record before trying again.</p>
                    <p v-else>Cancel to update any required fields before trying again.</p>
                </div>
                <div class="flex flex-wrap justify-end gap-3">
                    <button
                        ref="cancel"
                        type="button"
                        class="wd-button wd-button-secondary"
                        :disabled="pending"
                        autofocus
                        @click="close"
                    >
                        Cancel
                    </button>
                    <Button type="submit" class="wd-button wd-button-primary" :disabled="pending || stale">{{
                        pending ? 'Working…' : confirmation.title
                    }}</Button>
                </div>
            </form>
        </dialog>
    </Teleport>
</template>

<style scoped>
.wd-confirmation {
    position: fixed;
    inset: 0;
    margin: auto;
    width: min(520px, calc(100% - 32px));
    max-height: calc(100dvh - 32px);
    overflow-y: auto;
    color: var(--wd-ink);
    background: var(--wd-surface, #fff);
}
.wd-confirmation::backdrop {
    background: rgb(0 0 0 / 45%);
}
</style>
