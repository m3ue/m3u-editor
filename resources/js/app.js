// Import styles (Support HMR)
import '../css/app.css'

// Enable websockets
import './echo'

// Vendor
import './vendor/qrcode'
import './vendor/video'
import './vendor/epg-viewer'

// Fix broken images
document.addEventListener('error', event => {
    const el = event.target;
    if (el.tagName.toLowerCase() === 'img') {
        el.onerror = null;
        el.src = '/placeholder.png';
    }
}, true);