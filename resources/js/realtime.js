import { io } from 'socket.io-client';

let socket = null;
let activeKey = null;
let connected = false;
let warnedUnavailable = false;
// In-memory only: SPA navigations skip retries; a full page reload probes again
// (so starting `npm run realtime` then refreshing works immediately).
let unavailableUntil = 0;

const COOLDOWN_MS = 60 * 1000;
const MAX_HARD_FAILURES = 2;

const dispatch = (event, detail = {}) => {
    window.dispatchEvent(new CustomEvent(`dromis:${event}`, { detail }));
};

const markUnavailable = (reason = 'unreachable') => {
    unavailableUntil = Date.now() + COOLDOWN_MS;

    if (!warnedUnavailable) {
        warnedUnavailable = true;
        console.warn(
            `[DROMIS] Realtime unavailable (${reason}). App continues without live updates. `
            + 'Start the gateway with `npm run realtime` (or `npm run dev`), then refresh.',
        );
    }

    dispatch('realtime.connection', { connected: false, reason });
};

const clearUnavailable = () => {
    unavailableUntil = 0;
};

/**
 * Resolve the Socket.IO public URL for the current page.
 *
 * Herd serves the app over HTTPS, so the browser requires WSS (https://…:6001).
 * Plain ws:// from an https page is blocked as mixed content — do not downgrade.
 * The Node gateway must therefore be started with SOCKET_IO_PUBLIC_URL=https://…:6001
 * so it loads Herd TLS certs (see realtime/server.mjs).
 */
const resolveSocketUrl = (configuredUrl) => {
    const parsedUrl = new URL(configuredUrl, window.location.origin);

    if (window.location.protocol === 'https:' && (parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'ws:')) {
        const error = new Error('insecure_socket_url');
        error.code = 'insecure_socket_url';
        throw error;
    }

    // Prefer the page hostname so Herd TLS certs match the Socket.IO host.
    const pageHost = window.location.hostname.toLowerCase();
    if (parsedUrl.hostname.toLowerCase() !== pageHost) {
        parsedUrl.hostname = window.location.hostname;
        if (window.location.protocol === 'https:') {
            parsedUrl.protocol = 'https:';
        }
    }

    // Prefer the configured Socket.IO gateway port (:6001). Herd/Codex may serve the
    // HTTPS app on another host port (e.g. :6081); that must not become the Socket.IO port.
    const configuredPort = String(parsedUrl.port || '');
    const defaultHttpPort = window.location.protocol === 'https:' ? '443' : '80';
    const pagePort = window.location.port || defaultHttpPort;
    const gatewayPorts = new Set(['6001', '6002']);
    if (parsedUrl.hostname.toLowerCase() === pageHost) {
        if (!gatewayPorts.has(configuredPort) || configuredPort === pagePort || configuredPort === defaultHttpPort || configuredPort === '') {
            parsedUrl.port = '6001';
        }
    }

    return parsedUrl.toString();
};

const connectToSocket = (socketUrl, authUrl) => {
    const key = authUrl ? `${socketUrl}|${authUrl}` : null;
    if (!key) {
        disconnectRealtime();
        return;
    }
    if (socket && activeKey === key) {
        return;
    }

    disconnectRealtime();
    activeKey = key;
    let hardFailures = 0;
    let settled = false;

    socket = io(socketUrl, {
        // Websocket-first; avoid a second failed polling attempt when the port is closed.
        transports: ['websocket'],
        upgrade: false,
        auth: async (callback) => {
            try {
                const response = await fetch(authUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (!response.ok) {
                    throw new Error('Realtime authentication failed.');
                }
                const credentials = await response.json();
                callback({ token: credentials.token });
            } catch {
                callback({});
            }
        },
        reconnection: true,
        reconnectionAttempts: MAX_HARD_FAILURES,
        reconnectionDelay: 1500,
        reconnectionDelayMax: 4000,
        timeout: 4000,
    });

    socket.on('connect', () => {
        connected = true;
        hardFailures = 0;
        settled = true;
        clearUnavailable();
        dispatch('realtime.connection', { connected: true });
    });

    socket.on('disconnect', () => {
        connected = false;
        dispatch('realtime.connection', { connected: false });
    });

    socket.on('connect_error', () => {
        connected = false;
        hardFailures += 1;
        dispatch('realtime.connection', { connected: false });
        if (!settled && hardFailures >= MAX_HARD_FAILURES) {
            settled = true;
            markUnavailable('connection_refused');
            disconnectRealtime();
        }
    });

    socket.onAny((event, payload) => dispatch(event, payload));
};

export const connectRealtime = (configuration) => {
    const configuredUrl = configuration?.url;
    if (!configuration?.enabled || !configuredUrl) {
        disconnectRealtime();
        return;
    }

    if (Date.now() < unavailableUntil) {
        dispatch('realtime.connection', {
            connected: false,
            reason: 'cooldown',
        });
        return;
    }

    try {
        connectToSocket(resolveSocketUrl(configuredUrl), configuration?.auth_url);
    } catch (error) {
        disconnectRealtime();
        if (error?.code === 'insecure_socket_url') {
            markUnavailable('insecure_socket_url');
            return;
        }
        markUnavailable(error?.message || 'connect_failed');
    }
};

export const disconnectRealtime = () => {
    if (socket) {
        try {
            socket.removeAllListeners();
            socket.disconnect();
        } catch {
            // ignore teardown errors
        }
    }
    socket = null;
    activeKey = null;
    connected = false;
};

export const isRealtimeConnected = () => connected;

export const listenRealtime = (event, callback) => {
    const name = `dromis:${event}`;
    const listener = (browserEvent) => callback(browserEvent.detail);
    window.addEventListener(name, listener);
    return () => window.removeEventListener(name, listener);
};
