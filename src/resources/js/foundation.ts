// The Inertia page adapters will consume these tested view components.
// No custom router or alternative session client is installed here.
import '../css/foundation.css';

export { default as AuthScreen } from './features/auth/AuthScreen.vue';
export { default as WorkspaceScreen } from './features/workspaces/WorkspaceScreen.vue';
export type * from './types/foundation';
