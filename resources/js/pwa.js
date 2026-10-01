const registerServiceWorker = async () => {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) {
        return;
    }

    const host = window.location.hostname.toLowerCase();
    const localDevelopment = host === 'localhost' || host === '127.0.0.1';
    const pwaAllowed = window.isSecureContext || localDevelopment;

    if (!pwaAllowed) {
        console.info('DROMIS PWA requires HTTPS when opened from another phone or computer.');
        return;
    }

    try {
        const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });

        if (registration.waiting) {
            registration.waiting.postMessage({ type: 'SKIP_WAITING' });
        }

        registration.addEventListener('updatefound', () => {
            const worker = registration.installing;

            worker?.addEventListener('statechange', () => {
                if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                    worker.postMessage({ type: 'SKIP_WAITING' });
                }
            });
        });
    } catch (error) {
        console.info('DROMIS PWA service worker registration skipped.', error);
    }
};

window.addEventListener('load', registerServiceWorker);
