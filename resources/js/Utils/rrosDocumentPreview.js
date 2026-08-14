/** Prefer same-origin relative paths so PDF iframes work regardless of APP_URL scheme. */

export const toAppPath = (value) => {

  const raw = String(value || "").trim();

  if (!raw) return null;

  if (raw.startsWith("/")) return raw;

  try {

    const parsed = new URL(raw, typeof window !== "undefined" ? window.location.origin : undefined);

    if (

      typeof window === "undefined"

      || parsed.host === window.location.host

      || parsed.origin === window.location.origin

    ) {

      return `${parsed.pathname}${parsed.search}${parsed.hash}`;

    }

  } catch {

    // fall through

  }

  return null;

};



export const signedAssessmentViewUrl = (row) => {

  const url = toAppPath(row?.signed_assessment_view_url);

  if (!url || url.includes("/assessment-pdf")) return null;

  return url;

};



/** Same builders as RROS Requests/Index View docs — do not rely only on server *_view_url. */

export const lguRequestViewUrl = (row) => {

  const source = row?.source_lgu_dromic_report;

  if (source?.id && source?.lgu_signed_request_path) {

    return `/lgu/dromic-sitrep/${source.id}/signed-copy/request`;

  }

  const fromServer = toAppPath(row?.lgu_request_view_url);

  if (fromServer) return fromServer;

  if (row?.id && row?.source_document_url) {

    return `/requests/${row.id}/source-document`;

  }

  return null;

};



export const assessmentPreviewUrl = (row) => {

  const signed = signedAssessmentViewUrl(row);

  if (signed) return { url: signed, kind: "signed" };

  const draft =

    toAppPath(row?.assessment_pdf_view_url)

    || (row?.id ? `/requests/${row.id}/assessment-pdf?margin=18&inline=1` : null);

  if (draft) return { url: draft, kind: "draft" };

  return { url: null, kind: null };

};



export const risPreviewPayload = (row) => {

  if (row?.ris_preview?.form) return row.ris_preview;

  const ris = row?.ris || row?.requisition_issuance_slip;

  if (!ris) return null;

  const items = Array.isArray(ris.items)

    ? ris.items.map((item) => ({

        item_name: item.item_name || item.name || "Item",

        unit: item.unit ?? null,

        quantity: Number(item.allocated_quantity ?? item.quantity ?? 0) || 0,

        remarks: item.remarks ?? null,

        warehouse_name: item.warehouse_name ?? null,

        warehouse_id: item.warehouse_id ?? null,

      }))

    : Array.isArray(ris.allocation_items)

      ? ris.allocation_items.map((item) => ({

          item_name: item.item_name || item.name || "Item",

          unit: item.unit ?? null,

          quantity: Number(item.allocated_quantity ?? item.quantity ?? 0) || 0,

          remarks: item.remarks ?? null,

          warehouse_name: item.warehouse_name ?? null,

          warehouse_id: item.warehouse_id ?? null,

        }))

      : [];

  const tracking = ris.tracking_data && typeof ris.tracking_data === "object" ? ris.tracking_data : {};

  const drNumber = ris.dr_number || tracking.dr_number || null;

  return {

    form: {

      ris_number: ris.ris_number,

      ris_date: ris.ris_date,

      purpose_of_release: ris.purpose_of_release || row.purpose || null,

      recipient: ris.recipient || row.requesting_agency || row.lgu || null,

      delivery_site: ris.delivery_site || null,

      receiving_representative: ris.receiving_representative || null,

      contact_number: ris.contact_number || null,

      remarks: ris.remarks || null,

      items,

    },

    tracking: {

      ris_drn: ris.ris_drn || tracking.ris_drn || null,

      dr_number: drNumber,

      prepared_by_name: ris.prepared_by_name || tracking.prepared_by_name || null,

      release_witnessed_by: ris.release_witnessed_by || tracking.release_witnessed_by || null,

      delivered_at: ris.delivered_at || tracking.delivered_at || null,

      // Transport checkboxes come from Dispatch Plan merge at print time — not RIS columns.
      driver_name: null,

      driver_contact_number: null,

      vehicle_plate_number: null,

      mode_of_transportation: [],

    },

    has_dr: Boolean(drNumber),

  };

};



/** Advance DomPDF URL for RIS / DR — same iframe path as Assessment draft. */

export const risAdvancePdfViewUrl = (row, kind = "ris") => {

  const normalized = String(kind || "ris").toLowerCase() === "dr" ? "dr" : "ris";

  if (normalized === "dr") {

    const fromServer = toAppPath(row?.dr_advance_pdf_view_url);

    if (fromServer) return fromServer;

  } else {

    const fromServer = toAppPath(row?.ris_advance_pdf_view_url);

    if (fromServer) return fromServer;

  }

  const slipId =

    row?.ris_slip_id

    || row?.requisition_issuance_slip?.id

    || row?.ris?.id

    || null;

  if (!slipId) return null;

  return `/rros/ris/${slipId}/preview-pdf/${normalized}?inline=1`;

};



export const ADVANCE_RIS_DR_MESSAGE =

  "Advance / official printable — prepared for signing (not an uploaded signed PDF).";



/**

 * Flat document tabs:

 * Requests: LGU Request | Assessment | Response Letter | RIS | DR | RDS | CSMR

 * Dispatches (core): LGU Request | Assessment | RIS | DR

 *

 * RIS / DR use PdfPreviewModal iframe `src` (signed upload when available, else DomPDF advance).

 */

export function buildRrosDocumentPreviewTabs(row, options = {}) {

  const {

    includeResponseLetter = false,

    includeRdsCsmr = false,

  } = options;



  const requestUrl = lguRequestViewUrl(row);

  const assessment = assessmentPreviewUrl(row);

  const assessmentUrl = assessment.url;

  const assessmentKind = assessment.kind;

  const preview = risPreviewPayload(row);

  const hasRis = Boolean(preview?.form || row?.requisition_issuance_slip || row?.ris || row?.ris_preview);

  const hasDr = Boolean(preview?.has_dr || row?.ris?.dr_number || row?.requisition_issuance_slip?.dr_number);

  const risEpirmaSignedUrl = toAppPath(row.signed_ris_view_url);

  const risUploadedUrl = toAppPath(row.ris_view_url);

  const risAdvanceUrl = risAdvancePdfViewUrl(row, "ris");

  const drAdvanceUrl = risAdvancePdfViewUrl(row, "dr");

  const responseUrl = toAppPath(row.signed_response_letter_view_url);



  const tabs = [

    {

      key: "request",

      label: "LGU Request",

      src: requestUrl,

      kind: requestUrl ? "signed" : null,

      message: requestUrl

        ? null

        : "LGU request letter / source document is unavailable for this request.",

    },

    {

      key: "assessment",

      label: "Assessment",

      src: assessmentUrl,

      kind: assessmentKind,

      message: assessmentUrl

        ? assessmentKind === "draft"

          ? "Draft / local assessment PDF — signed e-PIRMA copy not available."

          : null

        : "Assessment document is unavailable for this request.",

    },

  ];



  if (includeResponseLetter) {

    tabs.push({

      key: "response",

      label: "Response Letter",

      src: responseUrl,

      kind: responseUrl ? "signed" : null,

      message: responseUrl ? null : "Signed file unavailable from e-PIRMA",

    });

  }



  if (hasRis) {

    const risSrc = risEpirmaSignedUrl || risUploadedUrl || risAdvanceUrl;

    const risKind = risEpirmaSignedUrl || risUploadedUrl ? "signed" : risAdvanceUrl ? "draft" : null;

    tabs.push({

      key: "ris",

      label: "RIS",

      src: risSrc,

      kind: risKind,

      message: risEpirmaSignedUrl
        ? "Official signed RIS retrieved through e-PIRMA."
        : risUploadedUrl

        ? null

        : risAdvanceUrl

          ? ADVANCE_RIS_DR_MESSAGE

          : "RIS advance printable is unavailable for this request.",

    });

  }



  if (hasDr) {

    const drSrc = drAdvanceUrl;

    tabs.push({

      key: "dr",

      label: "DR",

      src: drSrc,

      kind: drSrc ? "draft" : null,

      message: drSrc

        ? ADVANCE_RIS_DR_MESSAGE

        : "DR advance printable is unavailable for this request.",

    });

  }



  if (includeRdsCsmr) {

    const rdsUrl = toAppPath(row.rds_view_url);

    const csmrUrl = toAppPath(row.csmr_view_url);

    tabs.push(

      {

        key: "rds",

        label: "RDS",

        src: rdsUrl,

        kind: rdsUrl ? "signed" : null,

        message: rdsUrl ? null : "RDS file has not been uploaded.",

      },

      {

        key: "csmr",

        label: "CSMR",

        src: csmrUrl,

        kind: csmrUrl ? "signed" : null,

        message: csmrUrl ? null : "CSMR file has not been uploaded.",

      },

    );

  }



  const initialTab = requestUrl

    ? "request"

    : assessmentUrl

      ? "assessment"

      : hasRis

        ? "ris"

        : tabs[0]?.key || "request";



  return {

    tabs,

    initialTab,

    risPreview: preview,

  };

}
