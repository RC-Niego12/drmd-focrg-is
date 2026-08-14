import { router, useForm } from "@inertiajs/react";
import {
  CheckCircle2,
  AlertCircle,
  Circle,
  Clock3,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  FileCheck2,
  Eye,
  ListChecks,
  PackageCheck,
  Plus,
  Printer,
  RefreshCw,
  Save,
  Sparkles,
  Truck,
  X,
} from "lucide-react";
import { Fragment, useEffect, useMemo, useRef, useState } from "react";
import SearchableSelect from "@/Components/SearchableSelect";
import {
  DEFAULT_DOCUMENT_PREVIEW_ZOOM,
  DOCUMENT_PREVIEW_ZOOM_OPTIONS,
} from "@/Components/DocumentPreviewCanvas";
import PdfPreviewModal from "@/Components/PdfPreviewModal";
import { PrintableRisDr, RROS_OFFICIAL_PRINT_CSS, RrosOfficialPreviewCanvas } from "@/Components/RrosOfficialDocuments";
import SectionTabs from "@/Components/SectionTabs";
import {
  formatWholeQuantity,
  wholeQuantityInputValue,
} from "@/Utils/wholeQuantity";
import {
  composeRisDrn,
  risDrnPrefixForDate,
  risDrnSequenceFromValue,
} from "@/Utils/risDrn";
import {
  ADVANCE_RIS_DR_MESSAGE,
  risAdvancePdfViewUrl,
} from "@/Utils/rrosDocumentPreview";
import { closeEpirmaTab, navigateEpirmaTab, openEpirmaTabPlaceholder } from "@/Utils/epirmaTab";
import { listenRealtime } from "@/realtime";

const asStringList = (value) => {
  if (Array.isArray(value)) {
    return value.map(String).map((item) => item.trim()).filter(Boolean);
  }
  if (value === null || value === undefined) return [];
  const text = String(value).trim();
  if (!text) return [];
  if (text.includes(",")) {
    return text.split(",").map((item) => item.trim()).filter(Boolean);
  }
  return [text];
};

/** Normalize legacy Mode of Transportation value "Partner LGU" to "Partner". */
const normalizeModeOfTransportation = (value) =>
  asStringList(value).map((item) => (item === "Partner LGU" ? "Partner" : item));

/** Collect unique modes from Dispatch Plan vehicle rows (then plan-level). */
const modesFromDispatchPlan = (dispatchPlan) => {
  if (!dispatchPlan) return [];
  const fromVehicles = (Array.isArray(dispatchPlan.vehicle_details)
    ? dispatchPlan.vehicle_details
    : []
  ).flatMap((row) => normalizeModeOfTransportation(row?.mode_of_transportation));
  const uniqueVehicles = [...new Set(fromVehicles)];
  if (uniqueVehicles.length) return uniqueVehicles;
  return [...new Set(normalizeModeOfTransportation(dispatchPlan.mode_of_transportation))];
};

/** Prefer Dispatch Plan logistics for DR printables; blank transport when none planned. */
const yesNoFromValue = (value) => {
  if (value === true || value === 1 || value === "1" || value === "Yes") return "Yes";
  if (value === false || value === 0 || value === "0" || value === "No") return "No";
  return value || "";
};

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

const mergeTransportForPrint = (tracking = {}, dispatchPlan = null) => {
  if (!dispatchPlan) {
    return {
      ...tracking,
      mode_of_transportation: [],
      vehicle_type: [],
      vehicle_types: [],
      number_of_vehicles: "",
      no_of_vehicles: "",
      driver_name: "",
      driver_contact_number: "",
      vehicle_plate_number: "",
    };
  }
  const modes = modesFromDispatchPlan(dispatchPlan);
  const vehicles = Array.isArray(dispatchPlan.vehicle_details)
    ? dispatchPlan.vehicle_details
    : [];
  const vehicleTypes = [
    ...new Set(
      [
        ...asStringList(dispatchPlan.vehicle_types),
        ...vehicles.flatMap((row) => asStringList(row?.vehicle_type)),
      ].filter(Boolean),
    ),
  ];
  const vehicleCount =
    dispatchPlan.number_of_vehicles
    ?? (vehicles.length || null);
  const deliveredAt =
    mergeVehiclePrintField(dispatchPlan, "delivered_at", "delivered_at")
    || tracking.delivered_at
    || "";
  const fullyValues = [
    ...new Set(
      vehicles
        .map((row) => yesNoFromValue(row?.fully_delivered))
        .filter(Boolean),
    ),
  ];
  const fullyDelivered =
    fullyValues.length > 0
      ? fullyValues.join("\n")
      : yesNoFromValue(dispatchPlan.fully_delivered ?? tracking.fully_delivered);
  const hasReturned = yesNoFromValue(
    dispatchPlan.has_returned_items ?? tracking.has_returned_items,
  );
  return {
    ...tracking,
    mode_of_transportation: modes,
    vehicle_type: vehicleTypes,
    vehicle_types: vehicleTypes,
    number_of_vehicles: vehicleCount ?? "",
    no_of_vehicles: vehicleCount ?? "",
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
    received_by:
      mergeVehiclePrintField(dispatchPlan, "received_by", "received_by")
      || tracking.received_by
      || "",
    date_received:
      mergeVehiclePrintField(dispatchPlan, "received_at", "received_at")
      || tracking.date_received
      || "",
    delivered_at: deliveredAt,
    release_witnessed_by:
      mergeVehiclePrintField(dispatchPlan, "release_witnessed_by", "release_witnessed_by")
      || tracking.release_witnessed_by
      || "",
    fully_delivered: fullyDelivered || tracking.fully_delivered || "",
    has_returned_items: hasReturned || tracking.has_returned_items || "",
    returned_particulars:
      dispatchPlan.returned_particulars || tracking.returned_particulars || "",
    returned_quantity:
      dispatchPlan.returned_quantity ?? tracking.returned_quantity ?? "",
    returned_reason:
      dispatchPlan.returned_reason || tracking.returned_reason || "",
  };
};

/** Client-side toast via AppLayout (`dromis:toast`). */
const notifyApp = ({ type = "error", title, message }) => {
  if (!message) return;
  window.dispatchEvent(
    new CustomEvent("dromis:toast", {
      detail: { type, title, message },
    }),
  );
};

const today = () => new Date().toLocaleDateString("en-CA");
const dateOnly = (value) => String(value || "").slice(0, 10);
const itemKey = (value) =>
  String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]/g, "");
const allocationGroupKey = (item) =>
  String(item.request_item_id || itemKey(item.item_name));
const peso = (value) =>
  new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(Number(value));
const singularUnit = (unit) => {
  const value = String(unit || "unit").trim();
  if (/ies$/i.test(value)) return value.replace(/ies$/i, "y");
  if (/(ches|shes|xes|ses)$/i.test(value)) return value.replace(/es$/i, "");
  if (/s$/i.test(value) && !/ss$/i.test(value)) return value.slice(0, -1);
  return value;
};
const pluralUnit = (unit) => {
  const value = singularUnit(unit);
  if (/[^aeiou]y$/i.test(value)) return `${value.slice(0, -1)}ies`;
  if (/(ch|sh|x|s)$/i.test(value)) return `${value}es`;
  return `${value}s`;
};
const quantityWithUnit = (quantity, unit) => {
  const qty = Math.trunc(Number(quantity) || 0);
  return `${formatWholeQuantity(qty, "0")} ${qty === 1 ? singularUnit(unit) : pluralUnit(unit)}`;
};

const normalizeRisItems = (items = []) =>
  (items || []).map((item) => ({
    ...item,
    brand_description: ["", "-", "n/a", "na", "not applicable", "none", "null"].includes(
      String(item.brand_description || "").trim().toLowerCase(),
    ) ? "" : item.brand_description,
    expiry: applicableExpiry(item.expiry) ? item.expiry : "",
    quantity:
      item.quantity === "" || item.quantity == null
        ? ""
        : Math.max(1, Math.trunc(Number(item.quantity) || 0) || 1),
    wit_stock_balance:
      item.wit_stock_balance == null || item.wit_stock_balance === ""
        ? item.wit_stock_balance ?? null
        : Math.trunc(Number(item.wit_stock_balance)),
    remaining_balance:
      item.remaining_balance == null || item.remaining_balance === ""
        ? item.remaining_balance ?? null
        : Math.trunc(Number(item.remaining_balance)),
    allocation_guide:
      item.allocation_guide == null || item.allocation_guide === ""
        ? item.allocation_guide ?? null
        : Math.trunc(Number(item.allocation_guide)),
  }));
const applicableExpiry = (value) => {
  const normalized = String(value || "").trim().toLowerCase();
  return !["", "-", "n/a", "na", "not applicable", "none", "null"].includes(
    normalized,
  );
};
const readableDate = (value) => {
  if (!applicableExpiry(value)) return null;
  const parsed = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleDateString("en-PH", {
        year: "numeric",
        month: "long",
        day: "numeric",
      });
};
const readableDateTime = (value) => {
  if (!value) return "Not yet recorded";
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString("en-PH", {
    year: "numeric", month: "short", day: "numeric", hour: "numeric", minute: "2-digit",
  });
};
const readableExpiry = (value) => {
  if (!applicableExpiry(value)) return null;
  const parsed = new Date(String(value));
  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleDateString("en-PH", { month: "short", year: "numeric" });
};
const naturalList = (values) => {
  const items = values.filter(Boolean);
  if (items.length < 2) return items[0] || "";
  if (items.length === 2) return `${items[0]} and ${items[1]}`;
  return `${items.slice(0, -1).join(", ")}, and ${items.at(-1)}`;
};
const locationKey = (value) =>
  String(value || "")
    .trim()
    .toLowerCase()
    .replace(/\b(city|municipality|province|of)\b/g, "")
    .replace(/[^a-z0-9]/g, "");
const warehouseZone = (row) =>
  locationKey(row.province).includes("dinagat")
    ? "pdi"
    : locationKey(row.district) === "sdn1"
      ? "sdn1"
      : "mainland";
const distanceKm = (from, to) => {
  if (
    ![from?.latitude, from?.longitude, to?.latitude, to?.longitude].every(
      (value) => Number.isFinite(Number(value)),
    )
  )
    return null;
  const radians = (degrees) => (Number(degrees) * Math.PI) / 180;
  const dLat = radians(to.latitude) - radians(from.latitude);
  const dLng = radians(to.longitude) - radians(from.longitude);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(radians(from.latitude)) *
      Math.cos(radians(to.latitude)) *
      Math.sin(dLng / 2) ** 2;
  return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
};
const prefixes = (date) => {
  const [year, month] = String(date || today()).split("-");
  return { ris: `RIS-CRG-${year}-${month}-`, dr: `DR#-${month}-` };
};

const meaningfulText = (value) => {
  const text = String(value || "").trim();
  return /^(?:n\/?a|none|not applicable|not specified|-)?$/i.test(text)
    ? ""
    : text;
};
const releaseLocation = (request) =>
  [request.municipality, request.province].filter(Boolean).join(", ") ||
  meaningfulText(request.office_agency_details) ||
  meaningfulText(request.lgu) ||
  meaningfulText(request.requesting_agency) ||
  "Caraga Region";
const readableIncidentDate = (value) => {
  const date = dateOnly(value);
  if (!date) return "";
  const parsed = new Date(`${date}T00:00:00`);
  return Number.isNaN(parsed.getTime())
    ? date
    : parsed.toLocaleDateString("en-PH", {
        year: "numeric",
        month: "long",
        day: "numeric",
      });
};
const barangayPhrase = (barangays) => {
  const names = [...new Set(barangays.map(meaningfulText).filter(Boolean))];
  if (!names.length) return "";
  return `${names.length === 1 ? "Brgy." : "Brgys."} ${naturalList(names)}`;
};
const incidentTypeKey = (value) =>
  String(value || "")
    .toLowerCase()
    .replace(/\b(?:the|a|an|incident|occurrence|of)\b/g, "")
    .replace(/[^a-z0-9]/g, "");
const releaseOccurrences = (request) => {
  const payload = request.source_lgu_dromic_report?.lgu_dromic_payload || {};
  const assessmentIncidents = Array.isArray(request.assessment_form_data?.incidents)
    ? request.assessment_form_data.incidents
    : [];
  if (assessmentIncidents.length) {
    return assessmentIncidents.map((row) => ({
      barangays: [row.barangay].filter(Boolean),
      municipality: row.city_municipality,
      date: row.occurrence_at,
      incidentType: row.incident_type,
      affectedFamilies: row.affected_families,
    }));
  }
  const primaryType = request.incident?.name || payload.incident_type || payload.incident_name;
  const primaryBarangays = Array.isArray(payload.affected_barangays)
    ? payload.affected_barangays
    : [request.barangay].filter(Boolean);
  const occurrences = [
    {
      barangays: primaryBarangays,
      date:
        payload.occurrence_started_at ||
        payload.incident_date ||
        request.incident_date ||
        request.incident?.incident_date,
    },
  ];
  const primaryKey = incidentTypeKey(primaryType);

  (Array.isArray(payload.related_incident_rows)
    ? payload.related_incident_rows
    : []
  ).forEach((row) => {
    const rowType = row.incident_type === "Others"
      ? row.incident_type_other
      : row.incident_type;
    if (primaryKey && incidentTypeKey(rowType) !== primaryKey) return;
    occurrences.push({
      barangays: [row.barangay].filter(Boolean),
      date: row.occurrence_at || row.occurrence_date,
    });
  });

  const grouped = new Map();
  occurrences.forEach((occurrence) => {
    const date = dateOnly(occurrence.date);
    const key = date || `undated-${grouped.size}`;
    const current = grouped.get(key) || { date, barangays: [] };
    current.barangays.push(...occurrence.barangays);
    grouped.set(key, current);
  });

  return [...grouped.values()].filter(
    (occurrence) => occurrence.date || occurrence.barangays.length,
  );
};
const releaseIncident = (request) => {
  const type = meaningfulText(request.incident?.name);
  const detail = meaningfulText(request.incident_details);
  const normalized = type.toLowerCase();

  if (detail && /(?:tropical|weather|cyclone|storm|typhoon|lpa)/i.test(type)) {
    return detail;
  }
  if (/tornado/.test(normalized)) return "the occurrence of a tornado";
  if (/fire/.test(normalized)) return "a fire incident";
  if (/armed conflict/.test(normalized)) return "armed conflict";
  if (/shear\s*line/.test(normalized)) return "a shear line";
  if (/trough.*lpa|lpa.*trough/.test(normalized)) return "a trough of an LPA";

  return detail || type || "the reported incident";
};
const generatedPurposeOfRelease = (request) => {
  const incident = releaseIncident(request);
  const location = releaseLocation(request).replace(/[.\s]+$/, "");
  const occurrences = releaseOccurrences(request);
  const details = occurrences.map((occurrence) => {
    const area = barangayPhrase(occurrence.barangays);
    const date = readableIncidentDate(occurrence.date);
    const occurrenceLocation = meaningfulText(occurrence.municipality) || location;
    const address = [area, occurrenceLocation].filter(Boolean).join(", ");
    return [address, date ? `on ${date}` : ""].filter(Boolean).join(" ");
  });

  if (!details.length) {
    return `Relief Augmentation to the affected families due to ${incident} in ${location}.`;
  }
  if (details.length === 1) {
    return `Relief Augmentation to the affected families due to ${incident} in ${details[0]}.`;
  }

  const numberedOccurrences = details
    .map((detail, index) => `(${index + 1}) ${detail}`)
    .join("; ");
  return `Relief Augmentation to the affected families due to multiple occurrences of ${incident.replace(/^(?:a|an|the)\s+/i, "")} in ${numberedOccurrences}.`;
};

const initialItems = (request) =>
  (request.items || [])
    .filter(
      (item) =>
        Number(item.approved_quantity || item.requested_quantity || 0) > 0,
    )
    .map((item) => ({
      request_item_id: item.id,
      item_name: item.item_name,
      unit: item.unit || "",
      warehouse_id:
        item.source_warehouse_id || item.source_warehouse?.id || null,
      warehouse_name: item.source_warehouse?.name || "",
      quantity: Math.max(
        1,
        Math.trunc(Number(item.approved_quantity || item.requested_quantity || 0)),
      ),
      warehouse_type: "",
      wit_stock_balance: null,
      remaining_balance: null,
      allocation_status: "",
      remarks: "",
    }));

export default function RisFormModal({
  request,
  currentUser,
  warehouseStock = [],
  warehouseReservations = [],
  rrosSignatories = [],
  libraryOptions = {},
  showPostRisSections = false,
  onClose,
  onGenerated,
}) {
  const saved = request.requisition_issuance_slip;
  const preparedDate = dateOnly(saved?.ris_date) || today();
  const risSystemCreatedDate = dateOnly(saved?.created_at) || preparedDate;
  // RIS creators (RROS) and RROS AA encode the final DRN sequence during create/prepare.
  const canAssignRisDrn =
    currentUser?.roles?.some((role) =>
      ["RROS", "RROS AA", "Super Admin"].includes(role),
    ) || currentUser?.permissions?.includes("assign ris drn");
  const initialRisDrnPrefix = risDrnPrefixForDate(preparedDate);
  const numberParts = prefixes(preparedDate);
  const initialCounter = saved?.tracking_data?.counter || "0001";
  const defaults = useMemo(
    () => {
      const base = {
      ris_number: saved?.ris_number || `${numberParts.ris}${initialCounter}`,
      ris_date: preparedDate,
      purpose_of_release:
        saved?.purpose_of_release ||
        generatedPurposeOfRelease(request),
      recipient:
        saved?.recipient ||
        request.requesting_agency ||
        request.lgu ||
        request.requester ||
        "",
      delivery_site:
        saved?.delivery_site ||
        request.office_agency_details ||
        [request.municipality, request.province].filter(Boolean).join(", "),
      receiving_representative:
        request.ris_receiving_representative ||
        saved?.receiving_representative ||
        "Directory match unavailable",
      contact_number:
        request.ris_receiving_contact_number ||
        saved?.contact_number ||
        "Directory match unavailable",
      remarks: saved?.remarks || "",
      status: saved?.status || "draft",
      approval_routing_mode: saved?.approval_routing_mode || "manual",
      items: normalizeRisItems(
        saved?.allocation_items?.length
          ? saved.allocation_items
          : saved?.items?.length
            ? saved.items
            : initialItems(request),
      ),
      rds_file: null,
      csmr_file: null,
      ris_dr_file: null,
      tracking_data: {
        assessment_drn_for_ris: request.assessment_drn || "",
        purpose_of_request:
          request.assessment_form_data?.response_purpose ||
          request.purpose ||
          "",
        incident_type: request.incident?.name || "",
        incident_specification: request.incident_details || "",
        ris_number_prefix: numberParts.ris,
        dr_number_prefix: numberParts.dr,
        counter: initialCounter,
        dr_number: `${numberParts.dr}${initialCounter}`,
        ris_drn: canAssignRisDrn ? initialRisDrnPrefix : "",
        ardo_endorsed_at: "",
        ardo_returned_at: "",
        delivered_at: "",
        release_witnessed_by: "",
        received_by: "",
        date_received: "",
        fully_delivered: "",
        has_returned_items: "",
        returned_particulars: "",
        returned_quantity: "",
        returned_reason: "",
        forwarded_to_accounting: "",
        forwarded_to_accounting_at: "",
        accounting_received_by: "",
        assessment_link: request.signed_assessment_view_url || "",
        ris_link: "",
        rds_link: "",
        csmr_link: "",
        ...(saved?.tracking_data || {}),
        ...(saved
          ? {
              assessment_drn_for_ris:
                saved.assessment_drn_for_ris ||
                saved.tracking_data?.assessment_drn_for_ris ||
                request.assessment_drn ||
                "",
              purpose_of_request:
                saved.purpose_of_request ||
                saved.tracking_data?.purpose_of_request ||
                request.purpose ||
                "",
              incident_type:
                saved.incident_type ||
                saved.tracking_data?.incident_type ||
                request.incident?.name ||
                "",
              incident_specification:
                saved.incident_specification ||
                saved.tracking_data?.incident_specification ||
                request.incident_details ||
                "",
              dr_number:
                saved.dr_number ||
                saved.tracking_data?.dr_number ||
                `${numberParts.dr}${initialCounter}`,
              ris_drn:
                saved.ris_drn ||
                saved.tracking_data?.ris_drn ||
                (canAssignRisDrn ? initialRisDrnPrefix : ""),
              ardo_endorsed_at: dateOnly(saved.ardo_endorsed_at),
              ardo_returned_at: dateOnly(saved.ardo_returned_at),
              delivered_at: dateOnly(saved.delivered_at),
              release_witnessed_by: saved.release_witnessed_by || "",
              received_by: saved.received_by || "",
              date_received: dateOnly(saved.date_received),
              fully_delivered:
                saved.fully_delivered == null
                  ? ""
                  : saved.fully_delivered
                    ? "Yes"
                    : "No",
              has_returned_items:
                saved.has_returned_items == null
                  ? ""
                  : saved.has_returned_items
                    ? "Yes"
                    : "No",
              returned_particulars: saved.returned_particulars || "",
              returned_quantity: wholeQuantityInputValue(saved.returned_quantity || ""),
              returned_reason: saved.returned_reason || "",
              forwarded_to_accounting:
                saved.forwarded_to_accounting == null
                  ? ""
                  : saved.forwarded_to_accounting
                    ? "Yes"
                    : "No",
              forwarded_to_accounting_at: dateOnly(
                saved.forwarded_to_accounting_at,
              ),
              accounting_received_by: saved.accounting_received_by || "",
              assessment_link:
                saved.assessment_link ||
                request.signed_assessment_view_url ||
                "",
              ris_link: saved.ris_link || "",
              rds_link: saved.rds_link || "",
              csmr_link: saved.csmr_link || "",
            }
          : {}),
      },
    };
    // Transport / vehicle fields are Dispatch Plan–owned — never keep them in RIS form state.
    const trackingData = { ...(base.tracking_data || {}) };
    [
      "mode_of_transportation",
      "vehicle_type",
      "vehicle_types",
      "number_of_vehicles",
      "no_of_vehicles",
      "driver_name",
      "driver_contact_number",
      "vehicle_plate_number",
    ].forEach((key) => {
      delete trackingData[key];
    });
    return {
      ...base,
      tracking_data: trackingData,
    };
    },
    [request, saved, canAssignRisDrn, initialRisDrnPrefix],
  );
  const form = useForm(defaults);
  const tracking = form.data.tracking_data;
  const currentRisDrnPrefix = risDrnPrefixForDate(form.data.ris_date);
  const risDrnSequence = risDrnSequenceFromValue(
    tracking.ris_drn,
    currentRisDrnPrefix,
  );
  const risDrnValue = String(tracking.ris_drn || "").trim();
  const risDrnComplete =
    !canAssignRisDrn ||
    (Boolean(risDrnSequence) &&
      Boolean(risDrnValue) &&
      risDrnValue !== currentRisDrnPrefix &&
      new RegExp(
        `^${currentRisDrnPrefix.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$`,
      ).test(risDrnValue));
  const [navCollapsed, setNavCollapsed] = useState(false);
  const [progressOpen, setProgressOpen] = useState(false);
  const [allocationNotice, setAllocationNotice] = useState(null);
  const [stockPreviewWarehouseId, setStockPreviewWarehouseId] = useState(null);
  const [stockPreviewQuery, setStockPreviewQuery] = useState("");
  const [draftPreviewOpen, setDraftPreviewOpen] = useState(false);
  const [pdfPreviewOpen, setPdfPreviewOpen] = useState(false);
  const [previewDocument, setPreviewDocument] = useState("ris");
  const [previewZoom, setPreviewZoom] = useState(DEFAULT_DOCUMENT_PREVIEW_ZOOM);
  const [slipSavedLocally, setSlipSavedLocally] = useState(Boolean(saved?.id));
  const [persistedSlipStatus, setPersistedSlipStatus] = useState(saved?.status || null);
  const [endorsementSaved, setEndorsementSaved] = useState(
    Boolean((dateOnly(saved?.ardo_endorsed_at) && dateOnly(saved?.ardo_returned_at)) || saved?.ris_epirma_status === "signed"),
  );
  const [risEpirma, setRisEpirma] = useState({
    status: saved?.ris_epirma_status || null,
    forwarded_at: saved?.ris_epirma_forwarded_at || null,
    routed_at: saved?.ris_epirma_routed_at || null,
    signed_at: saved?.ris_epirma_signed_at || null,
    transaction_id: saved?.ris_epirma_transaction_id || null,
    signature_reference: saved?.ris_epirma_signature_reference || null,
    complete: saved?.ris_epirma_status === "signed",
    signed_preview_url: saved?.ris_epirma_status === "signed" && saved?.id
      ? `/rros/ris/${saved.id}/epirma/signed-preview`
      : null,
    document: null,
  });
  const [risEpirmaBusy, setRisEpirmaBusy] = useState(false);
  const isRrosAa = currentUser?.roles?.includes("RROS AA");
  const isPreparedByCurrentUser = Number(saved?.prepared_by) === Number(currentUser?.id);
  const canDirectRouteRis = isRrosAa && isPreparedByCurrentUser;
  const canStartRisEpirmaRoute = canDirectRouteRis
    && [null, "", "forwarded", "failed", "cancelled"].includes(risEpirma.status);
  const [signingReminderOpen, setSigningReminderOpen] = useState(false);
  const [generatedAwaitingHandoff, setGeneratedAwaitingHandoff] = useState(false);
  const initialAllocationAppliedRef = useRef(false);
  // Prepared slip (Generate succeeded) — used for print / signing handoff.
  const slipIsPrepared =
    Boolean(saved?.id || slipSavedLocally) &&
    ["prepared", "approved", "completed"].includes(
      String(persistedSlipStatus || saved?.status || "").toLowerCase(),
    );
  // Sections 03/04 (Delivery / Accounting) only in dedicated post-RIS entry mode
  // (In Progress → Complete post RIS/DR). Create/draft/generate stay at 01–02.
  const isPostRis = Boolean(showPostRisSections);
  // Generated RIS/DR data is immutable during dispatch planning.
  const printTracking = useMemo(() => ({ ...tracking }), [tracking]);
  // Print only after a prepared/final save — drafts may preview but cannot print.
  const canPrintDocuments = slipIsPrepared;
  const printDocumentTitle = previewDocument === "dr" ? "Delivery Receipt" : "Requisition and Issuance Slip";
  const printPreviewDocument = () => {
    if (!canPrintDocuments) return;
    const sheet = document.getElementById(`${previewDocument}-print-preview`);
    if (!sheet) return;
    const popup = window.open("", "_blank", "width=1100,height=850");
    if (!popup) return;
    const clone = sheet.cloneNode(true);
    clone.querySelectorAll("style").forEach((node) => node.remove());
    popup.document.write(`<!doctype html><html><head><base href="${window.location.origin}/"><title>${printDocumentTitle} - ${form.data.ris_number || "draft"}</title><style>${RROS_OFFICIAL_PRINT_CSS}</style></head><body>${clone.outerHTML}<script>window.onload=()=>{window.print();window.onafterprint=()=>window.close();}<\/script></body></html>`);
    popup.document.close();
  };
  const planningWarehouseStock = useMemo(() => {
    const rows = warehouseStock.map((row) => ({
      ...row,
      physical_available: Math.max(0, Number(row.available) || 0),
      reserved_elsewhere: 0,
      available: Math.max(0, Number(row.available) || 0),
    }));
    const reserved = warehouseReservations
      .filter((row) => String(row.request_id) !== String(request.id))
      .reduce((groups, row) => {
        const key = `${row.warehouse_id}|${row.item_key || itemKey(row.item_name)}|${String(row.brand_description || "").toLowerCase()}|${String(row.expiry || "").toLowerCase()}`;
        groups.set(key, (groups.get(key) || 0) + Math.max(0, Number(row.quantity) || 0));
        return groups;
      }, new Map());

    reserved.forEach((quantity, key) => {
      let remaining = quantity;
      const [warehouseId, reservedItemKey, brand, expiry] = key.split("|");
      rows
        .filter((row) =>
          String(row.warehouse_id) === warehouseId &&
          itemKey(row.item) === reservedItemKey &&
          (!brand || String(row.brand_description || "").toLowerCase() === brand) &&
          (!expiry || String(row.expiry || "").toLowerCase() === expiry),
        )
        .sort(
          (a, b) =>
            (Date.parse(a.expiry || "") || Number.MAX_SAFE_INTEGER) -
            (Date.parse(b.expiry || "") || Number.MAX_SAFE_INTEGER),
        )
        .forEach((row) => {
          if (remaining <= 0) return;
          const deduction = Math.min(remaining, row.available);
          row.available -= deduction;
          row.reserved_elsewhere += deduction;
          remaining -= deduction;
        });
    });

    return rows;
  }, [request.id, warehouseReservations, warehouseStock]);
  const stockPreviewRows = useMemo(
    () => planningWarehouseStock
      .filter((row) => String(row.warehouse_id) === String(stockPreviewWarehouseId))
      .filter((row) => Number(row.physical_available || 0) !== 0)
      .sort((a, b) =>
        String(a.category || "").localeCompare(String(b.category || "")) ||
        String(a.item || "").localeCompare(String(b.item || "")) ||
        (Date.parse(a.expiry || "") || Number.MAX_SAFE_INTEGER) -
          (Date.parse(b.expiry || "") || Number.MAX_SAFE_INTEGER),
      ),
    [planningWarehouseStock, stockPreviewWarehouseId],
  );
  const stockPreviewWarehouse = stockPreviewRows[0] || null;
  const filteredStockPreviewRows = useMemo(() => {
    const query = stockPreviewQuery.trim().toLowerCase();
    if (!query) return stockPreviewRows;
    return stockPreviewRows.filter((row) => [
      row.category,
      row.item,
      row.brand_description,
      row.expiry,
    ].some((value) => String(value || "").toLowerCase().includes(query)));
  }, [stockPreviewQuery, stockPreviewRows]);
  const stockPreviewTotals = useMemo(
    () => filteredStockPreviewRows.reduce((totals, row) => ({
      current: totals.current + Number(row.physical_available || 0),
      available: totals.available + Number(row.available || 0),
      cost: totals.cost + Number(row.physical_available || 0) * Number(row.unit_price || 0),
    }), { current: 0, available: 0, cost: 0 }),
    [filteredStockPreviewRows],
  );
  const isCompleted = (value) => {
    if (value === null || value === undefined || value === "") return false;
    if (Array.isArray(value)) return value.length > 0;
    if (typeof value === "boolean") return value;
    if (typeof value === "string" && /unavailable|not encoded/i.test(value))
      return false;
    return true;
  };
  const documentChecks = [
    tracking.assessment_drn_for_ris,
    tracking.purpose_of_request,
    tracking.incident_type ||
      form.data.purpose_of_release !== "Relief Augmentation",
    tracking.incident_specification ||
      form.data.purpose_of_release !== "Relief Augmentation",
    form.data.purpose_of_release,
    currentUser?.name,
    form.data.ris_date,
    form.data.ris_number,
    tracking.dr_number,
    form.data.delivery_site,
    form.data.recipient,
    form.data.receiving_representative,
    form.data.contact_number,
  ];
  const itemChecks = form.data.items.flatMap((item) => [
    item.unit,
    item.item_name,
    item.quantity,
    item.warehouse_id,
    item.warehouse_type,
    item.wit_stock_balance,
    item.remaining_balance,
    item.allocation_status,
  ]);
  // Post-RIS document monitoring only (dispatch owns delivery / receipt logistics).
  const endorsementChecks = [
    ...(form.data.approval_routing_mode === "epirma"
      ? [risEpirma.complete]
      : [tracking.ardo_endorsed_at, tracking.ardo_returned_at]),
  ];
  const notForwarded = tracking.forwarded_to_accounting === "No";
  const accountingChecks = [
    tracking.forwarded_to_accounting,
    notForwarded || tracking.forwarded_to_accounting_at,
    notForwarded || tracking.accounting_received_by,
    saved?.ris_dr_path || form.data.ris_dr_file,
    saved?.rds_path || saved?.rds_link || form.data.rds_file,
    saved?.csmr_path || saved?.csmr_link || form.data.csmr_file,
    form.data.remarks,
  ];
  const preparationChecks = [...documentChecks, ...itemChecks];
  const followUpChecks = [...endorsementChecks, ...accountingChecks];
  const progressChecks = showPostRisSections ? followUpChecks : preparationChecks;
  const completedChecks = progressChecks.filter(isCompleted).length;
  const progressPercent = progressChecks.length
    ? Math.round((completedChecks / progressChecks.length) * 100)
    : 0;
  const hasAllocationShortfall = form.data.items.some(
    (item) =>
      item._allocation_shortfall || item._plan_stale || !item.warehouse_id,
  );
  const createReadinessGaps = () => {
    const gaps = [];
    const labeled = [
      ["Assessment DRN for RIS", tracking.assessment_drn_for_ris],
      ["Purpose of request", tracking.purpose_of_request],
      [
        "Incident type",
        tracking.incident_type ||
          form.data.purpose_of_release !== "Relief Augmentation",
      ],
      [
        "Incident specification",
        tracking.incident_specification ||
          form.data.purpose_of_release !== "Relief Augmentation",
      ],
      ["Purpose of release", form.data.purpose_of_release],
      ["Prepared by", currentUser?.name],
      ["RIS date", form.data.ris_date],
      ["RIS number", form.data.ris_number],
      ["DR number", tracking.dr_number],
      ["Delivery site", form.data.delivery_site],
      ["Recipient", form.data.recipient],
      ["Receiving representative", form.data.receiving_representative],
      ["Contact number", form.data.contact_number],
    ];
    labeled.forEach(([label, value]) => {
      if (!isCompleted(value)) gaps.push(label);
    });
    if (!form.data.items.length) {
      gaps.push("At least one FNI allocation");
    }
    form.data.items.forEach((item, index) => {
      const n = index + 1;
      [
        ["unit", item.unit],
        ["item name", item.item_name],
        ["quantity", item.quantity],
        ["source warehouse", item.warehouse_id],
        ["warehouse type", item.warehouse_type],
        ["WIT stock balance", item.wit_stock_balance],
        ["remaining balance", item.remaining_balance],
        ["allocation status", item.allocation_status],
      ].forEach(([field, value]) => {
        if (!isCompleted(value)) gaps.push(`Item ${n}: ${field}`);
      });
      if (
        item._allocation_shortfall ||
        item._plan_stale ||
        !item.warehouse_id
      ) {
        gaps.push(`Item ${n}: complete source warehouse allocation`);
      }
    });
    const checkedGroups = new Set();
    form.data.items.forEach((item) => {
      const key = allocationGroupKey(item);
      if (checkedGroups.has(key)) return;
      checkedGroups.add(key);
      const assessmentItem = (request.items || []).find((row) =>
        (item.request_item_id && String(row.id) === String(item.request_item_id)) ||
        itemKey(row.item_name) === itemKey(item.item_name),
      );
      if (!assessmentItem) return;
      const provision = Number(
        assessmentItem.approved_quantity || assessmentItem.requested_quantity || 0,
      );
      const allocated = form.data.items
        .filter((row) => allocationGroupKey(row) === key)
        .reduce((total, row) => total + Number(row.quantity || 0), 0);
      if (allocated !== provision) {
        gaps.push(
          `${item.item_name}: combined allocation must equal assessment provision (${formatWholeQuantity(provision, "0")})`,
        );
      }
    });
    if (canAssignRisDrn && !risDrnComplete) {
      gaps.push(`Complete RIS / DR DRN sequence after ${currentRisDrnPrefix}`);
    }
    return [...new Set(gaps)];
  };
  const canPreviewDocuments =
    isPostRis ||
    (preparationChecks.length > 0 &&
      preparationChecks.every(isCompleted) &&
      !hasAllocationShortfall &&
      (!canAssignRisDrn || risDrnComplete));
  const openDocumentPreview = () => {
    const gaps = createReadinessGaps();
    if (!isPostRis && gaps.length) {
      notifyApp({
        type: "error",
        title: "Preview unavailable",
        message: `Complete required create fields first: ${gaps.slice(0, 6).join("; ")}${gaps.length > 6 ? ` (+${gaps.length - 6} more)` : ""}.`,
      });
      return;
    }
    setPreviewZoom(DEFAULT_DOCUMENT_PREVIEW_ZOOM);
    setPreviewDocument("ris");
    // Saved slip: DomPDF iframe via PdfPreviewModal (same chrome as Requests/Dispatches).
    // Unsaved create draft: keep live HTML canvas from current form values.
    if (saved?.id) {
      setPdfPreviewOpen(true);
      setDraftPreviewOpen(false);
      return;
    }
    setPdfPreviewOpen(false);
    setDraftPreviewOpen(true);
  };

  const slipPreviewRow = useMemo(() => {
    if (!saved?.id) return null;
    return {
      ris_slip_id: saved.id,
      ris_advance_pdf_view_url: `/rros/ris/${saved.id}/preview-pdf/ris?inline=1`,
      ris_view_url: null,
      ris_preview: {
        form: true,
        has_dr: false,
      },
      requisition_issuance_slip: { id: saved.id, dr_number: null },
    };
  }, [saved?.id]);

  const savedSlipPreviewTabs = useMemo(() => {
    if (!slipPreviewRow) return null;
    const signedRisUrl = risEpirma.status === "signed"
      ? (risEpirma.signed_preview_url || `/rros/ris/${saved.id}/epirma/signed-preview`)
      : null;
    const risUrl = signedRisUrl || risAdvancePdfViewUrl(slipPreviewRow, "ris");
    return [
      {
        key: "ris",
        label: "RIS",
        src: risUrl,
        kind: risUrl ? (signedRisUrl ? "signed" : "draft") : null,
        message: risUrl
          ? (signedRisUrl ? "Official signed RIS retrieved through e-PIRMA." : ADVANCE_RIS_DR_MESSAGE)
          : "RIS preview is unavailable.",
      },
    ];
  }, [slipPreviewRow, risEpirma.status, risEpirma.signed_preview_url, saved?.id]);
  const progressSections = [
    {
      label: "Document Setup",
      anchor: "#ris-setup",
      complete: documentChecks.every(isCompleted),
      completed: documentChecks.filter(isCompleted).length,
      total: documentChecks.length,
    },
    {
      label: "FNIs per RIS / DR",
      anchor: "#ris-items",
      complete: itemChecks.length > 0 && itemChecks.every(isCompleted),
      completed: itemChecks.filter(isCompleted).length,
      total: itemChecks.length,
    },
    {
      label: "(RIS) Approving Authority Endorsement",
      anchor: "#ris-delivery",
      complete: endorsementChecks.every(isCompleted),
      completed: endorsementChecks.filter(isCompleted).length,
      total: endorsementChecks.length,
    },
    {
      label: "Accounting & Files",
      anchor: "#ris-accounting",
      complete: accountingChecks.every(isCompleted),
      completed: accountingChecks.filter(isCompleted).length,
      total: accountingChecks.length,
    },
  ];
  const visibleProgressSections = showPostRisSections
    ? progressSections.slice(2)
    : progressSections.slice(0, 2);

  useEffect(() => {
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previous;
    };
  }, []);

  useEffect(() => {
    if (!isPostRis) return;
    const timer = window.setTimeout(() => {
      const section = document.getElementById("ris-delivery");
      section?.scrollIntoView({ behavior: "smooth", block: "start" });
      const firstIncomplete = section?.querySelector(
        "input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), button[aria-haspopup]",
      );
      if (firstIncomplete && typeof firstIncomplete.focus === "function") {
        firstIncomplete.focus({ preventScroll: true });
      }
    }, 80);
    return () => window.clearTimeout(timer);
  }, [isPostRis]);

  useEffect(() => {
    loadNumbers(preparedDate);
  }, []);

  const setTracking = (key, value) =>
    form.setData("tracking_data", { ...form.data.tracking_data, [key]: value });
  const setRisDrnSequence = (sequence) =>
    setTracking("ris_drn", composeRisDrn(currentRisDrnPrefix, sequence));
  const loadNumbers = async (value) => {
    try {
      const response = await fetch(
        `/rros/requests/${request.id}/ris-next-number?date=${encodeURIComponent(value)}`,
        { headers: { Accept: "application/json" }, credentials: "same-origin" },
      );
      if (!response.ok) return;
      const numbers = await response.json();
      const nextPrefixes = prefixes(value);
      const counter =
        String(numbers.ris_number || "").match(/(\d{4})$/)?.[1] || "0001";
      form.setData((data) => ({
        ...data,
        ris_number: numbers.ris_number,
        tracking_data: {
          ...data.tracking_data,
          ris_number_prefix: nextPrefixes.ris,
          dr_number_prefix: nextPrefixes.dr,
          counter,
          dr_number: numbers.dr_number,
        },
      }));
    } catch {
      // Keep the locally generated fallback when the sequence endpoint is unavailable.
    }
  };
  const setPreparedDate = (value) => {
    const nextPrefixes = prefixes(value);
    const oldDrnPrefix = risDrnPrefixForDate(form.data.ris_date);
    const nextDrnPrefix = risDrnPrefixForDate(value);
    form.setData((data) => {
      const sequence = risDrnSequenceFromValue(
        data.tracking_data.ris_drn,
        oldDrnPrefix,
      );
      return {
        ...data,
        ris_date: value,
        tracking_data: {
          ...data.tracking_data,
          ris_number_prefix: nextPrefixes.ris,
          dr_number_prefix: nextPrefixes.dr,
          ris_drn: canAssignRisDrn
            ? composeRisDrn(nextDrnPrefix, sequence)
            : data.tracking_data.ris_drn,
        },
      };
    });
    loadNumbers(value);
  };
  const batchRowsFor = (
    item,
    warehouseId = item.warehouse_id,
    brand = item.brand_description,
    expiry = item.expiry,
  ) =>
    planningWarehouseStock.filter(
      (row) =>
        String(row.warehouse_id) === String(warehouseId) &&
        itemKey(row.item) === itemKey(item.item_name) &&
        (!brand || String(row.brand_description || "-") === String(brand)) &&
        (!expiry || String(row.expiry || "N/A") === String(expiry)),
    );
  const stockFor = (item, warehouseId = item.warehouse_id) =>
    batchRowsFor(item, warehouseId)
      .reduce((total, row) => total + Number(row.available || 0), 0);
  const brandOptions = (item) => [
    ...new Set(
      batchRowsFor(item, item.warehouse_id, null, null).map(
        (row) => String(row.brand_description || "-").trim() || "-",
      ),
    ),
  ].filter((value) => value !== "-").sort().map((value) => ({ value, label: value }));
  const expiryOptions = (item) => [
    ...new Set(
      batchRowsFor(item, item.warehouse_id, item.brand_description, null).map(
        (row) => applicableExpiry(row.expiry) ? String(row.expiry) : "",
      ),
    ),
  ].filter(applicableExpiry).sort(
    (a, b) =>
      (Date.parse(a || "") || Number.MAX_SAFE_INTEGER) -
      (Date.parse(b || "") || Number.MAX_SAFE_INTEGER),
  ).map((value) => ({ value, label: readableDate(value) }));
  const warehouseOptions = (item) => {
    const groupedRows = planningWarehouseStock
      .filter((row) => itemKey(row.item) === itemKey(item.item_name))
      .reduce((groups, row) => {
        const key = String(row.warehouse_id);
        const current = groups.get(key) || {
          ...row,
          available: 0,
          physicalTotal: 0,
          reservedTotal: 0,
          priceTotal: 0,
          priceWeight: 0,
          prices: [],
          expiries: [],
          brands: [],
        };
        const available = Number(row.available || 0);
        current.available += available;
        current.physicalTotal += Number(row.physical_available || 0);
        current.reservedTotal += Number(row.reserved_elsewhere || 0);
        if (
          Number.isFinite(Number(row.unit_price)) &&
          Number(row.unit_price) >= 0
        ) {
          current.priceTotal += Number(row.unit_price) * available;
          current.priceWeight += available;
          if (!current.prices.includes(Number(row.unit_price)))
            current.prices.push(Number(row.unit_price));
        }
        if (applicableExpiry(row.expiry) && !current.expiries.includes(row.expiry))
          current.expiries.push(row.expiry);
        const brand = String(row.brand_description || "").trim();
        if (brand && brand !== "-" && !current.brands.includes(brand))
          current.brands.push(brand);
        groups.set(key, current);
        return groups;
      }, new Map());
    const rows = Array.from(groupedRows.values()).map((row) => ({
      ...row,
      unit_price: row.priceWeight > 0 ? row.priceTotal / row.priceWeight : null,
    }));
    const municipality = locationKey(
      request.municipality || request.lgu || request.requesting_agency,
    );
    const province = locationKey(request.province);
    const requestZone =
      request.location_zone ||
      (province.includes("dinagat") ? "pdi" : "mainland");
    const localCoordinates = Array.from(
      new Map(
        planningWarehouseStock
          .filter(
            (row) =>
              locationKey(row.municipality) === municipality &&
              Number.isFinite(Number(row.latitude)) &&
              Number.isFinite(Number(row.longitude)),
          )
          .map((row) => [String(row.warehouse_id), row]),
      ).values(),
    );
    const target = localCoordinates.length
      ? {
          latitude:
            localCoordinates.reduce(
              (sum, row) => sum + Number(row.latitude),
              0,
            ) / localCoordinates.length,
          longitude:
            localCoordinates.reduce(
              (sum, row) => sum + Number(row.longitude),
              0,
            ) / localCoordinates.length,
        }
      : null;
    const ranked = rows
      .map((row) => {
        const sameMunicipality =
          Boolean(municipality) &&
          locationKey(row.municipality) === municipality;
        const sameProvince =
          Boolean(province) && locationKey(row.province) === province;
        const distance = target ? distanceKm(target, row) : null;
        const zone = warehouseZone(row);
        const requiresSeaTravel = zone !== requestZone;
        const localRank = sameMunicipality
          ? 0
          : distance != null
            ? 1
            : sameProvince
              ? 2
              : 3;
        return {
          ...row,
          zone,
          sameMunicipality,
          sameProvince,
          distance,
          requiresSeaTravel,
          recommendationRank: (requiresSeaTravel ? 10 : 0) + localRank,
        };
      })
      .sort(
        (a, b) =>
          a.recommendationRank - b.recommendationRank ||
          (a.distance ?? Number.MAX_SAFE_INTEGER) -
            (b.distance ?? Number.MAX_SAFE_INTEGER) ||
          Number(b.available) - Number(a.available),
      );
    return ranked.map((row, index) => ({
      ...row,
      actualWarehouseName: row.warehouse,
      warehouse: `${index === 0 ? "Recommended • " : ""}${row.warehouse} • ${formatWholeQuantity(row.available, "0")} available to plan${row.reservedTotal > 0 ? ` • ${formatWholeQuantity(row.reservedTotal, "0")} reserved by other RIS` : ""}${row.requiresSeaTravel ? " • SEA TRAVEL FALLBACK" : row.sameMunicipality ? " • same LGU" : row.distance != null ? ` • ${row.distance.toFixed(1)} km` : row.sameProvince ? " • same province" : " • land-access preferred"}`,
    }));
  };
  const calculatedItem = (item) => {
    const current = item.warehouse_id ? stockFor(item) : null;
    const warehouseRows = item.warehouse_id ? batchRowsFor(item) : [];
    const physical = item.warehouse_id
      ? warehouseRows.reduce(
          (total, row) => total + Number(row.physical_available || 0),
          0,
        )
      : null;
    const reserved = item.warehouse_id
      ? warehouseRows.reduce(
          (total, row) => total + Number(row.reserved_elsewhere || 0),
          0,
        )
      : null;
    const variance =
      current == null ? null : current - Number(item.quantity || 0);
    // Prefer allocation remarks / explicit unit_cost; fall back to warehouse stock prices for DR UNIT COST.
    let unit_cost =
      item.unit_cost != null && item.unit_cost !== "" && Number.isFinite(Number(item.unit_cost))
        ? Number(item.unit_cost)
        : null;
    if (unit_cost == null && warehouseRows.length) {
      let priceTotal = 0;
      let priceWeight = 0;
      warehouseRows.forEach((row) => {
        const price = Number(row.unit_price);
        const available = Number(row.available || 0);
        if (Number.isFinite(price) && price >= 0 && available > 0) {
          priceTotal += price * available;
          priceWeight += available;
        }
      });
      if (priceWeight > 0) unit_cost = priceTotal / priceWeight;
    }
    const stockBrands = [...new Set(
      warehouseRows
        .map((row) => String(row.brand_description || "").trim())
        .filter((value) => value && value !== "-"),
    )];
    const stockExpiries = [...new Set(
      warehouseRows
        .map((row) => row.expiry)
        .filter(applicableExpiry),
    )].sort(
      (a, b) =>
        (Date.parse(a || "") || Number.MAX_SAFE_INTEGER) -
        (Date.parse(b || "") || Number.MAX_SAFE_INTEGER),
    );
    return {
      ...item,
      ...(unit_cost != null ? { unit_cost } : {}),
      brand_description:
        item.brand_description || naturalList(stockBrands),
      expiry:
        item.expiry || naturalList(stockExpiries.map(readableDate)),
      wit_stock_balance: current == null ? null : Math.trunc(current),
      physical_stock_balance: physical == null ? null : Math.trunc(physical),
      reserved_elsewhere: reserved == null ? null : Math.trunc(reserved),
      remaining_balance: variance == null ? null : Math.trunc(variance),
      allocation_status:
        variance == null ? "" : variance >= 0 ? "SUFFICIENT" : "LACKING",
    };
  };
  const assessmentProvisionFor = (item) => {
    const assessmentItem = (request.items || []).find((row) =>
      (item.request_item_id && String(row.id) === String(item.request_item_id)) ||
      itemKey(row.item_name) === itemKey(item.item_name),
    );
    if (!assessmentItem) return null;
    const provision = Number(
      assessmentItem.approved_quantity || assessmentItem.requested_quantity || 0,
    );
    return Number.isFinite(provision) ? provision : null;
  };
  const refreshAutoRemarks = (item) => {
    const batch = batchRowsFor(item)[0];
    const brand = String(item.brand_description || batch?.brand_description || "").trim();
    const expiry = item.expiry || batch?.expiry;
    const price = Number(batch?.unit_price ?? item.unit_cost);
    const generatedText = [
      brand && brand !== "-" ? `Brand: ${brand}` : null,
      applicableExpiry(expiry) ? `Expiry: ${readableExpiry(expiry)}` : null,
      Number.isFinite(price) && price >= 0
        ? `Unit price: ${peso(price)}/${singularUnit(item.unit)}`
        : null,
    ].filter(Boolean).join("; ");
    const generated = generatedText ? `${generatedText}.` : "";
    const oldGenerated = String(item._auto_remarks || "");
    const existingRemarks = String(item.remarks || "");
    const userRemarks = oldGenerated && existingRemarks.startsWith(oldGenerated)
      ? existingRemarks.slice(oldGenerated.length).trim()
      : existingRemarks.replace(
          /^(?=(?:Brand|Brand\/Description|Expiry|Expiries|Unit price|Unit prices):).*?\.(?=\s+[A-Z]|$)\s*/,
          "",
        ).trim();

    return {
      ...item,
      _auto_remarks: generated,
      remarks: [generated, userRemarks].filter(Boolean).join(" "),
    };
  };
  const setItem = (index, key, value) => {
    const editedItem = form.data.items[index];
    const invalidatesPlan =
      key === "quantity" && Boolean(editedItem?._allocation_id);
    form.setData(
      "items",
      form.data.items.map((item, itemIndex) =>
        itemIndex === index
          ? calculatedItem(refreshAutoRemarks({
              ...item,
              [key]: value,
              ...(invalidatesPlan ? { _plan_stale: true } : {}),
            }))
          : item,
      ),
    );
    if (invalidatesPlan) {
      setAllocationNotice({
        type: "warning",
        text: "Quantity changed after the suggested plan was generated. Apply Suggested Plan again to recalculate expiry groups, source warehouses, stock balances, and remarks before preparing the RIS.",
      });
    }
  };
  const setWarehouse = (index, warehouseId) => {
    const item = form.data.items[index];
    const selected = warehouseOptions(item).find(
      (row) => String(row.warehouse_id) === String(warehouseId),
    );
    const warehouseDetails = [
      selected?.warehouse_type,
      selected?.warehouse_ownership,
    ]
      .filter(Boolean)
      .filter(
        (value, detailIndex, details) => details.indexOf(value) === detailIndex,
      )
      .join(" • ");
    const firstBatch = batchRowsFor(item, warehouseId, null, null)
      .filter((row) => Number(row.available || 0) > 0)
      .sort(
        (a, b) =>
          (Date.parse(a.expiry || "") || Number.MAX_SAFE_INTEGER) -
          (Date.parse(b.expiry || "") || Number.MAX_SAFE_INTEGER),
      )[0];
    const generatedRemarks = [
      firstBatch && String(firstBatch.brand_description || "").trim() !== "-"
        ? `Brand: ${firstBatch.brand_description}`
        : null,
      applicableExpiry(firstBatch?.expiry)
        ? `Expiry: ${readableExpiry(firstBatch.expiry)}`
        : null,
      Number.isFinite(Number(firstBatch?.unit_price))
        ? `Unit price: ${peso(firstBatch.unit_price)}/${singularUnit(item.unit)}`
        : null,
    ]
      .filter(Boolean)
      .join("; ");
    const punctuatedRemarks = generatedRemarks ? `${generatedRemarks}.` : "";
    form.setData(
      "items",
      form.data.items.map((row, itemIndex) => {
        if (itemIndex !== index) return row;
        const userRemarks =
          row._auto_remarks &&
          String(row.remarks || "").startsWith(row._auto_remarks)
            ? String(row.remarks || "")
                .slice(row._auto_remarks.length)
                .trim()
            : String(row.remarks || "").replace(
                /^(?=(?:Brand|Brand\/Description|Expiry|Expiries|Unit price|Unit prices):).*?\.(?=\s+[A-Z]|$)\s*/,
                "",
              ).trim();
        return calculatedItem({
          ...row,
          warehouse_id: selected?.warehouse_id || null,
          warehouse_name: selected?.actualWarehouseName || "",
          warehouse_type: warehouseDetails,
          brand_description: firstBatch && String(firstBatch.brand_description || "").trim() !== "-"
            ? String(firstBatch.brand_description)
            : "",
          expiry: firstBatch
            ? (applicableExpiry(firstBatch.expiry) ? String(firstBatch.expiry) : "")
            : "",
          _auto_remarks: punctuatedRemarks,
          remarks: [punctuatedRemarks, userRemarks].filter(Boolean).join(" "),
        });
      }),
    );
  };
  const setBrand = (index, brand) => {
    form.setData(
      "items",
      form.data.items.map((item, itemIndex) => {
        if (itemIndex !== index) return item;
        const firstExpiry = expiryOptions({ ...item, brand_description: brand })[0]?.value || "";
        return calculatedItem(refreshAutoRemarks({
          ...item,
          brand_description: brand,
          expiry: firstExpiry,
          _plan_stale: Boolean(item._allocation_id),
        }));
      }),
    );
  };
  const setExpiry = (index, expiry) => {
    form.setData(
      "items",
      form.data.items.map((item, itemIndex) =>
        itemIndex === index
          ? calculatedItem(refreshAutoRemarks({
              ...item,
              expiry,
              _plan_stale: Boolean(item._allocation_id),
            }))
          : item,
      ),
    );
  };
  const applyAllocationPlan = (automatic = false) => {
    const groups = Array.from(
      form.data.items
        .reduce((map, item) => {
          const key = item.request_item_id || itemKey(item.item_name);
          map.set(key, [...(map.get(key) || []), item]);
          return map;
        }, new Map())
        .values(),
    );
    let shortfall = 0;
    let sourceCount = 0;
    const planned = groups.flatMap((group) => {
      const base = group[0];
      const assessmentWarehouseId = base.warehouse_id;
      const assessmentProvision = assessmentProvisionFor(base);
      let remaining = assessmentProvision != null
        ? Math.max(0, Math.round(assessmentProvision))
        : group.reduce(
            (total, row) =>
              total + Math.max(0, Math.round(Number(row.quantity) || 0)),
            0,
          );
      const target = remaining;
      const candidates = warehouseOptions(base)
        .map((warehouse) => {
          const batches = planningWarehouseStock
            .filter(
              (row) =>
                String(row.warehouse_id) === String(warehouse.warehouse_id) &&
                itemKey(row.item) === itemKey(base.item_name) &&
                Number(row.available) > 0,
            )
            .sort(
              (a, b) =>
                (Date.parse(a.expiry || "") || Number.MAX_SAFE_INTEGER) -
                (Date.parse(b.expiry || "") || Number.MAX_SAFE_INTEGER),
            );
          return {
            ...warehouse,
            batches,
            earliestExpiry:
              Date.parse(
                batches.find((batch) => Date.parse(batch.expiry || ""))
                  ?.expiry || "",
              ) || Number.MAX_SAFE_INTEGER,
          };
        })
        .filter((warehouse) => warehouse.batches.length)
        .sort(
          (a, b) =>
            Number(String(b.warehouse_id) === String(assessmentWarehouseId)) -
              Number(String(a.warehouse_id) === String(assessmentWarehouseId)) ||
            Number(a.requiresSeaTravel) - Number(b.requiresSeaTravel) ||
            Number(b.sameMunicipality) - Number(a.sameMunicipality) ||
            a.earliestExpiry - b.earliestExpiry ||
            (a.distance ?? Number.MAX_SAFE_INTEGER) -
              (b.distance ?? Number.MAX_SAFE_INTEGER),
        );
      const allocations = [];
      for (const warehouse of candidates) {
        if (remaining <= 0) break;
        const expiryParts = [];
        let allocated = 0;
        for (const batch of warehouse.batches) {
          if (remaining <= 0) break;
          const quantity = Math.min(
            remaining,
            Math.floor(Number(batch.available) || 0),
          );
          if (quantity <= 0) continue;
          remaining -= quantity;
          allocated += quantity;
          const detailKey = `${batch.brand_description || ""}|${applicableExpiry(batch.expiry) ? batch.expiry : ""}|${Number.isFinite(Number(batch.unit_price)) ? Number(batch.unit_price) : ""}`;
          const existingDetail = expiryParts.find(
            (detail) => detail.key === detailKey,
          );
          if (existingDetail) {
            existingDetail.quantity += quantity;
          } else {
            expiryParts.push({
              key: detailKey,
              quantity,
              brand: String(batch.brand_description || "").trim() === "-"
                ? null
                : (String(batch.brand_description || "").trim() || null),
              expiry: applicableExpiry(batch.expiry) ? batch.expiry : null,
              unitPrice: Number.isFinite(Number(batch.unit_price))
                ? Number(batch.unit_price)
                : null,
            });
          }
        }
        if (!allocated) continue;
        sourceCount += 1;
        expiryParts.forEach((detail, detailIndex) => {
          const generated = `${[
            detail.brand ? `Brand: ${detail.brand}` : null,
            detail.expiry ? `Expiry: ${readableExpiry(detail.expiry)}` : null,
            detail.unitPrice != null
              ? `Unit price: ${peso(detail.unitPrice)}/${singularUnit(base.unit)}`
              : null,
          ].filter(Boolean).join("; ")}.${warehouse.requiresSeaTravel ? "\nNote: Sea-travel fallback." : ""}`;
          allocations.push(calculatedItem({
            ...base,
            warehouse_id: warehouse.warehouse_id,
            warehouse_name: warehouse.actualWarehouseName,
            warehouse_type: [
              warehouse.warehouse_type,
              warehouse.warehouse_ownership,
            ]
              .filter(Boolean)
              .filter((value, index, values) => values.indexOf(value) === index)
              .join(" • "),
            brand_description: detail.brand || "",
            expiry: detail.expiry || "",
            quantity: detail.quantity,
            ...(detail.unitPrice != null ? { unit_cost: detail.unitPrice } : {}),
            remarks: generated,
            _auto_remarks: generated,
            _allocation_id: `${base.request_item_id || itemKey(base.item_name)}-${warehouse.warehouse_id}-${detail.brand || "unspecified"}-${detail.expiry || "no-expiry"}-${detailIndex}`,
          }));
        });
      }
      if (remaining > 0) {
        shortfall += remaining;
        allocations.push({
          ...base,
          warehouse_id: null,
          warehouse_name: "",
          warehouse_type: "",
          quantity: remaining,
          remarks: `UNALLOCATED: ${formatWholeQuantity(remaining, "0")} of ${formatWholeQuantity(target, "0")} cannot be covered by current synchronized stock.`,
          _allocation_shortfall: true,
          _allocation_id: `${base.request_item_id || itemKey(base.item_name)}-shortfall`,
        });
      }
      return allocations;
    });
    form.setData("items", planned);
    setAllocationNotice(
      shortfall
        ? {
            type: "warning",
            text: `Suggested plan applied, but ${formatWholeQuantity(shortfall, "0")} unit(s) remain unallocated. Synchronize WIT or review the plan before preparing the RIS.`,
          }
        : {
            type: "success",
            text: automatic
              ? `Source warehouses were prefilled for ${sourceCount} allocation(s) using the assessment source where available, then earliest-expiry and delivery-access rules. You may change any source warehouse before saving.`
              : `Suggested plan covered all quantities using ${sourceCount} source allocation(s). Earliest-expiring eligible stock was prioritized without bypassing island-access rules.`,
          },
    );
  };

  useEffect(() => {
    if (
      initialAllocationAppliedRef.current ||
      saved ||
      !form.data.items.length ||
      !planningWarehouseStock.length
    ) {
      return;
    }

    initialAllocationAppliedRef.current = true;
    applyAllocationPlan(true);
  }, []);
  const postReadinessGaps = () => {
    const gaps = [];
    const labeled = [
      ["Date RIS was Endorsed to (RIS) Approving Authority", tracking.ardo_endorsed_at],
      ["Date RIS was Returned from (RIS) Approving Authority", tracking.ardo_returned_at],
      ["Forwarded RIS to Accounting?", tracking.forwarded_to_accounting],
      ["General Remarks", form.data.remarks],
    ];
    if (tracking.forwarded_to_accounting === "Yes") {
      labeled.push(
        ["Date RIS Forwarded to Accounting", tracking.forwarded_to_accounting_at],
        ["Received by (Accounting Staff)", tracking.accounting_received_by],
      );
    }
    labeled.forEach(([label, value]) => {
      if (!isCompleted(value)) gaps.push(label);
    });
    return [...new Set(gaps)];
  };
  const postRisSavable = [
    ...endorsementChecks,
    tracking.forwarded_to_accounting,
    notForwarded || tracking.forwarded_to_accounting_at,
    notForwarded || tracking.accounting_received_by,
    form.data.remarks,
  ].every(isCompleted);
  const missingUploads = [
    ["signed RIS / DR", saved?.ris_dr_path || form.data.ris_dr_file],
    ["RDS", saved?.rds_path || saved?.rds_link || form.data.rds_file],
    ["CSMR", saved?.csmr_path || saved?.csmr_link || form.data.csmr_file],
  ].filter(([, value]) => !isCompleted(value)).map(([label]) => label);
  const closeAfterSaveNotification = () => {
    // Closing issues a second Inertia visit. Give AppLayout time to render the
    // success toast from this save before that visit replaces the page props.
    window.setTimeout(onClose, 1200);
  };
  const validatePostRisDates = (includeAccounting = false) => {
    const errors = {};
    const endorsed = dateOnly(tracking.ardo_endorsed_at);
    const returned = dateOnly(tracking.ardo_returned_at);
    const forwarded = dateOnly(tracking.forwarded_to_accounting_at);
    const currentDate = today();
    if (endorsed && endorsed < risSystemCreatedDate) {
      errors["tracking_data.ardo_endorsed_at"] = `Endorsement cannot be earlier than the RIS / DR system creation date (${readableDate(risSystemCreatedDate)}).`;
    } else if (endorsed && endorsed > currentDate) {
      errors["tracking_data.ardo_endorsed_at"] = "Endorsement cannot be dated in the future.";
    }
    if (returned && returned < risSystemCreatedDate) {
      errors["tracking_data.ardo_returned_at"] = `Return cannot be earlier than the RIS / DR system creation date (${readableDate(risSystemCreatedDate)}).`;
    } else if (returned && endorsed && returned < endorsed) {
      errors["tracking_data.ardo_returned_at"] = "Return cannot be earlier than endorsement.";
    } else if (returned && returned > currentDate) {
      errors["tracking_data.ardo_returned_at"] = "Return cannot be dated in the future.";
    }
    if (includeAccounting && tracking.forwarded_to_accounting === "Yes") {
      if (forwarded && forwarded < risSystemCreatedDate) {
        errors["tracking_data.forwarded_to_accounting_at"] = `Accounting forwarding cannot be earlier than the RIS / DR system creation date (${readableDate(risSystemCreatedDate)}).`;
      } else if (forwarded && returned && forwarded < returned) {
        errors["tracking_data.forwarded_to_accounting_at"] = "Accounting forwarding cannot be earlier than the approving authority return date.";
      } else if (forwarded && forwarded > currentDate) {
        errors["tracking_data.forwarded_to_accounting_at"] = "Accounting forwarding cannot be dated in the future.";
      }
    }
    if (!Object.keys(errors).length) return true;
    form.clearErrors();
    form.setError(errors);
    notifyApp({
      type: "error",
      title: "Invalid Post-RIS chronology",
      message: Object.values(errors).join(" "),
    });
    return false;
  };
  const runRisEpirmaAction = async (action, { silent = false } = {}) => {
    if (!saved?.id) {
      if (!silent) notifyApp({ type: "error", title: "RIS is not ready", message: "Generate and save the RIS / DR before forwarding it." });
      return;
    }
    // Silent socket/poll synchronization must never block a deliberate user
    // action such as Forward or Route. Only interactive actions own the busy UI.
    if (!silent && risEpirmaBusy) return;
    const interactive = !silent;
    // Reserve the tab during the click event; browsers otherwise block a
    // window opened only after the asynchronous e-PIRMA request completes.
    const epirmaTab = action === "route" ? openEpirmaTabPlaceholder("Opening RIS in e-PIRMA…") : null;
    if (interactive) setRisEpirmaBusy(true);
    try {
      const response = await fetch(`/rros/ris/${saved.id}/epirma/${action}`, {
        method: action === "status" ? "GET" : "POST",
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content || "" },
      });
      const payload = await response.json();
      if (!response.ok || payload.success === false) throw new Error(payload.message || "Unable to update RIS e-PIRMA.");
      if (payload.workflow) {
        const statusChanged = payload.workflow.status && payload.workflow.status !== risEpirma.status;
        setRisEpirma(payload.workflow);
        if (payload.workflow.complete) setEndorsementSaved(true);
        if (silent && statusChanged) notifyApp({
          type: payload.workflow.complete ? "success" : "info",
          title: payload.workflow.complete ? "RIS signing completed" : "e-PIRMA status updated",
          message: payload.workflow.complete ? "The RIS is fully signed. Section 4 is now unlocked." : `The RIS e-PIRMA status is now ${String(payload.workflow.status).replaceAll('_', ' ')}.`,
        });
      }
      if (!silent) notifyApp({ type: "success", title: action === "status" ? "e-PIRMA status refreshed" : "RIS workflow updated", message: payload.message || (payload.workflow?.complete ? "RIS approval is complete. Section 4 is unlocked." : "The current e-PIRMA status is displayed below.") });
      if (payload.redirect_url) {
        if (!navigateEpirmaTab(epirmaTab, payload.redirect_url)) {
          throw new Error("Allow pop-ups for this site so e-PIRMA can open in a new tab.");
        }
      } else if (epirmaTab) {
        closeEpirmaTab(epirmaTab);
      }
    } catch (error) {
      if (epirmaTab && !epirmaTab.closed) {
        try {
          epirmaTab.document.body.innerHTML = `<main style="margin:0;font-family:Segoe UI,system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:grid;place-items:center;min-height:100vh;padding:32px;box-sizing:border-box"><section style="max-width:680px;text-align:center"><h1 style="font-size:20px">Unable to open RIS in e-PIRMA</h1><p style="line-height:1.6;color:#cbd5e1">${String(error.message || "The e-PIRMA request failed.").replace(/[<>&"]/g, "")}</p><p style="font-size:13px;color:#94a3b8">You may close this tab and try again after correcting the issue.</p></section></main>`;
          epirmaTab.document.title = "RIS e-PIRMA error";
        } catch { closeEpirmaTab(epirmaTab); }
      }
      if (!silent) notifyApp({ type: "error", title: "RIS e-PIRMA action failed", message: error.message });
    } finally {
      if (interactive) setRisEpirmaBusy(false);
    }
  };

  useEffect(() => {
    if (!showPostRisSections || form.data.approval_routing_mode !== "epirma" || !saved?.id) return undefined;
    const active = ["forwarded", "pending", "routed", "partially_signed"].includes(String(risEpirma.status || ""));
    if (!active) return undefined;
    runRisEpirmaAction("status", { silent: true });
    const timer = window.setInterval(() => runRisEpirmaAction("status", { silent: true }), 15000);
    return () => window.clearInterval(timer);
  }, [showPostRisSections, form.data.approval_routing_mode, saved?.id, risEpirma.status]);

  useEffect(() => listenRealtime("ris.epirma.status.changed", (payload = {}) => {
    if (!saved?.id || Number(payload.ris_id) !== Number(saved.id) || !payload.workflow) return;
    const workflow = payload.workflow;
    setRisEpirma(workflow);
    setEndorsementSaved(Boolean(workflow.complete));
    if (workflow.complete) setPersistedSlipStatus("approved");
  }), [saved?.id]);

  useEffect(() => listenRealtime("epirma.status.changed", (payload = {}) => {
    if (!saved?.id || Number(payload.request_id) !== Number(request?.id)) return;
    if (payload.document_type && String(payload.document_type).toLowerCase() !== "ris") return;
    runRisEpirmaAction("status", { silent: true });
  }), [saved?.id, request?.id]);

  const savePostRis = () => {
    if (!endorsementSaved) {
      if (form.data.approval_routing_mode === "epirma") {
        notifyApp({ type: "warning", title: "e-PIRMA approval is not complete", message: "Forward or route the RIS and wait until all configured signatories have completed signing." });
        return;
      }
      submit("approved", "endorsement");
      return;
    }
    submit(missingUploads.length ? "approved" : "completed");
  };
  const submit = (status, postRisSection = null) => {
    if (form.data.approval_routing_mode === "manual" && !validatePostRisDates(postRisSection !== "endorsement")) return;
    if (postRisSection === "endorsement" && form.data.approval_routing_mode === "manual") {
      const gaps = [
        ["Date RIS was Endorsed to (RIS) Approving Authority", tracking.ardo_endorsed_at],
        ["Date RIS was Returned from (RIS) Approving Authority", tracking.ardo_returned_at],
      ].filter(([, value]) => !isCompleted(value)).map(([label]) => label);
      if (gaps.length) {
        notifyApp({
          type: "error",
          title: "Section 3 incomplete",
          message: `Complete required endorsement fields first: ${gaps.join("; ")}.`,
        });
        return;
      }
    }
    if ((status === "approved" || status === "completed") && postRisSection !== "endorsement") {
      const gaps = postReadinessGaps();
      if (gaps.length) {
        notifyApp({
          type: "error",
          title: "Post RIS / DR incomplete",
          message: `Complete required post fields first: ${gaps.slice(0, 6).join("; ")}${gaps.length > 6 ? ` (+${gaps.length - 6} more)` : ""}.`,
        });
        return;
      }
    }
    form.transform((data) => {
      const prefix = risDrnPrefixForDate(data.ris_date);
      const sequence = risDrnSequenceFromValue(
        data.tracking_data?.ris_drn,
        prefix,
      );
      const trackingData = { ...(data.tracking_data || {}) };
      // Do not submit dispatch-owned transport fields from RIS / Post-RIS forms.
      [
        "mode_of_transportation",
        "vehicle_type",
        "vehicle_types",
        "number_of_vehicles",
        "no_of_vehicles",
        "driver_name",
        "driver_contact_number",
        "vehicle_plate_number",
      ].forEach((key) => {
        delete trackingData[key];
      });
      return {
        ...data,
        status,
        post_ris_section: postRisSection,
        tracking_data: {
          ...trackingData,
          // Do not persist an incomplete year/month-only prefix on draft saves.
          ris_drn: sequence ? `${prefix}${sequence}` : "",
        },
        items: data.items.map(
          ({
            _auto_remarks,
            _allocation_id,
            _allocation_shortfall,
            _plan_stale,
            ...item
          }) => calculatedItem(item),
        ),
      };
    });
    form.post(`/rros/requests/${request.id}/ris`, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => {
        setSlipSavedLocally(true);
        setPersistedSlipStatus(status);
        // Only Generate / prepared path opens the print-ready preview modal.
        // Draft save stays on the form so encoding can continue.
        // Post-RIS approved save closes back to the workspace.
        if (postRisSection === "endorsement") {
          setEndorsementSaved(true);
          notifyApp({
            type: "success",
            title: "Section 3 saved",
            message: "Approving Authority endorsement dates recorded and marked Approved. Section 4 is now available.",
          });
        } else if (status === "draft") {
          notifyApp({
            type: "success",
            title: "RIS / DR draft saved",
            message: "Document setup and FNI allocation changes were saved successfully.",
          });
        } else if (status === "prepared") {
          setPreviewDocument("ris");
          setDraftPreviewOpen(true);
          setGeneratedAwaitingHandoff(true);
          setSigningReminderOpen(true);
          notifyApp({
            type: "success",
            title: "RIS / DR generated",
            message:
              "Please notify the dispatch officer that an RIS is ready for signing.",
          });
        } else if (status === "approved") {
          notifyApp({
            type: missingUploads.length ? "warning" : "success",
            title: missingUploads.length ? "Saved — uploads still needed" : "Post RIS / DR saved",
            message: missingUploads.length
              ? `Upload ${missingUploads.join(", ")} before this RIS / DR can be marked Completed.`
              : "Accounting handoff recorded.",
          });
          if (!missingUploads.length) closeAfterSaveNotification();
        } else if (status === "completed") {
          notifyApp({
            type: "success",
            title: "Post RIS / DR completed",
            message: "Accounting handoff and all confirmed uploads were saved.",
          });
          closeAfterSaveNotification();
        }
      },
      onError: (errors) => {
        const messages = Object.values(errors || {})
          .flat()
          .filter(Boolean)
          .map(String);
        notifyApp({
          type: "error",
          title:
            status === "draft"
              ? "Draft not saved"
              : status === "approved"
                ? "Unable to save post RIS / DR"
                : "Unable to generate",
          message:
            messages.slice(0, 4).join(" ") ||
            "Please review the form and try again.",
        });
      },
    });
  };

  return (
    <div
      className="fixed inset-0 z-[110] flex bg-slate-950/75 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-label="Requisition and Issuance Slip / Delivery Receipt form"
    >
      <div className="flex h-dvh w-screen max-w-none flex-col overflow-hidden bg-slate-100 shadow-2xl dark:bg-zinc-950">
        <header className="flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
          <div>
            <p className="text-xs font-black uppercase tracking-wider text-emerald-700">
              RROS Request Workspace
            </p>
            <h2 className="mt-1 flex items-center gap-2 text-xl font-black">
              <ClipboardList className="h-5 w-5" />{" "}
              {showPostRisSections ? "Complete Post RIS/DR" : "Create RIS/DR"}
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              RIS / DR tracking and FNI allocation for {request.reference_number}.
            </p>
            <p className="mt-2 text-xs font-semibold text-slate-600">
              Fields marked with <span className="font-black text-rose-600">*</span> are required.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              title={
                navCollapsed ? "Show form sections" : "Collapse form sections"
              }
              aria-label={
                navCollapsed ? "Show form sections" : "Collapse form sections"
              }
              onClick={() => setNavCollapsed((value) => !value)}
              className="rounded-lg border border-slate-200 bg-white p-2 hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900"
            >
              {navCollapsed ? (
                <ChevronRight className="h-5 w-5" />
              ) : (
                <ChevronLeft className="h-5 w-5" />
              )}
            </button>
            <button
              type="button"
              onClick={onClose}
              aria-label="Close RIS / DR form"
              data-tip="Close RIS / DR form"
              data-tip-side="bottom"
              data-tip-preferred-side="bottom"
              data-tip-locked="true"
              className="dromis-tip rounded-lg border border-slate-200 bg-white p-2 hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        </header>

        <div className="relative border-b border-slate-200 bg-white px-6 py-3 dark:border-zinc-800 dark:bg-zinc-950">
          <button
            type="button"
            onClick={() => setProgressOpen((value) => !value)}
            className="flex w-full items-center gap-3 text-left"
          >
            <span className="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-emerald-800">
              <ListChecks className="h-4 w-4" />
            </span>
            <span className="min-w-0 flex-1">
              <span className="flex justify-between text-xs font-black uppercase">
                <span>{showPostRisSections ? "Post-RIS / DR Monitoring Progress" : "RIS / DR Preparation Progress"}</span>
                <span>
                  {completedChecks}/{progressChecks.length} fields ·{" "}
                  {progressPercent}%
                </span>
              </span>
              <span className="mt-1 block h-2 overflow-hidden rounded-full bg-slate-100">
                <span
                  className="block h-full rounded-full bg-emerald-600 transition-all"
                  style={{ width: `${progressPercent}%` }}
                />
              </span>
            </span>
            <ChevronDown
              className={`h-4 w-4 transition ${progressOpen ? "rotate-180" : ""}`}
            />
          </button>
          {progressOpen && (
            <div className={`absolute left-6 right-6 top-[calc(100%+6px)] z-20 grid gap-2 rounded-xl border border-emerald-200 bg-white p-3 shadow-xl dark:bg-zinc-950 ${showPostRisSections ? "sm:grid-cols-2" : "sm:grid-cols-2"}`}>
              {visibleProgressSections.map((section) => (
                <a
                  key={section.label}
                  href={section.anchor}
                  onClick={() => setProgressOpen(false)}
                  className={`flex items-center justify-between rounded-lg border p-3 text-xs font-black ${section.complete ? "border-emerald-200 bg-emerald-50 text-emerald-800" : "border-amber-200 bg-amber-50 text-amber-800"}`}
                >
                  <span>
                    {section.label}
                    <span className="mt-0.5 block text-[10px] font-semibold opacity-70">
                      {section.completed}/{section.total} fields
                    </span>
                  </span>
                  {section.complete ? (
                    <CheckCircle2 className="h-4 w-4" />
                  ) : (
                    <span>Needs input</span>
                  )}
                </a>
              ))}
            </div>
          )}
        </div>

        <div
          className={`grid min-h-0 min-w-0 flex-1 ${navCollapsed ? "lg:grid-cols-[68px_minmax(0,1fr)]" : "lg:grid-cols-[230px_minmax(0,1fr)]"}`}
        >
          <aside className="hidden border-r border-slate-200 bg-white p-3 lg:block dark:border-zinc-800 dark:bg-zinc-950">
            {!navCollapsed && (
              <p className="mb-3 text-[10px] font-black uppercase tracking-[.2em] text-slate-400">
                Form sections
              </p>
            )}
            {!isPostRis && (
              <>
                <Nav
                  collapsed={navCollapsed}
                  href="#ris-setup"
                  icon={FileCheck2}
                  label="Document Setup"
                  number="01"
                />
                <Nav
                  collapsed={navCollapsed}
                  href="#ris-items"
                  icon={PackageCheck}
                  label="FNIs per RIS / DR"
                  number="02"
                />
              </>
            )}
            {showPostRisSections && (
              <>
                <Nav
                  collapsed={navCollapsed}
                  href="#ris-delivery"
                  icon={Truck}
                  label="(RIS) Approving Authority Endorsement"
                  number="03"
                />
                <Nav
                  collapsed={navCollapsed}
                  href="#ris-accounting"
                  icon={ClipboardList}
                  label="Accounting & Files"
                  number="04"
                />
              </>
            )}
            {!navCollapsed && (
              <div className="mt-5 rounded-xl bg-emerald-50 p-3 text-xs leading-5 text-emerald-900">
                <strong>Guided flow:</strong> {showPostRisSections
                  ? "complete (RIS) Approving Authority endorsement dates and accounting / document links. Delivery and receipt are encoded on the Dispatch Plan."
                  : "confirm request data and encode the stock allocations needed to create the RIS / DR."}
              </div>
            )}
          </aside>

          <main className="min-w-0 max-w-full overflow-x-hidden overflow-y-auto p-4 md:p-6">
            <div className="w-full min-w-0 max-w-none space-y-5">
              {isPostRis && (
                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                  <p className="text-[10px] font-black uppercase tracking-[.18em] text-slate-500">
                    Prepared RIS / DR · Read only
                  </p>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Summary label="RIS Number" value={form.data.ris_number} />
                    <Summary label="Recipient" value={form.data.recipient || "—"} />
                    <Summary label="Delivery site" value={form.data.delivery_site || "—"} />
                  </div>
                  <p className="mt-3 text-xs font-semibold text-slate-600">
                    A separate Delivery Receipt is assigned to every vehicle in the Dispatch Plan. Transport, release, and LGU receipt details are maintained there.
                  </p>
                </div>
              )}
              {!isPostRis && (
              <>
              <FormSection
                id="ris-setup"
                number="01"
                title="Document Setup"
                subtitle="RIS / DR Tracking columns W–AM"
                icon={FileCheck2}
              >
                <div className="mb-5 rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                  <p className="mb-3 text-[10px] font-black uppercase tracking-[.18em] text-slate-500">
                    System-provided information · Read only
                  </p>
                  <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Field
                      label="Assessment DRN"
                      required
                      hint="From the signed assessment"
                    >
                      <Input value={tracking.assessment_drn_for_ris} disabled />
                    </Field>
                    <Field
                      label="Purpose of Request"
                      required
                      hint="From the approved request"
                    >
                      <Input value={tracking.purpose_of_request} disabled />
                    </Field>
                    <Field
                      label="Incident Type / Weather Disturbance"
                      hint="From the approved request"
                    >
                      <Input
                        value={tracking.incident_type || "Not applicable"}
                        disabled
                      />
                    </Field>
                    <Field
                      label="Incident Specification"
                      hint="From the approved request"
                    >
                      <Input
                        value={
                          tracking.incident_specification || "Not specified"
                        }
                        disabled
                      />
                    </Field>
                    <Field
                      label="Purpose of Release"
                      required
                      hint="Generated from the approved incident and requesting LGU"
                      className="md:col-span-2 xl:col-span-4"
                    >
                      <Input value={form.data.purpose_of_release} disabled />
                    </Field>
                    <Field label="(RIS / DR) Prepared / Processed By" required>
                      <Input value={currentUser?.name || ""} disabled />
                    </Field>
                    <Field
                      label="RIS Number"
                      required
                      hint="Generated automatically"
                    >
                      <Input value={form.data.ris_number} disabled />
                    </Field>
                    <Field
                      label="Receiving LSWDO / Representative"
                      hint="Fetched from LGU Directory"
                    >
                      <Input
                        value={form.data.receiving_representative}
                        disabled
                      />
                    </Field>
                    <Field
                      label="Contact No. of LSWDO / Representative"
                      hint="Fetched from LGU Directory"
                    >
                      <Input value={form.data.contact_number} disabled />
                    </Field>
                  </div>
                </div>
                <div>
                  <p className="mb-3 text-[10px] font-black uppercase tracking-[.18em] text-emerald-700">
                    Information to encode
                  </p>
                  <div className="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Field label="Date the RIS / DR are prepared" required>
                      <Input
                        type="date"
                        value={form.data.ris_date}
                        onChange={(e) => setPreparedDate(e.target.value)}
                      />
                    </Field>
                    <Field
                      label="RIS / DR DRN"
                      required={canAssignRisDrn}
                      hint={
                        canAssignRisDrn
                          ? "The year/month prefix is generated. Add the final sequence to generate the RIS / DR."
                          : "Assigned by RROS / RROS AA during RIS / DR generation"
                      }
                      error={form.errors["tracking_data.ris_drn"]}
                    >
                      {canAssignRisDrn ? (
                        <div className="flex w-full min-w-0 items-stretch overflow-hidden rounded-md border border-slate-300 bg-white focus-within:border-emerald-600 focus-within:ring-1 focus-within:ring-emerald-600">
                          <span
                            title={currentRisDrnPrefix}
                            className="min-w-0 flex-[3] break-all border-r border-slate-200 bg-slate-100 px-2 py-2 text-[1.00em] font-black leading-snug tracking-tight text-slate-500"
                          >
                            {currentRisDrnPrefix}
                          </span>
                          <input
                            value={risDrnSequence}
                            onChange={(event) =>
                              setRisDrnSequence(event.target.value)
                            }
                            placeholder="0001"
                            aria-label="RIS / DR DRN final sequence"
                            className="min-w-0 w-auto flex-1 border-0 bg-white px-2 py-2 text-sm font-black tracking-tight text-slate-900 outline-none focus:ring-0"
                          />
                        </div>
                      ) : (
                        <Input
                          value={tracking.ris_drn || ""}
                          disabled
                          placeholder="Pending RIS DRN assignment"
                        />
                      )}
                    </Field>
                    <Field label="Delivery Site" required>
                      <Input
                        value={form.data.delivery_site}
                        onChange={(e) =>
                          form.setData("delivery_site", e.target.value)
                        }
                      />
                    </Field>
                    <Field label="Recipient" required>
                      <Input
                        value={form.data.recipient}
                        onChange={(e) =>
                          form.setData("recipient", e.target.value)
                        }
                      />
                    </Field>
                  </div>
                </div>
              </FormSection>

              <FormSection
                id="ris-items"
                number="02"
                title="FNIs per RIS / DR"
                subtitle="Placed here because allocations depend on the RIS / DR identifiers above"
                icon={PackageCheck}
              >
                <div className="mb-4 grid gap-3 sm:grid-cols-3">
                  <Summary label="RIS Number" value={form.data.ris_number} />
                  <Summary
                    label="Recipient"
                    value={form.data.recipient || "Not encoded"}
                  />
                </div>
                <div className="mb-4 grid min-w-0 gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                  <div className="min-w-0">
                    <p className="text-sm font-black text-emerald-950">
                      Suggested stock allocation
                    </p>
                    <p className="mt-1 text-xs font-semibold leading-5 text-emerald-800">
                      Prioritizes the earliest expiry group, continues to the
                      next expiry when insufficient, then splits the balance
                      across other stock-qualified warehouses. Same-LGU and same
                      logistics-zone sources remain preferred over sea-travel
                      options.
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => applyAllocationPlan(false)}
                    disabled={!form.data.items.length}
                    className="inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-emerald-700 px-4 py-2 text-xs font-black text-white disabled:opacity-50"
                  >
                    <Sparkles className="h-4 w-4" /> Apply Suggested Plan
                  </button>
                </div>
                {allocationNotice && (
                  <div
                    className={`mb-4 rounded-lg border px-4 py-3 text-sm font-bold ${allocationNotice.type === "warning" ? "border-amber-300 bg-amber-50 text-amber-900" : "border-emerald-300 bg-white text-emerald-800"}`}
                  >
                    {allocationNotice.text}
                  </div>
                )}
                <div className="w-full min-w-0 max-w-full overflow-x-auto overscroll-x-contain rounded-xl border border-slate-200 pb-2 [scrollbar-gutter:stable]">
                  <table className="w-full min-w-[1170px] table-fixed text-xs">
                    <colgroup>
                      <col className="w-[52px]" />
                      <col className="w-[72px]" />
                      <col className="w-[190px]" />
                      <col className="w-[110px]" />
                      <col className="w-[260px]" />
                      <col className="w-[180px]" />
                      <col className="w-[130px]" />
                      <col className="w-[120px]" />
                      <col className="w-[140px]" />
                      <col className="w-[100px]" />
                      <col className="w-[116px]" />
                    </colgroup>
                    <thead className="bg-slate-900 text-white">
                      <tr>
                        <th className="px-3 py-3 text-left">No.</th>
                        <th className="px-3 py-3 text-left">Unit</th>
                        <th className="px-3 py-3 text-left">
                          Description / FNI
                        </th>
                        <th className="px-3 py-3 text-right">
                          Quantity <span className="text-rose-400">*</span>
                        </th>
                        <th className="px-3 py-3 text-left">
                          Source Warehouse <span className="text-rose-400">*</span>
                        </th>
                        <th className="px-3 py-3 text-left">Brand</th>
                        <th className="px-3 py-3 text-left">Expiry</th>
                        <th className="px-3 py-3 text-right">
                          Current Stockpile
                        </th>
                        <th className="px-3 py-3 text-right">
                          Available to Plan
                        </th>
                        <th className="px-3 py-3 text-right">Variance</th>
                        <th className="px-3 py-3 text-center">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {form.data.items.map((item, index) => {
                        const calculated = calculatedItem(item);
                        const groupKey = allocationGroupKey(item);
                        const startsAllocationGroup =
                          index === 0 ||
                          allocationGroupKey(form.data.items[index - 1]) !==
                            groupKey;
                        let allocationRowSpan = 1;
                        if (startsAllocationGroup) {
                          while (
                            index + allocationRowSpan < form.data.items.length &&
                            allocationGroupKey(
                              form.data.items[index + allocationRowSpan],
                            ) === groupKey
                          ) {
                            allocationRowSpan += 1;
                          }
                        }
                        const logicalItemNumber = form.data.items
                          .slice(0, index + 1)
                          .filter(
                            (row, rowIndex, rows) =>
                              rowIndex === 0 ||
                              allocationGroupKey(rows[rowIndex - 1]) !==
                                allocationGroupKey(row),
                          ).length;
                        const allocationGroupRows = form.data.items.filter(
                          (row) => allocationGroupKey(row) === groupKey,
                        );
                        const splitSubtotal = allocationGroupRows.reduce(
                          (total, row) =>
                            total + Math.max(0, Number(row.quantity) || 0),
                          0,
                        );
                        const groupProvision = assessmentProvisionFor(item);
                        return (
                          <Fragment key={item._allocation_id || `${groupKey}-${item.warehouse_id || "unassigned"}-${index}`}>
                            <tr className="border-t border-slate-200 align-top">
                            {startsAllocationGroup && (
                              <>
                                <td rowSpan={allocationRowSpan} className="bg-slate-50 px-3 py-3 align-middle font-bold dark:bg-zinc-900/60">
                                  {logicalItemNumber}
                                </td>
                                <td rowSpan={allocationRowSpan} className="bg-slate-50 px-3 py-3 align-middle dark:bg-zinc-900/60">
                                  {item.unit || "—"}
                                </td>
                                <td rowSpan={allocationRowSpan} className="bg-slate-50 px-3 py-3 align-middle font-bold dark:bg-zinc-900/60">
                                  {item.item_name}
                                  {allocationRowSpan > 1 && (
                                    <span className="mt-1 block text-[10px] font-black uppercase tracking-wide text-blue-600">
                                      {allocationRowSpan} source allocations
                                    </span>
                                  )}
                                </td>
                                <td rowSpan={allocationRowSpan} className="bg-slate-50 px-3 py-3 text-right align-middle font-black dark:bg-zinc-900/60">
                                  {formatWholeQuantity(groupProvision ?? splitSubtotal, "0")}
                                </td>
                              </>
                            )}
                            <td className="w-72 px-3 py-2">
                              <div className="flex items-start gap-1.5">
                                <div className="min-w-0 flex-1">
                                  <SearchableSelect
                                    compact
                                    value={item.warehouse_id || ""}
                                    onChange={(value) => setWarehouse(index, value)}
                                    options={warehouseOptions(item).map((row) => ({
                                      value: row.warehouse_id,
                                      label: row.warehouse,
                                    }))}
                                    placeholder="Search or select warehouse"
                                  />
                                </div>
                                <button
                                  type="button"
                                  onClick={() => {
                                    setStockPreviewQuery("");
                                    setStockPreviewWarehouseId(item.warehouse_id);
                                  }}
                                  disabled={!item.warehouse_id}
                                  className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 shadow-sm transition hover:border-emerald-500 hover:bg-emerald-50 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-40"
                                  aria-label={`Preview ${item.warehouse_name || "warehouse"} current stockpile`}
                                  title="Preview warehouse current stockpile"
                                >
                                  <Eye className="h-4 w-4" />
                                </button>
                              </div>
                              {item.warehouse_id && item.warehouse_type ? (
                                <span
                                  className="mt-1 block truncate whitespace-nowrap text-[10px] font-semibold text-slate-500"
                                  title={String(item.warehouse_type).replace(/\s*\/\s*/g, " • ")}
                                >
                                  {String(item.warehouse_type).replace(/\s*\/\s*/g, " • ")}
                                </span>
                              ) : null}
                              {!warehouseOptions(item).length && (
                                <span className="mt-1 block text-xs font-semibold text-amber-700">
                                  No synchronized WIT stock found.
                                </span>
                              )}
                            </td>
                            <td className="min-w-[180px] px-3 py-2">
                              {brandOptions(item).length ? (
                                <SearchableSelect
                                  compact
                                  value={item.brand_description || ""}
                                  onChange={(value) => setBrand(index, value)}
                                  options={brandOptions(item)}
                                  placeholder="Select brand"
                                  disabled={!item.warehouse_id}
                                />
                              ) : null}
                            </td>
                            <td className="min-w-[140px] px-3 py-2">
                              {expiryOptions(item).length ? (
                                <SearchableSelect
                                  compact
                                  value={item.expiry || ""}
                                  onChange={(value) => setExpiry(index, value)}
                                  options={expiryOptions(item)}
                                  placeholder="Select expiry"
                                  disabled={!item.warehouse_id || (brandOptions(item).length > 0 && !item.brand_description)}
                                />
                              ) : null}
                            </td>
                            <td className="px-3 py-3 text-right font-bold">
                              {calculated.physical_stock_balance == null
                                ? "—"
                                : formatWholeQuantity(calculated.physical_stock_balance, "0")}
                            </td>
                            <td className="px-3 py-3 text-right font-bold">
                              {calculated.wit_stock_balance == null
                                ? "—"
                                : formatWholeQuantity(calculated.wit_stock_balance, "0")}
                              {Number(calculated.reserved_elsewhere) > 0 && (
                                <span className="mt-1 block whitespace-nowrap text-[10px] font-semibold text-amber-700">
                                  {formatWholeQuantity(calculated.reserved_elsewhere, "0")} reserved by other RIS
                                </span>
                              )}
                            </td>
                            <td
                              className={`px-3 py-3 text-right font-black ${Number(calculated.remaining_balance) < 0 ? "text-rose-700" : "text-emerald-700"}`}
                            >
                              {calculated.remaining_balance == null
                                ? "—"
                                : formatWholeQuantity(calculated.remaining_balance, "0")}
                            </td>
                            <td className="px-3 py-3 text-center">
                              {calculated.allocation_status ? (
                                <span
                                  className={`rounded-full px-2 py-1 text-[10px] font-black ${calculated.allocation_status === "SUFFICIENT" ? "bg-emerald-100 text-emerald-800" : "bg-rose-100 text-rose-800"}`}
                                >
                                  {calculated.allocation_status}
                                </span>
                              ) : (
                                "—"
                              )}
                            </td>
                            </tr>
                          </Fragment>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
                <p className="mt-3 text-xs font-semibold text-slate-500">
                  Current stockpile is the synchronized WIT balance before active
                  RIS reservations. Available to plan subtracts those
                  reservations. Variance equals available to plan minus RIS
                  quantity.
                </p>
                {!form.data.items.length && (
                  <p className="mt-3 rounded-lg bg-amber-50 p-4 text-sm font-bold text-amber-800">
                    No approved assessment items are available.
                  </p>
                )}
              </FormSection>
              </>
              )}

              {showPostRisSections && <FormSection
                id="ris-delivery"
                number="03"
                title="(RIS) Approving Authority Endorsement"
                subtitle={`Responsible: ${saved?.preparer?.name || currentUser?.name || "RROS staff"} · Delivery / receipt encoded on Dispatch Plan`}
                icon={Truck}
                phase="Post-RIS"
              >
                <div className="mb-4 grid gap-3 md:grid-cols-2">
                  {[['manual', 'Manual routing', 'Print the RIS / DR, route it physically to the signatories, and wait for approval before items may be released.'], ['epirma', 'e-PIRMA routing', 'Route the RIS electronically to the configured signatories. Completion notifies RROS and makes the RIS ready for Dispatch Plan.']].map(([value, title, copy]) => (
                    <button key={value} type="button" disabled={endorsementSaved} onClick={() => form.setData('approval_routing_mode', value)} className={`rounded-xl border p-4 text-left ${form.data.approval_routing_mode === value ? 'border-emerald-600 bg-emerald-50 ring-1 ring-emerald-600' : 'border-slate-200 bg-white'}`}>
                      <span className="block text-sm font-black text-slate-900">{title}</span><span className="mt-1 block text-xs font-semibold leading-5 text-slate-600">{copy}</span>
                    </button>
                  ))}
                </div>
                {form.data.approval_routing_mode === "manual" ? <>
                <p className="mb-4 text-xs font-semibold text-slate-600">Print the files and obtain the required wet signatures. Enter both dates after the approved RIS is returned. Dates must be on or after {readableDate(risSystemCreatedDate)} and cannot be future-dated.</p>
                <div className="grid gap-4 md:grid-cols-2">
                  <Field
                    label="Date RIS was Endorsed to (RIS) Approving Authority"
                    required
                    error={form.errors["tracking_data.ardo_endorsed_at"]}
                  >
                    <Input
                      type="date"
                      min={risSystemCreatedDate}
                      max={today()}
                      value={tracking.ardo_endorsed_at}
                      onChange={(e) =>
                        setTracking("ardo_endorsed_at", e.target.value)
                      }
                    />
                  </Field>
                  <Field
                    label="Date RIS was Returned from (RIS) Approving Authority"
                    required
                    error={form.errors["tracking_data.ardo_returned_at"]}
                  >
                    <Input
                      type="date"
                      min={tracking.ardo_endorsed_at || risSystemCreatedDate}
                      max={today()}
                      value={tracking.ardo_returned_at}
                      onChange={(e) =>
                        setTracking("ardo_returned_at", e.target.value)
                      }
                    />
                  </Field>
                </div>
                </> : <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div><p className="text-sm font-black text-blue-950">e-PIRMA status: <span className="uppercase">{risEpirma.status || 'not forwarded'}</span></p><p className="mt-1 max-w-4xl text-xs font-semibold leading-5 text-blue-900">An RROS user who is not the RROS AA (Admin Assistant) forwards this RIS to the RROS AA, who then routes it to the concerned signatories. If the RROS user is also the RROS AA, they can route the RIS directly through e-PIRMA. When signing is complete, RROS is notified and Section 4 unlocks automatically.</p></div>
                    <div className="flex gap-2">
                      {!isRrosAa && isPreparedByCurrentUser && !['forwarded','routed','partially_signed','signed'].includes(risEpirma.status) && <button type="button" disabled={risEpirmaBusy} onClick={() => runRisEpirmaAction('forward')} className="rounded-lg bg-blue-700 px-4 py-2 text-xs font-black text-white">Forward to RROS AA</button>}
                      {canStartRisEpirmaRoute && <button type="button" disabled={risEpirmaBusy} onClick={() => runRisEpirmaAction('route')} className="rounded-lg bg-blue-700 px-4 py-2 text-xs font-black text-white">Route through e-PIRMA</button>}
                      {isRrosAa && !isPreparedByCurrentUser && <button type="button" onClick={() => { window.location.href = `/rros-aa/epirma?search=${encodeURIComponent(form.data.ris_number || '')}`; }} className="rounded-lg bg-blue-700 px-4 py-2 text-xs font-black text-white">Open RROS AA Routing Workspace</button>}
                      {risEpirma.status === 'signed' && <button type="button" onClick={() => { setPreviewDocument('ris'); setPdfPreviewOpen(true); }} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-2 text-xs font-black text-white"><Eye className="h-3.5 w-3.5" />View signed RIS</button>}
                      {risEpirma.status && <button type="button" disabled={risEpirmaBusy} onClick={() => runRisEpirmaAction('status')} className="inline-flex items-center gap-1.5 rounded-lg border border-blue-300 bg-white px-4 py-2 text-xs font-black text-blue-900"><RefreshCw className={`h-3.5 w-3.5 ${risEpirmaBusy ? 'animate-spin' : ''}`} />Refresh status</button>}
                    </div>
                  </div>
                  {risEpirma.status === 'forwarded' && <div className="mt-3 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-xs font-bold text-emerald-900"><CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /><span>This RIS has been forwarded to the RROS AA (Administrative Assistant) and is ready for e-PIRMA routing to the concerned signatories.</span></div>}
                  {risEpirma.status && <div className="mt-4 rounded-xl border border-blue-200 bg-white p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2"><div><p className="text-xs font-black uppercase tracking-wide text-slate-700">Routing progress</p><p className="mt-1 text-[11px] font-semibold text-slate-500">Automatically checked every 15 seconds while routing is active.</p></div>{risEpirma.transaction_id && <span className="max-w-full break-all rounded bg-slate-100 px-2 py-1 font-mono text-[10px] font-bold text-slate-600">UUID: {risEpirma.transaction_id}</span>}</div>
                    <div className="mt-4 grid gap-2 md:grid-cols-4">{[
                      ['forwarded', 'Forwarded to RROS AA', risEpirma.forwarded_at],
                      ['routed', 'Routed through e-PIRMA', risEpirma.routed_at],
                      ['partially_signed', 'Signatures in progress', null],
                      ['signed', 'Signing completed', risEpirma.signed_at],
                    ].map(([stage, label, timestamp], index) => {
                      const order = { forwarded: 0, pending: 1, routed: 1, partially_signed: 2, signed: 3 };
                      const current = order[String(risEpirma.status)] ?? -1;
                      const complete = current > index || risEpirma.status === 'signed';
                      const active = current === index && risEpirma.status !== 'signed';
                      const reached = complete || active;
                      const Icon = complete ? CheckCircle2 : active ? Clock3 : Circle;
                      return <div key={stage} className={`rounded-lg border px-3 py-3 ${complete ? 'border-emerald-200 bg-emerald-50' : active ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-slate-50'}`}><div className="flex items-start gap-2"><Icon className={`mt-0.5 h-4 w-4 shrink-0 ${complete ? 'text-emerald-600' : active ? 'text-amber-600' : 'text-slate-300'}`} /><div><p className="text-[11px] font-black text-slate-800">{label}</p><p className="mt-1 text-[10px] font-semibold text-slate-500">{timestamp ? readableDateTime(timestamp) : reached ? (active ? 'Current stage' : 'Status confirmed') : 'Pending'}</p></div></div></div>;
                    })}</div>
                    {['failed', 'cancelled'].includes(String(risEpirma.status)) && <p className="mt-3 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-800"><AlertCircle className="h-4 w-4" />This e-PIRMA route is {risEpirma.status}. The RROS AA may start a new routing attempt.</p>}
                    {Array.isArray(risEpirma.document?.signers) && risEpirma.document.signers.length > 0 && <div className="mt-4 overflow-hidden rounded-lg border border-slate-200"><div className="bg-slate-50 px-3 py-2 text-[10px] font-black uppercase tracking-wide text-slate-600">Concerned signatories</div><div className="divide-y divide-slate-100">{risEpirma.document.signers.map((signer, index) => {
                      const signerStatus = String(signer.status || signer.signing_status || 'pending').replaceAll('_', ' ');
                      const signerDone = ['signed', 'completed', 'approved'].includes(signerStatus.toLowerCase());
                      return <div key={`${signer.username || signer.id || index}`} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-xs"><div><p className="font-black text-slate-800">{signer.fullname || signer.full_name || signer.name || signer.username || `Signatory ${index + 1}`}</p>{(signer.position || signer.designation) && <p className="mt-0.5 text-[10px] font-semibold text-slate-500">{signer.position || signer.designation}</p>}</div><div className="text-right"><span className={`inline-flex rounded px-2 py-0.5 text-[10px] font-black uppercase ${signerDone ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>{signerStatus}</span>{(signer.date_signed || signer.signed_at) && <p className="mt-1 text-[10px] font-semibold text-slate-500">{readableDateTime(signer.date_signed || signer.signed_at)}</p>}</div></div>;
                    })}</div></div>}
                  </div>}
                  <p className="mt-3 border-t border-blue-200 pt-3 text-xs font-semibold leading-5 text-blue-900">After approval, the concerned LGU is notified for reference—particularly for distant LGU-to-LGU warehouse releases. The LGU can see that e-PIRMA approval is complete, but printing continues to use only the unsigned RIS and DR so electronic signatures are never combined with wet signatures.</p>
                </div>}
              </FormSection>}

              {showPostRisSections && <FormSection
                id="ris-accounting"
                number="04"
                title="Accounting Handoff & Document Links"
                subtitle={`Responsible: ${saved?.preparer?.name || currentUser?.name || "RROS staff"}`}
                icon={ClipboardList}
                phase="Post-RIS"
                locked={!endorsementSaved}
                lockedMessage="Save section 3 first to unlock Accounting Handoff & Document Links."
              >
                <p className="mb-4 text-xs font-semibold text-slate-600">
                  Fields marked with <span className="font-black text-rose-600">*</span> are required to save. Uploads may be added later, but all three are required for Completed.
                </p>
                {missingUploads.length > 0 && (
                  <div className="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p className="font-black">Completion reminder</p>
                    <p className="mt-1 font-semibold">This form can be saved now, but it will not be marked Completed until you upload and confirm: {missingUploads.join(", ")}.</p>
                  </div>
                )}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                  <Field
                    label="Forwarded RIS to Accounting?"
                    required
                    error={form.errors["tracking_data.forwarded_to_accounting"]}
                  >
                    <YesNo
                      value={tracking.forwarded_to_accounting}
                      onChange={(e) =>
                        setTracking("forwarded_to_accounting", e.target.value)
                      }
                    />
                  </Field>
                  <Field
                    label="Date RIS Forwarded to Accounting"
                    required={tracking.forwarded_to_accounting === "Yes"}
                    error={form.errors["tracking_data.forwarded_to_accounting_at"]}
                  >
                    <Input
                      type="date"
                      min={tracking.ardo_returned_at || risSystemCreatedDate}
                      max={today()}
                      value={tracking.forwarded_to_accounting_at}
                      onChange={(e) =>
                        setTracking(
                          "forwarded_to_accounting_at",
                          e.target.value,
                        )
                      }
                    />
                  </Field>
                  <Field
                    label="Received by (Accounting Staff)"
                    required={tracking.forwarded_to_accounting === "Yes"}
                    hint="Enter at least 2 letters of the Accounting staff's last name or first name"
                    error={form.errors["tracking_data.accounting_received_by"]}
                  >
                    <MyPortalEmployeeSelect
                      value={tracking.accounting_received_by}
                      onChange={(value) => setTracking("accounting_received_by", value)}
                      accountingOnly
                      placeholder="Search Accounting staff..."
                    />
                  </Field>
                  <Field
                    label="Upload RIS / DR"
                    hint="Signed RIS / DR PDF, maximum 20 MB — required for completion"
                    error={form.errors.ris_dr_file}
                  >
                    <ConfirmedPdfUpload
                      currentName={saved?.ris_dr_name}
                      currentPath={saved?.ris_dr_path}
                      onConfirm={(file) => form.setData("ris_dr_file", file)}
                    />
                  </Field>
                  <Field
                    label="Upload RDS"
                    hint={
                      saved?.rds_name || saved?.rds_link
                        ? `Current: ${saved.rds_name || "Google Sheet file"}`
                        : "PDF, maximum 20 MB — required for completion"
                    }
                    error={form.errors.rds_file}
                  >
                    <ConfirmedPdfUpload
                      currentName={saved?.rds_name}
                      currentPath={saved?.rds_path || saved?.rds_link}
                      onConfirm={(file) => form.setData("rds_file", file)}
                    />
                  </Field>
                  <Field
                    label="Upload CSMR"
                    hint={
                      saved?.csmr_name || saved?.csmr_link
                        ? `Current: ${saved.csmr_name || "Google Sheet file"}`
                        : "PDF, maximum 20 MB — required for completion"
                    }
                    error={form.errors.csmr_file}
                  >
                    <ConfirmedPdfUpload
                      currentName={saved?.csmr_name}
                      currentPath={saved?.csmr_path || saved?.csmr_link}
                      onConfirm={(file) => form.setData("csmr_file", file)}
                    />
                  </Field>
                </div>
                <div className="mt-4 w-full">
                  <Field label="General Remarks" required error={form.errors.remarks} className="w-full">
                    <textarea
                      rows="3"
                      value={form.data.remarks}
                      onChange={(e) => form.setData("remarks", e.target.value)}
                      className="form-input w-full"
                    />
                  </Field>
                </div>
              </FormSection>}
            </div>
          </main>
        </div>

        {stockPreviewWarehouseId && (
          <div
            className="fixed inset-0 z-[205] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="warehouse-stock-preview-title"
            onMouseDown={(event) => {
              if (event.target === event.currentTarget) setStockPreviewWarehouseId(null);
            }}
          >
            <div className="flex max-h-[82vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
              <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
                <div className="min-w-0">
                  <p className="text-[10px] font-black uppercase tracking-wide text-emerald-700">Current stockpile preview</p>
                  <h2 id="warehouse-stock-preview-title" className="mt-1 truncate text-lg font-black text-slate-900 dark:text-zinc-50">
                    {stockPreviewWarehouse?.warehouse || "Selected warehouse"}
                  </h2>
                  <p className="mt-1 truncate text-xs font-semibold text-slate-500">
                    {[stockPreviewWarehouse?.warehouse_type, stockPreviewWarehouse?.warehouse_ownership]
                      .filter(Boolean).filter((value, index, values) => values.indexOf(value) === index).join(" • ")}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setStockPreviewWarehouseId(null)}
                  className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:border-zinc-700 dark:hover:bg-zinc-800"
                  aria-label="Close warehouse stock preview"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
              <div className="overflow-auto p-4">
                <div className="mb-3 flex items-center justify-between gap-3">
                  <div className="relative w-full max-w-sm">
                    <input
                      type="search"
                      value={stockPreviewQuery}
                      onChange={(event) => setStockPreviewQuery(event.target.value)}
                      placeholder="Filter by item, category, brand, or expiry"
                      className="form-input h-9 w-full text-xs"
                      aria-label="Filter warehouse stockpile items"
                    />
                  </div>
                  <span className="shrink-0 text-xs font-bold text-slate-500">
                    {filteredStockPreviewRows.length} item row{filteredStockPreviewRows.length === 1 ? "" : "s"}
                  </span>
                </div>
                {filteredStockPreviewRows.length ? (
                  <table className="w-full min-w-[760px] text-xs">
                    <thead className="bg-slate-900 text-white">
                      <tr>
                        <th className="px-3 py-2 text-left">Category</th>
                        <th className="px-3 py-2 text-left">Item</th>
                        <th className="px-3 py-2 text-left">Brand</th>
                        <th className="px-3 py-2 text-left">Expiry</th>
                        <th className="px-3 py-2 text-right">Current Balance</th>
                        <th className="px-3 py-2 text-right">Available to Plan</th>
                        <th className="px-3 py-2 text-right">Cost</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredStockPreviewRows.map((row, rowIndex) => {
                        const cost = Number(row.physical_available || 0) * Number(row.unit_price || 0);
                        return (
                          <tr key={`${row.item}-${row.brand_description}-${row.expiry}-${rowIndex}`} className="border-b border-slate-200 last:border-0 dark:border-zinc-800">
                            <td className="px-3 py-2 text-slate-600 dark:text-zinc-300">{row.category || ""}</td>
                            <td className="px-3 py-2 font-bold text-slate-900 dark:text-zinc-50">{row.item}</td>
                            <td className="px-3 py-2">{String(row.brand_description || "").trim() === "-" ? "" : row.brand_description}</td>
                            <td className="px-3 py-2">{applicableExpiry(row.expiry) ? readableDate(row.expiry) : ""}</td>
                            <td className="px-3 py-2 text-right font-bold">{formatWholeQuantity(row.physical_available, "0")}</td>
                            <td className="px-3 py-2 text-right font-bold text-emerald-700">{formatWholeQuantity(row.available, "0")}</td>
                            <td className="px-3 py-2 text-right">{Number.isFinite(cost) && cost > 0 ? peso(cost) : ""}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                    <tfoot className="border-t-2 border-slate-900 bg-slate-100 font-black text-slate-900 dark:border-zinc-100 dark:bg-zinc-900 dark:text-zinc-50">
                      <tr>
                        <td colSpan={4} className="px-3 py-2 text-right uppercase tracking-wide">Total</td>
                        <td className="px-3 py-2 text-right">{formatWholeQuantity(stockPreviewTotals.current, "0")}</td>
                        <td className="px-3 py-2 text-right text-emerald-700">{formatWholeQuantity(stockPreviewTotals.available, "0")}</td>
                        <td className="px-3 py-2 text-right">{stockPreviewTotals.cost > 0 ? peso(stockPreviewTotals.cost) : ""}</td>
                      </tr>
                    </tfoot>
                  </table>
                ) : (
                  <div className="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm font-semibold text-slate-500">
                    No current stockpile is recorded for this warehouse.
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

        {signingReminderOpen && (
          <div
            className="fixed inset-0 z-[200] flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="ris-signing-reminder-title"
            aria-describedby="ris-signing-reminder-body"
          >
            <div className="w-full max-w-lg rounded-xl border border-amber-200 bg-white p-6 shadow-2xl dark:border-amber-900 dark:bg-zinc-950">
              <p className="text-[10px] font-black uppercase tracking-wide text-emerald-700">Successfully generated</p>
              <h2 id="ris-signing-reminder-title" className="mt-1 text-lg font-black text-slate-900 dark:text-zinc-50">
                RIS is ready for preview
              </h2>
              <p id="ris-signing-reminder-body" className="mt-3 text-sm leading-6 text-slate-700 dark:text-zinc-200">
                The RIS was saved and moved to In Progress. Delivery Receipts will be assigned per vehicle when the Dispatch Plan is saved.
              </p>
              <div className="mt-5 flex justify-end">
                <button
                  type="button"
                  onClick={() => {
                    setPreviewDocument("ris");
                    setSigningReminderOpen(false);
                  }}
                  className="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-black text-white hover:bg-emerald-800"
                >
                  View RIS
                </button>
              </div>
            </div>
          </div>
        )}

        <PdfPreviewModal
          open={pdfPreviewOpen && Boolean(savedSlipPreviewTabs)}
          title={request?.reference_number || saved?.ris_number || "RIS / DR"}
          subtitle={
            canPrintDocuments
              ? "Advance DomPDF preview · same Document Preview chrome as Requests / Dispatches"
              : "Advance DomPDF preview for review — printing unlocks after Generate RIS / DR"
          }
          tabs={savedSlipPreviewTabs}
          initialTab="ris"
          wide
          onClose={() => setPdfPreviewOpen(false)}
        />

        {draftPreviewOpen && (
          <div
            className="fixed inset-0 z-[190] flex items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-label="RIS / DR document preview"
          >
            <div className="flex h-[96vh] w-[96vw] max-w-[1600px] flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-950">
              <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-zinc-800">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-[10px] font-black uppercase tracking-wide text-orange-700">Document preview</p>
                    <span className={`rounded px-2 py-0.5 text-[10px] font-black uppercase ${canPrintDocuments ? "bg-emerald-600 text-white" : "bg-amber-100 text-amber-900"}`}>
                      {canPrintDocuments ? "Ready to print" : "Draft"}
                    </span>
                  </div>
                  <h2 className="mt-0.5 truncate font-black text-slate-900 dark:text-zinc-50">
                    {previewDocument === "dr" ? "DR" : "RIS"}
                  </h2>
                  <p className="truncate text-xs text-slate-500">
                    {canPrintDocuments
                      ? `A4 paper preview · matches official Print ${previewDocument === "dr" ? "DR" : "RIS"} worksheet`
                      : "A4 paper preview for review — printing unlocks after Generate RIS / DR"}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setDraftPreviewOpen(false);
                    if (generatedAwaitingHandoff) {
                      setGeneratedAwaitingHandoff(false);
                      onGenerated?.();
                    }
                  }}
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

              <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-3 py-2 dark:border-zinc-800">
                <SectionTabs
                  appearance="plain"
                  value={previewDocument}
                  onChange={setPreviewDocument}
                  ariaLabel="RIS document tabs"
                  tabs={[
                    { id: "ris", label: "RIS" },
                  ]}
                />
                <div className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1 dark:border-zinc-700 dark:bg-zinc-900">
                  {DOCUMENT_PREVIEW_ZOOM_OPTIONS.map((option) => (
                    <button
                      key={option.value}
                      type="button"
                      onClick={() => setPreviewZoom(option.value)}
                      className={`rounded-md px-2.5 py-1 text-[11px] font-black ${
                        previewZoom === option.value
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

              {!canPrintDocuments && (
                <p className="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs font-bold text-amber-950">
                  Draft preview only — printing is disabled until you Generate RIS / DR (prepared save). Layout matches the printable form.
                </p>
              )}

              <div className="min-h-0 flex-1 overflow-hidden">
                <RrosOfficialPreviewCanvas zoom={previewZoom} className="h-full">
                  <PrintableRisDr
                    form={{
                      ...form.data,
                      items: form.data.items.map((item) =>
                        calculatedItem(refreshAutoRemarks(item)),
                      ),
                    }}
                    tracking={printTracking}
                    type={previewDocument}
                    signatories={rrosSignatories}
                  />
                </RrosOfficialPreviewCanvas>
              </div>

              <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <button type="button" onClick={() => {
                  setDraftPreviewOpen(false);
                  if (generatedAwaitingHandoff) {
                    setGeneratedAwaitingHandoff(false);
                    onGenerated?.();
                  }
                }} className="rounded-lg bg-slate-900 px-5 py-2 text-sm font-black text-white">
                  {generatedAwaitingHandoff ? "Continue to In Progress" : "Return to Editing"}
                </button>
                {canPrintDocuments && (
                  <button
                    type="button"
                    onClick={printPreviewDocument}
                    title={`Print ${printDocumentTitle}`}
                    className="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2 text-sm font-black text-white"
                  >
                    <Printer className="h-4 w-4" />
                    Print {previewDocument === "dr" ? "DR" : "RIS"}
                  </button>
                )}
              </div>
            </div>
          </div>
        )}

        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-6 py-4 dark:border-zinc-800 dark:bg-zinc-950">
          <p className="text-xs font-semibold text-slate-500">
            {showPostRisSections
              ? "Post-RIS updates track delivery and accounting."
              : slipIsPrepared
                ? "RIS / DR generated. Please notify the dispatch officer that an RIS is ready for signing."
                : "Saving a draft or prepared RIS / DR reserves its selected quantities for planning."}
          </p>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={!form.data.items.length && !slipIsPrepared}
              onClick={openDocumentPreview}
              className={`inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-black text-slate-700 disabled:opacity-50 ${canPreviewDocuments ? "" : "opacity-50"}`}
              title={
                canPreviewDocuments
                  ? "Preview encoded RIS / DR documents from current form values"
                  : "Complete required create fields (progress 100%) before preview"
              }
              aria-disabled={!canPreviewDocuments}
            >
              <Eye className="h-4 w-4" /> Preview RIS
            </button>
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-black"
            >
              Cancel
            </button>
            {!slipIsPrepared && <button
              type="button"
              disabled={form.processing}
              onClick={() => submit("draft")}
              className="inline-flex items-center gap-2 rounded-lg border border-emerald-700 bg-white px-4 py-2 text-sm font-black text-emerald-800 disabled:opacity-50"
              title="Save current encoding as a draft without opening preview"
            >
              <Save className="h-4 w-4" /> Save Draft
            </button>}
            <button
              type="button"
              disabled={
                form.processing ||
                !form.data.items.length ||
                hasAllocationShortfall ||
                (canAssignRisDrn && !risDrnComplete) ||
                (showPostRisSections && !endorsementSaved && !endorsementChecks.every(isCompleted)) ||
                (showPostRisSections && endorsementSaved && !postRisSavable)
              }
              title={
                hasAllocationShortfall
                  ? "Allocate every quantity to a source warehouse before preparing the RIS / DR"
                  : canAssignRisDrn && !risDrnComplete
                    ? `Enter the final RIS / DR DRN sequence after ${currentRisDrnPrefix}`
                  : showPostRisSections && !endorsementSaved
                    ? "Save the completed endorsement dates to mark this RIS / DR Approved and unlock section 4"
                  : showPostRisSections && !postRisSavable
                    ? "Complete the required accounting fields before saving; uploads may be added later"
                  : showPostRisSections
                    ? "Save delivery, receipt, accounting, and file updates (marks Approved)"
                    : slipIsPrepared
                      ? "Update the prepared RIS / DR"
                      : "Generate the RIS / DR"
              }
              onClick={() => showPostRisSections ? savePostRis() : submit("prepared")}
              className="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-black text-white disabled:opacity-50"
            >
              <Plus className="h-4 w-4" />{" "}
              {showPostRisSections
                ? "Save Post RIS / DR"
                : slipIsPrepared
                  ? "Update RIS / DR"
                  : "Generate RIS / DR"}
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}

function Nav({ href, icon: Icon, label, number, collapsed = false }) {
  return (
    <a
      href={href}
      title={label}
      className={`mb-2 flex items-center rounded-xl border border-transparent py-3 text-sm font-black text-slate-600 hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 ${collapsed ? "justify-center px-2" : "gap-3 px-3"}`}
    >
      {!collapsed && (
        <span className="text-[10px] text-slate-400">{number}</span>
      )}
      <Icon className="h-4 w-4 shrink-0" />
      {!collapsed && label}
    </a>
  );
}
function FormSection({ id, number, title, subtitle, icon: Icon, children, locked = false, lockedMessage = "Create or save the RIS / DR first. These follow-up fields do not affect RIS / DR generation.", phase = null }) {
  return (
    <section
      id={id}
      className="min-w-0 max-w-full scroll-mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
    >
      <header className="flex items-center gap-4 border-b border-slate-200 bg-gradient-to-r from-emerald-50 to-white px-5 py-4">
        <span className="text-3xl font-black text-emerald-200">{number}</span>
        <span className="rounded-xl bg-emerald-700 p-2 text-white">
          <Icon className="h-5 w-5" />
        </span>
        <div>
          <h3 className="font-black text-slate-950">{title}</h3>
          <p className="text-xs text-slate-500">{subtitle}</p>
        </div>
        {phase && <span className="ml-auto rounded-full bg-blue-100 px-3 py-1 text-[10px] font-black uppercase tracking-wide text-blue-700">{phase}</span>}
      </header>
      <fieldset disabled={locked} className={`min-w-0 max-w-full ${locked ? "opacity-60" : ""}`}>
        {locked && <div className="border-b border-amber-200 bg-amber-50 px-5 py-3 text-xs font-bold text-amber-800">{lockedMessage}</div>}
        <div className="min-w-0 max-w-full p-5">{children}</div>
      </fieldset>
    </section>
  );
}
function Field({ label, required = false, hint, error, children, className = "" }) {
  return (
    <div className={`flex h-full flex-col text-[11px] font-black uppercase leading-4 tracking-wide text-slate-600 ${className}`}>
      <span className="block min-h-8">
        {label}
        {required && <span className="text-rose-600"> *</span>}
      </span>
      <div className="mt-1.5 normal-case tracking-normal">{children}</div>
      {hint && (
        <span className="mt-1 block normal-case font-semibold tracking-normal text-slate-400">
          {hint}
        </span>
      )}
      {error && (
        <span className="mt-1 block normal-case text-rose-600">{error}</span>
      )}
    </div>
  );
}
function Input({ className = "", ...props }) {
  return (
    <input
      {...props}
      className={`form-input w-full ${props.disabled ? "bg-slate-100 text-slate-500" : "bg-white"} ${className}`}
    />
  );
}

function ConfirmedPdfUpload({ currentName = "", currentPath = "", onConfirm }) {
  const [pendingFile, setPendingFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState("");
  const [confirmedName, setConfirmedName] = useState("");
  const [viewingConfirmed, setViewingConfirmed] = useState(false);
  const storedUrl = currentPath
    ? (/^https?:\/\//i.test(currentPath) ? currentPath : `/storage/${String(currentPath).replace(/^\/+/, "")}`)
    : "";

  useEffect(() => () => {
    if (previewUrl?.startsWith("blob:")) URL.revokeObjectURL(previewUrl);
  }, [previewUrl]);

  const chooseFile = (event) => {
    const file = event.target.files?.[0] || null;
    event.target.value = "";
    if (!file) return;
    if (previewUrl?.startsWith("blob:")) URL.revokeObjectURL(previewUrl);
    setPendingFile(file);
    setPreviewUrl(URL.createObjectURL(file));
  };

  return (
    <>
      <div className="flex min-h-11 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2">
        <label className="cursor-pointer rounded-md bg-slate-100 px-3 py-1.5 text-xs font-black text-slate-800 hover:bg-slate-200">
          Choose PDF
          <input type="file" accept="application/pdf" className="sr-only" onChange={chooseFile} />
        </label>
        <span className="min-w-0 flex-1 truncate text-xs font-semibold text-slate-600">
          {confirmedName || currentName || (currentPath ? "Uploaded PDF" : "No confirmed file")}
        </span>
        {(previewUrl || storedUrl) && !pendingFile && (
          <button type="button" onClick={() => { if (!previewUrl) setPreviewUrl(storedUrl); setViewingConfirmed(true); }} className="text-xs font-black text-emerald-700">
            Preview
          </button>
        )}
      </div>
      {pendingFile && previewUrl && (
        <div className="fixed inset-0 z-[230] flex items-center justify-center bg-slate-950/80 p-4" role="dialog" aria-modal="true" aria-label="Confirm PDF upload">
          <div className="flex h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b px-5 py-3">
              <div><p className="font-black text-slate-900">Preview before accepting</p><p className="text-xs text-slate-500">{pendingFile.name}</p></div>
              <button type="button" onClick={() => { setPendingFile(null); setPreviewUrl(""); }} className="rounded-lg border px-3 py-2 text-sm font-black">Reject file</button>
            </div>
            <iframe src={previewUrl} title={`Preview ${pendingFile.name}`} className="min-h-0 flex-1 bg-slate-100" />
            <div className="flex justify-end gap-2 border-t px-5 py-3">
              <button type="button" onClick={() => { setPendingFile(null); setPreviewUrl(""); }} className="rounded-lg border px-4 py-2 text-sm font-black">Choose another</button>
              <button type="button" onClick={() => { onConfirm(pendingFile); setConfirmedName(pendingFile.name); setPendingFile(null); }} className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-black text-white">Accept this PDF</button>
            </div>
          </div>
        </div>
      )}
      {viewingConfirmed && previewUrl && !pendingFile && (
        <div className="fixed inset-0 z-[230] flex items-center justify-center bg-slate-950/80 p-4" role="dialog" aria-modal="true" aria-label="PDF preview">
          <div className="flex h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b px-5 py-3">
              <div><p className="font-black text-slate-900">Uploaded PDF preview</p><p className="text-xs text-slate-500">{confirmedName || currentName || "Uploaded PDF"}</p></div>
              <button type="button" onClick={() => setViewingConfirmed(false)} className="rounded-lg border px-3 py-2 text-sm font-black">Close</button>
            </div>
            <iframe src={previewUrl} title="Uploaded PDF preview" className="min-h-0 flex-1 bg-slate-100" />
          </div>
        </div>
      )}
    </>
  );
}
function MyPortalEmployeeSelect({
  value,
  onChange,
  placeholder,
  accountingOnly = false,
}) {
  const [query, setQuery] = useState(value || "");
  const [options, setOptions] = useState([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [directoryError, setDirectoryError] = useState("");
  const wrapper = useRef(null);

  useEffect(() => {
    if (value) setQuery(value);
  }, [value]);
  useEffect(() => {
    const close = (event) => {
      if (!wrapper.current?.contains(event.target)) setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);
  useEffect(() => {
    const search = query.trim();
    if (!open || search.length < 2 || search === value) {
      setOptions([]);
      return undefined;
    }

    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setLoading(true);
      setDirectoryError("");
      try {
        const params = new URLSearchParams({ search });
        if (accountingOnly) params.set("accounting", "1");
        const response = await fetch(`/rros/myportal-employees?${params}`, {
          headers: { Accept: "application/json" },
          signal: controller.signal,
        });
        const payload = response.ok ? await response.json() : { employees: [] };
        setOptions(payload.employees || []);
        setDirectoryError(payload.directory_error || (!response.ok ? "MyPortal directory request failed." : ""));
      } catch (error) {
        if (error.name !== "AbortError") {
          setOptions([]);
          setDirectoryError("Could not connect to the MyPortal employee directory.");
        }
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    }, 300);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [query, open, value, accountingOnly]);

  return (
    <div ref={wrapper} className="relative">
      <input
        value={query}
        placeholder={placeholder}
        autoComplete="off"
        role="combobox"
        aria-expanded={open}
        className="form-input w-full bg-white pr-10"
        onFocus={() => setOpen(true)}
        onChange={(event) => {
          setQuery(event.target.value);
          setOpen(true);
          if (value) onChange("");
        }}
      />
      <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      {open && query.trim().length >= 2 && query !== value && (
        <div className="absolute z-[160] mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-2xl">
          <div className="border-b border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-bold normal-case text-amber-800">
            Search using at least 2 letters from the employee's last name or first name.
          </div>
          <div className="border-b border-slate-100 bg-slate-50 px-3 py-2 text-[10px] font-black uppercase tracking-[.16em] text-slate-500">
            MyPortal directory results
          </div>
          <div className="max-h-72 overflow-y-auto py-1">
          {loading && <p className="px-3 py-2 text-slate-500">Searching MyPortal...</p>}
          {!loading && directoryError && (
            <p className="px-3 py-2 font-semibold text-rose-700">{directoryError}</p>
          )}
          {!loading && !directoryError && options.length === 0 && (
            <p className="px-3 py-2 text-slate-500">
              {accountingOnly ? "No Accounting staff matched." : "No employee matched."}
            </p>
          )}
          {!loading && options.map((option) => (
            <button
              key={`${option.value}-${option.label}`}
              type="button"
              className="block w-full border-b border-slate-100 px-3 py-2.5 text-left normal-case transition last:border-b-0 hover:bg-emerald-50"
              onClick={() => {
                onChange(option.value);
                setQuery(option.value);
                setOpen(false);
              }}
            >
              <span className="block font-black text-slate-900">
                {option.id_number ? `[${option.id_number}] ` : ""}{option.value}
              </span>
              {option.position && <span className="mt-0.5 block text-xs font-bold text-slate-600">{option.position}</span>}
              {option.section_unit_program && <span className="mt-1 block text-xs text-emerald-700">{option.section_unit_program}</span>}
              {option.division && <span className="block text-[11px] font-semibold text-slate-500">{option.division}</span>}
            </button>
          ))}
          </div>
        </div>
      )}
    </div>
  );
}
function YesNo(props) {
  return (
    <select {...props} className="form-input w-full bg-white">
      <option value="">Not yet encoded</option>
      <option value="Yes">Yes</option>
      <option value="No">No</option>
    </select>
  );
}
function Summary({ label, value }) {
  return (
    <div className="rounded-xl bg-slate-50 px-4 py-3">
      <p className="text-[10px] font-black uppercase text-slate-400">{label}</p>
      <p className="mt-1 truncate text-sm font-black" title={value}>
        {value}
      </p>
    </div>
  );
}
function WarehouseDetails({ value }) {
  if (!value) return "—";
  const [type, ...partnershipParts] = String(value).split(" / ");
  const partnership = partnershipParts.join(" / ");
  return (
    <span className="block">
      <span className="block font-semibold text-slate-700">{type}</span>
      {partnership && (
        <span className="mt-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">
          {partnership}
        </span>
      )}
    </span>
  );
}
