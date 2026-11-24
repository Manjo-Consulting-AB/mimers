Category: {!! $categoryLabel !!} ({!! $category !!})
Name: {!! $name !!}
Email: {!! $email !!}
@if ($signedIn)
Signed in: yes
User ULID: {!! $userUlid !!}
Account ULID: {!! $accountUlid !!}
@else
Not signed in — identity not verified.
@endif
Locale: {!! $locale !!}
Sent (UTC): {!! $sentAt !!}

Message:
{!! $body !!}
