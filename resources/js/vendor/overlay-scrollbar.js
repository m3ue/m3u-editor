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
