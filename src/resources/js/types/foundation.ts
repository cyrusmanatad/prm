export type WorkspaceRole = 'owner' | 'admin' | 'manager' | 'member' | 'viewer';

export interface WorkspaceSummary {
    id: string;
    name: string;
    role: WorkspaceRole;
}

export interface AccountSummary {
    name: string;
    email: string;
}

export type FieldErrors = Partial<Record<string, string>>;
export type AuthMode = 'login' | 'register' | 'forgot-password' | 'reset-password' | 'verify-email';
export type AuthSubmission =
    | { mode: 'login'; email: string; password: string }
    | { mode: 'register'; name: string; email: string; password: string; password_confirmation: string }
    | { mode: 'forgot-password'; email: string }
    | { mode: 'reset-password'; email: string; password: string; password_confirmation: string };
export type WorkspaceAction = 'create' | 'select' | 'rename' | 'revoke-sessions';
