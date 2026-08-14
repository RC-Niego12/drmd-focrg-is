import crypto from 'crypto';
import fs from 'fs';
import http from 'http';
import https from 'https';
import os from 'os';
import path from 'path';
import process from 'process';
import { Server } from 'socket.io';
import dotenv from 'dotenv';

dotenv.config({ path: path.resolve(process.cwd(), '.env') });

const appUrl = new URL(process.env.APP_URL || 'http://localhost');
const publicUrl = new URL(
    process.env.SOCKET_IO_PUBLIC_URL
    || `${appUrl.protocol}//${appUrl.hostname}:6001`,
);
const enabled = String(process.env.SOCKET_IO_ENABLED || 'false').toLowerCase() === 'true';
const secret = process.env.SOCKET_IO_SECRET || process.env.APP_KEY || '';
const publicPort = Number(process.env.SOCKET_IO_PUBLIC_PORT || publicUrl.port || 6001);
const internalPort = Number(process.env.SOCKET_IO_INTERNAL_PORT || 6002);
const maxPayloadBytes = 64 * 1024;

if (!enabled) {
    throw new Error('SOCKET_IO_ENABLED is false. Enable it in .env before starting the real-time gateway.');
}
if (!secret) {
    throw new Error('SOCKET_IO_SECRET or APP_KEY is required.');
}

const safeEqual = (left, right) => {
    const a = Buffer.from(String(left));
    const b = Buffer.from(String(right));
    return a.length === b.length && crypto.timingSafeEqual(a, b);
};

const verifyToken = (token) => {
    const [encodedPayload, signature] = String(token || '').split('.');
    if (!encodedPayload || !signature) throw new Error('Missing token.');
    const expected = crypto
        .createHmac('sha256', secret)
        .update(encodedPayload)
        .digest('base64url');
    if (!safeEqual(signature, expected)) throw new Error('Invalid token signature.');
    const payload = JSON.parse(Buffer.from(encodedPayload, 'base64url').toString('utf8'));
    if (!Number.isInteger(payload.user_id) || Number(payload.exp) <= Math.floor(Date.now() / 1000)) {
        throw new Error('Expired token.');
    }
    return payload;
};

const allowedOrigins = new Set([
    appUrl.origin.toLowerCase(),
    ...(process.env.SOCKET_IO_ALLOWED_ORIGINS || '')
        .split(',')
        .map((value) => value.trim().toLowerCase())
        .filter(Boolean),
]);
const allowOrigin = (origin) => !origin || allowedOrigins.has(String(origin).toLowerCase());

const resolveHerdTlsPaths = () => {
    if (process.env.SOCKET_IO_CERT && process.env.SOCKET_IO_KEY) {
        return { certPath: process.env.SOCKET_IO_CERT, keyPath: process.env.SOCKET_IO_KEY };
    }

    const certificateDirectory = path.join(os.homedir(), '.config', 'herd', 'config', 'valet', 'Certificates');
    const host = appUrl.hostname;
    const candidates = [
        host,
        host.endsWith('.lan') || host.endsWith('.test') ? null : `${host}.lan`,
        host.endsWith('.lan') || host.endsWith('.test') ? null : `${host}.test`,
    ].filter(Boolean);

    for (const base of candidates) {
        const certPath = path.join(certificateDirectory, `${base}.crt`);
        const keyPath = path.join(certificateDirectory, `${base}.key`);
        if (fs.existsSync(certPath) && fs.existsSync(keyPath)) {
            return { certPath, keyPath };
        }
    }

    throw new Error(
        `Herd TLS certs not found for ${host}. Looked under ${certificateDirectory} `
        + `(tried ${candidates.join(', ')}). Set SOCKET_IO_CERT / SOCKET_IO_KEY, `
        + 'or use http://…:6001 only when APP_URL is also http.',
    );
};

let publicServer;
if (publicUrl.protocol === 'https:') {
    const { certPath, keyPath } = resolveHerdTlsPaths();
    publicServer = https.createServer({
        cert: fs.readFileSync(certPath),
        key: fs.readFileSync(keyPath),
    });
    process.stdout.write(`DROMIS Socket.IO TLS using ${certPath}\n`);
} else {
    publicServer = http.createServer();
}

const io = new Server(publicServer, {
    cors: {
        origin: (origin, callback) => callback(allowOrigin(origin) ? null : new Error('Origin not allowed.'), allowOrigin(origin)),
        credentials: true,
    },
    transports: ['websocket', 'polling'],
    pingInterval: 25000,
    pingTimeout: 20000,
    maxHttpBufferSize: maxPayloadBytes,
});

io.use((socket, next) => {
    try {
        socket.data.identity = verifyToken(socket.handshake.auth?.token);
        next();
    } catch {
        next(new Error('Unauthorized'));
    }
});

io.on('connection', (socket) => {
    socket.join(`user:${socket.data.identity.user_id}`);
    socket.emit('realtime.ready', { connected_at: new Date().toISOString() });
});

const internalServer = http.createServer((request, response) => {
    if (request.method === 'GET' && request.url === '/health') {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ status: 'ok', clients: io.engine.clientsCount }));
        return;
    }
    if (request.method !== 'POST' || request.url !== '/publish') {
        response.writeHead(404).end();
        return;
    }
    if (!safeEqual(request.headers['x-dromis-realtime-secret'] || '', secret)) {
        response.writeHead(401).end();
        return;
    }

    let body = '';
    request.on('data', (chunk) => {
        body += chunk;
        if (Buffer.byteLength(body) > maxPayloadBytes) request.destroy();
    });
    request.on('end', () => {
        try {
            const message = JSON.parse(body);
            if (!/^[a-z][a-z0-9._-]{1,80}$/i.test(message.event) || !Array.isArray(message.rooms)) {
                throw new Error('Invalid event envelope.');
            }
            const rooms = [...new Set(message.rooms)]
                .filter((room) => /^user:\d+$/.test(String(room)))
                .slice(0, 1000);
            rooms.forEach((room) => io.to(room).emit(message.event, message.payload || {}));
            response.writeHead(202, { 'Content-Type': 'application/json' });
            response.end(JSON.stringify({ delivered_to_rooms: rooms.length }));
        } catch {
            response.writeHead(422).end();
        }
    });
});

const publicHost = process.env.SOCKET_IO_PUBLIC_HOST || '0.0.0.0';
publicServer.listen(publicPort, publicHost, () => {
    process.stdout.write(`DROMIS Socket.IO gateway listening on ${publicUrl.protocol}//${publicHost}:${publicPort} (public ${publicUrl.origin})\n`);
});
internalServer.listen(internalPort, '127.0.0.1', () => {
    process.stdout.write(`DROMIS internal event publisher listening on 127.0.0.1:${internalPort}\n`);
});

const shutdown = () => {
    io.close();
    internalServer.close();
    publicServer.close(() => process.exit(0));
};
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
