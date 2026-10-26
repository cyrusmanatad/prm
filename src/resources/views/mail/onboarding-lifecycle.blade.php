@if ($kind === 'onboarding.submitted')
A form was submitted for your review in WeavDash.

Sign in and select the workspace for this request before opening the review page:
{{ $returnUrl }}
@else
@if ($kind === 'onboarding.corrected')
A correction was requested for your WeavDash onboarding form. Open the form to read the request and update your submission.
@else
Your WeavDash onboarding submission was accepted. Open the form to view its status. The accepted form is read-only.
@endif

Open invitation:
{{ $returnUrl }}

This new private link can be opened once, until {{ $expiresAt }}. Opening it creates a session for up to 24 hours in that browser, ending sooner if the invitation expires. Anyone with the link can access this form; keep it private. Replacing or revoking the invitation ends access. A newer review or edit may invalidate an unopened link.
@endif

This transactional notice contains no submitted form details or attachments.
