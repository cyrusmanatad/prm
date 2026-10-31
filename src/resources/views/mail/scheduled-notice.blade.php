@if ($kind === 'schedule.reminder')
Your scheduled reminder is ready in WeavDash.
@else
Your weekly review is ready in WeavDash.
Due or overdue reminders: {{ $summary['dueReminders'] }}
Onboarding forms awaiting review: {{ $summary['onboardingReviews'] }}
Counts reflect the workspace at send reservation. Open the app for current records.
@endif

Scheduled for {{ $dueAt }} ({{ $timezone }}).

Sign in and select workspace {{ $workspaceId }} before opening these pages. Each page shows current records for your selected workspace.
{{ $origin }}/workspaces

Reminders:
{{ $origin }}/partner/contacts
@if ($kind === 'schedule.weekly_digest')

Onboarding review:
{{ $origin }}/partner/onboarding
@endif

Manage optional notices or withdraw schedule consent:
{{ $origin }}/automation

This notice was requested for your verified account. It contains no private record details. Changes to a reminder cancel its old scheduled notice; save a new schedule if needed. Delayed weekly reviews are combined into one notice.
