import { Loader2, PackageCheck } from 'lucide-react';
import clsx from 'clsx';
import { useEffect, useRef, useState } from 'react';

export default function CreativePageLoader({ active }) {
    const [visible, setVisible] = useState(false);
    const startedAt = useRef(0);
    const timeoutRef = useRef(null);

    useEffect(() => {
        const minimumLoaderTime = 1000;

        if (active) {
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
            }

            startedAt.current = Date.now();
            setVisible(true);

            return undefined;
        }

        const elapsed = Date.now() - startedAt.current;
        const remaining = Math.max(minimumLoaderTime - elapsed, 0);

        timeoutRef.current = setTimeout(() => {
            setVisible(false);
        }, remaining);

        return () => {
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
            }
        };
    }, [active]);

    return (
        <>
            <div className={clsx(
                'fixed left-0 right-0 top-0 z-50 h-1 overflow-hidden bg-brand-50/70 transition-opacity duration-150 dark:bg-zinc-900/70',
                visible ? 'opacity-100' : 'pointer-events-none opacity-0',
            )}>
                <div className="h-full w-1/2 animate-[page-loader_1s_ease-in-out_infinite] bg-gradient-to-r from-brand-500 via-emerald-300 to-sky-500 shadow-[0_0_18px_rgba(17,94,89,0.55)]" />
            </div>
            <div className={clsx(
                'fixed inset-0 z-40 flex items-start justify-center bg-slate-950/0 pt-24 transition duration-200',
                visible ? 'pointer-events-auto opacity-100' : 'pointer-events-none opacity-0',
            )}>
                <div className="relative overflow-hidden rounded-md border border-slate-200 bg-white/95 px-5 py-4 shadow-2xl shadow-slate-900/10 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/95 dark:shadow-black/30">
                    <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-600 via-emerald-300 to-sky-500" />
                    <div className="flex items-center gap-4">
                        <div className="relative flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-950/40 dark:text-brand-100">
                            <span className="absolute inset-0 rounded-full border-2 border-brand-200 dark:border-brand-800" />
                            <span className="absolute inset-1 animate-spin rounded-full border-2 border-transparent border-r-sky-500 border-t-brand-600 dark:border-r-sky-300 dark:border-t-brand-200" />
                            <PackageCheck className="h-5 w-5" />
                        </div>
                        <div>
                            <p className="text-sm font-bold text-slate-950 dark:text-white">Loading workspace</p>
                            <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">Preparing inventory and response data</p>
                        </div>
                        <Loader2 className="h-4 w-4 animate-spin text-brand-600 dark:text-brand-200" />
                    </div>
                </div>
            </div>
        </>
    );
}
