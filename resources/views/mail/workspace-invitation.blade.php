<x-mail::message>
{{-- The markdown* values are echoed raw because the mailable has already HTML- and Markdown-escaped them; anything else here must use {{ }}. --}}
# You have been invited to {!! $markdownWorkspaceName !!}

{!! $markdownInviterName !!} invited you to the **{!! $markdownWorkspaceName !!}** workspace on {{ $appName }} with the **{!! $markdownRole !!}** role.

To join, sign in with this e-mail address and open the link below. If you do not have an account yet, ask your administrator for an account with this e-mail address first; sign-up is not open.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

The link is valid for {{ $expireDays }} days and can be used once. If you did not expect this invitation, you can ignore this e-mail.

<x-mail::subcopy>
If the button does not work, copy this address into your browser: {{ $url }}
</x-mail::subcopy>
</x-mail::message>
