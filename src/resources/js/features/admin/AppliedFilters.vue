<script setup lang="ts">
/** What the server applied, never an unsent edit, with Clear filters; announces a request in progress. */
defineProps<{ applied: { label: string; value: string }[]; pending?: boolean }>();
const emit = defineEmits<{ clear: [] }>();
</script>

<template>
    <div v-if="pending || applied.length" class="adm-status">
        <p role="status">{{ pending ? 'Updating results…' : 'Filters applied:' }}</p>
        <ul v-if="applied.length && !pending" aria-label="Applied filters">
            <li v-for="item in applied" :key="item.label">{{ item.label }}: {{ item.value }}</li>
        </ul>
        <button v-if="applied.length" type="button" class="wd-link" :disabled="pending" @click="emit('clear')">
            Clear filters
        </button>
    </div>
</template>
