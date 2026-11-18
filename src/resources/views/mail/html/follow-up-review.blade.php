<x-mail::message>
# A follow-up draft is ready for your review

A private follow-up draft is ready for you to review in WeavDash.

Sign in and choose the workspace where you created the draft.

<x-mail::button :url="$origin.'/automation'">
Review draft
</x-mail::button>

<x-mail::panel>
Review the recipient and full message before approving. Approval does not send email; sending requires a separate explicit action.
</x-mail::panel>

<x-slot:subcopy>
If the button does not work, copy and paste this link into your browser:

<x-mail::link :url="$origin.'/automation'" />
</x-slot:subcopy>
</x-mail::message>
