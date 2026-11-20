import { ref } from 'vue';

/**
 * The list a phone shows, kept for the browser session so paging and Back/Forward, which
 * render the page again, return to the same list. A full page load starts from the URL.
 */
export const crmPanel = ref<'contacts' | 'reminders' | null>(null);
