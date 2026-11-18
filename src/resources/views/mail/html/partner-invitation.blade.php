<x-mail::message>
# You're invited to connect with a brand

You have been invited to connect a creator workspace with a brand on WeavDash.

Sign in with this recipient email, choose a creator workspace you own or administer, and explicitly accept the partnership.

<x-mail::button :url="$invitationUrl">
Review invitation
</x-mail::button>

<x-mail::details :rows="['Invitation expires' => $expiresAt]" />

This private invitation can be revoked earlier by the inviting brand. Only the verified recipient can accept it.

<x-mail::panel>
Acceptance shares your workspace display name and relationship status. Your private CRM and financial records stay private.
</x-mail::panel>

<x-slot:subcopy>
If the button does not work, copy and paste this link into your browser:

<x-mail::link :url="$invitationUrl" />

If you did not expect this invitation, you can ignore this email. Keep the link private.
</x-slot:subcopy>
</x-mail::message>
