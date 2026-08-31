@php
    $externalIpService = app(\App\Services\ExternalIpService::class);
    $externalIp = $externalIpService->getExternalIpWithFallback();
@endphp

<div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1 dark:border-gray-700 dark:bg-gray-800">
    <div class="flex items-center gap-1">
        <code
            class="cursor-pointer rounded bg-white px-2 py-1.5 font-mono text-xs text-gray-800 select-all dark:bg-gray-900 dark:text-gray-200"
            x-tooltip="'{{ __('WAN IP, Click to copy') }}'"
            x-on:click="
                if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
                    window.navigator.clipboard.writeText('{{ $externalIp }}')
                        .then(() => { copied = true; setTimeout(() => copied = false, 1500); });
                } else {
                    // fallback for unsupported browsers
                    const el = document.createElement('textarea');
                    el.value = '{{ $externalIp }}';
                    document.body.appendChild(el);
                    el.select();
                    document.execCommand('copy');
                    document.body.removeChild(el);
                    copied = true; setTimeout(() => copied = false, 1500);
                }
                if (copied) {
                    showNotification('IP address copied to clipboard', 'success');
                }
            "
        >{{ $externalIp }}</code>

        <button
            class="rounded p-1 text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300"
            title="Copy to clipboard"
            x-on:click="
                if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
                    window.navigator.clipboard.writeText('{{ $externalIp }}')
                        .then(() => { copied = true; setTimeout(() => copied = false, 1500); });
                } else {
                    // fallback for unsupported browsers
                    const el = document.createElement('textarea');
                    el.value = '{{ $externalIp }}';
                    document.body.appendChild(el);
                    el.select();
                    document.execCommand('copy');
                    document.body.removeChild(el);
                    copied = true; setTimeout(() => copied = false, 1500);
                }
                if (copied) {
                    showNotification('IP address copied to clipboard', 'success');
                }
            "
        >
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                ></path>
            </svg>
        </button>

        <button
            onclick="refreshExternalIp()"
            class="rounded p-1 text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300"
            title="Refresh IP"
        >
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                ></path>
            </svg>
        </button>
    </div>
</div>

<script>
    function refreshExternalIp() {
        // Show loading state
        const button = event.target.closest('button');
        const originalIcon = button.innerHTML;
        button.innerHTML =
            '<svg class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>';

        // Make request to refresh IP
        fetch('/admin/refresh-external-ip', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    // Reload the page to show updated IP
                    showNotification('IP address refreshed successfully', 'success');
                    // Update the displayed IP without reloading
                    const codeElement = button.closest('div').querySelector('code');
                    codeElement.textContent = data.external_ip;
                } else {
                    showNotification('Failed to refresh IP address', 'error');
                }
            })
            .catch((error) => {
                console.error('Error refreshing IP:', error);
                showNotification('Failed to refresh IP address', 'error');
            })
            .finally(() => {
                button.innerHTML = originalIcon;
            });
    }

    function showNotification(message, type = 'success') {
        // Try to use Filament's notification system if available
        if (window.FilamentNotification) {
            const notification = new window.FilamentNotification();
            if (type === 'success') {
                notification.success().title(message).send();
            } else {
                notification.danger().title(message).send();
            }
        } else {
            // Fallback to simple alert
            alert(message);
        }
    }
</script>
