<x-mail::message>
{{-- The translations get only values the mailable has already HTML- and Markdown-escaped, so they are echoed raw; anything else here must use {{ }}. --}}
# {!! __('invitation.heading', ['workspace' => $markdownWorkspaceName]) !!}

{!! __('invitation.intro', ['inviter' => $markdownInviterName, 'workspace' => $markdownWorkspaceName, 'app' => $markdownAppName, 'role' => $markdownRole]) !!}

{{ __('invitation.how') }}

<x-mail::button :url="$url">
{{ __('invitation.accept') }}
</x-mail::button>

{{ __('invitation.validity', ['days' => $expireDays]) }}

<x-mail::subcopy>
{{ __('invitation.fallback') }} {{ $url }}
</x-mail::subcopy>
</x-mail::message>
