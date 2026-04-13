// Import styles (Support HMR)
import '../css/app.css'

// OverlayScrollbars
import { OverlayScrollbars } from 'overlayscrollbars'

const OS_OPTIONS = {
    scrollbars: {
        theme: 'os-theme-app',
        autoHide: 'scroll',
        autoHideDelay: 800,
        clickScroll: true,
    },
}

const OS_SELECTORS = [
    // 'body',
    '.fi-sidebar-nav',
].join(', ')

const initScrollbars = () => {
    document.querySelectorAll(OS_SELECTORS).forEach(el => {
        if (!OverlayScrollbars(el)) {
            OverlayScrollbars(el, OS_OPTIONS)
        }
    })
}

let osDebounce
const debouncedInit = () => {
    clearTimeout(osDebounce)
    osDebounce = setTimeout(initScrollbars, 120)
}

document.addEventListener('DOMContentLoaded', () => {
    initScrollbars()
    // Catch Livewire-rendered elements (tables, modals, etc.)
    new MutationObserver(debouncedInit).observe(document.body, {
        childList: true,
        subtree: true,
    })
})
document.addEventListener('livewire:navigated', initScrollbars)
// Fires after every Livewire server round-trip (table loads, filters, pagination)
document.addEventListener('livewire:commit', debouncedInit)

// Enable websockets
import './echo'

// Import streaming libraries
import Hls from 'hls.js'
import mpegts from 'mpegts.js'

// Make streaming libraries globally available
window.Hls = Hls
window.mpegts = mpegts

// Vendor
import './vendor/qrcode'
import './vendor/epg-viewer'
import './vendor/stream-viewer'
import './vendor/multi-stream-manager'
import './vendor/schedule-builder'

// Fix broken images
document.addEventListener('error', event => {
    const el = event.target;
    if (el.tagName.toLowerCase() === 'img') {
        el.onerror = null;
        if (el.classList.contains('episode-placeholder')) {
            el.src = '/episode-placeholder.png';
        } else {
            el.src = '/placeholder.png';
        }
    }
}, true);