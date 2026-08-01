import http from 'http';

const healthUrl = 'http://127.0.0.1:6002/health';

const gatewayIsRunning = () => new Promise((resolve) => {
    const request = http.get(healthUrl, { timeout: 1000 }, (response) => {
        let body = '';
        response.on('data', (chunk) => {
            body += chunk;
        });
        response.on('end', () => {
            try {
                resolve(response.statusCode === 200 && JSON.parse(body)?.status === 'ok');
            } catch {
                resolve(false);
            }
        });
    });

    request.on('timeout', () => {
        request.destroy();
        resolve(false);
    });
    request.on('error', () => resolve(false));
});

if (await gatewayIsRunning()) {
    process.stdout.write('DROMIS Socket.IO gateway is already running; reusing it.\n');
} else {
    await import('./server.mjs');
}
