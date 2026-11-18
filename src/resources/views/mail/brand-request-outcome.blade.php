@if ($kind === 'brand_request.approved')
Your request for a Brand workspace on WeavDash was approved. The workspace is ready and you are its owner.
@elseif ($kind === 'brand_request.needs_information')
The reviewer needs more information before deciding on your request for a Brand workspace on WeavDash. You can read what is needed and update your request.
@else
Your request for a Brand workspace on WeavDash was reviewed and was not approved. You can read the reviewer's explanation, and you may submit a new request.
@endif

Sign in to view your request:
{{ $requestUrl }}

This link opens only for the account that made the request. This transactional notice contains none of the information you submitted and none of the reviewer's comments.
