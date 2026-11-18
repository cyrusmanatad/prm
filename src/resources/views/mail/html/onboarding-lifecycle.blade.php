<x-mail::message>
@if ($kind === 'onboarding.submitted')
# An onboarding form is ready for review

A form was submitted for your review in WeavDash.

Sign in and select the workspace for this request before opening the review page.

<x-mail::button :url="$returnUrl">
Review submission
</x-mail::button>
@else
@if ($kind === 'onboarding.corrected')
# Your onboarding form needs a correction

A correction was requested for your WeavDash onboarding form. Open the form to read the request and update your submission.
@else
# Your onboarding submission was accepted

Your WeavDash onboarding submission was accepted. Open the form to view its status. The accepted form is read-only.
@endif

<x-mail::button :url="$returnUrl">
Open invitation
</x-mail::button>

<x-mail::details :rows="['Link expires' => $expiresAt, 'Link use' => 'One time']" />

<x-mail::panel>
Anyone with the link can access this form; keep it private.
</x-mail::panel>

Opening this new private link creates a session for up to 24 hours in that browser, ending sooner if the invitation expires. Replacing or revoking the invitation ends access. A newer review or edit may invalidate an unopened link.
@endif

<x-slot:subcopy>
If the button does not work, copy and paste this link into your browser:

<x-mail::link :url="$returnUrl" />

This transactional notice contains no submitted form details or attachments.
</x-slot:subcopy>
</x-mail::message>
