<script setup lang="ts">
/** Marks a planned feature. Assistive technology hears ", coming soon" after the feature's name. */
import { SOON_TEXT } from '../features/portals/portalNavigation';
</script>

<template>
    <span class="wd-soon-pill"><span class="wd-sr-only">, coming </span>{{ SOON_TEXT }}</span>
</template>
