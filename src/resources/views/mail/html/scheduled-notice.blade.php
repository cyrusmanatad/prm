<x-mail::message>
@if ($kind === 'schedule.reminder')
# Your scheduled reminder is ready

Your scheduled reminder is ready in WeavDash.
@else
# Your weekly review is ready

Your weekly review is ready in WeavDash.

<x-mail::panel>
Due or overdue reminders: {{ $summary['dueReminders'] }}<br>
Onboarding forms awaiting review: {{ $summary['onboardingReviews'] }}
</x-mail::panel>

Counts reflect the workspace at send reservation. Open the app for current records.
@endif

<x-mail::details :rows="['Scheduled for' => $dueAt.' ('.$timezone.')', 'Workspace' => $workspaceId]" />

Sign in and select this workspace before opening these pages. Each page shows current records for your selected workspace.

<x-mail::button :url="$origin.'/workspaces'">
Select workspace
</x-mail::button>

Then open:

- [Reminders]({{ $origin }}/partner/contacts)
@if ($kind === 'schedule.weekly_digest')
- [Onboarding review]({{ $origin }}/partner/onboarding)
@endif

<x-slot:subcopy>
This notice was requested for your verified account. It contains no private record details. Changes to a reminder cancel its old scheduled notice; save a new schedule if needed. Delayed weekly reviews are combined into one notice.

[Manage optional notices or withdraw schedule consent]({{ $origin }}/automation)
</x-slot:subcopy>
</x-mail::message>
