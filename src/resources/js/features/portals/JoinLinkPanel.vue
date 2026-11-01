<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirmAction } from '../../composables/useConfirmation';
import { useNotify } from '../../composables/useNotify';
import type { JoinLink } from './types';

const props = defineProps<{ joinLink: JoinLink | null }>();
const notify = useNotify();
const processing = ref(false);
// Preserve state so the invitation dialog stays open with the refreshed link.
const visit = {
    preserveScroll: true,
    preserveState: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function copy(text: string, label: 'link' | 'code'): void {
    const writing = navigator.clipboard?.writeText(text) ?? Promise.reject(new Error('Clipboard unavailable'));
    void notify.promise(
        writing,
        {
            pending: `Copying sign-up ${label}`,
            success: `Sign-up ${label} copied`,
            error: `Couldn't copy the sign-up ${label}. Select it and copy it manually.`,
        },
        'join-link-copy',
    );
}
function issue(): void {
    if (!processing.value) router.post('/partner-join-link', {}, visit);
}
// Replacing or turning off the link revokes access for anyone holding the current link or code.
function replace(event: Event): void {
    confirmAction(
        'Replace sign-up link',
        `Replace sign-up code ${props.joinLink?.code}? Anyone holding the current link or code can no longer join. Existing partnerships stay active.`,
        issue,
        event,
        { tone: 'danger' },
    );
}
function turnOff(event: Event): void {
    confirmAction(
        'Turn off sign-up link',
        `Turn off sign-up code ${props.joinLink?.code}? Potential partners can no longer join with this link or code. Existing partnerships stay active.`,
        () => router.delete('/partner-join-link', visit),
        event,
        { tone: 'danger' },
    );
}
</script>

<template>
    <section class="grid gap-3 border-t border-[var(--wd-line)] pt-4" aria-labelledby="join-link-title">
        <h3 id="join-link-title" class="font-display text-[16px] font-bold">Share a sign-up link</h3>
        <p class="wd-hint">
            Anyone with this link or code can create an account, or sign in, and join as a partner right away. Share it
            only where you want new partners to find you.
        </p>
        <template v-if="joinLink">
            <div class="wd-field">
                <label for="join-link-url">Sign-up link</label>
                <input
                    id="join-link-url"
                    :value="joinLink.url"
                    readonly
                    @focus="($event.target as HTMLInputElement).select()"
                />
            </div>
            <div class="wd-field">
                <label for="join-link-code">Sign-up code</label>
                <input
                    id="join-link-code"
                    :value="joinLink.code"
                    readonly
                    aria-describedby="join-link-code-hint"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <p id="join-link-code-hint" class="wd-hint">
                    Partners can also enter this code at {{ joinLink.url.split('#')[0] }}.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="wd-button wd-button-secondary" @click="copy(joinLink.url, 'link')">
                    Copy link
                </button>
                <button type="button" class="wd-button wd-button-secondary" @click="copy(joinLink.code, 'code')">
                    Copy code
                </button>
                <button type="button" class="wd-action-danger" :disabled="processing" @click="replace">
                    Replace link
                </button>
                <button type="button" class="wd-action-danger" :disabled="processing" @click="turnOff">Turn off</button>
            </div>
        </template>
        <div v-else>
            <button type="button" class="wd-button wd-button-secondary" :disabled="processing" @click="issue">
                Create sign-up link
            </button>
        </div>
    </section>
</template>
