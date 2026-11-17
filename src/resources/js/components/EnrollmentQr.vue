<script setup lang="ts">
import { computed } from 'vue';
import { encode } from 'uqr';

// The code is drawn here, in the browser. The value must never be sent to an image service.
const props = defineProps<{ value: string; label: string }>();
const code = computed(() => {
    const { data, size } = encode(props.value, { ecc: 'M', border: 4 });
    const path = data.flatMap((row, y) => row.map((dark, x) => (dark ? `M${x} ${y}h1v1h-1z` : ''))).join('');
    return { path, size };
});
</script>

<template>
    <svg
        class="wd-enrollment-qr"
        role="img"
        :aria-label="label"
        :viewBox="`0 0 ${code.size} ${code.size}`"
        shape-rendering="crispEdges"
    >
        <rect :width="code.size" :height="code.size" fill="#fff" />
        <path :d="code.path" fill="#000" />
    </svg>
</template>
