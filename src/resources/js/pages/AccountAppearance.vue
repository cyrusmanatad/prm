<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import FoundationShell from '../layouts/FoundationShell.vue';
import ContentSection from '../components/ContentSection.vue';
import ActionGroup from '../components/ActionGroup.vue';
import { Button } from '../components/ui/button';
import { useValidationFocus } from '../composables/useValidationFocus';
import { appearances, normalizeAppearance } from '../composables/useAppearance';
import type { AccountSummary } from '../types/foundation';

const props = defineProps<{ appearance: string }>();
const account = computed(() => usePage<{ account?: AccountSummary | null }>().props.account);
const saved = computed(() => normalizeAppearance(props.appearance));
const form = useForm({ appearance: saved.value });
const root = ref<HTMLElement>();
const errors = computed(() => form.errors as Record<string, string>);
useValidationFocus(
    root,
    () => errors.value,
    () => !form.processing,
);

function save(): void {
    if (form.processing) return;
    // The document root changes only when the server returns the saved value.
    form.post('/account/appearance', {
        preserveScroll: true,
        onSuccess: () => form.defaults({ appearance: saved.value }),
    });
}
</script>

<template>
    <Head title="Appearance" />
    <FoundationShell :account-name="account?.name" :account-email="account?.email" @logout="router.post('/logout')">
        <div ref="root" class="wd-workspace-content space-y-6">
            <header class="wd-page-heading">
                <span class="wd-eyebrow">Your account</span>
                <h1>Appearance</h1>
                <p class="wd-description">
                    Choose the accent color you see in WeavDash. It follows your account on every device and workspace,
                    and does not change what other people see.
                </p>
                <Link href="/workspaces" class="wd-link">Back to workspaces</Link>
            </header>
            <ContentSection
                title="Accent color"
                description="Buttons, links, current navigation and highlights use this color."
            >
                <form :aria-busy="form.processing" @submit.prevent="save">
                    <fieldset class="wd-fields" :disabled="form.processing">
                        <fieldset
                            class="ap-options"
                            :aria-invalid="form.errors.appearance ? 'true' : undefined"
                            :aria-describedby="form.errors.appearance ? 'appearance-error' : undefined"
                        >
                            <legend>Accent color</legend>
                            <label
                                v-for="option in appearances"
                                :key="option.value"
                                class="ap-option"
                                :class="`ap-option-${option.value}`"
                            >
                                <input
                                    v-model="form.appearance"
                                    type="radio"
                                    name="appearance"
                                    :value="option.value"
                                    :aria-describedby="`appearance-${option.value}-description`"
                                />
                                <span class="ap-swatch" aria-hidden="true"
                                    ><span class="ap-swatch-main" /><span class="ap-swatch-soft"
                                /></span>
                                <span class="ap-text">
                                    <strong
                                        >{{ option.label
                                        }}<span v-if="saved === option.value" class="ap-current">Current</span></strong
                                    >
                                    <small :id="`appearance-${option.value}-description`">{{
                                        option.description
                                    }}</small>
                                </span>
                            </label>
                        </fieldset>
                        <p v-if="form.errors.appearance" id="appearance-error" class="wd-field-error" role="alert">
                            {{ form.errors.appearance }}
                        </p>
                        <ActionGroup>
                            <Button type="submit" class="wd-button wd-button-primary">{{
                                form.processing ? 'Saving…' : 'Save appearance'
                            }}</Button>
                        </ActionGroup>
                    </fieldset>
                </form>
            </ContentSection>
        </div>
    </FoundationShell>
</template>

<style scoped>
.ap-options {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    max-width: 640px;
    margin: 0;
    padding: 0;
    border: 0;
}
.ap-options legend {
    margin-bottom: 6px;
    padding: 0;
    font-size: 13px;
    font-weight: 700;
}
.ap-option {
    display: flex;
    gap: 12px;
    align-items: center;
    min-height: 64px;
    padding: 12px 14px;
    border: 1px solid var(--wd-control-line);
    border-radius: 10px;
    background: var(--wd-surface);
    cursor: pointer;
}
/* Selection shows as the checked radio and a heavier outline, not as color alone. */
.ap-option:has(input:checked) {
    border-color: var(--wd-ink);
    box-shadow: 0 0 0 1px var(--wd-ink);
}
.ap-option:has(input:focus-visible) {
    outline: 3px solid var(--wd-focus);
    outline-offset: 2px;
}
.ap-option input {
    flex: none;
    width: 18px;
    height: 18px;
    margin: 0;
    accent-color: var(--wd-ink);
}
/* Each swatch shows its own palette, whichever accent is currently applied. */
.ap-option-burgundy {
    --ap-main: #8e1650;
    --ap-soft: #f6e3ec;
}
.ap-option-purple {
    --ap-main: #641d73;
    --ap-soft: #ede1f2;
}
.ap-swatch {
    display: inline-flex;
    flex: none;
    overflow: hidden;
    width: 48px;
    height: 32px;
    border: 1px solid var(--wd-line);
    border-radius: 8px;
}
.ap-swatch-main {
    flex: 2;
    background: var(--ap-main);
}
.ap-swatch-soft {
    flex: 1;
    background: var(--ap-soft);
}
.ap-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.ap-text strong {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    font-size: 14px;
}
.ap-text small {
    color: var(--wd-muted);
    font-size: 12px;
}
.ap-current {
    display: inline-flex;
    align-items: center;
    height: 20px;
    padding: 0 7px;
    border-radius: 999px;
    background: var(--wd-success-bg);
    color: var(--wd-success);
    font-size: 11.5px;
    font-weight: 700;
}
@media (max-width: 599px) {
    .ap-options {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
