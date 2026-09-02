<x-mail::message>
# {{ __('mail_settings.test.mail_title') }}

{{ __('mail_settings.test.mail_body') }}

{{ __('mail_settings.test.mail_when', ['quando' => $quando]) }}

<x-mail::subcopy>
{{ __('mail_settings.test.mail_footer') }}
</x-mail::subcopy>
</x-mail::message>
