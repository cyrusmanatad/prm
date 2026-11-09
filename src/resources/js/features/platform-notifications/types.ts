export type DeliveryNotice = {
    id: string;
    org_id: string;
    kind: string;
    state: string;
    created_at: string;
    available_at: string;
    failure_code: string | null;
    resolution: string | null;
    resolution_version: number;
};
export type DeliveryFilters = { workspace: string; state: string; page: number };
export const resolutionOptions = [
    { value: 'transport_accepted', label: 'Provider confirms transport acceptance' },
    { value: 'not_sent', label: 'Provider confirms no send' },
    { value: 'unknown_closed', label: 'Close with outcome still unknown' },
];
