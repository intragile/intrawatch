<x-mail::message>
# You have been invited to {{ $workspaceName }}

{{ $inviterName }} invited you to the **{{ $workspaceName }}** workspace on {{ $appName }} with the **{{ $role }}** role.

To join, sign in with this e-mail address and open the link below. If you do not have an account yet, ask your administrator for an account with this e-mail address first; sign-up is not open.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

The link is valid for {{ $expireDays }} days and can be used once. If you did not expect this invitation, you can ignore this e-mail.

<x-mail::subcopy>
If the button does not work, copy this address into your browser: {{ $url }}
</x-mail::subcopy>
</x-mail::message>
