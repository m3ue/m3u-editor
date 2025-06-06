import QRCode from 'easyqrcodejs';

function generateQRCodes(logo = null) {
    const qrEls = document.querySelectorAll('.qr-code');
    qrEls.forEach((el) => {
        // Skip if QR code already generated (has canvas child)
        if (el.querySelector('canvas')) {
            return;
        }
        
        // Clear any existing QR code to prevent duplication
        el.innerHTML = '';

        const text = el.getAttribute('data-text');
        const size = parseInt(el.getAttribute('data-size')) || 128;
        const color = el.getAttribute('data-color') || '#ffffff';
        const bgColor = el.getAttribute('data-bg-color') || '#000000';

        // Only generate if we have text
        if (text && text.trim() !== '') {
            try {
                let config = {
                    text,
                    width: size,
                    height: size,
                    colorDark: bgColor,
                    colorLight: color,
                    logoBackgroundTransparent: false,
                };
                if (logo) {
                    config.logo = logo;
                }
                new QRCode(el, config);
            } catch (error) {
                console.error('Error generating QR code:', error);
            }
        }
    });
}

// Make QRCode available globally
window.QRCode = QRCode;
window.generateQRCodes = generateQRCodes;

// Run on initial page load
document.addEventListener('DOMContentLoaded', () => generateQRCodes('/logo.png'));

// Run after Livewire SPA navigation
document.addEventListener('livewire:navigated', () => generateQRCodes('/logo.png'));
