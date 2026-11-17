<script setup lang="ts">
import { Copy } from '@lucide/vue';
import { useNotify } from '../composables/useNotify';

const props = defineProps<{ value: string; label: string }>();
const notify = useNotify();
function copy() {
    const task = navigator.clipboard
        ? navigator.clipboard.writeText(props.value)
        : Promise.reject(new Error('Clipboard unavailable'));
    void notify
        .promise(
            task,
            {
                pending: `Copying ${props.label}…`,
                success: `Copied ${props.label}`,
                error: `Couldn't copy ${props.label}. Select the text and copy it manually.`,
            },
            `copy-${props.label}`,
        )
        .catch(() => undefined);
}
</script>

<template>
    <span class="wd-copy-reference"
        ><code>{{ value }}</code
        ><button type="button" :aria-label="`Copy ${label}`" @click="copy">
            <Copy :size="16" aria-hidden="true" focusable="false" /></button
    ></span>
</template>
