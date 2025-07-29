// Import styles (Support HMR)
import '../css/app.css'

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

// Fix broken images
document.addEventListener('error', event => {
    const el = event.target;
    if (el.tagName.toLowerCase() === 'img') {
        el.onerror = null;
        el.src = '/placeholder.png';
    }
}, true);