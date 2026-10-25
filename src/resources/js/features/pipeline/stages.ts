// The five main columns retain the approved portal composition. Supplemental
// stages keep their stored meaning and are never folded into Active.
export const pipelineStages = [
    { value: 'lead', label: 'Lead', dot: 'var(--wd-stage-lead)' },
    { value: 'pitched', label: 'Pitched', dot: 'var(--wd-stage-pitched)' },
    { value: 'negotiating', label: 'Negotiating', dot: 'var(--wd-stage-negotiating)' },
    { value: 'onboarded', label: 'Onboarded', dot: 'var(--wd-stage-onboarded)' },
    { value: 'active', label: 'Active', dot: 'var(--wd-brand)' },
    { value: 'repeat-client', label: 'Repeat Client', dot: 'var(--wd-brand)' },
    { value: 'lost', label: 'Lost', dot: 'var(--wd-muted)' },
] as const;
