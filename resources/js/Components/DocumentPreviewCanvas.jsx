import { useLayoutEffect, useRef, useState } from "react";

/** User zoom multipliers; with fit-to-width, 100% = page fills the preview canvas. */
export const DOCUMENT_PREVIEW_ZOOM_OPTIONS = [
  { value: 0.75, label: "75%" },
  { value: 1, label: "100%" },
  { value: 1.25, label: "125%" },
  { value: 1.5, label: "150%" },
];

/** Default user zoom (× fit-to-width scale). */
export const DEFAULT_DOCUMENT_PREVIEW_ZOOM = 1;

/** Horizontal padding on `.doc-preview-stage` (20px each side). */
const STAGE_PAD_X = 40;

export const DOCUMENT_PREVIEW_CANVAS_CSS = `
.doc-preview-canvas {
  min-height: 100%;
  background: linear-gradient(180deg, #cbd5e1 0%, #94a3b8 100%);
  overflow: auto;
}
.doc-preview-stage {
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 28px 20px 48px;
  min-width: min-content;
}
.doc-preview-paper {
  background: #fff;
  box-shadow:
    0 1px 2px rgba(15, 23, 42, 0.12),
    0 18px 40px rgba(15, 23, 42, 0.28);
  border: 1px solid rgba(15, 23, 42, 0.18);
  transform-origin: top center;
}
.doc-preview-paper > .print-document,
.doc-preview-paper > article,
.doc-preview-paper > .doc-preview-sheet {
  box-shadow: none !important;
  border-color: transparent !important;
}
@media print {
  .doc-preview-canvas,
  .doc-preview-stage,
  .doc-preview-paper {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    transform: none !important;
    zoom: 1 !important;
  }
}
`;

function paperWidthToCssPx(paperWidth) {
  const raw = String(paperWidth ?? "210mm").trim().toLowerCase();
  const value = parseFloat(raw);
  if (!Number.isFinite(value) || value <= 0) return (210 * 96) / 25.4;
  if (raw.endsWith("mm")) return (value * 96) / 25.4;
  if (raw.endsWith("cm")) return (value * 96) / 2.54;
  if (raw.endsWith("in")) return value * 96;
  if (raw.endsWith("px")) return value;
  return value;
}

/**
 * Gray PDF-viewer canvas with a centered paper sheet (screen preview only).
 * Scales the sheet to fill most of the canvas width by default; `zoom` multiplies that fit scale.
 * Print CSS resets zoom so true A4 mm sizing is unchanged.
 *
 * @param {object} props
 * @param {import('react').ReactNode} props.children
 * @param {number} [props.zoom=1] Multiplier of fit-to-width (or absolute scale when fitWidth is false)
 * @param {boolean} [props.fitWidth=true] Fill canvas width (modest padding), then apply zoom
 * @param {string} [props.paperWidth='210mm'] A4 default; use wider values for landscape worksheets
 * @param {string} [props.className]
 * @param {string} [props.paperClassName]
 */
export default function DocumentPreviewCanvas({
  children,
  zoom = DEFAULT_DOCUMENT_PREVIEW_ZOOM,
  fitWidth = true,
  paperWidth = "210mm",
  className = "",
  paperClassName = "",
}) {
  const canvasRef = useRef(null);
  const [fitScale, setFitScale] = useState(1);

  useLayoutEffect(() => {
    if (!fitWidth) {
      setFitScale(1);
      return undefined;
    }

    const el = canvasRef.current;
    if (!el) return undefined;

    const paperPx = paperWidthToCssPx(paperWidth);
    const update = () => {
      const available = Math.max(0, el.clientWidth - STAGE_PAD_X);
      if (paperPx <= 0 || available <= 0) return;
      // Cap slightly under 1× canvas so shadow/border don’t force a scrollbar at 100%.
      setFitScale(Math.max(0.5, (available / paperPx) * 0.98));
    };

    update();
    const observer = new ResizeObserver(update);
    observer.observe(el);
    return () => observer.disconnect();
  }, [fitWidth, paperWidth]);

  const effectiveZoom = fitWidth ? fitScale * zoom : zoom;

  return (
    <div ref={canvasRef} className={`doc-preview-canvas ${className}`.trim()}>
      <style>{DOCUMENT_PREVIEW_CANVAS_CSS}</style>
      <div className="doc-preview-stage">
        <div
          className={`doc-preview-paper ${paperClassName}`.trim()}
          style={{ zoom: effectiveZoom, width: paperWidth, maxWidth: paperWidth, minWidth: paperWidth }}
        >
          {children}
        </div>
      </div>
    </div>
  );
}
