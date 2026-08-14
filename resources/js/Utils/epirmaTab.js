/**
 * Open / drive e-PIRMA transaction tabs without navigating the current page.
 *
 * window.open(..., 'noopener') returns null even when a tab opens, and async
 * fetch() loses the user-gesture so a later window.open is often blocked —
 * both caused the previous fallback to window.location.href.
 */

export function openEpirmaTab(url) {
    if (!url) return false;

    const tab = window.open(url, '_blank');
    if (!tab) return false;

    try {
        tab.opener = null;
    } catch {
        // Cross-origin / restricted contexts may reject opener writes.
    }
    try {
        tab.focus();
    } catch {
        // Ignore focus failures.
    }

    return true;
}

/** Call synchronously inside a click handler before any await. */
export function openEpirmaTabPlaceholder(message = 'Opening e-PIRMA transaction…') {
    const tab = window.open('about:blank', '_blank');
    if (!tab) return null;

    try {
        tab.opener = null;
    } catch {
        // Ignore.
    }

    try {
        tab.document.open();
        tab.document.write(
            `<!doctype html><html><head><meta charset="utf-8"><title>e-PIRMA</title></head>`
            + `<body style="margin:0;font-family:Segoe UI,system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:grid;place-items:center;min-height:100vh">`
            + `<p style="font-size:14px;font-weight:700;letter-spacing:.04em">${message}</p>`
            + `</body></html>`,
        );
        tab.document.close();
    } catch {
        // Ignore write failures on restricted about:blank contexts.
    }

    return tab;
}

export function navigateEpirmaTab(tab, url) {
    if (url && tab && !tab.closed) {
        try {
            tab.location.href = url;
            try {
                tab.focus();
            } catch {
                // Ignore.
            }
            return true;
        } catch {
            // Fall through to a fresh open attempt.
        }
    }

    return openEpirmaTab(url);
}

export function closeEpirmaTab(tab) {
    if (tab && !tab.closed) {
        try {
            tab.close();
        } catch {
            // Ignore.
        }
    }
}
