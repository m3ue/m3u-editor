import svgPanZoom from 'svg-pan-zoom'

// svg-pan-zoom is small — load it eagerly so it's available synchronously
// when the mermaid render callback runs.
window.__svgPanZoom = svgPanZoom

// Mermaid is large (~2 MB). Lazy-load it on demand so it only enters the
// bundle when the sync-run diagram view is actually opened.
window.__loadMermaid = async () => {
    if (!window.__mermaid) {
        const { default: mermaid } = await import('mermaid')
        window.__mermaid = mermaid
        window.__mermaid.initialize({ startOnLoad: false, securityLevel: 'loose', theme: 'default' })
    }

    return window.__mermaid
}
