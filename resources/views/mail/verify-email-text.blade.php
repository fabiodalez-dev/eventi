{{ $product }}

{{ __('account.verify.title') }}

{{ __('account.mail.verify.intro') }}

{{ __('account.mail.verify.action') }}: {!! $verificationUrl !!}

{{ __('account.mail.verify.expires', ['minutes' => $minutes]) }}

{{ __('account.mail.verify.ignore') }}
