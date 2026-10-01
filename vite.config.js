import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import fs from 'fs';
import os from 'os';
import path from 'path';

export default defineConfig(({ command, mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = env.APP_URL || 'http://localhost';
    const appHost = new URL(appUrl).hostname;
    const appProtocol = new URL(appUrl).protocol;
    const viteOrigin = env.VITE_DEV_SERVER_URL || `${appProtocol}//${appHost}:5173`;
    const viteUrl = new URL(viteOrigin);
    const isHttpsDevServer = command === 'serve' && viteUrl.protocol === 'https:';
    const herdCertificates = path.join(os.homedir(), '.config', 'herd', 'config', 'valet', 'Certificates');
    const certificateBase = appHost.endsWith('.test') || appHost.endsWith('.lan')
        ? appHost
        : `${appHost}.lan`;
    const certificatePath = env.VITE_DEV_SERVER_CERT || path.join(herdCertificates, `${certificateBase}.crt`);
    const certificateKeyPath = env.VITE_DEV_SERVER_KEY || path.join(herdCertificates, `${certificateBase}.key`);

    if (isHttpsDevServer && (!fs.existsSync(certificatePath) || !fs.existsSync(certificateKeyPath))) {
        throw new Error(
            `HTTPS Vite assets require a trusted certificate. Set VITE_DEV_SERVER_CERT and `
            + `VITE_DEV_SERVER_KEY, or secure ${certificateBase} in Laravel Herd.`,
        );
    }

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.jsx'],
                refresh: true,
            }),
            react(),
        ],
        server: {
            host: '0.0.0.0',
            cors: {
                origin: [
                    /^https?:\/\/localhost(?::\d+)?$/,
                    /^https?:\/\/127\.0\.0\.1(?::\d+)?$/,
                    /^https?:\/\/10(?:\.\d{1,3}){3}(?::\d+)?$/,
                    /^https?:\/\/192\.168(?:\.\d{1,3}){2}(?::\d+)?$/,
                    /^https?:\/\/172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2}(?::\d+)?$/,
                    /^https?:\/\/drmd-focrg-is\.test(?::\d+)?$/,
                    /^https?:\/\/desktop-lj8f734(?:\.lan)?(?::\d+)?$/i,
                ],
                credentials: true,
            },
            origin: viteOrigin,
            port: Number(viteUrl.port || (viteUrl.protocol === 'https:' ? 443 : 80)),
            strictPort: true,
            https: isHttpsDevServer
                ? {
                    cert: fs.readFileSync(certificatePath),
                    key: fs.readFileSync(certificateKeyPath),
                }
                : undefined,
            hmr: {
                host: viteUrl.hostname,
                protocol: viteUrl.protocol === 'https:' ? 'wss' : 'ws',
                clientPort: Number(viteUrl.port || (viteUrl.protocol === 'https:' ? 443 : 80)),
            },
        },
        resolve: {
            alias: {
                '@': path.resolve('./resources/js'),
            },
        },
        build: {
            // Keep the last complete manifest and hashed assets available while
            // Vite compiles their replacements. This prevents transient Laravel
            // 500 responses when a report is opened during a local/watch build.
            emptyOutDir: false,
        },
    };
});
