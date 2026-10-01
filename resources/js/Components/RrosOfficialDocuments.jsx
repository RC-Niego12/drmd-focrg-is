import DocumentPreviewCanvas from "@/Components/DocumentPreviewCanvas";
import { formatWholeQuantity } from "@/Utils/wholeQuantity";

/** Shared printable styles for RIS / DR screen preview and print popup. */
export const RROS_OFFICIAL_PRINT_CSS = `
@page { size: A4 portrait; margin: 0.5in; }
/* RIS official sheet: 0.25in all sides (Google Sheets reference). DR keeps 0.5in. */
@page ris {
  size: A4 portrait;
  margin: 0.25in;
}
* { box-sizing: border-box; }
/* Browser print uses @page margins; keep html margin untouched for DomPDF parity if CSS is reused. */
html { color: #111; font-family: Arial, Helvetica, sans-serif; background: #fff; }
body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; background: #fff; }
.rros-official.print-document {
  width: 100%;
  max-width: none !important;
  margin: 0 auto;
  background: #fff;
  color: #111;
  border: 0 !important;
  box-shadow: none !important;
  padding: 0 !important;
}
.rros-official table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}
/* DomPDF ignores <colgroup>; fixed layout uses only row 0. Zero-height colspec sets % widths. */
.rros-official .ris-colspec td,
.rros-official .dr-colspec td,
.rros-official .dr-lower-colspec td {
  height: 0 !important;
  max-height: 0 !important;
  padding: 0 !important;
  border: 0 !important;
  font-size: 0 !important;
  line-height: 0 !important;
  overflow: hidden !important;
  visibility: hidden;
}
.rros-official th,
.rros-official td {
  border: 1px solid #111;
  padding: 2px 4px;
  vertical-align: middle;
  font-size: 8.5px;
  line-height: 1.15;
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: normal;
  overflow: visible;
  text-overflow: clip;
  white-space: normal;
}
.rros-official .no-border,
.rros-official .no-border td,
.rros-official .no-border th {
  border: 0 !important;
}
.rros-official .outer-frame {
  border: 1.5px solid #111;
}
.rros-official .doc-title {
  position: relative;
  text-align: center;
  font-family: "Times New Roman", Times, serif;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.2px;
  padding: 4px 6px;
}
.rros-official .section-title {
  background: #d9e2ef;
  text-align: center;
  font-weight: 700;
  font-size: 9px;
  letter-spacing: 0.3px;
}
.rros-official .label { font-weight: 700; white-space: nowrap; text-align: left; }
.rros-official .center { text-align: center; }
.rros-official .right { text-align: right; }
.rros-official .left { text-align: left; }
.rros-official .top { vertical-align: top; }
.rros-official .muted { color: #333; }
.rros-official .tiny { font-size: 7px; }
.rros-official .remarks-head {
  white-space: nowrap;
  font-size: 8px;
  letter-spacing: 0.02em;
}
.rros-official .remarks-cell {
  white-space: pre-line;
  word-break: normal;
  overflow-wrap: break-word;
  vertical-align: top;
  overflow: visible;
  /* Prefer wrapping at spaces/semicolons — avoid mid-peso splits */
  line-break: auto;
  hyphens: manual;
}
.rros-official.ris-sheet .remarks-cell {
  font-size: 7px;
  line-height: 1.12;
}
.rros-official .unit-cost-cell {
  white-space: nowrap;
  word-break: keep-all;
  overflow-wrap: normal;
  text-align: right;
}
.rros-official .item-name-cell {
  overflow: visible;
  text-overflow: clip;
  white-space: normal;
  word-break: normal;
  overflow-wrap: break-word;
}
.rros-official .sig-name-cell {
  overflow: visible;
  text-overflow: clip;
  white-space: normal;
  word-break: normal;
  overflow-wrap: break-word;
  hyphens: manual;
}
.rros-official .signature-space { height: 28px; }
.rros-official .footer {
  text-align: center;
  font-family: "Times New Roman", Times, serif;
  font-size: 7px;
  line-height: 1.2;
  padding: 3px 4px 0;
  border: 0;
}
.rros-official .official-logo {
  object-fit: contain;
  max-height: 38px;
  width: auto;
}
.rros-official .official-logo.bp { max-height: 34px; }
.rros-official .header-division {
  font-family: "Times New Roman", Times, serif;
  font-size: 11px;
  font-weight: 700;
  text-align: center;
  line-height: 1.2;
  letter-spacing: 0.02em;
  padding: 2px 6px;
}
.rros-official .drn-cell {
  font-size: 8px;
  font-weight: 700;
  text-align: right;
  padding: 2px 6px;
  vertical-align: middle;
}
.rros-official .appendix {
  position: absolute;
  top: 2px;
  right: 6px;
  font-size: 6.5px;
  font-style: italic;
  font-weight: 600;
  white-space: nowrap;
  line-height: 1.1;
}
.rros-official .ris-brand-header {
  padding: 2px 6px;
}
/* RIS only: open top/sides on logo band (both dual copies); keep bottom vs DRN. */
.rros-official.ris-sheet td.ris-brand-header {
  border-top: 0 !important;
  border-left: 0 !important;
  border-right: 0 !important;
  border-bottom: 1px solid #111 !important;
}
.rros-official .ris-brand-header .ris-brand-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
}
.rros-official .ris-brand-header .ris-brand-logos {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}
.rros-official .ris-brand-header .header-division {
  flex: 1 1 auto;
}
.rros-official .ris-meta-pair .label {
  margin-right: 4px;
}
.rros-official .item-row td { height: 16px; }
.rros-official .purpose-cell {
  font-size: 8px;
  line-height: 1.2;
  vertical-align: top;
}
/*
 * Dual-copy equal halves (matches DomPDF ris.blade.php):
 * Usable after 0.25in padding = 284.3mm; cut band 3.6mm; each copy 140.35mm.
 * Cut line at 140.35 + 1.8 = 142.15mm (content + page midpoint).
 */
.rros-official .copy-divider {
  flex: 0 0 3.6mm;
  height: 3.6mm;
  margin: 0;
  border: 0;
  display: flex;
  align-items: center;
  position: relative;
}
.rros-official .copy-divider::before {
  content: "";
  flex: 1 1 auto;
  border-top: 1px dashed #111;
}
.rros-official .copy-divider .screen-only {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  background: #fff;
  padding: 0 6px;
  font-size: 8px;
  font-weight: 700;
  color: #64748b;
  letter-spacing: 0.04em;
}
.rros-official.ris-sheet,
.rros-official.dr-sheet {
  width: 210mm;
  max-width: 210mm;
  min-width: 210mm;
  height: 297mm;
  min-height: 297mm;
  margin: 0 auto;
  background: #fff;
  color: #111;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
}
/* RIS screen preview: simulate @page 0.25in (official sheet). DR keeps 0.5in. */
.rros-official.ris-sheet {
  page: ris;
  padding: 0.25in !important;
}
.rros-official.dr-sheet {
  padding: 0.5in !important;
}
.rros-official .ris-copy {
  flex: 0 0 140.35mm;
  height: 140.35mm;
  max-height: 140.35mm;
  min-height: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  /* Modest inset before brand band; prefer this over shrinking logos. */
  padding-top: 4pt;
  box-sizing: border-box;
}
.rros-official .ris-copy > table {
  flex: 1 1 auto;
  height: 100%;
  min-height: 0;
}
.rros-official .ris-copy > .footer {
  flex: 0 0 auto;
}
/* DR: flex column + margin-top:auto pins footer to sheet bottom (gap under REMARKS when short). */
.rros-official.dr-sheet > table {
  flex: 0 1 auto;
  width: 100%;
  min-height: 0;
}
.rros-official.dr-sheet > .footer {
  flex: 0 0 auto;
  margin-top: auto;
  padding-top: 4px;
}
.rros-official.ris-sheet th,
.rros-official.ris-sheet td {
  padding: 1.5px 3px;
  font-size: 8px;
}
.rros-official.ris-sheet .doc-title { font-size: 13px; padding: 3px; }
.rros-official.ris-sheet .signature-space,
.rros-official.ris-sheet .signature-space td {
  height: 40px;
  min-height: 40px;
}
.rros-official.ris-sheet .item-row td { height: 14px; }
.rros-official.dr-sheet th,
.rros-official.dr-sheet td {
  padding: 5px 6px;
  font-size: 10px;
  line-height: 1.25;
}
.rros-official.dr-sheet .ris-brand-header {
  padding: 6px 10px;
}
.rros-official.dr-sheet .header-division { font-size: 11px; line-height: 1.2; letter-spacing: 0.02em; }
.rros-official.dr-sheet .header-division-line { white-space: nowrap; display: inline-block; }
.rros-official.dr-sheet .doc-title { font-size: 18px; padding: 8px 10px; letter-spacing: 0.4px; }
.rros-official.dr-sheet .section-title { font-size: 10px; padding: 5px 6px; }
.rros-official.dr-sheet .tiny { font-size: 8.5px; }
.rros-official.dr-sheet .remarks-head { font-size: 9px; }
.rros-official.dr-sheet .purpose-cell { font-size: 9.5px; line-height: 1.3; padding: 6px 8px; }
.rros-official.dr-sheet .transport-label {
  font-size: 9.5px;
  line-height: 1.2;
  padding: 6px 6px;
  white-space: normal;
  word-break: keep-all;
  overflow-wrap: normal;
}
.rros-official.dr-sheet .dr-meta-label {
  font-weight: 700;
  white-space: nowrap;
  text-align: right;
  vertical-align: middle;
  padding: 6px 6px;
  word-break: keep-all;
  overflow-wrap: normal;
}
.rros-official.dr-sheet .dr-meta-value {
  vertical-align: middle;
  padding: 6px 8px;
}
.rros-official.dr-sheet th.dr-qty-unit-head {
  text-align: center;
  white-space: nowrap !important;
  word-break: keep-all;
  overflow-wrap: normal;
  padding: 5px 3px;
}
.rros-official.dr-sheet .dr-recipient-label {
  font-weight: 700;
  white-space: nowrap;
  vertical-align: middle;
  text-align: left;
  padding: 6px 8px;
  word-break: keep-all;
  overflow-wrap: normal;
}
.rros-official.dr-sheet .dr-recipient-value {
  vertical-align: middle;
  padding: 6px 10px;
  text-align: left;
}
/* Item rows absorb leftover page height (no fixed tiny heights) */
.rros-official.dr-sheet .item-row td {
  height: 4.5%;
  min-height: 28px;
  padding: 7px 6px;
  font-size: 10px;
  vertical-align: middle;
}
.rros-official.dr-sheet .item-row .remarks-cell {
  vertical-align: top;
  font-size: 8.5px;
}
.rros-official.dr-sheet td.dr-lower-wrap {
  height: 28%;
  padding: 0 !important;
  border: 0 !important;
  vertical-align: top;
  background: #fff;
  position: relative;
  z-index: 1;
}
.rros-official.dr-sheet .dr-lower {
  height: 100%;
}
.rros-official.dr-sheet .signature-space,
.rros-official.dr-sheet .signature-space td {
  height: 56px;
}
.rros-official.dr-sheet .dr-lower .dr-sig-row-label,
.rros-official.dr-sheet .dr-lower .dr-sig-role,
.rros-official.dr-sheet .dr-lower .dr-sig-name,
.rros-official.dr-sheet .dr-lower .dr-sig-designation,
.rros-official.dr-sheet .dr-lower .dr-sig-office,
.rros-official.dr-sheet .dr-lower .dr-purpose-label,
.rros-official.dr-sheet .dr-lower .dr-purpose-value {
  padding: 6px 7px;
}
/* Must beat .rros-official.dr-sheet td { font-size: 10px } (0,2,1) */
.rros-official.dr-sheet td.dr-number-box,
.rros-official td.dr-number-box {
  background: #fde047;
  text-align: center;
  font-size: 20px;
  font-weight: 800;
  letter-spacing: 0.02em;
  vertical-align: middle;
  padding: 6px 4px;
  white-space: nowrap;
  line-height: 1.15;
}
.rros-official .ris-number-box {
  background: #fde047;
}
.rros-official .check { font-family: Arial, sans-serif; font-size: 12px; font-weight: 700; }
/* PURPOSE + 5×6 signatory grid — own table so parent 15-col borders cannot bleed through */
.rros-official .dr-lower {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  background: #fff;
}
.rros-official .dr-lower > colgroup > col.dr-lower-label {
  width: 15%;
}
.rros-official .dr-lower > colgroup > col.dr-lower-role-primary {
  width: 25%;
}
.rros-official .dr-lower > colgroup > col.dr-lower-role-secondary {
  width: 25%;
}
.rros-official .dr-lower > colgroup > col.dr-lower-role {
  width: 17.5%;
}
.rros-official .dr-lower > tbody > tr > th,
.rros-official .dr-lower > tbody > tr > td {
  border: 1px solid #111;
  overflow: visible;
  background: #fff;
}
.rros-official .dr-lower .dr-purpose-label {
  font-weight: 700;
  text-align: right;
  white-space: nowrap;
  vertical-align: middle;
  font-size: 10px;
}
.rros-official .dr-lower .dr-purpose-value {
  font-size: 9.5px;
  line-height: 1.3;
  vertical-align: top;
  text-align: left;
}
.rros-official .dr-lower .dr-sig-header-row > th {
  border-top: 3px solid #111;
}
.rros-official .dr-lower .dr-sig-date-row > td {
  border-bottom: 3px solid #111;
}
.rros-official .dr-lower .dr-sig-row-label {
  font-weight: 700;
  text-align: right;
  white-space: nowrap;
  vertical-align: middle;
  font-size: 9px;
  padding: 6px 8px;
}
.rros-official .dr-lower .dr-sig-office-label {
  white-space: normal;
  line-height: 1.15;
}
.rros-official .dr-lower .dr-sig-role {
  text-align: center;
  font-weight: 700;
  font-size: 9px;
  line-height: 1.2;
  text-transform: uppercase;
  white-space: normal;
  overflow: visible;
  word-break: normal;
  overflow-wrap: break-word;
  hyphens: manual;
  padding: 6px 8px;
}
.rros-official .dr-lower .dr-sig-name {
  text-align: center;
  font-weight: 700;
  text-transform: uppercase;
  font-size: 9.5px;
  line-height: 1.2;
  white-space: nowrap;
  overflow: visible;
  word-break: keep-all;
  overflow-wrap: normal;
  hyphens: manual;
  padding: 6px 8px;
}
.rros-official .dr-lower .dr-sig-designation {
  text-align: center;
  font-style: italic;
  text-transform: uppercase;
  font-size: 8.5px;
  line-height: 1.2;
  white-space: normal;
  overflow: visible;
  word-break: normal;
  overflow-wrap: break-word;
  padding: 6px 8px;
}
.rros-official .dr-lower .dr-sig-office {
  text-align: center;
  font-style: normal;
  font-weight: 400;
  text-transform: uppercase;
  font-size: 8.5px;
  line-height: 1.2;
  white-space: normal;
  overflow: visible;
  word-break: normal;
  overflow-wrap: break-word;
  padding: 6px 8px;
}
/* Beat .dr-lower td / .rros-official.dr-sheet td padding+middle, and column center from .dr-sig-role */
.rros-official .dr-lower td.dr-sig-transport,
.rros-official.dr-sheet .dr-lower td.dr-sig-transport {
  text-align: left !important;
  vertical-align: top !important;
  font-style: italic;
  font-size: 6.5px;
  line-height: 1.15;
  overflow: visible;
  white-space: normal;
  padding: 1px 2px 1px 2px !important;
}
.rros-official .dr-lower td.dr-sig-transport .dr-sig-transport-line,
.rros-official.dr-sheet .dr-lower td.dr-sig-transport .dr-sig-transport-line {
  display: block;
  text-align: left !important;
  margin: 0;
  padding: 0;
}
.rros-official .dr-lower td.dr-sig-transport .dr-sig-transport-label,
.rros-official.dr-sheet .dr-lower td.dr-sig-transport .dr-sig-transport-label {
  display: block;
  text-align: left !important;
  font-style: italic;
  font-weight: 400;
  font-size: 6.5px;
}
.rros-official .dr-lower td.dr-sig-transport .dr-sig-transport-value,
.rros-official.dr-sheet .dr-lower td.dr-sig-transport .dr-sig-transport-value {
  display: block;
  text-align: left !important;
  font-style: normal;
  font-weight: 700;
  text-transform: uppercase;
  margin: 1px 0 0;
  font-size: 6.5px;
  white-space: pre-line;
}
.rros-official .transport-label {
  font-weight: 700;
  text-align: right;
  vertical-align: middle;
  line-height: 1.15;
  white-space: normal;
  word-break: keep-all;
  overflow-wrap: normal;
}
/* PDF-viewer chrome (screen only) */
.rros-preview-canvas {
  min-height: 100%;
  background:
    linear-gradient(180deg, #cbd5e1 0%, #94a3b8 100%);
  overflow: auto;
}
.rros-preview-stage {
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 28px 20px 48px;
  min-width: min-content;
}
.rros-preview-paper {
  background: #fff;
  box-shadow:
    0 1px 2px rgba(15, 23, 42, 0.12),
    0 18px 40px rgba(15, 23, 42, 0.28);
  border: 1px solid rgba(15, 23, 42, 0.18);
  transform-origin: top center;
}
.rros-preview-paper .rros-official.print-document {
  box-shadow: none !important;
  border: 0 !important;
}
@media print {
  @page { size: A4 portrait; margin: 0.5in; }
  @page ris { size: A4 portrait; margin: 0.25in; }
  html {
    width: 100% !important;
    height: auto !important;
    padding: 0 !important;
    background: #fff !important;
  }
  body {
    width: 100% !important;
    height: auto !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
  }
  .rros-official.print-document {
    width: 100% !important;
    min-width: 0 !important;
    max-width: none !important;
    min-height: 0 !important;
    padding: 0 !important;
    box-shadow: none !important;
  }
  /* Printable area: RIS A4 − 0.25in×2 ≈ 284.3mm; DR A4 − 0.5in×2 ≈ 271.6mm */
  .rros-official.ris-sheet,
  .rros-official.dr-sheet {
    width: 100% !important;
    max-width: none !important;
    min-width: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    page-break-after: avoid;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .rros-official.ris-sheet {
    page: ris;
    height: 284.3mm !important;
    min-height: 284.3mm !important;
    max-height: 284.3mm !important;
  }
  .rros-official.dr-sheet {
    height: 271.6mm !important;
    min-height: 271.6mm !important;
    max-height: 271.6mm !important;
  }
  .rros-official .ris-copy {
    flex: 0 0 140.35mm !important;
    height: 140.35mm !important;
    max-height: 140.35mm !important;
    min-height: 0 !important;
    overflow: hidden !important;
    padding-top: 4pt !important;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .rros-official.dr-sheet > table {
    flex: 0 1 auto !important;
    width: 100% !important;
    min-height: 0 !important;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .rros-official.dr-sheet > .footer {
    flex: 0 0 auto !important;
    margin-top: auto !important;
  }
  .rros-official.dr-sheet .item-row td {
    height: 4.5% !important;
    min-height: 24px !important;
  }
  .rros-official.dr-sheet td.dr-lower-wrap {
    height: 28% !important;
    padding: 0 !important;
    border: 0 !important;
    background: #fff !important;
  }
  .rros-official.dr-sheet .signature-space,
  .rros-official.dr-sheet .signature-space td {
    height: 48px !important;
  }
  .rros-official .copy-divider {
    flex: 0 0 3.6mm !important;
    height: 3.6mm !important;
    margin: 0 !important;
  }
  .rros-official .copy-divider .screen-only { display: none !important; }
  .rros-preview-canvas,
  .rros-preview-stage,
  .rros-preview-paper {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    transform: none !important;
  }
  .rros-official th,
  .rros-official td {
    border: 1px solid #111 !important;
    overflow: visible !important;
    text-overflow: clip !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .rros-official .unit-cost-cell {
    white-space: nowrap !important;
  }
  .rros-official .dr-lower .dr-sig-name {
    white-space: nowrap !important;
  }
  .rros-official .section-title,
  .rros-official.dr-sheet td.dr-number-box,
  .rros-official td.dr-number-box,
  .rros-official .ris-number-box {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .rros-official.dr-sheet td.dr-number-box,
  .rros-official td.dr-number-box {
    font-size: 20px;
    font-weight: 800;
  }
  .rros-official .no-border,
  .rros-official .no-border td,
  .rros-official .no-border th { border: 0 !important; }
  /* Keep title/DRN bands as single cells (no forced inner verticals). */
  .rros-official td.doc-title,
  .rros-official td.drn-cell {
    border-left: 1px solid #111 !important;
    border-right: 1px solid #111 !important;
  }
  /* RIS logo band: no top/left/right (both dual copies). */
  .rros-official.ris-sheet td.ris-brand-header {
    border-top: 0 !important;
    border-left: 0 !important;
    border-right: 0 !important;
    border-bottom: 1px solid #111 !important;
  }
  .rros-official td.dr-lower-wrap {
    padding: 0 !important;
    border: 0 !important;
    background: #fff !important;
  }
  .rros-official td.dr-lower-wrap .dr-lower th,
  .rros-official td.dr-lower-wrap .dr-lower td {
    border: 1px solid #111 !important;
    background: #fff !important;
    overflow: visible !important;
  }
  .rros-official td.dr-lower-wrap .dr-lower .dr-sig-header-row > th {
    border-top: 3px solid #111 !important;
  }
  .rros-official td.dr-lower-wrap .dr-lower .dr-sig-date-row > td {
    border-bottom: 3px solid #111 !important;
  }
  .rros-official .dr-lower td.dr-sig-transport,
  .rros-official.dr-sheet .dr-lower td.dr-sig-transport {
    text-align: left !important;
    vertical-align: top !important;
    padding: 1px 2px 1px 2px !important;
  }
  .rros-official .dr-lower td.dr-sig-transport .dr-sig-transport-line,
  .rros-official.dr-sheet .dr-lower td.dr-sig-transport .dr-sig-transport-line {
    display: block;
    text-align: left !important;
  }
}
`.trim();

const applicableExpiry = (value) => {
  const normalized = String(value || "").trim().toLowerCase();
  return !["", "-", "n/a", "na", "not applicable", "none", "null"].includes(normalized);
};

const readableDate = (value, { weekday = false } = {}) => {
  if (!applicableExpiry(value)) return null;
  const raw = String(value).trim();
  const parsed = new Date(/^\d{4}-\d{2}-\d{2}/.test(raw) ? `${raw.slice(0, 10)}T00:00:00` : raw);
  if (Number.isNaN(parsed.getTime())) return raw;
  return parsed.toLocaleDateString("en-PH", {
    ...(weekday ? { weekday: "long" } : {}),
    year: "numeric",
    month: "long",
    day: "numeric",
  });
};

const peso = (value) =>
  new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(Number(value));

/** RIS item remarks: keep full auto-generated content (expiry, unit price, notes, user text). */
export function documentRisItemRemarks(remarks) {
  return String(remarks || "").trim();
}

/**
 * DR item remarks: expiry details only (with qty when multiple expiries).
 * Never include unit price — DR already has a Unit Cost column.
 */
export function documentDrItemRemarks(remarks, expiry = null) {
  const normalizedExpiry = String(expiry || "").trim();
  if (normalizedExpiry && !/^(?:-|n\/?a|not applicable|none|null)$/i.test(normalizedExpiry)) {
    const parsed = new Date(normalizedExpiry);
    const label = Number.isNaN(parsed.getTime())
      ? normalizedExpiry
      : parsed.toLocaleDateString("en-PH", { month: "short", year: "numeric" });
    return `Expiry: ${label}.`;
  }
  return String(remarks || "")
    .split(/\n+/)
    .map((line) => {
      const withoutPrice = line
        .replace(/\s*;?\s*Unit prices?:\s*[^.;\n]+/gi, "")
        .replace(/\s*;?\s*Note:\s*Sea-travel fallback\.?/gi, "")
        .replace(/\s*;\s*;+/g, ";")
        .replace(/^[\s;]+|[\s;]+$/g, "")
        .trim();
      if (!withoutPrice) return "";

      const numbered = withoutPrice.match(
        /^(\d+\.\s*(?:Qty:\s*[^;]+;\s*)?Expiry:\s*[^.;]+)/i,
      );
      if (numbered) return `${numbered[1].trim().replace(/[.;\s]+$/, "")}.`;

      const qtyExpiry = withoutPrice.match(
        /^(Qty:\s*[^;]+;\s*Expiry:\s*[^.;]+)/i,
      );
      if (qtyExpiry) return `${qtyExpiry[1].trim().replace(/[.;\s]+$/, "")}.`;

      const expiryOnly = withoutPrice.match(/^((?:Expiry|Expiries):\s*[^.;]+)/i);
      if (expiryOnly) return `${expiryOnly[1].trim().replace(/[.;\s]+$/, "")}.`;

      return "";
    })
    .filter(Boolean)
    .join("\n");
}

/**
 * DR UNIT COST: prefer prices embedded in auto-generated remarks (same source as
 * RIS remarks unit price), then explicit unit_cost/unit_price fields.
 */
export function documentDrUnitCost(item) {
  const remarks = String(item?.remarks || "");
  const prices = [];
  const priceChunks = remarks.matchAll(/Unit prices?:\s*([^./\n]+)/gi);
  for (const match of priceChunks) {
    const amounts = String(match[1]).match(/[\d,]+(?:\.\d+)?/g) || [];
    amounts.forEach((amount) => {
      const parsed = Number(String(amount).replace(/,/g, ""));
      if (Number.isFinite(parsed) && parsed >= 0) prices.push(parsed);
    });
  }
  if (prices.length) {
    const unique = [...new Set(prices.map((price) => Number(price.toFixed(4))))];
    if (unique.length === 1) return unique[0];
    return prices.reduce((sum, price) => sum + price, 0) / prices.length;
  }

  const explicit = [item?.unit_cost, item?.unit_price]
    .map((value) => Number(value))
    .find((value) => Number.isFinite(value) && value >= 0);
  return explicit != null ? explicit : null;
}

function OfficialFooter() {
  return (
    <p className="footer">
      PAGE 1 of 1
      <br />
      DSWD Field Office Caraga, R. Palma Street, Butuan City, Philippines 8600
      <br />
      Website: http://www.caraga.dswd.gov.ph Tel Nos.: (085) 303-8620 local 238
    </p>
  );
}

function signatory(signatories, type, context, fallback = "") {
  const row = (signatories || []).find((entry) => entry.library_type === type && entry.context === context);
  const [savedName = "", savedDesignation = ""] = String(row?.value || "").split("|");
  const employeeName = row?.metadata?.employee_name || "";
  const suffix = row?.metadata?.suffix || "";
  return {
    name: employeeName ? `${employeeName}${suffix ? `, ${suffix}` : ""}` : savedName.trim() || fallback,
    position: row?.metadata?.position || "",
    designation: row?.metadata?.designation || savedDesignation.trim(),
    office: row?.metadata?.office || row?.metadata?.office_unit || row?.metadata?.myportal_office || "",
  };
}

/** Display-only: printed/signatory names render in ALL CAPS without mutating library storage. */
function printedName(value) {
  return String(value || "").toUpperCase();
}

function withdrawalRemarks(items = []) {
  const byWarehouse = new Map();
  items
    .filter((item) => item?.warehouse_name)
    .forEach((item) => {
      const key = String(item.warehouse_name).trim();
      if (!key) return;
      const parts = byWarehouse.get(key) || [];
      if (item.item_name) {
        parts.push(`${item.item_name}: ${formatWholeQuantity(item.quantity, "0")}`);
      }
      byWarehouse.set(key, parts);
    });
  const entries = [...byWarehouse.entries()];
  if (!entries.length) return "";
  const warehouseWord = entries.length === 1 ? "warehouse" : "warehouse/s";
  const lines = entries.map(([warehouse, parts], index) => {
    const detail = parts.length ? ` - ${parts.join("; ")};` : "";
    return `${index + 1}. ${warehouse}${detail}`;
  });
  return `To be withdrawn at the following ${warehouseWord}:\n${lines.join("\n")}`;
}

function stockAvailableMark(item) {
  if (!item || item.wit_stock_balance == null || item.wit_stock_balance === "") {
    return { yes: "", no: "", quantity: "" };
  }
  const available = Math.trunc(Number(item.wit_stock_balance));
  const hasStock = Number.isFinite(available) && available > 0;
  return {
    yes: hasStock ? "✓" : "",
    no: hasStock ? "" : "✓",
    quantity: Number.isFinite(available) ? formatWholeQuantity(available) : "",
  };
}

/** RIS item-table internal col % (10 cols; Description = cols 2–4). Visible: 6|8|36|7|4|4|7|28 */
// Delivery meta labels use colspan 2 (cols 0–1 = 14% — snug to "Contact Number:" + pad).
// Values use colspan 3 (cols 2–4 = 36%). Item grid: Stock6 Unit8 Desc36 Qty7 Yes4 No4 Qty7 Remarks28.
const RIS_COL_WIDTHS = [6, 8, 12, 12, 12, 7, 4, 4, 7, 28];
/** RIS signatory grid (5 cols): label 13% (Printed Name: one line), remaining split equally across 4 roles. */
const RIS_SIG_COL_WIDTHS = [13, 21.75, 21.75, 21.75, 21.75];
/** DR internal col % (15 cols). Qty5.5 Unit6.5 Desc25 Cost10 | Qty5.5 Unit6.5 Items25 Remarks16 */
const DR_COL_WIDTHS = [5.5, 6.5, 6.25, 6.25, 6.25, 6.25, 10, 5.5, 6.5, 5, 5, 5, 5, 5, 16];
/** DR lower PURPOSE/signatory grid (5 cols). */
const DR_LOWER_COL_WIDTHS = [15, 25, 25, 17.5, 17.5];

/**
 * Pad each RIS copy's items table to this many rows (visual fill within fixed 140.35mm half).
 * Cut-line position is enforced by fixed .ris-copy height, not by blank-row count.
 * DomPDF max safe target with current signature height is 9 (10+ overflows the half).
 */
const RIS_MIN_ITEM_ROWS = 9;

function OfficialRisCopy({ form, tracking, items, signatories }) {
  const dash = "";
  // Each copy pads independently so top + bottom halves fill the sheet evenly.
  const blankRows = Math.max(0, RIS_MIN_ITEM_ROWS - items.length);
  const rows = [...items, ...Array.from({ length: blankRows }, () => null)];
  const requestedBy = signatory(signatories, "rros_ris_signatory", "requested_by", tracking.prepared_by_name);
  const approvedBy = signatory(signatories, "rros_ris_signatory", "approved_by");
  const issuedBy = signatory(signatories, "rros_ris_signatory", "issued_by", tracking.release_witnessed_by);
  const warehouseNote = withdrawalRemarks(items);

  return (
    <section className="ris-copy">
      {/* Single table keeps outer/inner borders continuous (logo → DRN → title → body). */}
      <table>
        {/* Visible: STOCK6 UNIT8 DESC36 QTY7 YES4 NO4 QTY7 REMARKS28 (Desc colspan 3; delivery labels 14%) */}
        <colgroup>
          {RIS_COL_WIDTHS.map((width, index) => (
            <col key={index} style={{ width: `${width}%` }} />
          ))}
        </colgroup>
        <tbody>
          {/* DomPDF fixed-layout reads only row 0 for column % (colgroup is dropped). */}
          <tr className="ris-colspec" aria-hidden="true">
            {RIS_COL_WIDTHS.map((width, index) => (
              <td key={index} style={{ width: `${width}%` }}>&nbsp;</td>
            ))}
          </tr>
          <tr>
            <td className="ris-brand-header" colSpan={10}>
              <div className="ris-brand-inner">
                <div className="ris-brand-logos">
                  <img className="official-logo" src="/images/dswd_logo_3.png" alt="DSWD Field Office Caraga" />
                  <img className="official-logo bp" src="/images/Bagong_PilipinasTransparent.png" alt="Bagong Pilipinas" />
                </div>
                <div className="header-division">
                  DISASTER RESPONSE MANAGEMENT DIVISION
                  <br />
                  FIELD OFFICE CARAGA
                </div>
              </div>
            </td>
          </tr>
          <tr>
            <td className="drn-cell" colSpan={10}>
              <b>DRN:</b> {tracking.ris_drn || ""}
            </td>
          </tr>
          <tr>
            <td className="doc-title" colSpan={10}>
              REQUISITION AND ISSUANCE SLIP (RIS)
              <span className="appendix">Appendix 63</span>
            </td>
          </tr>
          <tr>
            <td className="ris-meta-pair" colSpan={6}>
              <span className="label">Office / Bureau:</span> <b>DSWD Caraga</b>
            </td>
            <td className="ris-meta-pair" colSpan={4}>
              <span className="label">Fund Cluster:</span> <b>101</b>
            </td>
          </tr>
          <tr>
            <td className="ris-meta-pair" colSpan={6}>
              <span className="label">Division:</span> DRMD
            </td>
            <td className="ris-meta-pair" colSpan={4}>
              <span className="label">Responsibility Center Code:</span>
            </td>
          </tr>
          <tr>
            <td className="ris-meta-pair" colSpan={6}>
              <span className="label">Address:</span> R. Palma Street, Butuan City
            </td>
            <td className="ris-meta-pair ris-number-box" colSpan={4}>
              <span className="label">RIS No.:</span> <b>{form.ris_number || dash}</b>
            </td>
          </tr>
          <tr>
            <th className="section-title" colSpan={6}><i>Requisition</i></th>
            <th className="section-title" colSpan={4}><i>Stocks Available?</i></th>
          </tr>
          <tr>
            <th className="center">Stock No.</th>
            <th className="center">Unit</th>
            <th className="center" colSpan={3}>Description</th>
            <th className="center">Quantity</th>
            <th className="center">Yes</th>
            <th className="center">No</th>
            <th className="center">Quantity</th>
            <th className="center remarks-head">REMARKS</th>
          </tr>
          {rows.map((item, index) => {
            const stock = stockAvailableMark(item);
            return (
              <tr key={index} className="item-row">
                <td className="center">{item ? index + 1 : ""}</td>
                <td className="center">{item?.unit || ""}</td>
                <td className="left item-name-cell" colSpan={3}>{item?.item_name || ""}</td>
                <td className="center">{item ? formatWholeQuantity(item.quantity, "0") : ""}</td>
                <td className="center check">{stock.yes}</td>
                <td className="center check">{stock.no}</td>
                <td className="center">{stock.quantity}</td>
                <td className="tiny left remarks-cell">{item ? documentRisItemRemarks(item.remarks) : ""}</td>
              </tr>
            );
          })}
          <tr>
            <td className="label" colSpan={2}>Delivery Site:</td>
            <td colSpan={3}>{form.delivery_site || dash}</td>
            <th className="center" colSpan={5}>Returned / Cancelled Items:</th>
          </tr>
          <tr>
            <td className="label" colSpan={2}>Contact Person:</td>
            <td colSpan={3}>{form.receiving_representative || dash}</td>
            <th className="center">Date</th>
            <th className="center" colSpan={2}>Particular</th>
            <th className="center">Quantity</th>
            <th className="center">Certified by:</th>
          </tr>
          <tr>
            <td className="label" colSpan={2}>Contact Number:</td>
            <td colSpan={3}>{form.contact_number || dash}</td>
            <td className="center tiny">{readableDate(tracking.returned_at) || ""}</td>
            <td className="tiny" colSpan={2}>{tracking.returned_particulars || ""}</td>
            <td className="center">{tracking.returned_quantity !== "" && tracking.returned_quantity != null ? formatWholeQuantity(tracking.returned_quantity) : ""}</td>
            <td className="tiny center">{tracking.returned_certified_by || ""}</td>
          </tr>
          <tr>
            <td className="label" colSpan={2}>Delivery Date:</td>
            <td colSpan={3}>{readableDate(tracking.delivered_at || form.ris_date) || dash}</td>
            <td className="label" colSpan={5}>
              Remarks: {tracking.returned_reason || ""}
            </td>
          </tr>
          <tr>
            <td className="label top" colSpan={2}>Purpose:</td>
            <td className="purpose-cell" colSpan={3}>{form.purpose_of_release || dash}</td>
            <td className="purpose-cell tiny remarks-cell" colSpan={5}>
              {warehouseNote || form.remarks || ""}
            </td>
          </tr>
          <tr>
            <td colSpan={10} style={{ padding: 0, border: 0 }}>
              <table style={{ width: "100%", borderCollapse: "collapse", tableLayout: "fixed" }}>
                <colgroup>
                  {RIS_SIG_COL_WIDTHS.map((width, index) => (
                    <col key={index} style={{ width: `${width}%` }} />
                  ))}
                </colgroup>
                <tbody>
                  {/* DomPDF fixed-layout reads only row 0 for column % (colgroup is dropped). */}
                  <tr className="ris-colspec" aria-hidden="true">
                    {RIS_SIG_COL_WIDTHS.map((width, index) => (
                      <td key={index} style={{ width: `${width}%` }}>&nbsp;</td>
                    ))}
                  </tr>
                  <tr>
                    <th className="label center">Signature:</th>
                    <th className="center">Requested by:</th>
                    <th className="center">Approved by:</th>
                    <th className="center">Issued by:</th>
                    <th className="center">Received by:</th>
                  </tr>
                  <tr className="signature-space">
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                  </tr>
                  <tr>
                    <td className="label">Printed Name:</td>
                    <td className="center sig-name-cell"><b>{printedName(requestedBy.name)}</b></td>
                    <td className="center sig-name-cell"><b>{printedName(approvedBy.name)}</b></td>
                    <td className="center sig-name-cell"><b>{printedName(issuedBy.name)}</b></td>
                    <td className="center"></td>
                  </tr>
                  <tr>
                    <td className="label">Designation:</td>
                    <td className="center tiny sig-name-cell">{requestedBy.designation || requestedBy.position}</td>
                    <td className="center tiny sig-name-cell">{approvedBy.designation || approvedBy.position}</td>
                    <td className="center tiny sig-name-cell">{issuedBy.designation || issuedBy.position}</td>
                    <td></td>
                  </tr>
                  <tr>
                    <td className="label">Date:</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            </td>
          </tr>
        </tbody>
      </table>
      <OfficialFooter />
    </section>
  );
}

function OfficialDr({ form, tracking, items, documentId, signatories }) {
  const dash = "";
  const blankRows = Math.max(0, 8 - items.length);
  const rows = [...items, ...Array.from({ length: blankRows }, () => null)];
  const issuanceApprovedBy = signatory(signatories, "rros_dr_signatory", "issuance_approved_by", tracking.release_witnessed_by);
  const releasedBy = signatory(signatories, "rros_dr_signatory", "released_by", tracking.prepared_by_name);
  const warehouseNote = withdrawalRemarks(items);
  const selectedModes = (() => {
    const raw = tracking.mode_of_transportation ?? tracking.transport_mode ?? [];
    const list = Array.isArray(raw)
      ? raw
      : String(raw || "")
          .split(",")
          .map((item) => item.trim())
          .filter(Boolean);
    return list.map((item) => String(item).trim().toLowerCase()).filter(Boolean);
  })();
  const transportMark = (label) => {
    if (!selectedModes.length) return "☐";
    const needle = String(label).toLowerCase();
    const matches = selectedModes.some((selectedMode) => {
      if (selectedMode === needle) return true;
      if (needle === "dswd-owned" && selectedMode.includes("dswd")) return true;
      if (needle === "service provider" && selectedMode.includes("service")) return true;
      if (needle === "government asset" && selectedMode.includes("government")) return true;
      // Legacy saved value "Partner LGU" should still check Partner.
      if (
        (needle === "partner" || needle === "partner lgu") &&
        (selectedMode === "partner" || selectedMode.includes("partner"))
      ) {
        return true;
      }
      return false;
    });
    return matches ? "✓" : "☐";
  };
  const drNumber = tracking.dr_number || "DR NUMBER PENDING";

  return (
    <article id={documentId} className="rros-official print-document dr-sheet">
      <style>{RROS_OFFICIAL_PRINT_CSS}</style>
      <table>
        {/* Qty5.5 Unit6.5 Desc25(6.25×4) Cost10 | Qty5.5 Unit6.5 Items25(5×5) Remarks16 */}
        <colgroup>
          {DR_COL_WIDTHS.map((width, index) => (
            <col key={index} style={{ width: `${width}%` }} />
          ))}
        </colgroup>
        <tbody>
          <tr className="dr-colspec" aria-hidden="true">
            {DR_COL_WIDTHS.map((width, index) => (
              <td key={index} style={{ width: `${width}%` }}>&nbsp;</td>
            ))}
          </tr>
          <tr>
            <td className="ris-brand-header" colSpan={15}>
              <div className="ris-brand-inner">
                <div className="ris-brand-logos">
                  <img className="official-logo" src="/images/dswd_logo_3.png" alt="DSWD Field Office Caraga" />
                  <img className="official-logo bp" src="/images/Bagong_PilipinasTransparent.png" alt="Bagong Pilipinas" />
                </div>
                <div className="header-division">
                  <span className="header-division-line">DISASTER RESPONSE MANAGEMENT DIVISION</span>
                  <br />
                  FIELD OFFICE CARAGA
                </div>
              </div>
            </td>
          </tr>
          <tr>
            <td className="doc-title" colSpan={10}>DELIVERY RECEIPT</td>
            <td className="dr-number-box" colSpan={5}>{drNumber}</td>
          </tr>
          <tr>
            <td className="dr-meta-label" colSpan={2}>DATE:</td>
            <td className="dr-meta-value" colSpan={13}><b>{readableDate(form.ris_date, { weekday: true }) || dash}</b></td>
          </tr>
          <tr>
            <td className="dr-meta-label" colSpan={2}>RECIPIENT:</td>
            <td className="dr-meta-value" colSpan={13}><b>{form.recipient || dash}</b></td>
          </tr>
          <tr>
            <td className="dr-meta-label" colSpan={2}>ADDRESS:</td>
            <td className="dr-meta-value" colSpan={13}>{form.delivery_site || dash}</td>
          </tr>
          <tr>
            <td className="dr-meta-label" colSpan={2}>RIS NO.:</td>
            <td className="dr-meta-value" colSpan={13}>{form.ris_number || dash}</td>
          </tr>
          <tr>
            <td className="transport-label" rowSpan={2} colSpan={3}>
              MODE OF
              <br />
              TRANSPORTATION:
            </td>
            <td colSpan={6}>
              <span className="check">{transportMark("DSWD-Owned")}</span> DSWD-Owned
            </td>
            <td colSpan={6}>
              <span className="check">{transportMark("Government Asset")}</span> Government Asset
            </td>
          </tr>
          <tr>
            <td colSpan={6}>
              <span className="check">{transportMark("Service Provider")}</span> Service Provider
            </td>
            <td colSpan={6}>
              <span className="check">{transportMark("Partner")}</span> Partner
            </td>
          </tr>
          <tr>
            <th className="section-title" colSpan={7}>ISSUED</th>
            <th className="section-title" colSpan={8}>RECEIVED</th>
          </tr>
          <tr>
            <th className="dr-qty-unit-head">QTY</th>
            <th className="dr-qty-unit-head">UNIT</th>
            <th className="center" colSpan={4}>ITEMS DESCRIPTION</th>
            <th className="center">UNIT COST</th>
            <th className="dr-qty-unit-head">QTY</th>
            <th className="dr-qty-unit-head">UNIT</th>
            <th className="center" colSpan={5}>ITEM(S)</th>
            <th className="center remarks-head">REMARKS</th>
          </tr>
          {rows.map((item, index) => {
            const unitCost = item ? documentDrUnitCost(item) : null;
            return (
              <tr key={index} className="item-row">
                <td className="center">{item ? formatWholeQuantity(item.quantity, "0") : ""}</td>
                <td className="center">{item?.unit || ""}</td>
                <td className="left item-name-cell" colSpan={4}>{item?.item_name || ""}</td>
                <td className="right unit-cost-cell">{unitCost != null ? peso(unitCost) : ""}</td>
                <td></td>
                <td></td>
                <td colSpan={5}></td>
                <td className="tiny left remarks-cell">{item ? documentDrItemRemarks(item.remarks, item.expiry) : ""}</td>
              </tr>
            );
          })}
          <tr>
            <td className="dr-lower-wrap" colSpan={15}>
              {/* Isolated 5-col grid: PURPOSE + signatories (avoids parent 15-col border bleed). */}
              <table className="dr-lower">
                <colgroup>
                  {DR_LOWER_COL_WIDTHS.map((width, index) => (
                    <col key={index} style={{ width: `${width}%` }} />
                  ))}
                </colgroup>
                <tbody>
                  <tr className="dr-lower-colspec" aria-hidden="true">
                    {DR_LOWER_COL_WIDTHS.map((width, index) => (
                      <td key={index} style={{ width: `${width}%` }}>&nbsp;</td>
                    ))}
                  </tr>
                  <tr>
                    <td className="dr-purpose-label">PURPOSE:</td>
                    <td className="dr-purpose-value" colSpan={4}>{form.purpose_of_release || dash}</td>
                  </tr>
                  <tr className="dr-sig-header-row">
                    <th className="dr-sig-row-label" aria-hidden="true" />
                    <th className="dr-sig-role">ISSUANCE APPROVED BY:</th>
                    <th className="dr-sig-role">RELEASED BY:</th>
                    <th className="dr-sig-role">TRANSPORTED BY:</th>
                    <th className="dr-sig-role">RECEIVED BY:</th>
                  </tr>
                  <tr className="signature-space">
                    <td className="dr-sig-row-label">SIGNATURE:</td>
                    <td />
                    <td />
                    <td />
                    <td />
                  </tr>
                  <tr>
                    <td className="dr-sig-row-label">NAME:</td>
                    <td className="dr-sig-name">{printedName(issuanceApprovedBy.name)}</td>
                    <td className="dr-sig-name">{printedName(releasedBy.name)}</td>
                    <td className="dr-sig-transport">
                      <div className="dr-sig-transport-line">
                        <span className="dr-sig-transport-label">DRIVER:</span>
                        {tracking.driver_name ? (
                          <span className="dr-sig-transport-value">{printedName(tracking.driver_name)}</span>
                        ) : null}
                      </div>
                    </td>
                    <td />
                  </tr>
                  <tr>
                    <td className="dr-sig-row-label">POSITION:</td>
                    <td className="dr-sig-designation">
                      {issuanceApprovedBy.designation || issuanceApprovedBy.position || ""}
                    </td>
                    <td className="dr-sig-designation">
                      {releasedBy.designation || releasedBy.position || ""}
                    </td>
                    <td className="dr-sig-transport">
                      <div className="dr-sig-transport-line">
                        <span className="dr-sig-transport-label">CONTACT NO.:</span>
                        {(tracking.driver_contact || tracking.driver_contact_number) ? (
                          <span className="dr-sig-transport-value">
                            {tracking.driver_contact || tracking.driver_contact_number}
                          </span>
                        ) : null}
                      </div>
                    </td>
                    <td />
                  </tr>
                  <tr>
                    <td className="dr-sig-row-label dr-sig-office-label">
                      OFFICE/ UNIT/
                      <br />
                      SECTION:
                    </td>
                    <td className="dr-sig-office">{issuanceApprovedBy.office || ""}</td>
                    <td className="dr-sig-office">{releasedBy.office || ""}</td>
                    <td className="dr-sig-transport">
                      <div className="dr-sig-transport-line">
                        <span className="dr-sig-transport-label">PLATE NO.:</span>
                        {tracking.vehicle_plate_number ? (
                          <span className="dr-sig-transport-value">{printedName(tracking.vehicle_plate_number)}</span>
                        ) : null}
                      </div>
                    </td>
                    <td />
                  </tr>
                  <tr className="dr-sig-date-row">
                    <td className="dr-sig-row-label">DATE SIGNED:</td>
                    <td />
                    <td />
                    <td />
                    <td />
                  </tr>
                </tbody>
              </table>
            </td>
          </tr>
          <tr>
            <th className="section-title" colSpan={15}>RECIPIENT&apos;S INFORMATION</th>
          </tr>
          <tr>
            <td className="dr-recipient-label" colSpan={5}>NAME OF CONTACT PERSON:</td>
            <td className="dr-recipient-value" colSpan={10}>{form.receiving_representative || dash}</td>
          </tr>
          <tr>
            <td className="dr-recipient-label" colSpan={5}>CONTACT NUMBER:</td>
            <td className="dr-recipient-value" colSpan={10}>{form.contact_number || dash}</td>
          </tr>
          <tr>
            <td className="label top" colSpan={2}>REMARKS:</td>
            <td className="purpose-cell remarks-cell" colSpan={13}>{warehouseNote || form.remarks || ""}</td>
          </tr>
        </tbody>
      </table>
      <OfficialFooter />
    </article>
  );
}

const yesNoLabel = (value) => {
  if (value === true || value === 1 || value === "1" || value === "Yes") return "Yes";
  if (value === false || value === 0 || value === "0" || value === "No") return "No";
  return value || "";
};

const normalizePrintModes = (value) => {
  const list = Array.isArray(value)
    ? value
    : String(value || "")
        .split(",")
        .map((item) => item.trim())
        .filter(Boolean);
  return [...new Set(list.map((item) => (item === "Partner LGU" ? "Partner" : item)))];
};

const modesFromDispatchPlan = (dispatchPlan) => {
  if (!dispatchPlan) return [];
  const fromVehicles = (Array.isArray(dispatchPlan.vehicle_details)
    ? dispatchPlan.vehicle_details
    : []
  ).flatMap((row) => normalizePrintModes(row?.mode_of_transportation));
  if (fromVehicles.length) return [...new Set(fromVehicles)];
  return normalizePrintModes(dispatchPlan.mode_of_transportation);
};

/** Unique non-blank values from all vehicles, joined with newlines (then plan legacy). */
const mergeVehiclePrintField = (dispatchPlan, vehicleKey, planKey = null) => {
  if (!dispatchPlan) return "";
  const vehicles = Array.isArray(dispatchPlan.vehicle_details)
    ? dispatchPlan.vehicle_details
    : [];
  const values = [
    ...new Set(
      vehicles
        .map((row) => {
          const raw = row?.[vehicleKey];
          if (raw == null || raw === "") return "";
          if (vehicleKey === "delivered_at" || vehicleKey === "received_at") {
            return String(raw).slice(0, 10);
          }
          return String(raw).trim();
        })
        .filter(Boolean),
    ),
  ];
  if (values.length) return values.join("\n");
  const legacyKey = planKey || vehicleKey;
  const legacy = dispatchPlan[legacyKey];
  if (legacy == null || legacy === "") return "";
  if (legacyKey === "delivered_at" || legacyKey === "received_at") {
    return String(legacy).slice(0, 10);
  }
  return String(legacy).trim();
};

const mergeFullyDeliveredForPrint = (dispatchPlan, tracking) => {
  if (!dispatchPlan) return yesNoLabel(tracking?.fully_delivered);
  const vehicles = Array.isArray(dispatchPlan.vehicle_details)
    ? dispatchPlan.vehicle_details
    : [];
  const values = [
    ...new Set(
      vehicles
        .map((row) => yesNoLabel(row?.fully_delivered))
        .filter(Boolean),
    ),
  ];
  if (values.length) return values.join("\n");
  return yesNoLabel(dispatchPlan.fully_delivered ?? tracking?.fully_delivered);
};

export function PrintableRisDr({ form, tracking, type, signatories = [] }) {
  const items = form.items || [];
  const documentId = `${type}-print-preview`;
  // RIS/DR are immutable snapshots after generation; Dispatch Plan details stay separate.
  const dispatchPlan = null;
  const printTracking = (() => {
    // MODE OF TRANSPORTATION is Dispatch Plan–owned; blank when no plan yet.
    if (!dispatchPlan) {
      return {
        ...tracking,
        mode_of_transportation: [],
        transport_mode: [],
        driver_name: "",
        driver_contact_number: "",
        vehicle_plate_number: "",
      };
    }
    const modes = modesFromDispatchPlan(dispatchPlan);
    return {
      ...tracking,
      mode_of_transportation: modes,
      driver_name: mergeVehiclePrintField(dispatchPlan, "driver", "driver"),
      driver_contact_number: mergeVehiclePrintField(
        dispatchPlan,
        "driver_contact_number",
        "driver_contact_number",
      ),
      vehicle_plate_number: mergeVehiclePrintField(
        dispatchPlan,
        "vehicle_plate_number",
        "vehicle_plate_number",
      ),
      delivered_at:
        mergeVehiclePrintField(dispatchPlan, "delivered_at", "delivered_at")
        || tracking?.delivered_at
        || "",
      release_witnessed_by:
        mergeVehiclePrintField(dispatchPlan, "release_witnessed_by", "release_witnessed_by")
        || tracking?.release_witnessed_by
        || "",
      received_by:
        mergeVehiclePrintField(dispatchPlan, "received_by", "received_by")
        || tracking?.received_by
        || "",
      fully_delivered: mergeFullyDeliveredForPrint(dispatchPlan, tracking),
      has_returned_items:
        yesNoLabel(dispatchPlan.has_returned_items ?? tracking?.has_returned_items),
      returned_particulars:
        dispatchPlan.returned_particulars || tracking?.returned_particulars || "",
      returned_quantity:
        dispatchPlan.returned_quantity ?? tracking?.returned_quantity ?? "",
      returned_reason:
        dispatchPlan.returned_reason || tracking?.returned_reason || "",
    };
  })();

  if (type === "dr") {
    return <OfficialDr form={form} tracking={printTracking} items={items} documentId={documentId} signatories={signatories} />;
  }

  return (
    <article id={documentId} className="rros-official print-document ris-sheet">
      <style>{RROS_OFFICIAL_PRINT_CSS}</style>
      <OfficialRisCopy form={form} tracking={printTracking} items={items} signatories={signatories} />
      <div className="copy-divider">
        <span className="screen-only">CUT LINE · DUPLICATE COPY BELOW</span>
      </div>
      <OfficialRisCopy form={form} tracking={printTracking} items={items} signatories={signatories} />
    </article>
  );
}

/** Gray PDF-viewer canvas with a centered A4 paper sheet (screen preview only). */
export function RrosOfficialPreviewCanvas({ children, zoom, className = "" }) {
  return (
    <DocumentPreviewCanvas zoom={zoom} className={className} paperWidth="210mm">
      <style>{RROS_OFFICIAL_PRINT_CSS}</style>
      {children}
    </DocumentPreviewCanvas>
  );
}
