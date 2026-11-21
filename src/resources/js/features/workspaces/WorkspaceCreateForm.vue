<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Button } from '../../components/ui/button';
import FormField from '../../components/FormField.vue';

type Kind = 'creator' | 'professional' | 'brand';

const props = withDefaults(defineProps<{ busy: boolean; pending: boolean; error?: string; inDialog?: boolean }>(), {
    error: undefined,
    inDialog: false,
});
const emit = defineEmits<{
    create: [name: string, kind: 'creator', experience: 'partner' | 'professional'];
    cancel: [];
}>();
const kinds: { value: Kind; label: string; hint: string }[] = [
    { value: 'creator', label: 'Partner', hint: 'Creator leads and Brand sharing' },
    { value: 'professional', label: 'Professional', hint: 'Independent client work' },
    { value: 'brand', label: 'Brand', hint: 'Reviewed before it is created' },
];
const kind = ref<Kind>('creator');
const name = ref('');

function submit(): void {
    // Brand workspaces are requested for review; the form offers a link instead of a create action.
    if (props.busy || kind.value === 'brand') return;
    emit('create', name.value.trim(), 'creator', kind.value === 'professional' ? 'professional' : 'partner');
}
defineExpose({
    clear: () => {
        name.value = '';
    },
});
</script>

<template>
    <form class="ws-create" :aria-busy="pending" @submit.prevent="submit">
        <fieldset class="wd-fields" :disabled="busy">
            <legend class="wd-sr-only">Create a workspace</legend>
            <fieldset class="ws-kinds">
                <legend>Workspace type</legend>
                <label v-for="option in kinds" :key="option.value" class="ws-kind">
                    <input v-model="kind" type="radio" name="workspace-kind" :value="option.value" />
                    <span
                        ><strong>{{ option.label }}</strong
                        ><small>{{ option.hint }}</small></span
                    >
                </label>
            </fieldset>
            <template v-if="kind === 'brand'">
                <p class="wd-hint">
                    Brand workspaces are reviewed before they are created. Tell us about the business and we will email
                    you when there is a decision. You can follow the status of your request at any time.
                </p>
                <div :class="inDialog ? 'wd-dialog-footer' : 'ws-create-actions'">
                    <button v-if="inDialog" type="button" class="wd-button wd-button-secondary" @click="emit('cancel')">
                        Cancel
                    </button>
                    <Link href="/brand/requests" class="wd-button wd-button-primary">Request Brand workspace</Link>
                </div>
            </template>
            <template v-else>
                <FormField
                    id="workspace_name"
                    v-model="name"
                    label="Workspace name"
                    autocomplete="organization"
                    :maxlength="120"
                    :hint="`${name.length} / 120`"
                    :error="error"
                />
                <div :class="inDialog ? 'wd-dialog-footer' : 'ws-create-actions'">
                    <button v-if="inDialog" type="button" class="wd-button wd-button-secondary" @click="emit('cancel')">
                        Cancel
                    </button>
                    <Button type="submit" class="wd-button wd-button-primary">{{
                        pending ? 'Creating…' : 'Create workspace'
                    }}</Button>
                </div>
            </template>
        </fieldset>
    </form>
</template>

<style scoped>
.ws-kinds {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    margin: 0;
    padding: 0;
    border: 0;
}
.ws-kinds legend {
    margin-bottom: 6px;
    padding: 0;
    font-size: 13px;
    font-weight: 700;
}
.ws-kind {
    position: relative;
    display: flex;
    gap: 8px;
    align-items: flex-start;
    min-height: 44px;
    padding: 9px 10px;
    border: 1px solid var(--wd-control-line);
    border-radius: 10px;
    background: var(--wd-surface);
    cursor: pointer;
}
.ws-kind:has(input:checked) {
    border-color: var(--wd-brand);
    box-shadow: 0 0 0 1px var(--wd-brand);
}
.ws-kind:has(input:focus-visible) {
    outline: 3px solid var(--wd-focus);
    outline-offset: 2px;
}
.ws-kind input {
    margin: 2px 0 0;
    accent-color: var(--wd-brand);
}
.ws-kind span {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.ws-kind strong {
    font-size: 13px;
}
.ws-kind small {
    color: var(--wd-muted);
    font-size: 12px;
    line-height: 1.35;
}
.ws-create-actions {
    display: flex;
    gap: 8px;
}
@media (max-width: 599px) {
    .ws-kinds {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
