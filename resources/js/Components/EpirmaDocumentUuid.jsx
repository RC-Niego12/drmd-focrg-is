import { useState } from 'react';
import { Check, Copy, Eye, EyeOff } from 'lucide-react';

const MASKED_UUID = '••••••••-••••-••••-••••-••••••••••••';

/**
 * Per-row e-PIRMA document UUID with show/hide (and optional copy when revealed).
 * Renders nothing when uuid is empty.
 */
export default function EpirmaDocumentUuid({ uuid, className = '' }) {
    const value = String(uuid || '').trim();
    const [revealed, setRevealed] = useState(false);
    const [copied, setCopied] = useState(false);

    if (!value) return null;

    const copyUuid = async (event) => {
        event.stopPropagation();
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard may be blocked; leave UUID visible for manual copy.
        }
    };

    return (
        <div className={`mt-1 flex max-w-full items-center gap-1 ${className}`.trim()}>
            <span
                className="min-w-0 truncate font-mono text-[10px] font-normal text-slate-500 dark:text-zinc-400"
                title={revealed ? value : 'Hidden document UUID'}
            >
                {revealed ? value : MASKED_UUID}
            </span>
            <button
                type="button"
                title={revealed ? 'Hide document UUID' : 'Show document UUID'}
                aria-label={revealed ? 'Hide document UUID' : 'Show document UUID'}
                data-tip={revealed ? 'Hide document UUID' : 'Show document UUID'}
                data-tip-side="bottom"
                aria-pressed={revealed}
                onClick={(event) => {
                    event.stopPropagation();
                    setRevealed((prev) => !prev);
                }}
                className="dromis-tip inline-flex shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
            >
                {revealed ? <EyeOff className="h-3 w-3" /> : <Eye className="h-3 w-3" />}
            </button>
            {revealed && (
                <button
                    type="button"
                    title={copied ? 'Copied' : 'Copy document UUID'}
                    aria-label={copied ? 'Copied' : 'Copy document UUID'}
                    data-tip={copied ? 'Copied' : 'Copy document UUID'}
                    data-tip-side="bottom"
                    onClick={copyUuid}
                    className="dromis-tip inline-flex shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                >
                    {copied ? <Check className="h-3 w-3 text-emerald-600" /> : <Copy className="h-3 w-3" />}
                </button>
            )}
        </div>
    );
}
