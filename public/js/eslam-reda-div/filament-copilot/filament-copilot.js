// Filament Copilot Scripts

// Keyboard shortcut to toggle copilot (Ctrl+Shift+K / Cmd+Shift+K)
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'K') {
        e.preventDefault();
        window.dispatchEvent(new CustomEvent('copilot-open'));
    }
});