@if ($review)
A private follow-up draft is ready for you to review in WeavDash.

Sign in and choose the workspace where you created the draft:
{{ $origin }}/automation

Review the recipient and full message before approving. Approval does not send email; sending requires a separate explicit action.
@else
{!! $messageBody !!}
@endif
