<script setup lang="ts">
/** Approved awards against a cap. Without a cap it states the count and that there is no limit. */
const props = defineProps<{ used: number; cap: number | null; label: string }>();
const width = () => `${Math.min(100, (props.used / (props.cap || 1)) * 100)}%`;
</script>

<template>
    <div v-if="cap" class="ic-meter">
        <div class="ic-meter-text">
            <span>{{ label }}</span
            ><strong>{{ used }} of {{ cap }}</strong>
        </div>
        <div
            class="ic-meter-bar"
            role="progressbar"
            :aria-label="label"
            aria-valuemin="0"
            :aria-valuemax="cap"
            :aria-valuenow="used"
        >
            <span :style="{ width: width() }"></span>
        </div>
    </div>
    <p v-else class="ic-small ic-muted">{{ used }} {{ used === 1 ? 'award' : 'awards' }} approved · no limit</p>
</template>
