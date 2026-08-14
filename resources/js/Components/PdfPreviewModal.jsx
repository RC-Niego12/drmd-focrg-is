import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import SectionTabs from '@/Components/SectionTabs';

/**
 * In-app PDF preview dialog. Never opens a new tab — embeds the same-origin PDF URL.
 * Pass `tabs` for a tabbed assessment / response-letter (or similar) preview.
 * Tabs may supply `src` (iframe) or `panel` (React node, e.g. Track status).
 */
export default function PdfPreviewModal({
    open = false,
    title = 'Document preview',
    subtitle = null,
    src = null,
    kind = null,
    message = null,
    tabs = null,
    initialTab = null,
    wide = false,
    onClose,
}) {
    const hasTabs = Array.isArray(tabs) && tabs.length > 0;
    const tabHasContent = (tab) => Boolean(tab?.src || tab?.panel);
    const tabKeys = hasTabs ? tabs.map((tab) => tab.key) : [];
    const firstTabKey = hasTabs
        ? (tabs.find(tabHasContent)?.key ?? tabs[0].key)
        : null;
    const resolvedInitialTab = hasTabs
        ? (tabs.find((tab) => tab.key === initialTab && tabHasContent(tab))?.key
            ?? firstTabKey)
        : null;
    const [activeTab, setActiveTab] = useState(resolvedInitialTab);

    // Only reset the main tab when the modal opens or the intended initial tab
    // changes — never when parent re-renders recreate `onClose` (e.g. RIS/DR
    // sub-tab clicks that update sibling state in the parent).
    useEffect(() => {
        if (!open) return;
        setActiveTab(resolvedInitialTab);
    }, [open, resolvedInitialTab]);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (event) => {
            if (event.key === 'Escape') onClose?.();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;

    const selectMainTab = (key) => {
        if (!tabKeys.includes(key)) return;
        setActiveTab(key);
    };

    const active = hasTabs
        ? (tabs.find((tab) => tab.key === activeTab) || tabs.find(tabHasContent) || tabs[0])
        : null;
    const resolvedSrc = active ? active.src : src;
    const resolvedPanel = active ? active.panel : null;
    const resolvedKind = active ? (active.kind ?? kind) : kind;
    const resolvedMessage = active ? (active.message ?? message) : message;
    const iframeTitle = active?.label || title;

    if (!resolvedSrc && !resolvedPanel && !resolvedMessage && !hasTabs) return null;

    const kindLabel = resolvedKind === 'signed'
        ? 'Signed'
        : resolvedKind === 'draft'
            ? 'Draft / local preview'
            : null;

    return (
        <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/65 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-label={title}
        >
            <div
                className={`flex h-[90vh] w-[96vw] flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-900 ${
                    wide ? 'max-w-[1500px]' : 'max-w-5xl'
                }`}
            >
                <div className="shrink-0 border-b border-slate-200 px-4 pt-2.5 pb-2 dark:border-zinc-800">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-[10px] font-black uppercase tracking-wide text-orange-700">Document preview</p>
                                {kindLabel && (
                                    <span className={`rounded px-2 py-0.5 text-[10px] font-black uppercase ${
                                        resolvedKind === 'signed' ? 'bg-emerald-600 text-white' : 'bg-amber-100 text-amber-900'
                                    }`}>
                                        {kindLabel}
                                    </span>
                                )}
                            </div>
                            <h2 className="truncate text-base font-black leading-tight text-slate-900 dark:text-zinc-50">{title}</h2>
                            {subtitle && <p className="truncate text-xs leading-snug text-slate-500">{subtitle}</p>}
                        </div>
                        <button
                            type="button"
                            aria-label="Close preview"
                            data-tip="Close preview"
                            data-tip-side="bottom"
                            data-tip-preferred-side="bottom"
                            data-tip-locked="true"
                            onClick={() => onClose?.()}
                            className="dromis-tip -mr-1 -mt-0.5 shrink-0 rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                    {hasTabs && (
                        <div className="mt-2">
                            <SectionTabs
                                appearance="plain"
                                value={active?.key ?? activeTab}
                                onChange={selectMainTab}
                                ariaLabel="Document tabs"
                                tabs={tabs.map((tab) => ({
                                    id: tab.key,
                                    label: tab.label,
                                    icon: tab.icon,
                                }))}
                            />
                        </div>
                    )}
                </div>
                {resolvedMessage && (
                    <p className="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs font-bold text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        {resolvedMessage}
                    </p>
                )}
                {resolvedPanel ? (
                    <div className="min-h-0 flex-1 overflow-auto bg-white dark:bg-zinc-950">
                        {resolvedPanel}
                    </div>
                ) : resolvedSrc ? (
                    <iframe
                        key={resolvedSrc}
                        title={iframeTitle}
                        src={resolvedSrc}
                        className="min-h-0 w-full flex-1 bg-white"
                    />
                ) : (
                    <div className="flex flex-1 flex-col items-center justify-center gap-2 px-6 text-center">
                        <p className="text-sm font-black text-slate-800 dark:text-zinc-100">
                            {resolvedMessage
                                ? 'Document preview is unavailable.'
                                : resolvedKind === 'signed'
                                    ? 'Signed file unavailable from e-PIRMA'
                                    : 'Document preview is unavailable.'}
                        </p>
                        {resolvedKind === 'signed' && !resolvedMessage && (
                            <p className="max-w-md text-xs font-semibold text-slate-500">
                                The e-PIRMA signed PDF could not be loaded. DomPDF draft is not shown here.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
