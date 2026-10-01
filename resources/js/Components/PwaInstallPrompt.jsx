import { Download, LockKeyhole, Share2, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const isInstalled = () =>
    window.matchMedia?.('(display-mode: standalone)').matches
    || window.navigator.standalone === true;

const isAppleMobile = () =>
    /iphone|ipad|ipod/i.test(window.navigator.userAgent)
    || (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);

export default function PwaInstallPrompt() {
    const [installEvent, setInstallEvent] = useState(null);
    const [visible, setVisible] = useState(false);
    const [apple, setApple] = useState(false);
    const [blockedByConnection, setBlockedByConnection] = useState(false);
    useEffect(() => {
        if (isInstalled()) return undefined;

        const mobileOrTablet = window.matchMedia?.('(max-width: 1024px), (pointer: coarse)').matches;
        if (!mobileOrTablet) return undefined;

        const appleDevice = isAppleMobile();
        setApple(appleDevice);

        const showTimer = window.setTimeout(() => {
            if (appleDevice) {
                setVisible(true);
            } else if (!window.isSecureContext) {
                setBlockedByConnection(true);
                setVisible(true);
            }
        }, 900);

        const onInstallAvailable = (event) => {
            event.preventDefault();
            setInstallEvent(event);
            setVisible(true);
        };
        const onInstalled = () => {
            setInstallEvent(null);
            setVisible(false);
        };

        window.addEventListener('beforeinstallprompt', onInstallAvailable);
        window.addEventListener('appinstalled', onInstalled);
        return () => {
            window.clearTimeout(showTimer);
            window.removeEventListener('beforeinstallprompt', onInstallAvailable);
            window.removeEventListener('appinstalled', onInstalled);
        };
    }, []);

    const install = async () => {
        if (!installEvent) return;
        await installEvent.prompt();
        const choice = await installEvent.userChoice;
        if (choice?.outcome === 'accepted') {
            setInstallEvent(null);
            setVisible(false);
        }
    };

    if (!visible || isInstalled()) return null;

    return (
        <div className="fixed inset-0 z-[300] flex items-end justify-center bg-slate-950/55 p-3 pb-[calc(.75rem+env(safe-area-inset-bottom))] backdrop-blur-[2px] sm:items-center sm:p-6">
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="pwa-install-title"
                className="w-full max-w-md overflow-hidden rounded-[2rem] border border-white/70 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-950"
            >
                <div className="flex items-start justify-between gap-4 p-6 pb-4">
                    <div>
                        <p className="text-xs font-black uppercase tracking-[0.18em] text-brand-700 dark:text-brand-200">Install app</p>
                        <h2 id="pwa-install-title" className="mt-1 text-2xl font-black text-slate-950 dark:text-white">Install DROMIS</h2>
                    </div>
                    <button type="button" onClick={() => setVisible(false)} aria-label="Close install prompt" className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-zinc-900 dark:text-zinc-300">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex items-center gap-4 px-6 py-4">
                    <img src="/images/pwa-icon-192.png" alt="DROMIS" className="h-20 w-20 shrink-0 rounded-2xl object-cover shadow-md ring-1 ring-slate-200 dark:ring-zinc-700" />
                    <div className="min-w-0">
                        <p className="text-lg font-black leading-tight text-slate-950 dark:text-white">DROMIS – Disaster Response Operations</p>
                        <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">DSWD Field Office Caraga</p>
                    </div>
                </div>

                {blockedByConnection ? (
                    <div className="mx-6 mb-6 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-slate-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-zinc-200">
                        <p className="flex items-center gap-2 font-black text-amber-900 dark:text-amber-200"><LockKeyhole className="h-4 w-4" /> Secure connection required</p>
                        <p className="mt-2 leading-6">This address is not trusted by your phone, so the browser can only create a webpage shortcut. Open DROMIS through its trusted HTTPS address, then choose <strong>Install app</strong> again.</p>
                        <p className="mt-2 break-all rounded-lg bg-white/70 px-3 py-2 font-mono text-xs dark:bg-black/20">{window.location.origin}</p>
                        <a href="/downloads/DROMIS-LAN-CA.crt" download className="mt-3 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-amber-900 px-4 py-2 font-black text-white">
                            <Download className="h-4 w-4" /> Download DROMIS Certificate
                        </a>
                        <p className="mt-2 text-xs leading-5">Install this certificate as a trusted CA on the phone, close and reopen the browser, then return to <strong>https://desktop-lj8f734/</strong>.</p>
                    </div>
                ) : apple ? (
                    <div className="mx-6 mb-6 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-slate-700 dark:border-sky-900 dark:bg-sky-950/30 dark:text-zinc-200">
                        <p className="flex items-center gap-2 font-black text-sky-800 dark:text-sky-200"><Share2 className="h-4 w-4" /> Add DROMIS to your Home Screen</p>
                        <ol className="mt-2 list-decimal space-y-1 pl-5">
                            <li>Tap the browser Share button.</li>
                            <li>Select <strong>Add to Home Screen</strong>.</li>
                            <li>Tap <strong>Add</strong>.</li>
                        </ol>
                    </div>
                ) : (
                    <div className="flex gap-3 border-t border-slate-200 p-5 dark:border-zinc-800">
                        <button type="button" onClick={() => setVisible(false)} className="h-12 flex-1 rounded-xl border border-slate-300 font-black text-slate-700 dark:border-zinc-700 dark:text-zinc-200">Not now</button>
                        <button type="button" onClick={install} className="inline-flex h-12 flex-[1.25] items-center justify-center gap-2 rounded-xl bg-brand-700 font-black text-white shadow-lg shadow-brand-900/20 transition active:scale-[.98] dark:bg-brand-600">
                            <Download className="h-5 w-5" /> Install
                        </button>
                    </div>
                )}
            </section>
        </div>
    );
}
