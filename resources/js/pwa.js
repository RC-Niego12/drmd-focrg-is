const registerServiceWorker = async () => {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) {
        return;
    }

    const host = window.location.hostname.toLowerCase();
    const pwaAllowed = host === 'localhost'
        || host === '127.0.0.1'
        || host.endsWith('.test')
        || host === 'drmd-focrg-is.test'
        || window.location.protocol === 'https:' && !host.startsWith('desktop-');

    if (!pwaAllowed) {
        try {
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(registrations.map((registration) => registration.unregister()));
        } catch (error) {
            console.info('DROMIS PWA cleanup skipped.', error);
        }

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
