/** Readable timestamp that always states its zone; details can show the exact ISO value. */
export function dateTime(value: string | null | undefined, missing = 'Not recorded'): string {
    if (!value) return missing;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return missing;
    // dateStyle/timeStyle cannot be combined with timeZoneName, so the fields are listed explicitly.
    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZoneName: 'short',
    }).format(date);
}

export function localZone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'local time';
}

/** Convert a datetime-local value entered in the browser zone to the ISO instant the server expects. */
export function localToIso(value: string): string {
    const date = new Date(value);
    return value && !Number.isNaN(date.getTime()) ? date.toISOString() : value;
}

export function query(params: Record<string, string | number | null | undefined>): string {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '' && !(key === 'page' && Number(value) <= 1))
            search.set(key, String(value));
    }
    const text = search.toString();
    return text ? `?${text}` : '';
}

export const notificationStates: Record<
    string,
    { label: string; meaning: string; tone: 'neutral' | 'success' | 'warning' | 'danger' }
> = {
    pending: { label: 'Queued', meaning: 'Durable intent exists; sending has not been established.', tone: 'neutral' },
    sending: {
        label: 'Sending',
        meaning: 'Reserved send in progress; the transport outcome may still be unknown.',
        tone: 'neutral',
    },
    sent: {
        label: 'Accepted by transport',
        meaning: 'The mail transport accepted it. Recipient delivery is not proven.',
        tone: 'success',
    },
    captured: {
        label: 'Captured locally',
        meaning: 'Captured in development. No external email was sent.',
        tone: 'neutral',
    },
    cancelled: { label: 'Cancelled', meaning: 'This intent will not send.', tone: 'neutral' },
    uncertain: {
        label: 'Outcome uncertain',
        meaning: 'It may already have sent. Review is required; it is never retried automatically.',
        tone: 'warning',
    },
};

type Tone = 'neutral' | 'success' | 'warning' | 'danger';

export const entitlementStates: Record<string, { label: string; tone: Tone; meaning: string }> = {
    active: { label: 'Active', tone: 'success', meaning: 'Creates are allowed up to each limit.' },
    scheduled: {
        label: 'Scheduled',
        tone: 'warning',
        meaning: 'Creates are blocked until the start time. The previous assignment is not used meanwhile.',
    },
    expired: {
        label: 'Expired',
        tone: 'danger',
        meaning: 'Creates are blocked. Existing records are kept. Access does not fall back to an older assignment.',
    },
    'unassigned-development': {
        label: 'Unassigned (development use)',
        tone: 'neutral',
        meaning: 'No plan is configured. This is not a free tier and has no limits recorded.',
    },
    'pending-activation': {
        label: 'Pending activation',
        tone: 'warning',
        meaning: 'A Professional workspace waiting for its first assignment.',
    },
    superseded: { label: 'Superseded', tone: 'neutral', meaning: 'Replaced by a newer assignment; kept as history.' },
};

export const capabilityLabels: Record<string, { label: string; grants: string }> = {
    'platform.owner': {
        label: 'Platform owner',
        grants: 'Every capability below, plus managing platform access. Does not unlock private workspace records.',
    },
    'directory.read': { label: 'Directory', grants: 'View account and workspace metadata and usage counts.' },
    'plans.assign': { label: 'Entitlements', grants: 'View plans and record subscription assignments.' },
    'notifications.read': { label: 'Notification diagnostics', grants: 'View delivery state without message content.' },
    'notifications.reconcile': {
        label: 'Notification resolution',
        grants: 'Record a resolution for an uncertain send. Never resends email.',
    },
    'operations.read': { label: 'Jobs and health', grants: 'View failed jobs, queue and scheduler observations.' },
    'audit.read': { label: 'Audit review', grants: 'View the administration audit trail.' },
    'access.manage': {
        label: 'Access management',
        grants: "Suspend and reactivate accounts and workspaces, and sign out an account's sessions.",
    },
    'support.request': {
        label: 'Support access',
        grants: 'Request time-limited, read-only access that a workspace owner must approve.',
    },
    'cleanup.review': {
        label: 'Cleanup review',
        grants: 'Mark synthetic records and run cleanup dry runs. Cannot delete.',
    },
    'brands.review': {
        label: 'Brand review',
        grants: 'Review Brand workspace requests: approve, request more information or decline. Gives no access to workspace records.',
    },
};

/** Capability names in the order the grant form presents them, whatever order they are stored in. */
export function capabilityNames(keys: string[]): string {
    const order = Object.keys(capabilityLabels);
    return [...keys]
        .sort((a, b) => order.indexOf(a) - order.indexOf(b))
        .map((key) => capabilityLabels[key]?.label ?? key)
        .join(', ');
}

export const workspaceTypes: Record<string, string> = { creator: 'Creator', brand: 'Brand' };

export function titleCase(value: string): string {
    return value ? value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' ') : value;
}

/** Whole-unit age such as "4 minutes"; null when nothing was observed. */
export function age(seconds: number | null | undefined): string | null {
    if (seconds === null || seconds === undefined) return null;
    if (seconds < 90) return `${seconds} seconds`;
    if (seconds < 5400) return `${Math.round(seconds / 60)} minutes`;
    if (seconds < 129600) return `${Math.round(seconds / 3600)} hours`;
    return `${Math.round(seconds / 86400)} days`;
}

export const auditSources: Record<string, string> = {
    platform: 'Platform administration',
    'local-pilot': 'Local pilot command',
};
// Platform events record that an action completed or that a request was denied; nothing finer is stored.
export const auditOutcomes: Record<string, { label: string; tone: Tone }> = {
    recorded: { label: 'Completed', tone: 'success' },
    denied: { label: 'Denied', tone: 'danger' },
};

export const supportStates: Record<string, { label: string; tone: Tone }> = {
    requested: { label: 'Awaiting workspace owner', tone: 'warning' },
    approved: { label: 'Active', tone: 'success' },
    declined: { label: 'Declined', tone: 'neutral' },
    revoked: { label: 'Ended', tone: 'neutral' },
    cancelled: { label: 'Withdrawn', tone: 'neutral' },
    expired: { label: 'Expired', tone: 'neutral' },
};
export const supportAreas: Record<string, string> = {
    pipeline_entries: 'Leads',
    brand_contacts: 'Contacts',
    reminders: 'Reminders',
    onboarding_requests: 'Onboarding requests',
    outreach_templates: 'Outreach templates',
    finance: 'Finance (invoice drafts and payment records)',
    invoice_drafts: 'Invoice drafts',
    manual_payments: 'Payment records',
};
export const dispositions: Record<string, { label: string; tone: Tone }> = {
    eligible: { label: 'Eligible', tone: 'success' },
    blocked: { label: 'Blocked', tone: 'danger' },
    protected: { label: 'Protected', tone: 'warning' },
};
