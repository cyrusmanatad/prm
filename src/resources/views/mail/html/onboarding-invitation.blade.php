<x-mail::message>
# You're invited to complete an onboarding form

You have been invited to complete a private onboarding form on WeavDash.

Select the button below, then choose **Open invitation** to start. No account is required.

<x-mail::button :url="$invitationUrl">
Open invitation
</x-mail::button>

<x-mail::details :rows="['Link expires' => $expiresAt, 'Link use' => 'One time']" />

<x-mail::panel>
Anyone with the link can open the form, so keep it private and do not forward it.
</x-mail::panel>

Opening the invitation creates a session in that browser for up to 24 hours, limited by the invitation expiry. Save the form page to return in the same browser. If access expires, ask the sender for a replacement invitation.

The sender can revoke or replace the invitation, ending previous access. You choose when to submit your business/contact details and reference files for review.

<x-slot:subcopy>
If the button does not work, copy and paste this link into your browser:

<x-mail::link :url="$invitationUrl" />

If you did not expect this invitation, you can ignore this email.
</x-slot:subcopy>
</x-mail::message>
