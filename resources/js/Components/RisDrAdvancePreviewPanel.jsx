import {
  DOCUMENT_PREVIEW_ZOOM_OPTIONS,
} from "@/Components/DocumentPreviewCanvas";
import { PrintableRisDr, RrosOfficialPreviewCanvas } from "@/Components/RrosOfficialDocuments";

/**
 * Advance RIS / DR printable preview — same chrome as Dispatch Plan Document Preview.
 */
export default function RisDrAdvancePreviewPanel({
  form,
  tracking = {},
  type = "ris",
  zoom,
  onZoomChange,
  signatories = [],
}) {
  return (
    <div
      className="flex h-full min-h-0 flex-col"
      onClick={(event) => event.stopPropagation()}
      onMouseDown={(event) => event.stopPropagation()}
    >
      <div className="flex flex-wrap items-center justify-end gap-3 border-b border-slate-200 bg-white px-3 py-2 dark:border-zinc-800">
        <div className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1 dark:border-zinc-700 dark:bg-zinc-900">
          {DOCUMENT_PREVIEW_ZOOM_OPTIONS.map((option) => (
            <button
              key={option.value}
              type="button"
              onClick={() => onZoomChange?.(option.value)}
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
      </div>
      <div className="min-h-0 flex-1 overflow-hidden">
        <RrosOfficialPreviewCanvas zoom={zoom} className="h-full">
          <PrintableRisDr
            form={form}
            tracking={tracking}
            type={type}
            signatories={signatories}
          />
        </RrosOfficialPreviewCanvas>
      </div>
    </div>
  );
}
