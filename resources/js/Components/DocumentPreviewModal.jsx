import { X } from "lucide-react";
import SectionTabs from "@/Components/SectionTabs";
import DocumentPreviewCanvas, {
  DEFAULT_DOCUMENT_PREVIEW_ZOOM,
  DOCUMENT_PREVIEW_ZOOM_OPTIONS,
} from "@/Components/DocumentPreviewCanvas";

/**
 * Modal chrome matching RIS/DR document preview (header, tabs, zoom, canvas, footer).
 */
export default function DocumentPreviewModal({
  open,
  onClose,
  eyebrow = "Document preview",
  badge = "Draft / local preview",
  badgeTone = "amber",
  title,
  subtitle,
  notice = null,
  tabs = null,
  activeTab = null,
  onTabChange = null,
  zoom = DEFAULT_DOCUMENT_PREVIEW_ZOOM,
  onZoomChange = null,
  paperWidth = "210mm",
  /** When false, children fill the pane (e.g. official PDF iframe) instead of A4 paper canvas. */
  usePaperCanvas = true,
  footer = null,
  children,
  zIndexClass = "z-[190]",
}) {
  if (!open) return null;

  const badgeClass =
    badgeTone === "emerald"
      ? "bg-emerald-600 text-white"
      : "bg-amber-100 text-amber-900";

  return (
    <div
      className={`fixed inset-0 ${zIndexClass} flex items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm`}
      role="dialog"
      aria-modal="true"
      aria-label={title || "Document preview"}
    >
      <div className="flex h-[96vh] w-[96vw] max-w-[1600px] flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-950">
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-zinc-800">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-[10px] font-black uppercase tracking-wide text-orange-700">{eyebrow}</p>
              {badge && (
                <span className={`rounded px-2 py-0.5 text-[10px] font-black uppercase ${badgeClass}`}>
                  {badge}
                </span>
              )}
            </div>
            <h2 className="mt-0.5 truncate font-black text-slate-900 dark:text-zinc-50">{title}</h2>
            {subtitle && <p className="truncate text-xs text-slate-500">{subtitle}</p>}
          </div>
          <button
            type="button"
            onClick={onClose}
            className="dromis-tip shrink-0 rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800"
            data-tip="Close document preview"
            data-tip-side="bottom"
            data-tip-preferred-side="bottom"
            data-tip-locked="true"
            aria-label="Close document preview"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {(tabs?.length || (usePaperCanvas && onZoomChange)) && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-3 py-2 dark:border-zinc-800">
            {tabs?.length ? (
              <SectionTabs
                appearance="plain"
                value={activeTab}
                onChange={onTabChange}
                ariaLabel="Document preview tabs"
                tabs={tabs}
              />
            ) : (
              <span />
            )}
            {usePaperCanvas && onZoomChange && (
              <div className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1 dark:border-zinc-700 dark:bg-zinc-900">
                {DOCUMENT_PREVIEW_ZOOM_OPTIONS.map((option) => (
                  <button
                    key={option.value}
                    type="button"
                    onClick={() => onZoomChange(option.value)}
                    className={`rounded-md px-2.5 py-1 text-[11px] font-black ${
                      zoom === option.value
                        ? "bg-slate-900 text-white dark:bg-white dark:text-slate-900"
                        : "text-slate-600 hover:bg-white dark:text-zinc-300 dark:hover:bg-zinc-800"
                    }`}
                    title={`Zoom ${option.label}`}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {notice && (
          <p className="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs font-bold text-amber-950">
            {notice}
          </p>
        )}

        <div className="min-h-0 flex-1 overflow-hidden">
          {usePaperCanvas ? (
            <DocumentPreviewCanvas zoom={zoom} paperWidth={paperWidth} className="h-full">
              {children}
            </DocumentPreviewCanvas>
          ) : (
            <div className="h-full w-full bg-slate-200 dark:bg-zinc-900">{children}</div>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
          {footer || (
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg bg-slate-900 px-5 py-2 text-sm font-black text-white"
            >
              Return to Editing
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
