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

/** Plain-language names for recorded administration actions; unknown codes stay readable rather than hidden. */
const auditEvents: Record<string, string> = {
    'account.authenticator_reset': 'Authenticator reset',
    'account.reactivated': 'Account reactivated',
    'account.signed_out': 'Account signed out everywhere',
    'account.suspended': 'Account suspended',
    'brand_request.approved': 'Brand request approved',
    'brand_request.declined': 'Brand request declined',
    'brand_request.information_requested': 'More Brand information requested',
    'cleanup.batch_created': 'Cleanup batch created',
    'cleanup.dry_run': 'Cleanup dry run',
    'cleanup.marked': 'Marked for cleanup',
    'cleanup.unmarked': 'Cleanup mark removed',
    'grant.assigned': 'Platform access granted',
    'grant.assigned.offline': 'Platform access granted offline',
    'grant.revoked': 'Platform access revoked',
    'mfa.enrolled': 'Authenticator enrolled',
    'mfa.verified': 'Authenticator verified',
    'notification.resolved': 'Uncertain send resolved',
    'plan.assigned': 'Plan assigned',
    'request.denied': 'Request denied',
    'support.approved': 'Support access approved',
    'support.cancelled': 'Support request withdrawn',
    'support.declined': 'Support access declined',
    'support.ended': 'Support access ended',
    'support.ended_by_owner': 'Support access ended by owner',
    'support.requested': 'Support access requested',
    'support.viewed': 'Support view opened',
    'workspace.reactivated': 'Workspace reactivated',
    'workspace.suspended': 'Workspace suspended',
};
export function auditEventLabel(event: string): string {
    if (auditEvents[event]) return auditEvents[event];
    const text = event.replaceAll(/[._]+/g, ' ').trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
}

const targetKinds: Record<string, string> = {
    account: 'Account',
    workspace: 'Workspace',
    support: 'Support access',
    brand_request: 'Brand request',
    cleanup: 'Cleanup',
    notification: 'Notification',
};
/** "Workspace · Northline Living" when the name is known; otherwise the kind and a shortened reference. */
export function auditTargetLabel(target: string, kind?: string | null, name?: string | null): string {
    const reference = target.length > 13 ? `${target.slice(0, 8)}…` : target;
    const label = kind ? targetKinds[kind] : undefined;
    if (!label) return target;
    return `${label} · ${name || reference}`;
}

/** Short time for recent lists: minutes ago, then today or yesterday with a time, then the full date. */
export function recentTime(value: string, now: Date = new Date()): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Not recorded';
    const minutes = Math.floor((now.getTime() - date.getTime()) / 60000);
    if (minutes >= 0 && minutes < 1) return 'Just now';
    if (minutes >= 0 && minutes < 60) return `${minutes} min ago`;
    const time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(date);
    const day = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const days = Math.round((day(now) - day(date)) / 86400000);
    if (days === 0) return `Today, ${time}`;
    if (days === 1) return `Yesterday, ${time}`;
    return dateTime(value);
}

/** Calendar date only, for dense directory columns; the full timestamp belongs in a title or detail page. */
export function dateOnly(value: string | null | undefined, missing = 'Not recorded'): string {
    if (!value) return missing;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return missing;
    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
}
