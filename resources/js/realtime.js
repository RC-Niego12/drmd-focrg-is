import { io } from 'socket.io-client';

let socket = null;
let activeKey = null;
let connected = false;

const dispatch = (event, detail = {}) => {
    window.dispatchEvent(new CustomEvent(`dromis:${event}`, { detail }));
};

export const connectRealtime = (configuration) => {
    const socketUrl = configuration?.url;
    if (configuration?.enabled && socketUrl && window.location.protocol === 'https:') {
        try {
            const parsedUrl = new URL(socketUrl, window.location.origin);
            if (parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'ws:') {
                disconnectRealtime();
                dispatch('realtime.connection', {
                    connected: false,
                    reason: 'insecure_socket_url',
                });
                return;
            }
        } catch {
            disconnectRealtime();
            return;
        }
    }

    const authUrl = configuration?.auth_url;
    const key = configuration?.enabled && authUrl ? `${socketUrl}|${authUrl}` : null;
    if (!key) {
        disconnectRealtime();
        return;
    }
    if (socket && activeKey === key) return;

    disconnectRealtime();
    activeKey = key;
    socket = io(socketUrl, {
        transports: ['websocket', 'polling'],
        auth: async (callback) => {
            try {
                const response = await fetch(authUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (!response.ok) throw new Error('Realtime authentication failed.');
                const credentials = await response.json();
                callback({ token: credentials.token });
            } catch {
                callback({});
            }
        },
        reconnection: true,
        reconnectionDelay: 1000,
        reconnectionDelayMax: 15000,
    });
    socket.on('connect', () => {
        connected = true;
        dispatch('realtime.connection', { connected: true });
    });
    socket.on('disconnect', () => {
        connected = false;
        dispatch('realtime.connection', { connected: false });
    });
    socket.on('connect_error', () => {
        connected = false;
        dispatch('realtime.connection', { connected: false });
    });
    socket.onAny((event, payload) => dispatch(event, payload));
};

export const disconnectRealtime = () => {
    socket?.disconnect();
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
