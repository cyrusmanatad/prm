<x-mail::message>
@if ($kind === 'brand_request.approved')
# Your Brand workspace request was approved

Your request for a Brand workspace on WeavDash was approved. The workspace is ready and you are its owner.
@elseif ($kind === 'brand_request.needs_information')
# Your Brand workspace request needs more information

The reviewer needs more information before deciding on your request for a Brand workspace on WeavDash. You can read what is needed and update your request.
@else
# Your Brand workspace request was reviewed

Your request for a Brand workspace on WeavDash was reviewed and was not approved. You can read the reviewer's explanation, and you may submit a new request.
@endif

<x-mail::button :url="$requestUrl">
View request
</x-mail::button>

Sign in to view your request. This link opens only for the account that made the request.

<x-slot:subcopy>
If the button does not work, copy and paste this link into your browser:

<x-mail::link :url="$requestUrl" />

This transactional notice contains none of the information you submitted and none of the reviewer's comments.
</x-slot:subcopy>
</x-mail::message>
