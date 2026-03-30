@php
    $hideForm = config('services.oidc.hide_login_form') && ! request()->has('local');
@endphp

<div @class(['mt-4' => ! $hideForm])>
    @unless($hideForm)
        <div class="relative flex items-center justify-center">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-gray-300 dark:border-gray-600"></div>
            </div>
            <div class="relative px-4 bg-white dark:bg-gray-900 text-sm text-gray-500 dark:text-gray-400">
                or
            </div>
        </div>
    @endunless

    <a href="{{ route('auth.oidc.redirect') }}"
       class="mt-4 w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-primary-600 hover:bg-primary-500 rounded-lg shadow-sm transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 dark:focus:ring-offset-gray-900">
        <x-heroicon-m-arrow-right-end-on-rectangle class="w-5 h-5" />
        {{ config('services.oidc.button_label', 'Login with SSO') }}
    </a>
</div>
