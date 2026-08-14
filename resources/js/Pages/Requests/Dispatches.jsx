import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import {
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  ClipboardList,
  Clock3,
  Eye,
  PackageCheck,
  Pencil,
  Plus,
  Save,
  Truck,
  X,
} from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import PdfPreviewModal from "@/Components/PdfPreviewModal";
import SearchableSelect from "@/Components/SearchableSelect";
import SectionTabs from "@/Components/SectionTabs";
import AppLayout, { Card, DataTable, TableActionButton } from "@/Layouts/AppLayout";
import { formatDate, formatDateTime } from "@/Utils/dateFormat";
import { buildRrosDocumentPreviewTabs } from "@/Utils/rrosDocumentPreview";
import {
  coerceWholeQuantity,
  formatWholeQuantity,
  wholeQuantityInputValue,
} from "@/Utils/wholeQuantity";
import { listenRealtime } from "@/realtime";

const MODE_OF_TRANSPORTATION_OPTIONS = [
  { value: "DSWD-Owned", label: "DSWD-Owned" },
  { value: "Service Provider", label: "Service Provider" },
  { value: "Government Asset", label: "Government Asset" },
  { value: "Partner", label: "Partner" },
];

const normalizeModeLabel = (value) => value === "Partner LGU" ? "Partner" : value;

const STATUS_META = {
  draft: { label: "Draft", className: "bg-slate-100 text-slate-700" },
  planned: { label: "Planned", className: "bg-amber-100 text-amber-800" },
  released: { label: "Released", className: "bg-sky-100 text-sky-800" },
  in_transit: { label: "In Transit", className: "bg-indigo-100 text-indigo-800" },
  received: { label: "Received", className: "bg-emerald-100 text-emerald-800" },
};

/** User-facing RIS / DR slip status (DB values stay draft/prepared/approved/completed). */
const RIS_SLIP_STATUS_META = {
  draft: { label: "Draft", className: "bg-slate-100 text-slate-700" },
  prepared: { label: "Ready for signing", className: "bg-amber-100 text-amber-800" },
  approved: { label: "Approved", className: "bg-emerald-100 text-emerald-800" },
  completed: { label: "Completed", className: "bg-violet-100 text-violet-800" },
};

const RIS_STATUS_TIP =
  "RIS/DR approval is complete and the record is ready for dispatch planning.";

const formatPeso = (value) => {
  const amount = Number(value);
  return Number.isFinite(amount)
    ? new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", minimumFractionDigits: 2 }).format(amount)
    : "—";
};

const allocationUnitCost = (item) => {
  const explicit = Number(item?.unit_cost ?? item?.unit_price);
  if (Number.isFinite(explicit)) return explicit;
  const match = String(item?.remarks || "").match(/Unit prices?:\s*(?:₱|PHP|P)?\s*([\d,]+(?:\.\d+)?)/i);
  return match ? Number(match[1].replaceAll(",", "")) : null;
};

const allocationTotalCost = (item, quantity = item?.allocated_quantity) => {
  const cost = allocationUnitCost(item);
  return cost == null ? null : cost * Number(quantity || 0);
};

const meaningfulDimension = (value) => {
  const text = String(value ?? "").trim();
  return text && !["n/a", "na", "not applicable", "-"].includes(text.toLowerCase()) ? text : null;
};

function AllocationStockCells({ item }) {
  const brand = meaningfulDimension(item?.brand_description);
  const expiry = meaningfulDimension(item?.expiry);
  return <>
    <td className="min-w-32 px-3 py-2 text-xs text-slate-600">{brand || "—"}</td>
    <td className="whitespace-nowrap px-3 py-2 text-xs text-slate-600">{expiry || "—"}</td>
    <td className="px-3 py-2 text-right tabular-nums">{formatWholeQuantity(item?.current_stockpile, "—")}</td>
    <td className="px-3 py-2 text-right tabular-nums">{formatWholeQuantity(item?.available_to_plan, "—")}</td>
  </>;
}

function AllocationCostCells({ item, quantity = item?.allocated_quantity }) {
  return <>
    <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{formatPeso(allocationUnitCost(item))}</td>
    <td className="whitespace-nowrap px-3 py-2 text-right font-bold tabular-nums">{formatPeso(allocationTotalCost(item, quantity))}</td>
  </>;
}

function AllocationTotalsRow({ items = [], labelColSpan = 1, trailingColSpan = 0 }) {
  const quantity = items.reduce((sum, item) => sum + Number(item?.allocated_quantity || 0), 0);
  const cost = items.reduce((sum, item) => sum + Number(allocationTotalCost(item) || 0), 0);
  const hasCost = items.some((item) => allocationUnitCost(item) != null);
  return (
    <tr className="border-t-2 border-slate-300 bg-slate-50 font-black text-slate-800">
      <td colSpan={labelColSpan} className="px-3 py-2 text-right uppercase tracking-wide">Total</td>
      <td className="px-3 py-2 tabular-nums">{formatWholeQuantity(quantity, "0")}</td>
      <td colSpan={4} className="px-3 py-2" />
      <td className="px-3 py-2 text-right text-slate-400">—</td>
      <td className="px-3 py-2 text-right tabular-nums">{hasCost ? formatPeso(cost) : "—"}</td>
      {trailingColSpan > 0 && <td colSpan={trailingColSpan} />}
    </tr>
  );
}

const TIMELINE = ["draft", "planned", "released", "in_transit", "received"];

const STEPPER_STEPS = [
  { id: "dispatch-source", label: "Draft / Source", completeWhen: null, helper: "Link the prepared RIS/DR and review allocation." },
  { id: "dispatch-stage-planning", label: "Plan", completeWhen: "planned", helper: "Mark Planned — set destination, vehicles, and estimated schedule." },
  { id: "dispatch-stage-release", label: "Release", completeWhen: "released", helper: "Confirm Release — record warehouse release, witness, and loaded quantities." },
  { id: "dispatch-stage-transit", label: "In Transit", completeWhen: "in_transit", helper: "Mark In Transit — capture actual departure." },
  { id: "dispatch-stage-receipt", label: "Recipient Receipt", completeWhen: "received", helper: "Confirm Recipient Receipt — actual arrival, who received the goods, and when." },
  { id: "dispatch-exceptions", label: "Delivery Variances", completeWhen: "received", helper: "Classify any undelivered balance as deferred, returned, cancelled, lost/damaged, or other." },
];

const STAGE_HELPERS = {
  draft: "Save Draft keeps work in progress without advancing the workflow.",
  planned: "Mark Planned locks in destination, vehicles, and estimated departure/arrival.",
  released: "Confirm Release records warehouse release, witness, and loaded quantities. Same-day estimated schedule is required unless Multi-day run is checked.",
  in_transit: "Mark In Transit records when each vehicle actually departed.",
  received: "Confirm Recipient Receipt records who received the goods and any delivery variances.",
};

const SAME_DAY_RELEASE_MESSAGE =
  "Confirm Release requires estimated departure and estimated arrival on the same calendar date (Asia/Manila). "
  + "Revise Planning dates, save/update the plan, then Confirm Release. "
  + "Check Multi-day run only when a multi-day trip is justified.";

const SAME_DAY_PLANNED_WARN =
  "Estimated departure and arrival are not on the same calendar date (Asia/Manila) for one or more vehicles. "
  + "You can still Mark Planned, but Confirm Release will be blocked unless you revise Planning dates "
  + "or check Multi-day run. Continue anyway?";

const notifyMissingVehicleFields = (errors = {}, targetStatus = "planned") => {
  const fields = [...new Set(Object.keys(errors).map(humanizeFieldKey))];
  if (!fields.length) return;
  window.dispatchEvent(new CustomEvent("dromis:toast", {
    detail: {
      type: "error",
      title: `Cannot mark ${STATUS_META[targetStatus]?.label || targetStatus}`,
      message: `Complete the following required data: ${fields.slice(0, 6).join("; ")}${fields.length > 6 ? ` (+${fields.length - 6} more)` : ""}.`,
    },
  }));
};

/** Strip secondary “ · detail” text so person fields store/display name only. */
const personNameOnly = (value) => {
  const text = String(value || "").trim();
  if (!text) return "";
  return text.split(/\s*[·•]\s*/)[0].trim();
};

const manilaCalendarDateFromLocal = (value) => {
  const text = String(value || "").trim();
  if (!text) return null;
  // datetime-local is already local wall time; app timezone is Asia/Manila.
  return text.slice(0, 10) || null;
};

const vehicleAllowsMultiDayRun = (row) => {
  const flag = row?.allows_multi_day_run;
  return flag === true || flag === 1 || flag === "1" || flag === "Yes" || flag === "yes" || flag === "true";
};

const collectSameDayScheduleIssues = (vehicleDetails = []) => {
  const issues = [];
  (vehicleDetails || []).forEach((row, index) => {
    if (!row || typeof row !== "object") return;
    if (vehicleAllowsMultiDayRun(row)) return;
    const dep = manilaCalendarDateFromLocal(row.estimated_departure);
    const arr = manilaCalendarDateFromLocal(row.estimated_arrival);
    if (!dep || !arr) return;
    if (dep !== arr) {
      issues.push({
        index,
        key: `vehicle_details.${index}.estimated_arrival`,
        message: SAME_DAY_RELEASE_MESSAGE,
      });
    }
  });
  return issues;
};

const PLANNING_VEHICLE_FIELDS = [
  "source_warehouse_id",
  "source_warehouse_name",
  "estimated_departure",
  "estimated_arrival",
  "mode_of_transportation",
  "land_transportation_source",
  "driver",
  "vehicle_plate_number",
];
const RELEASE_VEHICLE_FIELDS = [
  "warehouse_released_at",
  "release_witnessed_by",
  "release_witness_id_number",
  "release_witness_position",
  "release_witness_office",
  "loaded_items",
];
const TRANSIT_VEHICLE_FIELDS = ["departed_at"];
const RECEIPT_VEHICLE_FIELDS = [
  "actual_arrival",
  "fully_delivered",
  "received_by",
  "received_by_id_number",
  "received_by_position",
  "received_by_office",
  "received_at",
  "receiver_contact",
  "receipt_acknowledged",
];
const RETURNS_FIELDS = [
  "has_returned_items",
  "returned_particulars",
  "returned_quantity",
  "returned_reason",
];

const STAGE_COMPLETE_STATUS = {
  planning: "planned",
  release: "released",
  transit: "in_transit",
  receipt: "received",
  returns: "received",
};

/** Compact lifecycle shown on list cards and the editor progress bar. */
const PROGRESS_STAGES = [
  {
    key: "planning",
    label: "Plan",
    shortLabel: "Plan",
    completeStatus: "planned",
    anchorId: "dispatch-stage-planning",
  },
  {
    key: "release",
    label: "Release",
    shortLabel: "Release",
    completeStatus: "released",
    anchorId: "dispatch-stage-release",
  },
  {
    key: "transit",
    label: "In Transit",
    shortLabel: "Transit",
    completeStatus: "in_transit",
    anchorId: "dispatch-stage-transit",
    skipWhenLocal: true,
  },
  {
    key: "receipt",
    label: "Recipient Receipt",
    shortLabel: "Received",
    completeStatus: "received",
    anchorId: "dispatch-stage-receipt",
  },
];

const statusIndex = (status) => {
  const idx = TIMELINE.indexOf(String(status || "draft"));
  return idx < 0 ? 0 : idx;
};

const vehicleHasValue = (value) => {
  if (value === true || value === 1 || value === "1") return true;
  if (value === false || value === 0 || value === "0" || value == null) return false;
  return String(value).trim() !== "";
};

/** Whether this plan skips the In Transit stage (local stock / warehouse pickup). */
const planSkipsTransit = ({
  localOnly = false,
  fulfillmentType = null,
  status = null,
  vehicleDetails = [],
  timeline = [],
} = {}) => {
  if (localOnly) return true;
  if (String(fulfillmentType || "") === "warehouse_pickup") return true;

  const rows = Array.isArray(vehicleDetails) ? vehicleDetails : [];
  const anyDeparted = rows.some((row) => vehicleHasValue(row?.departed_at));
  if (anyDeparted) return false;

  const hadTransit = (timeline || []).some((entry) => {
    const to = entry?.to_status || entry?.status;
    return to === "in_transit";
  });
  if (hadTransit) return false;

  const current = String(status || "draft");
  // Local-only plans jump released → received without encoding departure.
  return current === "received";
};

/**
 * @returns {'done'|'current'|'upcoming'|'skipped'}
 */
const progressStageState = (stageKey, planStatus, { skipTransit = false } = {}) => {
  if (skipTransit && stageKey === "transit") {
    return "skipped";
  }
  const mode = stageModeForStatus(stageKey, planStatus, { localOnly: skipTransit });
  if (mode === "accomplished") return "done";
  if (mode === "current") return "current";
  return "upcoming";
};

const resolveProgressStages = ({ skipTransit = false } = {}) =>
  PROGRESS_STAGES.filter((stage) => !(skipTransit && stage.skipWhenLocal));

/** Multi-vehicle milestone hint for the active logistics stage. */
const vehicleProgressHint = (planStatus, vehicleDetails = [], { skipTransit = false } = {}) => {
  const rows = (Array.isArray(vehicleDetails) ? vehicleDetails : []).filter(
    (row) => row && typeof row === "object",
  );
  if (rows.length <= 1) return null;

  const total = rows.length;
  const status = String(planStatus || "draft");
  const countWhere = (predicate) => rows.filter(predicate).length;

  if (status === "planned" || status === "draft") {
    const planned = countWhere((row) =>
      vehicleHasValue(row.estimated_departure)
      && vehicleHasValue(row.vehicle_plate_number || row.driver),
    );
    if (planned < total) return `${planned} of ${total} vehicles planned`;
    return `${total} vehicles`;
  }

  if (status === "released" && !skipTransit) {
    const released = countWhere((row) => vehicleHasValue(row.warehouse_released_at));
    return `${released} of ${total} vehicles released`;
  }

  if (status === "released" && skipTransit) {
    const received = countWhere((row) =>
      vehicleHasValue(row.receipt_acknowledged)
      || vehicleHasValue(row.received_at)
      || vehicleHasValue(row.actual_arrival),
    );
    return `${received} of ${total} vehicles received`;
  }

  if (status === "in_transit") {
    const departed = countWhere((row) => vehicleHasValue(row.departed_at));
    return `${departed} of ${total} vehicles departed`;
  }

  if (status === "received") {
    const received = countWhere((row) =>
      vehicleHasValue(row.receipt_acknowledged)
      || vehicleHasValue(row.received_at)
      || vehicleHasValue(row.actual_arrival),
    );
    return received >= total
      ? `${total} vehicles received`
      : `${received} of ${total} vehicles received`;
  }

  return `${total} vehicles`;
};

/** Infer local-only (no transport needed) from serialized plan items + warehouse catalog. */
const dispatchIsLocalOnly = (dispatch, warehouseCatalog = {}) => {
  const items = dispatch?.items || [];
  if (!items.length) return false;
  const receivingContext = {
    lgu: dispatch?.request?.lgu || "",
    municipality: dispatch?.request?.municipality || "",
    province: dispatch?.request?.province || "",
    lgu_level: dispatch?.request?.lgu_level || "",
    requesting_agency: dispatch?.request?.requesting_agency || "",
    receiving_agency_lgu: dispatch?.receiving_agency_lgu || "",
    recipient: dispatch?.ris?.recipient || "",
  };
  const warehouses = uniqueSourceWarehouses(items, warehouseCatalog, receivingContext);
  if (!warehouses.length) return false;
  return warehouses.every((warehouse) => !warehouseRequiresTransport(warehouse));
};

/** Next status after current (field-delivery path; never skips to warehouse-pickup rules). */
const nextRecommendedStatus = (currentStatus, { localOnly = false, combinedReleaseReceipt = false } = {}) => {
  const status = String(currentStatus || "draft");
  if (combinedReleaseReceipt) {
    if (status === "draft") return "planned";
    if (status === "planned" || status === "released" || status === "in_transit") return "received";
    return "received";
  }
  if (localOnly) {
    if (status === "draft") return "planned";
    if (status === "planned") return "released";
    if (status === "released" || status === "in_transit") return "received";
    return "received";
  }
  const idx = statusIndex(currentStatus);
  if (idx >= TIMELINE.length - 1) return "received";
  return TIMELINE[idx + 1];
};

/** Which logistics stage is active for the current plan status. */
const currentStageForStatus = (planStatus, { localOnly = false, combinedReleaseReceipt = false } = {}) => {
  const status = String(planStatus || "draft");
  if (status === "draft") return "planning";
  if (combinedReleaseReceipt && status === "planned") return "receipt";
  if (status === "planned") return "release";
  if (status === "released") return localOnly ? "receipt" : "transit";
  if (status === "in_transit") return "receipt";
  if (status === "received") return "returns";
  return "planning";
};

const stageAnchorId = (stage) => {
  if (stage === "planning") return "dispatch-stage-planning";
  if (stage === "release") return "dispatch-stage-release";
  if (stage === "transit") return "dispatch-stage-transit";
  if (stage === "receipt") return "dispatch-stage-receipt";
  if (stage === "returns") return "dispatch-exceptions";
  return "dispatch-source";
};

/**
 * @returns {'current'|'accomplished'|'future'}
 */
const stageModeForStatus = (stage, planStatus, { localOnly = false, combinedReleaseReceipt = false } = {}) => {
  const current = currentStageForStatus(planStatus, { localOnly, combinedReleaseReceipt });
  if (combinedReleaseReceipt && planStatus === "planned" && stage === "release") return "current";
  if (stage === current) return "current";
  if (localOnly && stage === "transit") {
    // Transit is unused for receiving-LGU-only allocations.
    return statusIndex(planStatus) >= statusIndex("released") ? "accomplished" : "future";
  }
  const complete = STAGE_COMPLETE_STATUS[stage];
  if (complete && statusIndex(planStatus) >= statusIndex(complete)) {
    return "accomplished";
  }
  return "future";
};

/** Last user who completed/updated a stage, from status_timeline. */
const resolveStageLastEditor = (timeline, stage) => {
  const complete = STAGE_COMPLETE_STATUS[stage];
  if (!complete) return null;
  let last = null;
  (timeline || []).forEach((entry) => {
    if (!entry || typeof entry !== "object") return;
    const to = entry.to_status || entry.status;
    if (to !== complete) return;
    const from = entry.from_status;
    if (entry.type === "status_change" || from === complete || from == null || from === "") {
      last = entry;
    }
  });
  if (!last) return null;
  return {
    id: last.by != null ? Number(last.by) : null,
    name: last.by_name || null,
    at: last.at || null,
  };
};

const canEditAccomplishedStage = (editor, authUser) => {
  if (!editor?.id) return true;
  const userId = authUser?.id != null ? Number(authUser.id) : null;
  if (userId == null) return false;
  return userId === Number(editor.id);
};

/** Fields that require a * for the given target status (mirrors assertStatusRequirements, field delivery). */
const requiredGroupsForStatus = (targetStatus, { fromStatus = null } = {}) => {
  const status = String(targetStatus || "draft");
  const groups = {
    destination: false,
    planning: false,
    release: false,
    transit: false,
    receipt: false,
    returns: false,
  };
  const targetIdx = statusIndex(status);
  const fromIdx = fromStatus != null ? statusIndex(fromStatus) : -1;
  const needs = (completeStatus) => {
    const completeIdx = statusIndex(completeStatus);
    return targetIdx >= completeIdx && fromIdx < completeIdx;
  };
  if (targetIdx >= statusIndex("planned")) {
    groups.destination = true;
    groups.planning = true;
  }
  if (needs("released")) {
    groups.release = true;
  }
  if (needs("in_transit")) {
    groups.transit = true;
  }
  if (needs("received")) {
    groups.receipt = true;
    groups.returns = true;
  }
  return groups;
};

const blank = (value) => {
  if (value === null || value === undefined) return true;
  if (typeof value === "boolean") return false;
  return String(value).trim() === "";
};

const humanizeFieldKey = (key) => {
  const map = {
    fulfillment_type: "Delivery Mode",
    destination: "Destination / Delivery Site",
    receiving_agency_lgu: "Receiving Agency / Organization",
    vehicle_details: "Vehicles",
    number_of_vehicles: "No. of Vehicles",
    has_returned_items: "With Returned / Cancelled Items",
    returned_particulars: "Returned Particulars",
    returned_quantity: "Returned Quantity",
    returned_reason: "Returned Reason",
    source_warehouse_id: "Source Warehouse",
    source_warehouse_name: "Source Warehouse",
    estimated_departure: "Estimated Departure",
    estimated_arrival: "Estimated Arrival",
    allows_multi_day_run: "Multi-day Run",
    mode_of_transportation: "Mode of Transportation",
    driver: "Driver's Name (Transported By)",
    driver_contact_number: "Driver's Contact No.",
    driver_id_number: "Driver's ID Number",
    driver_position: "Driver's Position",
    driver_office: "Driver's Office",
    vehicle_plate_number: "Plate Number",
    has_dswd_escort: "With DSWD Escort?",
    escort_name: "Escort's Name",
    escort_contact_number: "Escort's Contact No.",
    escort_id_number: "Escort's ID Number",
    escort_position: "Escort's Position",
    escort_office: "Escort's Office",
    warehouse_released_at: "Warehouse Release Date and Time",
    release_witnessed_by: "Release Witness",
    release_witness_affiliation: "Released/Witnessed by Organization",
    release_witness_contact_number: "Witness Contact Number",
    release_witness_id_number: "Witness ID Number",
    release_witness_position: "Witness Position",
    release_witness_office: "Witness Office",
    loaded_items: "Loaded Quantities",
    departed_at: "Actual Departure Date and Time",
    actual_arrival: "Actual Arrival Date and Time",
    delivered_at: "Actual Arrival Date and Time",
    fully_delivered: "Delivery Completion",
    received_by: "Actual Receiving Representative",
    received_by_id_number: "Recipient ID Number",
    received_by_position: "Recipient Position",
    received_by_office: "Recipient Office",
    received_at: "Receipt Date and Time",
    receiver_contact: "Recipient Contact Number",
    receipt_acknowledged: "Receipt Acknowledgment",
  };
  const vehicleMatch = String(key).match(/^vehicle_details\.(\d+)\.(.+)$/);
  if (vehicleMatch) {
    const field = vehicleMatch[2].replace(/\.\d+$/, "");
    return `Vehicle ${Number(vehicleMatch[1]) + 1}: ${map[field] || field}`;
  }
  const localMatch = String(key).match(/^local_handover_details\.(.+)$/);
  if (localMatch) {
    const field = localMatch[1];
    const localMap = {
      expected_release_at: "Direct warehouse release: Estimated / Expected Release Date",
      release_witness_affiliation: "Direct warehouse release: Witness Organization",
      released_by: "Direct warehouse release: Released/Witnessed By",
      releaser_contact: "Direct warehouse release: Witness Contact Number",
      releaser_id_number: "Direct warehouse release: Witness ID Number",
      releaser_position: "Direct warehouse release: Witness Position",
      releaser_office: "Direct warehouse release: Witness Office",
      released_at: "Direct warehouse release: Actual Release Date and Time",
      received_by: "Direct warehouse receipt: Receiving Representative",
      receiver_id_number: "Direct warehouse receipt: Recipient ID Number",
      receiver_position: "Direct warehouse receipt: Recipient Position",
      receiver_office: "Direct warehouse receipt: Recipient Office",
      receiver_contact: "Direct warehouse receipt: Recipient Contact Number",
      received_at: "Direct warehouse receipt: Receipt Date and Time",
      receipt_acknowledged: "Direct warehouse receipt: Receipt Acknowledgment",
    };
    return localMap[field] || `Local warehouse release and receipt: ${field.replaceAll("_", " ")}`;
  }
  const itemMatch = String(key).match(/^items\.(\d+)\.(.+)$/);
  if (itemMatch) {
    const field = itemMatch[2];
    const label = field === "warehouse_id" || field === "warehouse_name"
      ? "Source Warehouse"
      : (map[field] || field.replaceAll("_", " "));
    return `Allocated item ${Number(itemMatch[1]) + 1}: ${label}`;
  }
  return map[key] || key;
};

/**
 * Client mirror of DispatchPlanController::assertStatusRequirements (field delivery only).
 * @returns {Record<string, string>}
 */
const collectStatusRequirementErrors = (
  data = {},
  status = "draft",
  options = {},
) => {
  const errors = {};
  const target = String(status || "draft");
  if (target === "draft") return errors;

  const warehouseCatalog = options.warehouseCatalog || {};
  const receivingContext = options.receivingContext || {};
  const releaseVehicleIndexes = Array.isArray(options.releaseVehicleIndexes)
    ? options.releaseVehicleIndexes.map((index) => Number(index))
    : null;
  const scopedReleaseIndexSet = releaseVehicleIndexes
    ? Object.fromEntries(releaseVehicleIndexes.map((index) => [index, true]))
    : null;
  const classifiedWarehouses = uniqueSourceWarehouses(
    data.items,
    warehouseCatalog,
    receivingContext,
  );
  const remoteWarehouses = classifiedWarehouses.filter(warehouseRequiresTransport);
  const localWarehouses = classifiedWarehouses.filter((warehouse) => !warehouseRequiresTransport(warehouse));
  const hasLocalWarehouse = localWarehouses.length > 0;
  const localOnly =
    classifiedWarehouses.length > 0 && remoteWarehouses.length === 0;

  const vehicleRows = Array.isArray(data.vehicle_details) ? data.vehicle_details : [];
  const vehicleCount = Math.max(
    0,
    Number(data.number_of_vehicles) || vehicleRows.length || 0,
  );
  const localHandover = data.local_handover_details || {};
  const fromStatus = options.fromStatus != null ? String(options.fromStatus) : null;
  const fromIdx = fromStatus != null ? statusIndex(fromStatus) : -1;
  const targetIdx = statusIndex(target);
  const needs = (completeStatus) => {
    const completeIdx = statusIndex(completeStatus);
    return targetIdx >= completeIdx && fromIdx < completeIdx;
  };
  const needsPlanning = targetIdx >= statusIndex("planned");
  const needsRelease = needs("released")
    || (scopedReleaseIndexSet && fromStatus === "planned");
  const needsTransit = needs("in_transit");
  const needsReceipt = needs("received");
  const isLguPickup = String(data.fulfillment_type || "field_delivery") === "warehouse_pickup";

  if (localOnly && target === "in_transit") {
    errors.status =
      "In Transit is not used when all allocations are already at the recipient custody location. After release, confirm recipient receipt.";
  }

  if (needsPlanning) {
    (data.items || []).forEach((item, index) => {
      if (Number(item?.allocated_quantity || 0) <= 0) return;
      if (blank(item?.warehouse_id) && blank(item?.warehouse_name)) {
        errors[`items.${index}.warehouse_id`] =
          `${item?.item_name || `Item ${index + 1}`} has no source warehouse. Return to the RIS allocation and select its source warehouse.`;
      }
    });
    if (blank(data.destination)) {
      errors.destination = "Delivery site / destination is required for this status.";
    }
    if (blank(data.receiving_agency_lgu)) {
      errors.receiving_agency_lgu = "Receiving agency / organization is required for this status.";
    }
    if (hasLocalWarehouse && blank(localHandover.expected_release_at)) {
      errors["local_handover_details.expected_release_at"] =
        "Enter the estimated or expected release date for the local warehouse release.";
    }

    if (remoteWarehouses.length > 0) {
      if (vehicleRows.length === 0) {
        errors.vehicle_details =
          "Enter vehicle details for warehouses that require DSWD transport.";
        errors.number_of_vehicles =
          "No. of Vehicles is required for remote warehouse allocations.";
      } else if (vehicleCount > 0 && vehicleRows.length !== vehicleCount) {
        errors.number_of_vehicles =
          "No. of Vehicles must match the number of vehicle rows entered.";
        errors.vehicle_details = `Enter details for all ${vehicleCount} vehicle(s).`;
      }

      remoteWarehouses.forEach((wh) => {
        const covered = vehicleRows.some((row) =>
          itemMatchesSourceWarehouse(
            { warehouse_id: wh.id, warehouse_name: wh.name },
            row?.source_warehouse_id,
            row?.source_warehouse_name,
          ),
        );
        if (!covered && !errors.vehicle_details) {
          errors.vehicle_details =
            `Add at least one vehicle for ${wh.name} (DSWD transport required).`;
        }
      });

      vehicleRows.forEach((row, index) => {
        const r = row || {};
        const assigned = classifiedWarehouses.find((wh) =>
          itemMatchesSourceWarehouse(
            { warehouse_id: wh.id, warehouse_name: wh.name },
            r.source_warehouse_id,
            r.source_warehouse_name,
          ),
        );
        if (assigned && !warehouseRequiresTransport(assigned)) {
          errors[`vehicle_details.${index}.source_warehouse_id`] =
            "Vehicle delivery planning is not allowed for stock already at the recipient custody location. Assign a remote source warehouse or remove this vehicle.";
          return;
        }
        const hasWarehouse =
          !blank(r.source_warehouse_id) || !blank(r.source_warehouse_name);
        if (!hasWarehouse) {
          errors[`vehicle_details.${index}.source_warehouse_id`] =
            "Source warehouse is required for each vehicle.";
        } else if (remoteWarehouses.length > 0) {
          const match = remoteWarehouses.find((wh) =>
            itemMatchesSourceWarehouse(
              { warehouse_id: wh.id, warehouse_name: wh.name },
              r.source_warehouse_id,
              r.source_warehouse_name,
            ),
          );
          if (!match) {
            errors[`vehicle_details.${index}.source_warehouse_id`] =
              "Source warehouse must match an allocated remote warehouse on this plan.";
          }
        }
        if (blank(r.estimated_departure)) {
          errors[`vehicle_details.${index}.estimated_departure`] =
            "Estimated departure date/time is required for each vehicle.";
        }
        if (blank(r.estimated_arrival)) {
          errors[`vehicle_details.${index}.estimated_arrival`] =
            "Estimated arrival date/time is required for each vehicle.";
        } else if (
          needsRelease
          && !vehicleAllowsMultiDayRun(r)
          && manilaCalendarDateFromLocal(r.estimated_departure)
          && manilaCalendarDateFromLocal(r.estimated_arrival)
          && manilaCalendarDateFromLocal(r.estimated_departure)
            !== manilaCalendarDateFromLocal(r.estimated_arrival)
        ) {
          errors[`vehicle_details.${index}.estimated_arrival`] = SAME_DAY_RELEASE_MESSAGE;
        }
        if (!primaryModeOfTransportation(r.mode_of_transportation)) {
          errors[`vehicle_details.${index}.mode_of_transportation`] =
            "Mode of transportation is required for each vehicle.";
        }
        if (blank(r.land_transportation_source)) {
          errors[`vehicle_details.${index}.land_transportation_source`] =
            "Land transportation source is required for each vehicle.";
        }
        if (blank(r.vehicle_type)) {
          errors[`vehicle_details.${index}.vehicle_type`] =
            "Vehicle type is required for each vehicle.";
        }
        if (blank(r.driver)) {
          errors[`vehicle_details.${index}.driver`] = "Driver is required for each vehicle.";
        }
        if (blank(r.vehicle_plate_number)) {
          errors[`vehicle_details.${index}.vehicle_plate_number`] =
            "Plate number is required for each vehicle.";
        }
        const mode = primaryModeOfTransportation(r.mode_of_transportation);
        if (blank(r.driver_contact_number)) {
          errors[`vehicle_details.${index}.driver_contact_number`] =
            "Driver contact number is required for each vehicle.";
        }
        if (mode === "DSWD-Owned") {
          [
            ["driver_id_number", "Driver ID number"],
            ["driver_position", "Driver position"],
            ["driver_office", "Driver office"],
          ].forEach(([field, label]) => {
            if (blank(r[field])) errors[`vehicle_details.${index}.${field}`] = `${label} is required for DSWD-Owned vehicles.`;
          });
        }
        if (Boolean(r.has_dswd_escort)) {
          [
            ["escort_name", "Escort name"],
            ["escort_contact_number", "Escort contact number"],
            ["escort_id_number", "Escort ID number"],
            ["escort_position", "Escort position"],
            ["escort_office", "Escort office"],
          ].forEach(([field, label]) => {
            if (blank(r[field])) errors[`vehicle_details.${index}.${field}`] = `${label} is required when a DSWD escort is assigned.`;
          });
        }
      });

      (data.items || []).forEach((item, itemIndex) => {
        const sourceWarehouse = classifiedWarehouses.find((warehouse) =>
          itemMatchesSourceWarehouse(
            item || {},
            warehouse.id,
            warehouse.name,
          ),
        );
        // Recipient-held/local stock has no vehicle loading step. Its allocated
        // quantity is shown and confirmed as "To Be Released" in the local handover.
        if (sourceWarehouse && !warehouseRequiresTransport(sourceWarehouse)) return;
        const allocated = Number(item?.allocated_quantity || 0);
        const totalToBeLoaded = sumPlannedAcrossVehicles(vehicleRows, item || {});
        if (totalToBeLoaded !== allocated) {
          errors[`items.${itemIndex}.loaded_quantity`] =
            `${item?.item_name || "Item"}: total To Be Loaded across all vehicles must equal the allocated quantity (${formatWholeQuantity(allocated, "0")}); currently ${formatWholeQuantity(totalToBeLoaded, "0")}.`;
        }
      });
    }
  }

  if (needsRelease) {
    if (blank(data.source_of_goods)) {
      errors.source_of_goods = "Select the Source of Goods before confirming release.";
    }
    if (blank(data.purpose)) {
      errors.purpose = "Select the Purpose before confirming release.";
    }
    if (hasLocalWarehouse && !scopedReleaseIndexSet) {
      [
        ["released_at", "Actual release date and time"],
        ["release_witness_affiliation", "Released/witnessed by organization"],
        ["released_by", "Released/witnessed by"],
        ["releaser_contact", "Witness contact number"],
        ["releaser_id_number", "Witness ID number"],
        ["releaser_position", "Witness position"],
        ["releaser_office", "Witness office"],
      ].forEach(([field, label]) => {
        if (field === "released_at") return;
        if (blank(localHandover[field])) {
          errors[`local_handover_details.${field}`] = `${label} is required for the local warehouse release.`;
        }
      });
    }
  }

  if (needsRelease && remoteWarehouses.length > 0) {
    vehicleRows.forEach((row, index) => {
      if (scopedReleaseIndexSet && !scopedReleaseIndexSet[index]) return;
      const r = row || {};
      const assigned = classifiedWarehouses.find((wh) =>
        itemMatchesSourceWarehouse(
          { warehouse_id: wh.id, warehouse_name: wh.name },
          r.source_warehouse_id,
          r.source_warehouse_name,
        ),
      );
      if (assigned && !warehouseRequiresTransport(assigned)) return;

      if (!isLguPickup && blank(r.warehouse_released_at)) {
        errors[`vehicle_details.${index}.warehouse_released_at`] =
          "Warehouse release date/time is required for each vehicle.";
      }
      if (
        needsReleaseWitnessAffiliation(r)
        && !["dswd", "lgu"].includes(r.release_witness_affiliation)
      ) {
        errors[`vehicle_details.${index}.release_witness_affiliation`] =
          "Select whether the person who released/witnessed the goods is from DSWD or the partner/recipient organization.";
      }
      if (blank(r.release_witnessed_by)) {
        errors[`vehicle_details.${index}.release_witnessed_by`] =
          "Released/Witnessed by is required for each vehicle.";
      }
      if (blank(r.release_witness_contact_number)) {
        errors[`vehicle_details.${index}.release_witness_contact_number`] =
          "Witness contact number is required for each vehicle.";
      }
      if (blank(r.release_witness_id_number)) {
        errors[`vehicle_details.${index}.release_witness_id_number`] =
          "Witness ID number is required for each vehicle.";
      }
      if (blank(r.release_witness_position)) {
        errors[`vehicle_details.${index}.release_witness_position`] =
          "Witness position is required for each vehicle.";
      }
      if (blank(r.release_witness_office)) {
        errors[`vehicle_details.${index}.release_witness_office`] =
          "Witness office is required for each vehicle.";
      }
      const warehouseItems = planItemsForVehicleWarehouse(data.items, r);
      const loadedTotal = (r.loaded_items || []).reduce((sum, line, lineIndex) => {
        const item = (data.items || [])[lineIndex];
        if (
          item
          && (r.source_warehouse_id || r.source_warehouse_name)
          && !itemMatchesSourceWarehouse(
            item,
            r.source_warehouse_id,
            r.source_warehouse_name,
          )
        ) {
          if (Number(line?.loaded_quantity) > 0) {
            errors[`vehicle_details.${index}.loaded_items.${lineIndex}.loaded_quantity`] =
              "Loaded quantity must belong to this vehicle's source warehouse.";
          }
          return sum;
        }
        return sum + (Number(line?.loaded_quantity) || 0);
      }, 0);
      if (loadedTotal <= 0) {
        errors[`vehicle_details.${index}.loaded_items`] =
          warehouseItems.length === 0 && (r.source_warehouse_id || r.source_warehouse_name)
            ? "No allocation lines for this source warehouse."
            : "Enter loaded quantities for each vehicle being released.";
      }
    });
  }

  if (needsTransit && remoteWarehouses.length > 0 && !isLguPickup) {
    vehicleRows.forEach((row, index) => {
      const assigned = classifiedWarehouses.find((wh) =>
        itemMatchesSourceWarehouse(
          { warehouse_id: wh.id, warehouse_name: wh.name },
          row?.source_warehouse_id,
          row?.source_warehouse_name,
        ),
      );
      if (assigned && !warehouseRequiresTransport(assigned)) return;
      if (blank(row?.departed_at)) {
        errors[`vehicle_details.${index}.departed_at`] =
          "Departure date/time is required for each vehicle.";
      }
    });
  }

  if (needsReceipt && hasLocalWarehouse) {
    [
      ["received_at", "Receipt date and time"],
      ["received_by", "Actual receiving representative"],
      ["receiver_id_number", "Recipient ID number"],
      ["receiver_position", "Recipient position"],
      ["receiver_office", "Recipient office"],
      ["receiver_contact", "Recipient contact number"],
    ].forEach(([field, label]) => {
      if (field === "received_at") return;
      if (blank(localHandover[field])) {
        errors[`local_handover_details.${field}`] = `${label} is required to acknowledge receipt.`;
      }
    });
    if (!localHandover.receipt_acknowledged) {
      errors["local_handover_details.receipt_acknowledged"] =
        "Confirm that the recipient received the goods from this warehouse.";
    }
    if (
      localHandover.released_at
      && localHandover.received_at
      && new Date(localHandover.received_at).getTime() < new Date(localHandover.released_at).getTime()
    ) {
      errors["local_handover_details.received_at"] =
        "Receipt date and time cannot be earlier than the actual release date and time.";
    }
  }

  if (needsReceipt) {
    if (remoteWarehouses.length > 0) {
      vehicleRows.forEach((row, index) => {
        const r = row || {};
        const assigned = classifiedWarehouses.find((wh) =>
          itemMatchesSourceWarehouse(
            { warehouse_id: wh.id, warehouse_name: wh.name },
            r.source_warehouse_id,
            r.source_warehouse_name,
          ),
        );
        if (assigned && !warehouseRequiresTransport(assigned)) return;
        if (blank(r.received_by)) {
          errors[`vehicle_details.${index}.received_by`] =
            "Actual Receiving Representative is required for each vehicle.";
        }
        if (blank(r.received_by_id_number)) {
          errors[`vehicle_details.${index}.received_by_id_number`] =
            "Recipient ID number is required for each vehicle.";
        }
        if (blank(r.received_by_position)) {
          errors[`vehicle_details.${index}.received_by_position`] =
            "Recipient position is required for each vehicle.";
        }
        if (blank(r.received_by_office)) {
          errors[`vehicle_details.${index}.received_by_office`] =
            "Recipient office is required for each vehicle.";
        }
        if (!isLguPickup && blank(r.received_at)) {
          errors[`vehicle_details.${index}.received_at`] =
            "Recipient receipt date/time is required for each vehicle.";
        }
        if (blank(r.receiver_contact)) {
          errors[`vehicle_details.${index}.receiver_contact`] =
            "Recipient contact number is required for each vehicle.";
        }
        if (!r.receipt_acknowledged) {
          errors[`vehicle_details.${index}.receipt_acknowledged`] =
            "Acknowledge recipient receipt for each vehicle.";
        }
        if (!isLguPickup && blank(r.actual_arrival)) {
          errors[`vehicle_details.${index}.actual_arrival`] =
            "Actual arrival date and time is required for each vehicle.";
        }
        if (yesNoValue(r.fully_delivered) === "") {
          errors[`vehicle_details.${index}.fully_delivered`] =
            "Fully delivered / picked-up must be Yes or No for each vehicle.";
        }
      });
    }

    (data.items || []).forEach((item, index) => {
      const loaded = sumLoadedAcrossVehicles(data.vehicle_details, item);
      const expected = Number(item.allocated_quantity || 0);
      const received = Number(item.received_quantity);
      if (item.received_quantity === "" || item.received_quantity == null) {
        errors[`items.${index}.received_quantity`] = "Received quantity is required for each item.";
        return;
      }
      if (received > expected) {
        errors[`items.${index}.received_quantity`] = "Received quantity cannot exceed the loaded or allocated quantity.";
      }
      if (expected - received > 0 && blank(item.variance_disposition)) {
        errors[`items.${index}.variance_disposition`] = "Select what will happen to the undelivered balance.";
      }
      if (expected - received > 0 && blank(item.variance_resolution || item.return_reason)) {
        errors[`items.${index}.variance_resolution`] = "Explain the reason or resolution for this delivery variance.";
      }
    });
  }

  return errors;
};

const stageFromErrorKey = (key) => {
  if (/^items\.\d+\.(warehouse_id|warehouse_name)$/.test(String(key))) return "planning";
  if (["destination", "receiving_agency_lgu", "vehicle_details", "number_of_vehicles"].includes(String(key))) return "planning";
  if (["source_of_goods", "purpose"].includes(String(key))) return "release";
  if (String(key).startsWith("local_handover_details.")) {
    if (String(key).endsWith("expected_release_at")) return "planning";
    if (/received_|receiver_|receipt_/.test(String(key))) return "receipt";
    return "release";
  }
  if (/^items\.\d+\.return_reason$/.test(String(key))) return "returns";
  if (/^items\.\d+\.received_quantity$/.test(String(key))) return "receipt";
  if (RETURNS_FIELDS.includes(key) || key === "destination" || key === "receiving_agency_lgu") {
    if (RETURNS_FIELDS.includes(key)) return "returns";
    return "planning";
  }
  const match = String(key).match(/^vehicle_details\.(\d+)\.(.+)$/);
  if (!match) return null;
  const field = match[2].replace(/\.\d+$/, "");
  if (PLANNING_VEHICLE_FIELDS.includes(field)) return "planning";
  if (RELEASE_VEHICLE_FIELDS.includes(field) || field.startsWith("loaded_items")) return "release";
  if (TRANSIT_VEHICLE_FIELDS.includes(field)) return "transit";
  if (RECEIPT_VEHICLE_FIELDS.includes(field) || field === "receipt_remarks") return "receipt";
  return "planning";
};

const parseVehicleErrorIndex = (key) => {
  const match = String(key).match(/^vehicle_details\.(\d+)\./);
  return match ? Number(match[1]) : null;
};

const scrollToFieldError = (errorKey) => {
  if (typeof document === "undefined" || !errorKey) return;
  let attempts = 0;
  const findAndFocus = () => {
    attempts += 1;
    const el =
      document.querySelector(`[data-field="${CSS.escape(errorKey)}"]`)
      || document.querySelector(`[data-error-anchor="${CSS.escape(errorKey)}"]`);
    if (el?.scrollIntoView) {
      el.scrollIntoView({ behavior: "smooth", block: "center" });
      const focusable = el.matches?.("input, select, textarea, button")
        ? el
        : el.querySelector?.("input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])");
      if (focusable?.focus) window.setTimeout(() => focusable.focus({ preventScroll: true }), 350);
      return;
    }
    if (attempts < 12) {
      window.setTimeout(findAndFocus, 80);
      return;
    }
    const stage = stageFromErrorKey(errorKey);
    const fallbackId = {
      planning: "dispatch-stage-planning",
      release: "dispatch-stage-release",
      transit: "dispatch-stage-transit",
      receipt: "dispatch-stage-receipt",
      returns: "dispatch-stage-returns",
    }[stage];
    document.getElementById(fallbackId)?.scrollIntoView?.({ behavior: "smooth", block: "start" });
  };
  window.requestAnimationFrame(findAndFocus);
};

const risSlipStatusMeta = (status) => {
  const key = String(status || "prepared").toLowerCase();
  return (
    RIS_SLIP_STATUS_META[key] || {
      label: String(status || "—").replaceAll("_", " "),
      className: "bg-slate-100 text-slate-700",
    }
  );
};

const formatRisDrNo = (ris) => {
  if (!ris) return "—";
  const parts = [];
  if (ris.ris_number) parts.push(ris.ris_number);
  if (ris.dr_number) parts.push(ris.dr_number);
  return parts.length ? parts.join(" / ") : "—";
};

const formatRisPreparedDate = (ris) => {
  if (ris?.ris_date) return formatDate(ris.ris_date);
  if (ris?.prepared_at) return formatDate(ris.prepared_at);
  return null;
};

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
  return [normalizeModeLabel(text)];
};

/** Per-vehicle mode is a single choice; coerce legacy array/CSV to one value. */
const primaryModeOfTransportation = (value) => {
  const list = asStringList(value).map(normalizeModeLabel);
  return list[0] || "";
};

const itemKey = (value) =>
  String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]/g, "");

const normalizePlaceToken = (value) =>
  String(value || "")
    .toLowerCase()
    .replace(/\b(city|municipality|lgu|province|of)\b/g, " ")
    .replace(/[^a-z0-9]+/g, "")
    .trim();

const tokensOverlap = (left, right) => {
  if (!left || !right) return false;
  return left === right || left.includes(right) || right.includes(left);
};

/**
 * Classify an allocated source against the receiving organization/custody location
 * using warehouse ownership, type, municipality and registered recipient names.
 */
const classifySourceWarehouse = (warehouse = {}, receivingContext = {}) => {
  const ownership = normalizePlaceToken(warehouse.ownership);
  const warehouseType = normalizePlaceToken(warehouse.warehouse_type);
  const partnership = normalizePlaceToken(warehouse.partnership);
  const nameToken = normalizePlaceToken(warehouse.name || warehouse.display_name);
  const municipalityToken = normalizePlaceToken(warehouse.municipality);

  const receivingTokens = [
    receivingContext.lgu,
    receivingContext.municipality,
    receivingContext.requesting_agency,
    receivingContext.receiving_agency_lgu,
    receivingContext.recipient,
  ]
    .map(normalizePlaceToken)
    .filter(Boolean);

  const matchesReceiving = receivingTokens.some(
    (token) =>
      tokensOverlap(token, municipalityToken)
      || tokensOverlap(token, nameToken),
  );

  const isRegionalOrDswd =
    warehouseType.includes("regional")
    || warehouseType.includes("satellite")
    || ownership === "owned"
    || ownership === "rented"
    || nameToken.includes("dswd")
    || nameToken.includes("regional");

  const isPartnerNga =
    ownership === "nga"
    || ["ppa", "dpwh"].includes(partnership);

  // DSWD regional / satellite sources always need transport planning, even when
  // the warehouse municipality string overlaps the recipient locality.
  if (isRegionalOrDswd) {
    return {
      kind: "dswd_regional",
      label: "DSWD / Regional warehouse",
      tone: "sky",
    };
  }

  if (isPartnerNga) {
    return {
      kind: "partner_nga",
      label: "Partner / NGA warehouse",
      tone: "violet",
    };
  }

  // Recipient-held stock needs a custody handover, not a transport plan,
  // regardless of whether the recipient is an LGU, NGA, partner or DSWD unit.
  if (matchesReceiving) {
    return {
      kind: "receiving_lgu",
      label: "Recipient custody warehouse",
      tone: "emerald",
    };
  }

  if (ownership === "lgu" || warehouseType.includes("preposition")) {
    return {
      kind: "other_lgu",
      label: "Other LGU warehouse",
      tone: "amber",
    };
  }

  return {
    kind: "other",
    label: "Other warehouse",
    tone: "slate",
  };
};

/** Recipient custody warehouses do not need transport planning. */
const warehouseRequiresTransport = (warehouse = {}) =>
  warehouse?.classification?.kind !== "receiving_lgu";

const isReceivingLguWarehouse = (warehouse = {}) =>
  warehouse?.classification?.kind === "receiving_lgu";

const warehouseToneClasses = (tone = "slate") => {
  switch (tone) {
    case "emerald":
      return {
        card: "border-emerald-300 bg-emerald-50/80 ring-emerald-600",
        badge: "bg-emerald-100 text-emerald-800",
        heading: "text-emerald-800",
      };
    case "amber":
      return {
        card: "border-amber-300 bg-amber-50/80 ring-amber-600",
        badge: "bg-amber-100 text-amber-900",
        heading: "text-amber-900",
      };
    case "sky":
      return {
        card: "border-sky-300 bg-sky-50/80 ring-sky-600",
        badge: "bg-sky-100 text-sky-900",
        heading: "text-sky-900",
      };
    case "violet":
      return {
        card: "border-violet-300 bg-violet-50/80 ring-violet-600",
        badge: "bg-violet-100 text-violet-900",
        heading: "text-violet-900",
      };
    default:
      return {
        card: "border-slate-300 bg-slate-50 ring-slate-600",
        badge: "bg-slate-100 text-slate-700",
        heading: "text-slate-700",
      };
  }
};

/** Unique source warehouses from plan allocation lines (id preferred, else name). */
const uniqueSourceWarehouses = (
  planItems = [],
  warehouseCatalog = {},
  receivingContext = {},
) => {
  const seen = new Map();
  (planItems || []).forEach((item) => {
    const id = item?.warehouse_id;
    const name = String(item?.warehouse_name || "").trim();
    if (id == null && !name) return;
    const key = id != null ? `id:${id}` : `name:${name.toLowerCase()}`;
    if (seen.has(key)) return;
    const catalog =
      id != null
        ? warehouseCatalog[String(id)] || warehouseCatalog[id] || {}
        : {};
    const meta = {
      id: id != null ? id : null,
      name: name || catalog.name || (id != null ? `Warehouse #${id}` : "Warehouse"),
      display_name: catalog.display_name || name || catalog.name || "",
      warehouse_type: catalog.warehouse_type || "",
      ownership: catalog.ownership || "",
      municipality: catalog.municipality || "",
      province: catalog.province || "",
      category: catalog.category || "",
      partnership: catalog.partnership || "",
      office: catalog.office || "",
      contact_person: catalog.contact_person || "",
      contact_number: catalog.contact_number || "",
      designated_storekeepers: catalog.designated_storekeepers || "",
      storekeeper_contact_number: catalog.storekeeper_contact_number || "",
      key,
    };
    const classification = classifySourceWarehouse(meta, receivingContext);
    seen.set(key, {
      ...meta,
      classification,
    });
  });
  return Array.from(seen.values());
};

const RELEASE_WITNESS_HELPER = {
  escort:
    "Prefilled from DSWD Escort — change if this is not the actual witness.",
  storekeeper:
    "Suggested from warehouse storekeeper record — change if the witness differs.",
  focal:
    "No storekeeper on file; suggested from warehouse focal person — change if the witness differs.",
  lgu:
    "Loaded from the warehouse master-list storekeeper — change if the actual release witness differs.",
  none:
    "No storekeeper on file for this warehouse — search MyPortal or enter witness details.",
};

/** Shared notice for values auto-filled from RIS, library, MyPortal, escort, or warehouse. */
const PREFILL_EDITABLE_HINT =
  "Prefilled when available — change if this is not the actual data.";

const catalogWarehouseForVehicle = (row, warehouseCatalog = {}) => {
  const id = row?.source_warehouse_id;
  if (id == null || id === "") return null;
  return warehouseCatalog[String(id)] || warehouseCatalog[id] || null;
};

const emptyReleaseWitnessFields = () => ({
  release_witnessed_by: "",
  release_witness_contact_number: "",
  release_witness_id_number: "",
  release_witness_position: "",
  release_witness_office: "",
});

// Always make the personnel source explicit. This prevents an auto-filled
// storekeeper or escort from hiding whether the release was witnessed by
// DSWD or LGU personnel.
const needsReleaseWitnessAffiliation = () => true;

const releaseWitnessFieldsFromEscort = (row = {}) => ({
  release_witnessed_by: String(row.escort_name || "").trim(),
  release_witness_contact_number: String(row.escort_contact_number || "").trim(),
  release_witness_id_number: String(row.escort_id_number || "").trim(),
  release_witness_position: String(row.escort_position || "").trim(),
  release_witness_office: String(row.escort_office || "").trim(),
});

const releaseWitnessFieldsFromStorekeeper = (warehouse = {}) => {
  const storekeeperName = String(warehouse?.designated_storekeepers || "").trim();
  const focalName = String(warehouse?.contact_person || "").trim();
  const name = storekeeperName || focalName;
  if (!name) {
    return { ...emptyReleaseWitnessFields(), matched: false };
  }
  return {
    release_witnessed_by: name,
    release_witness_contact_number: String(
      storekeeperName
        ? warehouse?.storekeeper_contact_number || ""
        : warehouse?.contact_number || "",
    ).trim(),
    // Prefer explicit storekeeper ID / position when catalog has them; otherwise leave blank for MyPortal.
    release_witness_id_number: String(
      warehouse?.storekeeper_id_number || warehouse?.id_number || "",
    ).trim(),
    release_witness_position: String(
      warehouse?.storekeeper_position || warehouse?.position || "",
    ).trim(),
    // Name/contact may come from an LGU warehouse focal; office never uses warehouse FO data.
    release_witness_office: "",
    matched: true,
    source: storekeeperName ? "storekeeper" : "focal",
  };
};

const isReleaseWitnessBlank = (row = {}) =>
  !String(row.release_witnessed_by || "").trim()
  && !String(row.release_witness_id_number || "").trim()
  && !String(row.release_witness_position || "").trim()
  && !String(row.release_witness_office || "").trim();

/** Resolve autofill source for Release of FNIs Witnessed By (escort vs warehouse storekeeper). */
const provinceShortName = (province = "") => {
  const value = String(province || "").trim();
  const normalized = value.toLowerCase().replace(/[^a-z]+/g, " ").trim();
  const known = {
    "agusan del norte": "ADN",
    "agusan del sur": "ADS",
    "surigao del norte": "SDN",
    "surigao del sur": "SDS",
    "dinagat islands": "PDI",
    "province of dinagat islands": "PDI",
  };
  if (known[normalized]) return known[normalized];
  return value
    .split(/\s+/)
    .filter((word) => !["of", "the"].includes(word.toLowerCase()))
    .map((word) => word[0] || "")
    .join("")
    .toUpperCase();
};

const looksLikeLguOfficeLabel = (value = "") =>
  /^(PLGU|CLGU|MLGU|MGLU)\b/i.test(String(value || "").trim());

/** Regional FO / DSWD office codes — never valid as an LGU witness office. */
const isRegionalFoOfficeLabel = (value = "") => {
  const normalized = String(value || "").trim();
  if (!normalized || looksLikeLguOfficeLabel(normalized)) return false;
  return /^(CARAGA|DSWD(?:\s|$)|FO\b|RROS|NROC|FNI|REGIONAL)\b/i.test(normalized);
};

/**
 * Office of the LGU personnel who witnessed release — the person's organization
 * (request/RIS receiving LGU), never the source warehouse FO office/municipality.
 * Example: "MLGU - Tubod, SDN".
 */
const lguWitnessOfficeName = (receivingContext = {}) => {
  const labeled = [
    // Use the same authoritative office as the Recipient Receipt flow.
    receivingContext.receiving_representative_office,
    receivingContext.receiving_agency_lgu,
    receivingContext.requesting_agency,
    receivingContext.recipient,
  ]
    .map((value) => String(value || "").trim())
    .find((value) => looksLikeLguOfficeLabel(value) && !isRegionalFoOfficeLabel(value));
  if (labeled) return labeled;

  // Some older dispatch payloads expose the recipient only through a display
  // label. Preserve that LGU identity instead of falling back to the regional
  // office carried by a warehouse/personnel record.
  const recipientLabel = String(receivingContext.recipient_label || "").trim();
  if (looksLikeLguOfficeLabel(recipientLabel) && !isRegionalFoOfficeLabel(recipientLabel)) {
    return recipientLabel;
  }

  const locality = String(
    receivingContext.municipality || receivingContext.lgu || "",
  ).trim();
  if (!locality || isRegionalFoOfficeLabel(locality)) return "";
  if (looksLikeLguOfficeLabel(locality)) return locality;

  const levelRaw = String(receivingContext.lgu_level || "").trim().toUpperCase();
  const level = ["PLGU", "CLGU", "MLGU", "MGLU"].includes(levelRaw)
    ? (levelRaw === "MGLU" ? "MLGU" : levelRaw)
    : (/\bcity\b/i.test(locality) ? "CLGU" : "MLGU");
  const province = provinceShortName(receivingContext.province);
  return `${level} - ${locality}${province ? `, ${province}` : ""}`;
};

const resolveLguWitnessOffice = (row = {}, receivingContext = {}) => {
  const suggested = lguWitnessOfficeName(receivingContext);
  // Canonical receiving-LGU label always wins for LGU witnesses.
  if (suggested) return suggested;
  const existing = String(row.release_witness_office || row.releaser_office || "").trim();
  if (looksLikeLguOfficeLabel(existing) && !isRegionalFoOfficeLabel(existing)) return existing;
  return "";
};

const resolveReleaseWitnessAutofill = (
  row = {},
  warehouseCatalog = {},
  receivingContext = {},
) => {
  // Explicit LGU affiliation wins over escort autofill — escort office is a DSWD FO code.
  if (needsReleaseWitnessAffiliation(row) && row.release_witness_affiliation === "lgu") {
    const warehouse = catalogWarehouseForVehicle(row, warehouseCatalog) || {};
    const warehousePersonnel = releaseWitnessFieldsFromStorekeeper(warehouse);
    const office = resolveLguWitnessOffice(row, receivingContext);
    return {
      key: [
        "lgu-personnel",
        row.source_warehouse_id || "",
        warehousePersonnel.release_witnessed_by,
        warehousePersonnel.release_witness_contact_number,
        office,
      ].join("|"),
      mode: "lgu",
      fields: {
        release_witnessed_by:
          row.release_witnessed_by || warehousePersonnel.release_witnessed_by || "",
        release_witness_contact_number:
          row.release_witness_contact_number
          || warehousePersonnel.release_witness_contact_number
          || "",
        release_witness_id_number: row.release_witness_id_number || "",
        release_witness_position: row.release_witness_position || "LGU Employee",
        release_witness_office: office,
      },
    };
  }

  if (Boolean(row.has_dswd_escort)) {
    const fields = releaseWitnessFieldsFromEscort(row);
    return {
      key: [
        "escort",
        fields.release_witnessed_by,
        fields.release_witness_id_number,
        fields.release_witness_position,
        fields.release_witness_office,
        String(row.escort_contact_number || "").trim(),
      ].join("|"),
      mode: "escort",
      fields,
    };
  }

  if (needsReleaseWitnessAffiliation(row) && !row.release_witness_affiliation) {
    return {
      key: "affiliation-required",
      mode: "none",
      fields: emptyReleaseWitnessFields(),
    };
  }

  const warehouse = catalogWarehouseForVehicle(row, warehouseCatalog) || {};

  if (needsReleaseWitnessAffiliation(row) && row.release_witness_affiliation === "dswd") {
    return {
      key: ["dswd-personnel", row.source_warehouse_id || ""].join("|"),
      mode: "none",
      fields: emptyReleaseWitnessFields(),
    };
  }

  const storekeeper = releaseWitnessFieldsFromStorekeeper(warehouse);
  const warehouseKey =
    row.source_warehouse_id != null && row.source_warehouse_id !== ""
      ? `id:${row.source_warehouse_id}`
      : `name:${String(row.source_warehouse_name || "").trim().toLowerCase()}`;

  return {
    key: [
      "storekeeper",
      warehouseKey,
      storekeeper.release_witnessed_by,
      storekeeper.release_witness_contact_number,
      storekeeper.release_witness_id_number,
      storekeeper.release_witness_position,
      storekeeper.release_witness_office,
    ].join("|"),
    mode: storekeeper.matched ? storekeeper.source : "none",
    fields: {
      release_witnessed_by: storekeeper.release_witnessed_by,
      release_witness_contact_number: storekeeper.release_witness_contact_number,
      release_witness_id_number: storekeeper.release_witness_id_number,
      release_witness_position: storekeeper.release_witness_position,
      release_witness_office: storekeeper.release_witness_office,
    },
  };
};

const releaseWitnessHelperText = (row = {}, warehouseCatalog = {}, receivingContext = {}) => {
  const mode = resolveReleaseWitnessAutofill(row, warehouseCatalog, receivingContext).mode;
  return RELEASE_WITNESS_HELPER[mode] || RELEASE_WITNESS_HELPER.none;
};

const warehouseMatchKey = (warehouseId, warehouseName = "") => {
  if (warehouseId != null && warehouseId !== "") return `id:${warehouseId}`;
  const name = String(warehouseName || "").trim();
  return name ? `name:${name.toLowerCase()}` : "";
};

const itemMatchesSourceWarehouse = (item, warehouseId, warehouseName = "") => {
  const itemKeyValue = warehouseMatchKey(item?.warehouse_id, item?.warehouse_name);
  const vehicleKey = warehouseMatchKey(warehouseId, warehouseName);
  if (!itemKeyValue || !vehicleKey) return false;
  return itemKeyValue === vehicleKey;
};

const planItemsForVehicleWarehouse = (planItems = [], vehicle = {}) =>
  (planItems || []).filter((item) =>
    itemMatchesSourceWarehouse(
      item,
      vehicle?.source_warehouse_id,
      vehicle?.source_warehouse_name,
    ),
  );

/** Same available-to-plan rule as RROS RIS: physical WIT stock minus other-request reservations. */
const buildPlanningWarehouseStock = (warehouseStock = [], warehouseReservations = [], requestId = null) => {
  const rows = warehouseStock.map((row) => ({
    ...row,
    physical_available: Math.max(0, Number(row.available) || 0),
    reserved_elsewhere: 0,
    available: Math.max(0, Number(row.available) || 0),
  }));
  const reserved = warehouseReservations
    .filter((row) => String(row.request_id) !== String(requestId))
    .reduce((groups, row) => {
      const key = `${row.warehouse_id}|${row.item_key || itemKey(row.item_name)}`;
      groups.set(key, (groups.get(key) || 0) + Math.max(0, Number(row.quantity) || 0));
      return groups;
    }, new Map());

  reserved.forEach((quantity, key) => {
    let remaining = quantity;
    rows
      .filter((row) => `${row.warehouse_id}|${itemKey(row.item)}` === key)
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
};

const stockReferenceForItem = (item, planningStock = []) => {
  if (!item?.warehouse_id) {
    return { current_stockpile: null, available_to_plan: null };
  }
  const rows = planningStock.filter(
    (row) =>
      String(row.warehouse_id) === String(item.warehouse_id) &&
      itemKey(row.item) === itemKey(item.item_name),
  );
  if (rows.length === 0) {
    return { current_stockpile: null, available_to_plan: null };
  }
  return {
    current_stockpile: Math.trunc(
      rows.reduce((total, row) => total + Number(row.physical_available || 0), 0),
    ),
    available_to_plan: Math.trunc(
      rows.reduce((total, row) => total + Number(row.available || 0), 0),
    ),
  };
};

const yesNoValue = (value) => {
  if (value === true || value === 1 || value === "1" || value === "Yes") return "Yes";
  if (value === false || value === 0 || value === "0" || value === "No") return "No";
  return "";
};

/** Local `YYYY-MM-DD` for calendar-date comparisons (Asia/Manila wall clock). */
const toDateLocalValue = (date = new Date()) => {
  const d = date instanceof Date ? date : new Date(date);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

/** Local `YYYY-MM-DDTHH:mm` for datetime-local min / comparisons (minute precision). */
const toDateTimeLocalValue = (date = new Date()) => {
  const d = date instanceof Date ? date : new Date(date);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

/** Start of today as datetime-local min — any clock time on today is allowed. */
const todayDateLocalMin = (date = new Date()) => {
  const day = toDateLocalValue(date);
  return day ? `${day}T00:00` : "";
};

const normalizeDateTimeLocal = (value) => {
  const raw = String(value || "").trim();
  if (!raw) return "";
  return raw.replace(" ", "T").slice(0, 16);
};

const parseDateTimeLocal = (value) => {
  const normalized = normalizeDateTimeLocal(value);
  if (!normalized) return null;
  const parsed = new Date(normalized);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const addMinutesDateTimeLocal = (value, minutes = 1) => {
  const parsed = parseDateTimeLocal(value);
  if (!parsed) return "";
  parsed.setMinutes(parsed.getMinutes() + minutes);
  return toDateTimeLocalValue(parsed);
};

const actualArrivalSequenceError = (departedAt, actualArrival) => {
  const departure = parseDateTimeLocal(departedAt);
  const arrival = parseDateTimeLocal(actualArrival);
  if (!departure || !arrival || arrival.getTime() > departure.getTime()) return "";
  return "Actual arrival date and time must be after actual departure.";
};

const isPastDateTimeLocal = (value, now = new Date()) => {
  const parsed = parseDateTimeLocal(value);
  if (!parsed) return false;
  const floorNow = new Date(now);
  floorNow.setSeconds(0, 0);
  return parsed.getTime() < floorNow.getTime();
};

/** True when the value's calendar date is before today (not clock time). */
const isPastCalendarDateLocal = (value, now = new Date()) => {
  const date = manilaCalendarDateFromLocal(value);
  if (!date) return false;
  const today = toDateLocalValue(now);
  return Boolean(today) && date < today;
};

/**
 * @param {object} [options]
 * @param {boolean} [options.allowPastUnchanged]
 * @param {string} [options.originalValue]
 * @param {boolean} [options.dateOnly] When true (actuals), min is start of today so any time today is allowed.
 */
const datetimeLocalMinForField = (
  currentValue,
  { allowPastUnchanged = false, originalValue = "", dateOnly = false } = {},
) => {
  const nowMin = dateOnly ? todayDateLocalMin() : toDateTimeLocalValue();
  const isPast = dateOnly
    ? isPastCalendarDateLocal(currentValue)
    : isPastDateTimeLocal(currentValue);
  if (
    allowPastUnchanged
    && currentValue
    && normalizeDateTimeLocal(currentValue) === normalizeDateTimeLocal(originalValue)
    && isPast
  ) {
    return normalizeDateTimeLocal(currentValue);
  }
  return nowMin;
};

const collectVehicleScheduleErrors = (vehicleDetails = [], previousVehicleDetails = []) => {
  const errors = {};
  const now = new Date();

  (vehicleDetails || []).forEach((row, index) => {
    if (!row || typeof row !== "object") return;
    const previous = previousVehicleDetails?.[index] || {};
    const estimatedDeparture = normalizeDateTimeLocal(row.estimated_departure);
    const estimatedArrival = normalizeDateTimeLocal(row.estimated_arrival);
    const departedAt = normalizeDateTimeLocal(row.departed_at);
    const actualArrival = normalizeDateTimeLocal(row.actual_arrival);
    const previousDeparted = normalizeDateTimeLocal(previous.departed_at);
    const previousActualArrival = normalizeDateTimeLocal(previous.actual_arrival);
    const usesSavedHistoricalDeparture = Boolean(
      departedAt && previousDeparted && departedAt === previousDeparted,
    );

    if (estimatedDeparture && isPastDateTimeLocal(estimatedDeparture, now)) {
      errors[`vehicle_details.${index}.estimated_departure`] =
        "Estimated departure cannot be earlier than the current date and time.";
    }

    if (estimatedArrival && isPastDateTimeLocal(estimatedArrival, now)) {
      errors[`vehicle_details.${index}.estimated_arrival`] =
        "Estimated arrival cannot be earlier than the current date and time.";
    } else if (
      estimatedDeparture
      && estimatedArrival
      && parseDateTimeLocal(estimatedArrival)?.getTime()
        < parseDateTimeLocal(estimatedDeparture)?.getTime()
    ) {
      errors[`vehicle_details.${index}.estimated_arrival`] =
        "Estimated arrival cannot be earlier than estimated departure.";
    }

    // Actuals: calendar date only — any clock time on today or later is allowed.
    if (
      departedAt
      && isPastCalendarDateLocal(departedAt, now)
      && departedAt !== previousDeparted
    ) {
      errors[`vehicle_details.${index}.departed_at`] =
        "Actual departure date cannot be earlier than today.";
    }

    if (
      actualArrival
      && isPastCalendarDateLocal(actualArrival, now)
      && actualArrival !== previousActualArrival
      && !usesSavedHistoricalDeparture
    ) {
      errors[`vehicle_details.${index}.actual_arrival`] =
        "Actual arrival date cannot be earlier than today.";
    } else if (
      departedAt
      && actualArrival
      && parseDateTimeLocal(actualArrival)?.getTime()
        <= parseDateTimeLocal(departedAt)?.getTime()
      && !errors[`vehicle_details.${index}.actual_arrival`]
    ) {
      errors[`vehicle_details.${index}.actual_arrival`] =
        "Actual arrival date and time must be after actual departure.";
    }
  });

  return errors;
};

const emptyLoadedItem = (item = {}) => ({
  requisition_issuance_item_id: item.requisition_issuance_item_id ?? null,
  item_name: item.item_name || "",
  loaded_quantity: "",
  planned_quantity: "",
  remarks: "",
});

const alphabeticVehicleSuffix = (index) => {
  let suffix = "";
  for (let number = Number(index) + 1; number > 0; number = Math.floor((number - 1) / 26)) {
    suffix = String.fromCharCode(65 + ((number - 1) % 26)) + suffix;
  }
  return suffix;
};

const assignVehicleDrNumbers = (vehicles = [], baseDrNumber = "", offset = 0, forceSuffix = false) => {
  const rows = Array.isArray(vehicles) ? vehicles : [];
  return rows.map((row) => ({
    ...row,
    // The backend assigns the permanent DR number when this release is
    // confirmed; Planning order must not reserve an alphabetical suffix.
    dr_number: row?.warehouse_released_at ? String(row?.dr_number || "") : "",
  }));
};

const emptyVehicleRow = (planItems = [], assignedWarehouse = null, options = {}) => {
  const unique = uniqueSourceWarehouses(
    planItems,
    options.warehouseCatalog || {},
    options.receivingContext || {},
  );
  const remoteOnly = unique.filter(warehouseRequiresTransport);
  const sole = remoteOnly.length === 1 ? remoteOnly[0] : null;
  const assigned =
    assignedWarehouse && warehouseRequiresTransport(assignedWarehouse)
      ? assignedWarehouse
      : sole;
  const row = {
    source_vehicle_index: options.source_vehicle_index ?? null,
    dr_number: "",
    source_warehouse_id: assigned?.id ?? "",
    source_warehouse_name: assigned?.name || "",
    vehicle_type: "",
    driver: "",
    driver_contact_number: "",
    driver_id_number: "",
    driver_position: "",
    driver_office: "",
    vehicle_plate_number: "",
    has_dswd_escort: false,
    escort_name: "",
    escort_contact_number: "",
    escort_id_number: "",
    escort_position: "",
    escort_office: "",
    estimated_departure: "",
    estimated_arrival: "",
    allows_multi_day_run: false,
    mode_of_transportation: "",
    land_transportation_source: "",
    warehouse_released_at: "",
    release_authorized_by: "",
    release_authorizer_position: "",
    release_authorizer_office: "",
    release_witnessed_by: "",
    release_witness_contact_number: "",
    release_witness_affiliation: "",
    release_witness_id_number: "",
    release_witness_position: "",
    release_witness_office: "",
    loaded_at: "",
    loading_remarks: "",
    loaded_items: (planItems || []).map((item) => emptyLoadedItem(item)),
    departed_at: "",
    actual_arrival: "",
    delivered_at: "",
    fully_delivered: "",
    received_by: "",
    received_by_id_number: "",
    received_by_position: "",
    received_by_office: "",
    received_at: "",
    receiver_contact: "",
    receipt_acknowledged: false,
    receipt_remarks: "",
  };

  // Default escort = No → suggest warehouse storekeeper when source warehouse is known.
  if (row.source_warehouse_id || row.source_warehouse_name) {
    return {
      ...row,
      ...resolveReleaseWitnessAutofill(
        row,
        options.warehouseCatalog || {},
        options.receivingContext || {},
      ).fields,
    };
  }

  return row;
};

const isEmptyVehicleRow = (row) => {
  if (!row || typeof row !== "object") return true;
  const loadedAny = (row.loaded_items || []).some(
    (line) => String(line?.loaded_quantity ?? "").trim() !== "",
  );
  return !(
    String(row.source_warehouse_id || "").trim()
    || String(row.source_warehouse_name || "").trim()
    || String(row.vehicle_type || "").trim()
    || String(row.driver || "").trim()
    || String(row.driver_contact_number || "").trim()
    || String(row.vehicle_plate_number || "").trim()
    || String(row.estimated_departure || "").trim()
    || String(row.estimated_arrival || "").trim()
    || primaryModeOfTransportation(row.mode_of_transportation)
    || String(row.warehouse_released_at || "").trim()
    || String(row.release_witnessed_by || "").trim()
    || String(row.release_witness_id_number || "").trim()
    || String(row.departed_at || "").trim()
    || String(row.received_by || "").trim()
    || loadedAny
  );
};

const vehicleIsAssignedForDr = (row) => Boolean(
  row
  && String(row.source_warehouse_id || row.source_warehouse_name || "").trim()
  && primaryModeOfTransportation(row.mode_of_transportation)
  && String(row.land_transportation_source || "").trim()
  && String(row.vehicle_type || "").trim()
  && String(row.vehicle_plate_number || "").trim()
);

const vehicleDrAssignmentHint = (row) => {
  const missing = [];
  if (!String(row?.source_warehouse_id || row?.source_warehouse_name || "").trim()) missing.push("source warehouse");
  if (!primaryModeOfTransportation(row?.mode_of_transportation)) missing.push("mode of transportation");
  if (!String(row?.land_transportation_source || "").trim()) missing.push("land transportation source");
  if (!String(row?.vehicle_type || "").trim()) missing.push("vehicle type");
  if (!String(row?.vehicle_plate_number || "").trim()) missing.push("plate number");
  return `Assign the ${missing.join(", ")} before previewing this vehicle's DR.`;
};

const alignLoadedItems = (loadedItems = [], planItems = []) => {
  const existing = Array.isArray(loadedItems) ? loadedItems : [];
  const byRisId = new Map();
  const byName = new Map();
  existing.forEach((line) => {
    if (line?.requisition_issuance_item_id != null) {
      byRisId.set(String(line.requisition_issuance_item_id), line);
    }
    const name = String(line?.item_name || "")
      .trim()
      .toLowerCase();
    if (name) byName.set(name, line);
  });

  if ((planItems || []).length === 0) {
    return existing.map((line) => ({
      requisition_issuance_item_id: line.requisition_issuance_item_id ?? null,
      item_name: line.item_name || "",
      loaded_quantity:
        line.loaded_quantity === null || line.loaded_quantity === undefined
          ? ""
          : String(line.loaded_quantity),
      planned_quantity:
        line.planned_quantity === null || line.planned_quantity === undefined
          ? (line.loaded_quantity === null || line.loaded_quantity === undefined ? "" : String(line.loaded_quantity))
          : String(line.planned_quantity),
      remarks: line.remarks || line.line_remarks || "",
    }));
  }

  return (planItems || []).map((item) => {
    const match =
      (item.requisition_issuance_item_id != null
        && byRisId.get(String(item.requisition_issuance_item_id)))
      || byName.get(String(item.item_name || "").trim().toLowerCase())
      || {};
    return {
      requisition_issuance_item_id: item.requisition_issuance_item_id ?? null,
      item_name: item.item_name || "",
      loaded_quantity:
        match.loaded_quantity === null || match.loaded_quantity === undefined
          ? ""
          : String(match.loaded_quantity),
      planned_quantity:
        match.planned_quantity === null || match.planned_quantity === undefined
          ? (match.loaded_quantity === null || match.loaded_quantity === undefined ? "" : String(match.loaded_quantity))
          : String(match.planned_quantity),
      remarks: match.remarks || match.line_remarks || "",
    };
  });
};

/** Normalize a vehicle row for the form. Legacy vehicle_id/fleet is ignored. */
const normalizeVehicleRow = (row = {}, planItems = []) => {
  const unique = uniqueSourceWarehouses(planItems);
  const sole = unique.length === 1 ? unique[0] : null;
  let sourceWarehouseId =
    row.source_warehouse_id != null && row.source_warehouse_id !== ""
      ? row.source_warehouse_id
      : "";
  let sourceWarehouseName = String(row.source_warehouse_name || "").trim();
  if (sole) {
    sourceWarehouseId = sole.id ?? "";
    sourceWarehouseName = sole.name || "";
  } else if (sourceWarehouseId !== "" && !sourceWarehouseName) {
    const match = unique.find((wh) => String(wh.id) === String(sourceWarehouseId));
    sourceWarehouseName = match?.name || "";
  } else if (sourceWarehouseId === "" && sourceWarehouseName) {
    const match = unique.find(
      (wh) => String(wh.name || "").toLowerCase() === sourceWarehouseName.toLowerCase(),
    );
    if (match?.id != null) sourceWarehouseId = match.id;
  }

  const estimatedDeparture =
    row.estimated_departure
    || (row.dispatch_date
      ? `${String(row.dispatch_date).slice(0, 10)}T08:00`
      : "");
  const aligned = alignLoadedItems(row.loaded_items, planItems).map((line, index) => {
    const item = planItems?.[index];
    if (
      (sourceWarehouseId !== "" || sourceWarehouseName)
      && item
      && !itemMatchesSourceWarehouse(item, sourceWarehouseId, sourceWarehouseName)
    ) {
      return emptyLoadedItem(item);
    }
    return line;
  });

  return {
    source_vehicle_index: row.source_vehicle_index ?? null,
    dr_number: row.dr_number || "",
    source_warehouse_id: sourceWarehouseId,
    source_warehouse_name: sourceWarehouseName,
    vehicle_type: row.vehicle_type || "",
    driver: personNameOnly(row.driver || ""),
    driver_contact_number: row.driver_contact_number || "",
    driver_id_number: row.driver_id_number || "",
    driver_position: row.driver_position || "",
    driver_office: row.driver_office || "",
    vehicle_plate_number: row.vehicle_plate_number || "",
    has_dswd_escort: Boolean(row.has_dswd_escort),
    escort_name: row.has_dswd_escort ? personNameOnly(row.escort_name || "") : "",
    escort_contact_number: row.has_dswd_escort ? (row.escort_contact_number || "") : "",
    escort_id_number: row.has_dswd_escort ? (row.escort_id_number || "") : "",
    escort_position: row.has_dswd_escort ? (row.escort_position || "") : "",
    escort_office: row.has_dswd_escort ? (row.escort_office || "") : "",
    estimated_departure: estimatedDeparture
      ? String(estimatedDeparture).replace(" ", "T").slice(0, 16)
      : "",
    estimated_arrival: row.estimated_arrival
      ? String(row.estimated_arrival).replace(" ", "T").slice(0, 16)
      : "",
    allows_multi_day_run: Boolean(row.allows_multi_day_run),
    mode_of_transportation: primaryModeOfTransportation(row.mode_of_transportation),
    land_transportation_source: row.land_transportation_source || "",
    warehouse_released_at: row.warehouse_released_at
      ? String(row.warehouse_released_at).replace(" ", "T").slice(0, 16)
      : "",
    release_authorized_by: row.release_authorized_by || "",
    release_authorizer_position: row.release_authorizer_position || "",
    release_authorizer_office: row.release_authorizer_office || "",
    release_witnessed_by: row.release_witnessed_by || "",
    release_witness_contact_number: row.release_witness_contact_number || "",
    release_witness_affiliation: ["dswd", "lgu"].includes(row.release_witness_affiliation)
      ? row.release_witness_affiliation
      : "",
    release_witness_id_number: row.release_witness_id_number || "",
    release_witness_position: row.release_witness_position || "",
    release_witness_office: row.release_witness_office || "",
    loaded_at: row.loaded_at
      ? String(row.loaded_at).replace(" ", "T").slice(0, 16)
      : "",
    loading_remarks: row.loading_remarks || "",
    loaded_items: aligned,
    departed_at: row.departed_at
      ? String(row.departed_at).replace(" ", "T").slice(0, 16)
      : "",
    actual_arrival: (() => {
      if (row.actual_arrival) {
        return String(row.actual_arrival).replace(" ", "T").slice(0, 16);
      }
      if (row.delivered_at) {
        return `${String(row.delivered_at).slice(0, 10)}T12:00`;
      }
      return "";
    })(),
    delivered_at: row.actual_arrival
      ? String(row.actual_arrival).replace(" ", "T").slice(0, 10)
      : (row.delivered_at ? String(row.delivered_at).slice(0, 10) : ""),
    fully_delivered: yesNoValue(row.fully_delivered),
    received_by: personNameOnly(row.received_by || ""),
    received_by_id_number:
      row.received_by_id_number || row.recipient_id_number || "",
    received_by_position: row.received_by_position || "",
    received_by_office: row.received_by_office || "",
    received_at: row.received_at
      ? String(row.received_at).replace(" ", "T").slice(0, 16)
      : "",
    receiver_contact: row.receiver_contact || "",
    receipt_acknowledged: Boolean(row.receipt_acknowledged),
    receipt_remarks: row.receipt_remarks || "",
  };
};

/** Prefill receipt name/contact/position/office from RIS (and optional library match) only when empty. */
const applyRisReceiptDefaults = (row, ris = {}, libraryMatch = null) => {
  const current = row && typeof row === "object" ? row : {};
  const risRep = personNameOnly(ris.receiving_representative || "");
  const name = personNameOnly(current.received_by || "") || risRep;
  const lib = libraryMatch && typeof libraryMatch === "object" ? libraryMatch : {};
  const risContact = String(ris.contact_number || "").trim();
  const risPosition = String(ris.receiving_representative_position || "").trim();
  const risOffice = String(ris.receiving_representative_office || "").trim();
  const risIdNumber = String(ris.receiving_representative_id_number || "").trim();
  const libContact = String(lib.contact_number || lib.metadata?.contact_number || "").trim();
  const libPosition = String(lib.position || lib.metadata?.position || "").trim();
  const libOffice = String(lib.office || lib.metadata?.office || "").trim();
  const libIdNumber = String(lib.id_number || lib.metadata?.id_number || "").trim();

  return {
    ...current,
    dr_number: current.released_at ? current.dr_number || "" : "",
    received_by: name,
    receiver_contact:
      String(current.receiver_contact || "").trim() || risContact || libContact,
    received_by_position:
      String(current.received_by_position || "").trim() || risPosition || libPosition,
    received_by_office:
      String(current.received_by_office || "").trim() || risOffice || libOffice,
    received_by_id_number:
      String(current.received_by_id_number || "").trim() || risIdNumber || libIdNumber,
  };
};

/** Map RIS / Received By library defaults onto local-handover receipt field names. */
const applyLocalHandoverReceiptDefaults = (
  handover = {},
  ris = {},
  libraryMatch = null,
  receivingContext = {},
) => {
  const bridged = applyRisReceiptDefaults(
    {
      received_by: handover.received_by || "",
      receiver_contact: handover.receiver_contact || "",
      received_by_position: handover.receiver_position || "",
      received_by_office: handover.receiver_office || "",
      received_by_id_number: handover.receiver_id_number || "",
    },
    ris,
    libraryMatch,
  );
  const office =
    String(bridged.received_by_office || "").trim()
    || lguWitnessOfficeName(receivingContext)
    || "";

  return {
    ...handover,
    received_by: bridged.received_by || "",
    receiver_contact: bridged.receiver_contact || "",
    receiver_position: bridged.received_by_position || "",
    receiver_office: office,
    receiver_id_number: bridged.received_by_id_number || "",
  };
};

/** Copy the proven vehicle receipt prefill into the local-owned warehouse field names. */
const localHandoverFromVehicleReceipt = (handover = {}, vehicleDetails = [], ris = {}) => {
  const current = { ...emptyForm().local_handover_details, ...(handover || {}) };
  const vehicleReceipt = (vehicleDetails || []).find((row) =>
    String(row?.received_by || "").trim(),
  ) || applyRisReceiptDefaults({}, ris);

  return {
    ...current,
    received_by: vehicleReceipt.received_by || "",
    receiver_contact: vehicleReceipt.receiver_contact || "",
    receiver_id_number: vehicleReceipt.received_by_id_number || "",
    receiver_position: vehicleReceipt.received_by_position || "",
    receiver_office: vehicleReceipt.received_by_office || "",
    remarks:
      (vehicleDetails || []).find((row) => String(row?.loading_remarks || "").trim())
        ?.loading_remarks
      || ris.purpose_of_release
      || current.remarks
      || "",
  };
};

const receivedBySelectionPatch = (option) => {
  const meta = option?.metadata || {};
  const contact = String(option?.contact_number || meta.contact_number || "").trim();
  const idNumber = String(option?.id_number || meta.id_number || "").trim();
  const position = String(option?.position || meta.position || "").trim();
  const office = String(option?.office || meta.office || "").trim();
  return { contact, idNumber, position, office };
};

const findReceivedByLibraryMatch = (name, options = []) => {
  const key = String(personNameOnly(name || "")).trim().toLowerCase();
  if (!key) return null;
  return (options || []).find(
    (option) => String(option?.value || "").trim().toLowerCase() === key,
  ) || null;
};

const resolveVehicleDetails = ({
  vehicle_details,
  number_of_vehicles,
  driver = "",
  driver_contact_number = "",
  vehicle_plate_number = "",
  mode_of_transportation = [],
  dispatch_date = "",
  estimated_arrival = "",
  warehouse_released_at = "",
  release_witnessed_by = "",
  loaded_at = "",
  loading_remarks = "",
  departed_at = "",
  actual_arrival = "",
  delivered_at = "",
  fully_delivered = "",
  received_by = "",
  received_at = "",
  receiver_contact = "",
  receipt_acknowledged = false,
  receipt_remarks = "",
  items = [],
} = {}) => {
  const planItems = items || [];
  let rows = Array.isArray(vehicle_details)
    ? vehicle_details.map((row) => normalizeVehicleRow(row, planItems))
    : [];

  if (rows.length === 0) {
    // New vehicle rows start with empty mode/type — never auto-pick options[0].
    rows = [
      normalizeVehicleRow(
        {
          vehicle_type: "",
          driver,
          driver_contact_number,
          vehicle_plate_number,
          mode_of_transportation: "",
          dispatch_date,
          estimated_arrival,
          warehouse_released_at,
          release_witnessed_by,
          loaded_at,
          loading_remarks,
          departed_at,
          actual_arrival,
          delivered_at,
          fully_delivered,
          received_by,
          received_at,
          receiver_contact,
          receipt_acknowledged,
          receipt_remarks,
          loaded_items: planItems.map((item) => ({
            ...emptyLoadedItem(item),
            loaded_quantity:
              item.loaded_quantity === null || item.loaded_quantity === undefined
                ? ""
                : String(item.loaded_quantity),
            remarks: item.remarks || "",
          })),
        },
        planItems,
      ),
    ];
  } else if (rows[0]) {
    // Backfill vehicles[0] from legacy plan-level fields when empty (keeps saved mode/type).
    const first = rows[0];
    rows[0] = normalizeVehicleRow(
      {
        ...first,
        estimated_departure:
          first.estimated_departure
          || (dispatch_date ? `${String(dispatch_date).slice(0, 10)}T08:00` : ""),
        estimated_arrival: first.estimated_arrival || estimated_arrival || "",
        mode_of_transportation:
          primaryModeOfTransportation(first.mode_of_transportation)
          || primaryModeOfTransportation(mode_of_transportation)
          || "",
        warehouse_released_at: first.warehouse_released_at || warehouse_released_at || "",
        release_witnessed_by: first.release_witnessed_by || release_witnessed_by || "",
        release_witness_id_number: first.release_witness_id_number || "",
        release_witness_position: first.release_witness_position || "",
        release_witness_office: first.release_witness_office || "",
        loaded_at: first.loaded_at || loaded_at || "",
        loading_remarks: first.loading_remarks || loading_remarks || "",
        departed_at: first.departed_at || departed_at || "",
        actual_arrival: first.actual_arrival || actual_arrival || "",
        delivered_at: first.delivered_at || delivered_at || "",
        fully_delivered: first.fully_delivered || yesNoValue(fully_delivered) || "",
        received_by: first.received_by || received_by || "",
        received_at: first.received_at || received_at || "",
        receiver_contact: first.receiver_contact || receiver_contact || "",
        receipt_acknowledged: first.receipt_acknowledged || Boolean(receipt_acknowledged),
        receipt_remarks: first.receipt_remarks || receipt_remarks || "",
      },
      planItems,
    );
  }

  const requested =
    number_of_vehicles === null || number_of_vehicles === undefined || number_of_vehicles === ""
      ? rows.length
      : Math.max(1, Number(number_of_vehicles) || 1);

  while (rows.length < requested) {
    rows.push(emptyVehicleRow(planItems));
  }

  return rows.map((row) => normalizeVehicleRow(row, planItems));
};

/** Resize vehicle rows to match count; keep filled data; only trim empty trailing rows when shrinking. */
const syncVehicleDetailsToCount = (
  rows,
  nextCount,
  planItems = [],
  assignedWarehouse = null,
  options = {},
) => {
  const current = Array.isArray(rows)
    ? rows.map((row) => normalizeVehicleRow(row, planItems))
    : [];
  const target = Math.max(1, Math.min(99, Number(nextCount) || 1));
  const next = [...current];

  while (next.length < target) {
    next.push(emptyVehicleRow(planItems, assignedWarehouse, options));
  }

  while (next.length > target) {
    const last = next[next.length - 1];
    if (isEmptyVehicleRow(last)) {
      next.pop();
      continue;
    }
    break;
  }

  return {
    vehicle_details: next,
    number_of_vehicles: String(next.length),
  };
};

const mirrorLegacyVehicleFields = (vehicleDetails) => {
  const first = normalizeVehicleRow((vehicleDetails || [])[0] || {});
  const types = (vehicleDetails || [])
    .map((row) => String(row?.vehicle_type || "").trim())
    .filter(Boolean);
  return {
    vehicle_id: "",
    driver: first.driver || "",
    driver_contact_number: first.driver_contact_number || "",
    vehicle_plate_number: first.vehicle_plate_number || "",
    vehicle_types: [...new Set(types)],
    mode_of_transportation: primaryModeOfTransportation(first.mode_of_transportation)
      ? [primaryModeOfTransportation(first.mode_of_transportation)]
      : [],
    dispatch_date: first.estimated_departure
      ? String(first.estimated_departure).slice(0, 10)
      : "",
    estimated_arrival: first.estimated_arrival || "",
    warehouse_released_at: first.warehouse_released_at || "",
    release_witnessed_by: first.release_witnessed_by || "",
    loaded_at: first.loaded_at || "",
    loading_remarks: first.loading_remarks || "",
    departed_at: first.departed_at || "",
    actual_arrival: first.actual_arrival || "",
    delivered_at: first.delivered_at || "",
    fully_delivered: first.fully_delivered || "",
    received_by: first.received_by || "",
    received_at: first.received_at || "",
    receiver_contact: first.receiver_contact || "",
    receipt_acknowledged: Boolean(first.receipt_acknowledged),
    receipt_remarks: first.receipt_remarks || "",
  };
};

const sumVehicleItemQuantity = (vehicleDetails = [], item, field = "loaded_quantity") => {
  const risId = item?.requisition_issuance_item_id;
  const name = String(item?.item_name || "")
    .trim()
    .toLowerCase();
  return (vehicleDetails || []).reduce((total, vehicle) => {
    const line = (vehicle.loaded_items || []).find((entry) => {
      if (risId != null && entry.requisition_issuance_item_id != null) {
        return String(entry.requisition_issuance_item_id) === String(risId);
      }
      return String(entry.item_name || "").trim().toLowerCase() === name;
    });
    return total + (Number(line?.[field]) || 0);
  }, 0);
};

const sumLoadedAcrossVehicles = (vehicleDetails = [], item) =>
  sumVehicleItemQuantity(vehicleDetails, item, "loaded_quantity");

const sumPlannedAcrossVehicles = (vehicleDetails = [], item) =>
  sumVehicleItemQuantity(vehicleDetails, item, "planned_quantity");

const remainingVehicleItemQuantity = (vehicleDetails = [], item, vehicleIndex, field) => {
  const allocated = Math.max(0, Number(item?.allocated_quantity || 0));
  const usedByOtherVehicles = (vehicleDetails || []).reduce((total, vehicle, index) => {
    if (index === vehicleIndex) return total;
    return total + sumVehicleItemQuantity([vehicle], item, field);
  }, 0);
  return Math.max(0, allocated - usedByOtherVehicles);
};

const emptyForm = () => ({
  request_id: "",
  vehicle_id: "",
  destination: "",
  receiving_agency_lgu: "",
  source_of_goods: "",
  purpose: "",
  driver: "",
  driver_contact_number: "",
  vehicle_plate_number: "",
  dispatch_officer: "",
  mode_of_transportation: [],
  vehicle_types: [],
  number_of_vehicles: "1",
  vehicle_details: [emptyVehicleRow()],
  dispatch_date: "",
  estimated_arrival: "",
  actual_arrival: "",
  warehouse_released_at: "",
  loaded_at: "",
  loading_remarks: "",
  departed_at: "",
  received_by: "",
  received_at: "",
  receiver_contact: "",
  receipt_acknowledged: false,
  receipt_remarks: "",
  delivered_at: "",
  release_witnessed_by: "",
  fully_delivered: "",
  has_returned_items: "",
  returned_particulars: "",
  returned_quantity: "",
  returned_reason: "",
  remarks: "",
  status: "draft",
  fulfillment_type: "",
  fulfillment_type_confirmed: false,
  local_handover_details: {
    dr_number: "", source_warehouse_id: "", source_warehouse_name: "", expected_release_at: "",
    release_authorized_by: "", release_authorizer_position: "", release_authorizer_office: "",
    release_witness_affiliation: "lgu", released_by: "", releaser_id_number: "", releaser_position: "", releaser_office: "", releaser_contact: "", released_at: "",
    received_by: "", receiver_id_number: "", receiver_position: "", receiver_office: "", receiver_contact: "", received_at: "",
    receipt_acknowledged: false, remarks: "",
  },
  items: [],
});

const formFromDispatch = (dispatch) => {
  const ris = dispatch?.ris || {};
  const items = (dispatch.items || []).map((item) => ({
    id: item.id,
    requisition_issuance_item_id: item.requisition_issuance_item_id,
    request_item_id: item.request_item_id,
    warehouse_id: item.warehouse_id,
    item_name: item.item_name,
    unit: item.unit || "",
    warehouse_name: item.warehouse_name || "",
    brand_description: item.brand_description || "",
    expiry: item.expiry || "",
    current_stockpile: item.current_stockpile ?? null,
    available_to_plan: item.available_to_plan ?? item.available_stock ?? null,
    unit_cost: item.unit_cost ?? null,
    allocated_quantity: item.allocated_quantity ?? 0,
    loaded_quantity:
      item.loaded_quantity === null || item.loaded_quantity === undefined
        ? ""
        : String(item.loaded_quantity),
    received_quantity:
      item.received_quantity === null || item.received_quantity === undefined
        ? ""
        : String(item.received_quantity),
    return_reason: item.return_reason || "",
    variance_disposition: item.variance_disposition || "",
    variance_resolution: item.variance_resolution || item.return_reason || "",
    return_condition: item.return_condition || "",
    return_stock_disposition: item.return_stock_disposition || "",
    return_received_at: item.return_received_at ? String(item.return_received_at).replace(" ", "T").slice(0, 16) : "",
    return_inspected_by: item.return_inspected_by || "",
    remarks: item.remarks || "",
  }));
  const vehicleDetails = resolveVehicleDetails({
    ...dispatch,
    items,
    release_witnessed_by:
      dispatch.release_witnessed_by || ris.release_witnessed_by || "",
    delivered_at: String(dispatch.delivered_at || ris.delivered_at || "").slice(0, 10),
    fully_delivered: dispatch.fully_delivered ?? ris.fully_delivered,
    received_by: dispatch.received_by || "",
    receiver_contact: dispatch.receiver_contact || "",
  }).map((row) => {
    const normalized = applyRisReceiptDefaults(row, ris);
    return {
      ...normalized,
      loading_remarks: normalized.loading_remarks || ris.purpose_of_release || "",
    };
  });
  const legacy = mirrorLegacyVehicleFields(vehicleDetails);
  return {
    ...emptyForm(),
    request_id: dispatch.request_id || "",
    vehicle_id: legacy.vehicle_id,
    destination: dispatch.destination || "",
    receiving_agency_lgu: dispatch.receiving_agency_lgu || "",
    source_of_goods: dispatch.source_of_goods || "",
    purpose: dispatch.purpose || "",
    driver: legacy.driver,
    driver_contact_number: legacy.driver_contact_number,
    vehicle_plate_number: legacy.vehicle_plate_number,
    dispatch_officer: "",
    mode_of_transportation: legacy.mode_of_transportation,
    vehicle_types: legacy.vehicle_types.length
      ? legacy.vehicle_types
      : asStringList(dispatch.vehicle_types),
    number_of_vehicles: String(vehicleDetails.length),
    vehicle_details: vehicleDetails,
    ...legacy,
    has_returned_items: yesNoValue(
      dispatch.has_returned_items ?? ris.has_returned_items,
    ),
    returned_particulars:
      dispatch.returned_particulars || ris.returned_particulars || "",
    returned_quantity:
      dispatch.returned_quantity === null || dispatch.returned_quantity === undefined
        ? wholeQuantityInputValue(ris.returned_quantity || "")
        : wholeQuantityInputValue(dispatch.returned_quantity),
    returned_reason: dispatch.returned_reason || ris.returned_reason || "",
    remarks: dispatch.remarks || "",
    status: dispatch.status || "draft",
    fulfillment_type: dispatch.fulfillment_type_confirmed ? (dispatch.fulfillment_type || "field_delivery") : "",
    fulfillment_type_confirmed: Boolean(dispatch.fulfillment_type_confirmed),
    local_handover_details: localHandoverFromVehicleReceipt(
      dispatch.local_handover_details,
      vehicleDetails,
      ris,
    ),
    items,
  };
};

const formFromSourceRequest = (source) => {
  const ris = source?.ris || {};
  const items = (ris.items || []).map((item) => ({
    requisition_issuance_item_id: item.requisition_issuance_item_id,
    request_item_id: item.request_item_id,
    warehouse_id: item.warehouse_id,
    item_name: item.item_name,
    unit: item.unit || "",
    warehouse_name: item.warehouse_name || "",
    brand_description: item.brand_description || "",
    expiry: item.expiry || "",
    current_stockpile: item.current_stockpile ?? null,
    available_to_plan: item.available_to_plan ?? item.available_stock ?? null,
    unit_cost: item.unit_cost ?? null,
    allocated_quantity: item.allocated_quantity ?? 0,
    loaded_quantity: "",
    received_quantity: "",
    return_reason: "",
    variance_disposition: "",
    variance_resolution: "",
    return_condition: "",
    return_stock_disposition: "",
    return_received_at: "",
    return_inspected_by: "",
    remarks: item.remarks || "",
  }));
  const vehicleDetails = resolveVehicleDetails({
    vehicle_types: ris.vehicle_types,
    number_of_vehicles: ris.number_of_vehicles,
    driver: ris.driver_name || "",
    driver_contact_number: ris.driver_contact_number || "",
    vehicle_plate_number: ris.vehicle_plate_number || "",
    mode_of_transportation: asStringList(ris.mode_of_transportation),
    release_witnessed_by: ris.release_witnessed_by || "",
    delivered_at: String(ris.delivered_at || "").slice(0, 10),
    fully_delivered: ris.fully_delivered,
    received_by: "",
    receiver_contact: "",
    items,
  }).map((row) => {
    const normalized = applyRisReceiptDefaults(row, ris);
    return {
      ...normalized,
      loading_remarks: normalized.loading_remarks || ris.purpose_of_release || "",
    };
  });
  const legacy = mirrorLegacyVehicleFields(vehicleDetails);
  return {
    ...emptyForm(),
    request_id: source?.id || "",
    destination: ris.delivery_site || "",
    receiving_agency_lgu: ris.recipient || source?.requesting_agency || source?.lgu || "",
    source_of_goods: "",
    purpose: "",
    vehicle_id: legacy.vehicle_id,
    driver: legacy.driver,
    driver_contact_number: legacy.driver_contact_number,
    vehicle_plate_number: legacy.vehicle_plate_number,
    mode_of_transportation: legacy.mode_of_transportation,
    vehicle_types: legacy.vehicle_types.length
      ? legacy.vehicle_types
      : asStringList(ris.vehicle_types),
    number_of_vehicles: String(vehicleDetails.length),
    vehicle_details: vehicleDetails,
    local_handover_details: localHandoverFromVehicleReceipt({}, vehicleDetails, ris),
    ...legacy,
    has_returned_items: yesNoValue(ris.has_returned_items),
    returned_particulars: ris.returned_particulars || "",
    returned_quantity: wholeQuantityInputValue(ris.returned_quantity || ""),
    returned_reason: ris.returned_reason || "",
    status: "draft",
    items,
  };
};

function StatusBadge({ status }) {
  const meta = STATUS_META[status] || {
    label: status || "—",
    className: "bg-slate-100 text-slate-700",
  };
  return (
    <span className={`inline-flex rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide ${meta.className}`}>
      {meta.label}
    </span>
  );
}

function FormSection({ id, number, title, subtitle, responsible = null, icon: Icon, children }) {
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
        <div className="min-w-0 flex-1">
          <h3 className="font-black text-slate-950">{title}</h3>
          <p className="text-xs text-slate-500">{subtitle}</p>
        </div>
        {responsible ? (
          <span className="ml-auto rounded-full border border-emerald-200 bg-white px-3 py-1 text-[10px] font-black uppercase tracking-wide text-emerald-800">
            Responsible: {responsible}
          </span>
        ) : null}
      </header>
      <div className="min-w-0 max-w-full p-5">{children}</div>
    </section>
  );
}

function Field({ label, required = false, hint, error, children, className = "", fieldKey = null }) {
  return (
    <div
      data-field={fieldKey || undefined}
      className={`flex h-full flex-col text-[11px] font-black uppercase leading-4 tracking-wide text-slate-600 ${className}`}
    >
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
      {error && <span className="mt-1 block normal-case text-rose-600">{error}</span>}
    </div>
  );
}

function StageBlock({
  id,
  title,
  titleClassName,
  mode = "future",
  forceOpen = false,
  helper = null,
  responsible = null,
  locked = false,
  lockNotice = null,
  embedded = false,
  children,
}) {
  if (embedded) {
    return <div id={id} className="scroll-mt-4">{children}</div>;
  }
  const stageKey = String(title || "").toLowerCase();
  const visual = stageKey.startsWith("plan")
    ? { number: "01", border: "border-amber-300", header: "bg-amber-50", badge: "bg-amber-600 text-white" }
    : stageKey.startsWith("release")
      ? { number: "02", border: "border-sky-300", header: "bg-sky-50", badge: "bg-sky-600 text-white" }
      : stageKey.startsWith("in transit")
        ? { number: "03", border: "border-violet-300", header: "bg-violet-50", badge: "bg-violet-600 text-white" }
        : stageKey.startsWith("recipient receipt") || stageKey.startsWith("lgu receipt")
          ? { number: "04", border: "border-emerald-300", header: "bg-emerald-50", badge: "bg-emerald-600 text-white" }
          : { number: "05", border: "border-rose-300", header: "bg-rose-50", badge: "bg-rose-600 text-white" };
  const [expanded, setExpanded] = useState(false);
  const isCurrent = mode === "current";
  const isAccomplished = mode === "accomplished";
  const open = isCurrent || forceOpen || expanded;

  if (!open) {
    return (
      <div
        id={id}
        className={`scroll-mt-4 overflow-hidden rounded-xl border-2 ${visual.border} bg-white shadow-sm`}
      >
        <div className={`flex flex-wrap items-center justify-between gap-2 px-4 py-3 ${visual.header}`}>
          <p className={`flex items-center gap-2 text-[10px] font-black uppercase tracking-[.16em] text-slate-500 ${titleClassName || ""}`}>
            <span className={`inline-flex h-7 w-7 items-center justify-center rounded-full text-[10px] ${visual.badge}`}>{visual.number}</span>
            <span>
            {title}
            {isAccomplished ? (
              <span className="ml-2 font-bold normal-case tracking-normal text-emerald-700/80">
                · Completed
              </span>
            ) : null}
            </span>
          </p>
          <div className="flex flex-wrap items-center gap-2">
            {responsible ? (
              <span className="rounded-full border border-slate-200 bg-white/90 px-2.5 py-1 text-[9px] font-black uppercase tracking-wide text-slate-600">
                Responsible: {responsible}
              </span>
            ) : null}
            <button
              type="button"
              onClick={() => setExpanded(true)}
              className="text-[10px] font-bold uppercase tracking-wide text-emerald-700 hover:text-emerald-900"
            >
              {isAccomplished ? "View" : "Show early"}
            </button>
          </div>
        </div>
        <p className="border-t border-slate-100 px-4 py-3 text-xs font-semibold normal-case text-slate-500">
          {isAccomplished
            ? (lockNotice || "Completed — expand to view. Editing is limited to the last updater.")
            : (helper || "Completes in a later status — expand only if you need to encode ahead.")}
        </p>
      </div>
    );
  }

  return (
    <div id={id} className={`scroll-mt-4 overflow-hidden rounded-xl border-2 ${visual.border} bg-white shadow-sm`}>
      <div className={`flex flex-wrap items-center justify-between gap-2 border-b ${visual.border} px-4 py-3 ${visual.header}`}>
        <div className="flex items-start gap-3">
          <span className={`inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-black ${visual.badge}`}>{visual.number}</span>
          <div>
          <p className={`text-[10px] font-black uppercase tracking-[.16em] ${titleClassName || "text-slate-700"}`}>
            {title}
            {isAccomplished ? (
              <span className="ml-2 font-bold normal-case tracking-normal text-emerald-700/80">
                · Completed
              </span>
            ) : null}
          </p>
          {helper && !isAccomplished ? (
            <p className="mt-0.5 text-[11px] font-semibold normal-case tracking-normal text-slate-400">
              {helper}
            </p>
          ) : null}
          {lockNotice ? (
            <p className={`mt-0.5 text-[11px] font-semibold normal-case tracking-normal ${
              locked ? "text-amber-700" : "text-emerald-700"
            }`}
            >
              {lockNotice}
            </p>
          ) : null}
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {responsible ? (
            <span className="rounded-full border border-slate-200 bg-white/90 px-2.5 py-1 text-[9px] font-black uppercase tracking-wide text-slate-600">
              Responsible: {responsible}
            </span>
          ) : null}
          {!isCurrent && (
            <button
              type="button"
              onClick={() => setExpanded(false)}
              className="text-[10px] font-bold uppercase tracking-wide text-slate-400 hover:text-slate-600"
            >
              {isAccomplished ? "Hide" : "Hide early edit"}
            </button>
          )}
        </div>
      </div>
      <fieldset disabled={locked} className={`p-4 ${locked ? "opacity-90" : ""}`}>
        {children}
      </fieldset>
    </div>
  );
}

function progressStageClasses(state, { interactive = false } = {}) {
  if (state === "done") {
    return {
      segment: "border-emerald-300 bg-emerald-100 text-emerald-800",
      dot: "border-emerald-500 bg-emerald-600 text-white",
      connector: "bg-emerald-400",
      label: "text-emerald-800",
    };
  }
  if (state === "current") {
    return {
      segment: "border-emerald-600 bg-emerald-700 text-white shadow-sm",
      dot: "border-emerald-700 bg-emerald-700 text-white ring-2 ring-emerald-200",
      connector: "bg-slate-200",
      label: "text-emerald-800",
    };
  }
  if (state === "skipped") {
    return {
      segment: "border-dashed border-slate-200 bg-slate-50 text-slate-400 line-through",
      dot: "border-slate-200 bg-slate-100 text-slate-400",
      connector: "bg-slate-200",
      label: "text-slate-400 line-through",
    };
  }
  return {
    segment: `border-slate-200 bg-white text-slate-400${interactive ? " hover:border-slate-300" : ""}`,
    dot: "border-slate-300 bg-white text-slate-400",
    connector: "bg-slate-200",
    label: "text-slate-400",
  };
}

/**
 * Per-transaction delivery progress.
 * compact — list rows; editor — modal header with jump links + Draft/Returns anchors.
 */
function DispatchProgressTracker({
  status = "draft",
  vehicleDetails = [],
  localOnly = false,
  fulfillmentType = null,
  timeline = [],
  variant = "compact",
  dense = false,
  onStepClick = null,
  className = "",
}) {
  const skipTransit = planSkipsTransit({
    localOnly,
    fulfillmentType,
    status,
    vehicleDetails,
    timeline,
  });
  const pickup = String(fulfillmentType || "") === "warehouse_pickup";
  const stages = pickup
    ? [
        PROGRESS_STAGES.find((stage) => stage.key === "planning"),
        {
          key: "receipt",
          label: "Release & Receipt",
          shortLabel: "Release & Receipt",
          completeStatus: "received",
          anchorId: "dispatch-stage-receipt",
        },
      ]
    : variant === "compact"
      ? resolveProgressStages({ skipTransit })
      : PROGRESS_STAGES;
  const vehicleHint = vehicleProgressHint(status, vehicleDetails, { skipTransit });
  const helper = STAGE_HELPERS[status] || null;
  const trackerStageState = (stage) => {
    if (pickup && stage.key === "receipt") {
      if (String(status) === "received") return "done";
      if (["planned", "released", "in_transit"].includes(String(status))) return "current";
      return "upcoming";
    }
    return progressStageState(stage.key, status, { skipTransit });
  };

  if (variant === "compact") {
    return (
      <div className={`min-w-[11rem] ${className}`} title={vehicleHint || helper || undefined}>
        <div className="flex overflow-hidden rounded-md border border-slate-200 bg-slate-50">
          {stages.map((stage) => {
            const state = trackerStageState(stage);
            const classes = progressStageClasses(state);
            return (
              <div
                key={stage.key}
                className={`flex-1 border-r border-slate-200 px-1 py-1 text-center last:border-r-0 ${classes.segment}`}
              >
                <span className="block truncate text-[8px] font-black uppercase leading-tight tracking-wide">
                  {stage.shortLabel}
                </span>
              </div>
            );
          })}
        </div>
        {vehicleHint ? (
          <p className="mt-0.5 truncate text-[9px] font-semibold normal-case tracking-normal text-slate-500">
            {vehicleHint}
          </p>
        ) : null}
      </div>
    );
  }

  const draftState = statusIndex(status) === 0 ? "current" : "done";
  const returnsState = statusIndex(status) >= statusIndex("received") ? "current" : "upcoming";

  return (
    <div className={`bg-white dark:bg-zinc-950 ${className}`}>
      {!dense && <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">
          Progress Tracker
        </p>
        {vehicleHint ? (
          <p className="text-[10px] font-bold uppercase tracking-wide text-slate-500">
            {vehicleHint}
          </p>
        ) : null}
      </div>}

      <div className={`flex items-center gap-1.5 ${dense ? "overflow-x-auto whitespace-nowrap" : "flex-wrap"}`}>
        <button
          type="button"
          onClick={() => onStepClick?.("dispatch-source")}
          title={STEPPER_STEPS[0]?.helper}
          className={`rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wide transition ${
            progressStageClasses(draftState, { interactive: true }).segment
          }`}
        >
          Draft
        </button>
        <span className="hidden h-px w-3 bg-slate-200 sm:block" aria-hidden />

        {stages.map((stage, index) => {
          const state = trackerStageState(stage);
          const classes = progressStageClasses(state, { interactive: true });
          const disabled = state === "skipped";
          return (
            <div key={stage.key} className="flex items-center gap-1.5">
              {index > 0 ? (
                <span
                  className={`hidden h-px w-3 sm:block ${
                    state === "done" || state === "current"
                      ? "bg-emerald-400"
                      : "bg-slate-200"
                  }`}
                  aria-hidden
                />
              ) : null}
              <button
                type="button"
                disabled={disabled}
                onClick={() => !disabled && onStepClick?.(stage.anchorId)}
                title={
                  disabled
                    ? "Not used for local / warehouse-pickup plans"
                    : (STEPPER_STEPS.find((s) => s.id === stage.anchorId)?.helper || stage.label)
                }
                className={`rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wide transition disabled:cursor-default ${classes.segment}`}
              >
                {state === "done" ? `✓ ${stage.label}` : stage.label}
              </button>
            </div>
          );
        })}

        <span className="hidden h-px w-3 bg-slate-200 sm:block" aria-hidden />
        <button
          type="button"
          onClick={() => onStepClick?.("dispatch-exceptions")}
          title={STEPPER_STEPS[STEPPER_STEPS.length - 1]?.helper}
          className={`rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wide transition ${
            progressStageClasses(returnsState, { interactive: true }).segment
          }`}
        >
        Delivery Variances
        </button>
      </div>

      {!dense && helper ? (
        <p className="mt-2 text-[11px] font-semibold text-slate-500">{helper}</p>
      ) : null}
      {!dense && skipTransit ? (
        <p className="mt-1 text-[10px] font-semibold text-slate-400">
          In Transit skipped — local stock or warehouse pickup.
        </p>
      ) : null}
    </div>
  );
}

function DispatchStepper({
  status,
  onStepClick,
  localOnly = false,
  fulfillmentType = null,
  vehicleDetails = [],
  timeline = [],
  dense = false,
}) {
  return (
    <DispatchProgressTracker
      variant="editor"
      status={status}
      onStepClick={onStepClick}
      localOnly={localOnly}
      fulfillmentType={fulfillmentType}
      vehicleDetails={vehicleDetails}
      timeline={timeline}
      dense={dense}
    />
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

function YesNo(props) {
  return (
    <select {...props} className="form-input w-full bg-white">
      <option value="">Not yet encoded</option>
      <option value="Yes">Yes</option>
      <option value="No">No</option>
    </select>
  );
}

function StatusTimeline({ status, timeline = [], creatorName = null }) {
  const activeIndex = Math.max(0, TIMELINE.indexOf(status));
  return (
    <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">
          Status timeline
        </p>
        {creatorName ? (
          <p className="text-[11px] font-semibold text-slate-500">
            Created by {creatorName}
          </p>
        ) : null}
      </div>
      <ol className="grid gap-2 md:grid-cols-5">
        {TIMELINE.map((step, index) => {
          const done = index <= activeIndex;
          return (
            <li
              key={step}
              className={`rounded-xl border px-3 py-2 text-center text-[11px] font-black uppercase tracking-wide ${
                done
                  ? "border-emerald-300 bg-emerald-50 text-emerald-800"
                  : "border-slate-200 bg-white text-slate-400"
              }`}
            >
              {STATUS_META[step]?.label || step}
            </li>
          );
        })}
      </ol>
      {timeline?.length > 0 && (
        <ul className="mt-3 space-y-1 text-xs text-slate-500">
          {timeline.slice(-5).map((entry, index) => {
            const fromLabel = entry.from_status
              ? (STATUS_META[entry.from_status]?.label || entry.from_status)
              : null;
            const toLabel = STATUS_META[entry.to_status || entry.status]?.label
              || entry.to_status
              || entry.status;
            return (
              <li key={`${entry.status}-${entry.at}-${index}`}>
                {fromLabel && toLabel && fromLabel !== toLabel
                  ? `${fromLabel} → ${toLabel}`
                  : toLabel}
                {entry.at ? ` · ${formatDateTime(entry.at)}` : ""}
                {entry.by_name ? ` · ${entry.by_name}` : ""}
                {entry.note ? ` — ${entry.note}` : ""}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function PlanHistoryPanel({ open, onClose, dispatch }) {
  if (!open || !dispatch) return null;
  const timeline = dispatch.history || dispatch.status_timeline || [];
  const creator = dispatch.created_by_name || dispatch.creator?.name || "—";

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
      <div className="max-h-[85vh] w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-zinc-950">
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-zinc-800">
          <div>
            <h3 className="font-black text-slate-950 dark:text-white">Plan history</h3>
            <p className="text-xs text-slate-500">
              {dispatch.dispatch_number || "Dispatch plan"} · Created by {creator}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50 dark:border-zinc-700"
            aria-label="Close history"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
        <div className="max-h-[65vh] overflow-y-auto px-5 py-4">
          {timeline.length === 0 ? (
            <p className="text-sm text-slate-500">No history recorded yet.</p>
          ) : (
            <ol className="space-y-3">
              {[...timeline].reverse().map((entry, index) => {
                const fromLabel = entry.from_status
                  ? (STATUS_META[entry.from_status]?.label || entry.from_status)
                  : null;
                const toLabel = STATUS_META[entry.to_status || entry.status]?.label
                  || entry.to_status
                  || entry.status
                  || "Update";
                return (
                  <li
                    key={`${entry.at}-${index}`}
                    className="rounded-xl border border-slate-200 bg-slate-50/80 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900"
                  >
                    <p className="text-sm font-semibold text-slate-900 dark:text-zinc-100">
                      {entry.type === "plan_update"
                        ? "Plan updated"
                        : fromLabel && toLabel && fromLabel !== toLabel
                          ? `${fromLabel} → ${toLabel}`
                          : toLabel}
                    </p>
                    <p className="mt-0.5 text-xs text-slate-500">
                      {entry.at ? formatDateTime(entry.at) : "—"}
                      {entry.by_name ? ` · ${entry.by_name}` : ""}
                    </p>
                    {entry.note ? (
                      <p className="mt-1 text-xs font-semibold text-slate-600 dark:text-zinc-300">
                        {entry.note}
                      </p>
                    ) : null}
                  </li>
                );
              })}
            </ol>
          )}
        </div>
      </div>
    </div>
  );
}

function ReceiptReconciliation({ form, setItem, disabled = false, disabledMessage = "" }) {
  const totals = (form.data.items || []).reduce((summary, item) => {
    const expected = Number(item.allocated_quantity || 0);
    const received = item.received_quantity === "" || item.received_quantity == null
      ? 0
      : Number(item.received_quantity || 0);
    summary.expected += expected;
    summary.received += received;
    summary.outstanding += Math.max(0, expected - received);
    return summary;
  }, { expected: 0, received: 0, outstanding: 0 });
  return (
    <div className="mt-4 overflow-hidden rounded-xl border border-emerald-200 bg-white">
      <div className="border-b border-emerald-100 bg-emerald-50/70 px-4 py-3">
        <p className="text-xs font-black uppercase tracking-wide text-emerald-800">Allocation and Recipient Receipt Reconciliation</p>
        <p className="mt-1 text-xs font-semibold normal-case text-slate-600">Enter the total quantity received by the recipient across all vehicles. Any shortage automatically generates a delivery variance below.</p>
        {disabled && disabledMessage && (
          <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">
            {disabledMessage}
          </p>
        )}
      </div>
      <div className={`grid grid-cols-3 border-b px-4 py-3 text-center text-xs ${totals.outstanding > 0 ? "border-amber-200 bg-amber-50" : "border-emerald-100 bg-emerald-50"}`}>
        <div><span className="block text-[9px] font-black uppercase tracking-wide text-slate-500">Expected</span><strong>{formatWholeQuantity(totals.expected, "0")}</strong></div>
        <div><span className="block text-[9px] font-black uppercase tracking-wide text-slate-500">Received</span><strong>{formatWholeQuantity(totals.received, "0")}</strong></div>
        <div><span className="block text-[9px] font-black uppercase tracking-wide text-slate-500">Outstanding</span><strong className={totals.outstanding > 0 ? "text-amber-700" : "text-emerald-700"}>{formatWholeQuantity(totals.outstanding, "0")}</strong></div>
      </div>
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50 text-left text-[10px] font-black uppercase tracking-wide text-slate-500">
            <tr><th className="px-3 py-2">Item</th><th className="px-3 py-2">Allocated</th><th className="px-3 py-2">Brand</th><th className="px-3 py-2">Expiry</th><th className="px-3 py-2 text-right">Current Stockpile</th><th className="px-3 py-2 text-right">Available to Plan</th><th className="px-3 py-2 text-right">Unit cost</th><th className="px-3 py-2 text-right">Total cost</th><th className="px-3 py-2">Loaded</th><th className="px-3 py-2">Expected</th><th className="min-w-40 px-3 py-2">Received Qty</th><th className="px-3 py-2">Variance</th></tr>
          </thead>
          <tbody>
            {(form.data.items || []).map((item, itemIndex) => {
              const loaded = sumLoadedAcrossVehicles(form.data.vehicle_details, item);
              const expected = Number(item.allocated_quantity || 0);
              const received = item.received_quantity === "" || item.received_quantity == null ? null : Number(item.received_quantity);
              const variance = received == null ? null : Math.max(0, expected - received);
              return <tr key={`receipt-${item.id || itemIndex}`} className="border-t border-slate-100">
                <td className="px-3 py-2 font-bold">{item.item_name}</td>
                <td className="px-3 py-2 tabular-nums">{formatWholeQuantity(item.allocated_quantity, "0")}</td>
                <AllocationStockCells item={item} />
                <AllocationCostCells item={item} />
                <td className="px-3 py-2 tabular-nums">{formatWholeQuantity(loaded, "0")}</td>
                <td className="px-3 py-2 tabular-nums font-bold">{formatWholeQuantity(expected, "0")}</td>
                <td className="px-3 py-2"><Input type="text" inputMode="numeric" disabled={disabled} value={wholeQuantityInputValue(item.received_quantity ?? "")} onChange={(event) => {
                  const entered = coerceWholeQuantity(event.target.value, { min: 0 });
                  const next = entered === "" ? "" : Math.min(entered, expected);
                  setItem(itemIndex, "received_quantity", next === "" ? "" : String(next));
                }} />{form.errors[`items.${itemIndex}.received_quantity`] && <p className="mt-1 text-xs font-semibold text-rose-600">{form.errors[`items.${itemIndex}.received_quantity`]}</p>}</td>
                <td className={`px-3 py-2 tabular-nums font-black ${variance > 0 ? "text-amber-700" : "text-emerald-700"}`}>{variance == null ? "—" : formatWholeQuantity(variance, "0")}</td>
              </tr>;
            })}
            <AllocationTotalsRow items={form.data.items || []} trailingColSpan={4} />
          </tbody>
        </table>
      </div>
    </div>
  );
}

export default function Dispatches({
  bucket = "still_for_action",
  counts = {},
  dispatches,
  eligibleRequests = [],
  selectedDispatch = null,
  sourceRequest = null,
  libraryOptions = {},
  landTransportationSources = [],
  dispatchContactLibraries = {},
  rrosSignatories = [],
  warehouseStock = [],
  warehouseReservations = [],
  warehouseCatalog = {},
  escortWorkspace = false,
  canManageDispatches = false,
}) {
  const authUser = usePage().props.auth?.user;
  const dispatchOfficerName = String(authUser?.name || "").trim();
  const [editorOpen, setEditorOpen] = useState(Boolean(selectedDispatch || sourceRequest));
  const [deliveryModeConfirmation, setDeliveryModeConfirmation] = useState(null);
  const [confirmationAlert, setConfirmationAlert] = useState(null);

  const requestConfirmation = ({ title, message, confirmLabel = "Confirm", tone = "emerald", onConfirm }) => {
    setConfirmationAlert({ title, message, confirmLabel, tone, onConfirm });
  };

  const closeConfirmationAlert = () => setConfirmationAlert(null);

  const acceptConfirmationAlert = () => {
    const action = confirmationAlert?.onConfirm;
    setConfirmationAlert(null);
    if (typeof action === "function") window.setTimeout(action, 0);
  };
  const [risPickerOpen, setRisPickerOpen] = useState(false);
  const [extraVehicleTypes, setExtraVehicleTypes] = useState([]);
  const [creatingVehicleType, setCreatingVehicleType] = useState(false);
  const [vehicleTypeNotice, setVehicleTypeNotice] = useState(null);
  const [vehicleTypeModal, setVehicleTypeModal] = useState({
    open: false,
    vehicleIndex: null,
    name: "",
    is_active: true,
    error: "",
  });
  const [extraLandSources, setExtraLandSources] = useState([]);
  const [creatingLandSource, setCreatingLandSource] = useState(false);
  const [landSourceNotice, setLandSourceNotice] = useState(null);
  const [extraWitOptions, setExtraWitOptions] = useState({ source_of_goods: [], transaction_purpose: [] });
  const [creatingWitOption, setCreatingWitOption] = useState(null);
  const [witOptionNotice, setWitOptionNotice] = useState(null);
  const [extraDrivers, setExtraDrivers] = useState([]);
  const [creatingDriver, setCreatingDriver] = useState(false);
  const [driverNotice, setDriverNotice] = useState(null);
  const [driverModal, setDriverModal] = useState({
    open: false,
    vehicleIndex: null,
    name: "",
    contact_number: "",
    position: "",
    office: "",
    is_active: true,
    error: "",
  });
  const [extraReceivedBy, setExtraReceivedBy] = useState([]);
  const [creatingReceivedBy, setCreatingReceivedBy] = useState(false);
  const [receivedByNotice, setReceivedByNotice] = useState(null);
  const [receivedByModal, setReceivedByModal] = useState({
    open: false,
    vehicleIndex: null,
    name: "",
    position: "",
    office: "",
    is_active: true,
    error: "",
  });
  const [documentPreview, setDocumentPreview] = useState({
    open: false,
    title: "",
    subtitle: null,
    src: null,
    kind: null,
    message: null,
    tabs: null,
    initialTab: null,
  });
  /** Status whose required markers / footer hint are shown (clicked target, else next recommended). */
  const [requirementFocusStatus, setRequirementFocusStatus] = useState(null);
  const [vehicleOpenMap, setVehicleOpenMap] = useState({});
  const [activeVehicleIndex, setActiveVehicleIndex] = useState(0);
  const [forcedStageKeys, setForcedStageKeys] = useState({});
  const [clientMissingHints, setClientMissingHints] = useState([]);
  const [deliveryUpdateForms, setDeliveryUpdateForms] = useState({});
  const [postingDeliveryUpdate, setPostingDeliveryUpdate] = useState(null);
  const [selectedPlanningWarehouseKey, setSelectedPlanningWarehouseKey] = useState("");
  const [dispatchNavCollapsed, setDispatchNavCollapsed] = useState(false);
  const [historyModal, setHistoryModal] = useState({ open: false, dispatch: null });
  const dispatchRealtimeTimer = useRef(null);
  /** Tracks last autofill source key per vehicle so manual witness edits are kept until escort/warehouse changes. */
  const releaseWitnessAutofillRef = useRef({ initialized: false, keys: {} });
  const editing = Boolean(selectedDispatch?.id);
  const showReadyQueue = !escortWorkspace && (bucket === "still_for_action" || bucket === "all");
  const showHeaderCreateCta = showReadyQueue && !editorOpen && eligibleRequests.length > 1;

  useEffect(() => {
    if (escortWorkspace) return undefined;
    const refreshReadyQueue = () => {
      window.clearTimeout(dispatchRealtimeTimer.current);
      dispatchRealtimeTimer.current = window.setTimeout(() => {
        router.reload({
          only: ["eligibleRequests", "counts", "sourceRequest"],
          preserveScroll: true,
          preserveState: true,
        });
      }, 200);
    };
    const stopApproved = listenRealtime("ris.approved", refreshReadyQueue);
    const stopUpdated = listenRealtime("ris.updated", refreshReadyQueue);
    const stopDispatchReady = listenRealtime("dispatch.ready.changed", refreshReadyQueue);
    const stopEpirma = listenRealtime("ris.epirma.status.changed", (payload = {}) => {
      if (payload.workflow?.complete || payload.status === "signed") refreshReadyQueue();
    });
    const stopConnection = listenRealtime("realtime.connection", ({ connected } = {}) => {
      // Reconcile records that may have changed while this tab was disconnected.
      if (connected) refreshReadyQueue();
    });
    const refreshWhenVisible = () => {
      if (document.visibilityState === "visible") refreshReadyQueue();
    };
    document.addEventListener("visibilitychange", refreshWhenVisible);
    return () => {
      stopApproved();
      stopUpdated();
      stopDispatchReady();
      stopEpirma();
      stopConnection();
      document.removeEventListener("visibilitychange", refreshWhenVisible);
      window.clearTimeout(dispatchRealtimeTimer.current);
    };
  }, [escortWorkspace]);

  useEffect(() => {
    if (!selectedDispatch?.id) return undefined;
    const refresh = (payload = {}) => {
      if (Number(payload.dispatch_id) !== Number(selectedDispatch.id)) return;
      router.reload({ only: ["selectedDispatch", "dispatches"], preserveScroll: true, preserveState: true });
    };
    const stop = listenRealtime("dispatch.delivery.update.created", refresh);
    return stop;
  }, [selectedDispatch?.id]);

  const generatedDeliveryMessage = (vehicleIndex, row, stage = "departed", location = "") => {
    const stageText = {
      departed: "departed",
      in_transit: "is currently in transit",
      arrived: "arrived at the delivery site",
      unloading_started: "started unloading",
      unloading_completed: "finished unloading",
      checkpoint: "reached a checkpoint",
      delay: "encountered a delay",
      incident: "reported an incident",
    }[stage] || "provided a delivery update";
    const cargo = (row?.loaded_items || []).map((line, itemIndex) => {
      const quantity = Number(line.loaded_quantity || line.planned_quantity || 0);
      const item = form.data.items?.[itemIndex] || {};
      if (quantity <= 0) return null;
      const rawName = item.item_name || line.item_name || "relief item";
      const itemName = quantity === 1 || /s$/i.test(rawName) ? rawName : `${rawName}s`;
      return `${formatWholeQuantity(quantity, "0")} ${itemName}`;
    }).filter(Boolean);
    const type = String(row?.vehicle_type || "vehicle").trim().toLowerCase();
    const transportSource = String(row?.land_transportation_source || row?.mode_of_transportation || "").toLowerCase();
    const ownership = /contract|hire|service provider/.test(transportSource) ? "hired " : "";
    const plate = row?.vehicle_plate_number ? ` (plate ${row.vehicle_plate_number})` : "";
    const driver = row?.driver ? ` driven by ${row.driver}` : "";
    const origin = ["departed", "in_transit"].includes(stage) && row?.source_warehouse_name ? ` from ${row.source_warehouse_name}` : "";
    const purpose = String(form.data.purpose || selectedDispatch?.request?.purpose || "").trim();
    const destination = String(form.data.destination || "").trim();
    const mission = purpose && destination && ["departed", "in_transit"].includes(stage)
      ? ` for ${purpose} in ${destination}`
      : ["departed", "in_transit"].includes(stage) && purpose ? ` for ${purpose}`
        : ["departed", "in_transit"].includes(stage) && destination ? ` bound for ${destination}`
          : destination ? ` in ${destination}` : "";
    const where = location ? ` Current location: ${location}.` : "";
    const escort = row?.escort_name ? ` Escort: ${row.escort_name}.` : "";
    const load = cargo.length
      ? (["unloading_started", "unloading_completed"].includes(stage)
        ? ` the vehicle's load of ${cargo.join(" and ")}`
        : ` loaded with ${cargo.join(" and ")}`)
      : "";
    const narrative = stage === "unloading_started"
      ? `A ${ownership}${type}${plate}${driver} ${stageText}${load}${mission}. The cargo is being turned over to the receiving team.`
      : stage === "unloading_completed"
        ? `A ${ownership}${type}${plate}${driver} ${stageText}${load}${mission}. The cargo is ready for receipt verification and acknowledgment.`
        : `A ${ownership}${type}${plate}${driver} ${stageText}${origin}${load}${mission}.`;
    return `${narrative}${escort}${where}`.replace(/\s+/g, " ").trim();
  };
  const deliveryStageOptions = [
    { value: "departed", label: "Departed", hint: "Vehicle left the source warehouse" },
    { value: "in_transit", label: "In Transit", hint: "Journey or location update" },
    { value: "arrived", label: "Arrived", hint: "Vehicle reached the delivery site" },
    { value: "unloading_started", label: "Unloading Started", hint: "Cargo unloading has begun" },
    { value: "unloading_completed", label: "Unloading Completed", hint: "Cargo unloading has finished" },
  ];
  const deliveryUpdateForm = (vehicleIndex, row) => deliveryUpdateForms[vehicleIndex] || {
    stage: row?.departed_at ? "in_transit" : "departed",
    occurred_at: row?.departed_at || new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16),
    location: "",
    latitude: "",
    longitude: "",
    message: generatedDeliveryMessage(vehicleIndex, row, row?.departed_at ? "in_transit" : "departed"),
    photos: [],
  };
  const patchDeliveryUpdate = (vehicleIndex, values) => setDeliveryUpdateForms((current) => ({
    ...current,
    [vehicleIndex]: { ...deliveryUpdateForm(vehicleIndex, form.data.vehicle_details?.[vehicleIndex]), ...values },
  }));
  const captureDeliveryLocation = (vehicleIndex) => {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
      async ({ coords }) => {
        const latitude = coords.latitude.toFixed(7);
        const longitude = coords.longitude.toFixed(7);
        let location = `${latitude}, ${longitude}`;
        try {
          const response = await fetch(`/dispatches/reverse-location?latitude=${encodeURIComponent(latitude)}&longitude=${encodeURIComponent(longitude)}`, { headers: { Accept: "application/json" } });
          if (response.ok) location = (await response.json()).location || location;
        } catch { /* coordinates remain a valid fallback */ }
        const row = form.data.vehicle_details?.[vehicleIndex];
        const current = deliveryUpdateForm(vehicleIndex, row);
        patchDeliveryUpdate(vehicleIndex, { latitude, longitude, location, message: generatedDeliveryMessage(vehicleIndex, row, current.stage, location) });
      },
      () => window.dispatchEvent(new CustomEvent("dromis:toast", { detail: { type: "info", title: "Location not captured", message: "Allow location access or enter the location manually." } })),
      { enableHighAccuracy: true, timeout: 10000 },
    );
  };
  const postDeliveryUpdate = (vehicleIndex, row) => {
    const update = deliveryUpdateForm(vehicleIndex, row);
    const vehicleUpdates = (selectedDispatch?.delivery_updates || []).filter((entry) => Number(entry.vehicle_index) === vehicleIndex);
    const hasStage = (stage) => vehicleUpdates.some((entry) => entry.stage === stage);
    const missing = [];
    if (!update.stage) missing.push("Update type");
    if (!update.occurred_at) missing.push("Update date and time");
    if (!String(update.location || "").trim()) missing.push("Location or captured GPS");
    if (String(update.message || "").trim().length < 10) missing.push("Field update message");
    if (!Array.isArray(update.photos) || update.photos.length === 0) missing.push("At least one photo");
    if (update.stage === "arrived" && !row?.departed_at && !hasStage("departed")) missing.push("Departure update before Arrival");
    if (update.stage === "unloading_started" && !hasStage("arrived")) missing.push("Arrival update before Unloading Started");
    if (update.stage === "unloading_completed" && !hasStage("unloading_started")) missing.push("Unloading Started update before Unloading Completed");
    if (missing.length) {
      window.dispatchEvent(new CustomEvent("dromis:toast", { detail: { type: "error", title: "Complete the delivery update", message: `Required: ${missing.join(", ")}.` } }));
      return;
    }
    const data = new FormData();
    const originalVehicleIndex = row?.source_vehicle_index ?? vehicleIndex;
    Object.entries({ ...update, vehicle_index: originalVehicleIndex }).forEach(([key, value]) => {
      if (key !== "photos" && value !== "" && value != null) data.append(key, value);
    });
    update.photos.forEach((photo) => data.append("photos[]", photo));
    setPostingDeliveryUpdate(vehicleIndex);
    router.post(`/dispatches/${selectedDispatch.id}/delivery-updates`, data, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => patchDeliveryUpdate(vehicleIndex, { message: "", location: "", latitude: "", longitude: "", photos: [] }),
      onFinish: () => setPostingDeliveryUpdate(null),
    });
  };

  const closeDocumentPreview = () => {
    setDocumentPreview({
      open: false,
      title: "",
      subtitle: null,
      src: null,
      kind: null,
      message: null,
      tabs: null,
      initialTab: null,
    });
  };

  const openReadyDocuments = (row) => {
    const built = buildRrosDocumentPreviewTabs(row);
    // DRs are vehicle-specific and are opened only from their vehicle action.
    const availableTabs = (built.tabs || []).filter((tab) => tab?.key !== "dr" && Boolean(tab?.src || tab?.panel));
    const previewTabs = availableTabs.length > 0 ? availableTabs : built.tabs;
    const initialTab = previewTabs.some((tab) => tab.key === built.initialTab)
      ? built.initialTab
      : previewTabs[0]?.key || null;
    setDocumentPreview({
      open: true,
      title: row.reference_number || "Document preview",
      subtitle: row.requesting_agency || row.lgu || null,
      src: null,
      kind: null,
      message: null,
      initialTab,
      tabs: previewTabs,
    });
  };

  const openDispatchDocuments = (dispatch) => {
    const source = dispatch.document_preview || dispatch.request || dispatch;
    const built = buildRrosDocumentPreviewTabs(source);
    const baseTabs = (built.tabs || []).filter((tab) => tab?.key !== "dr" && Boolean(tab?.src || tab?.panel));
    const drTabs = String(dispatch.status || "draft") === "draft"
      ? []
      : (dispatch.dr_documents || [])
        .filter((document) => document?.preview_url)
        .map((document, index) => ({
          key: `dr-${index + 1}`,
          label: document.dr_number || `DR ${index + 1}`,
          src: document.preview_url,
          kind: "DR",
          message: document.vehicle
            ? `Delivery Receipt for ${document.vehicle}.`
            : "Delivery Receipt for the local warehouse release.",
        }));
    const tabs = [...baseTabs, ...drTabs];
    setDocumentPreview({
      open: true,
      title: dispatch.dispatch_number || source.reference_number || "Dispatch documents",
      subtitle: dispatch.request?.reference_number || dispatch.destination || null,
      src: null,
      kind: null,
      message: null,
      initialTab: tabs[0]?.key || null,
      tabs,
    });
  };

  const openVehicleDr = (vehicle, index) => {
    if (!selectedDispatch?.id || !vehicle?.dr_number) return;
    if (!vehicleIsAssignedForDr(vehicle)) {
      window.dispatchEvent(new CustomEvent("dromis:toast", { detail: {
        type: "info",
        title: "Vehicle assignment required",
        message: vehicleDrAssignmentHint(vehicle),
      } }));
      return;
    }
    const savedVehicles = selectedDispatch?.vehicle_details || [];
    const savedVehicle = savedVehicles[index];
    const numberingIsSaved = savedVehicles.length === (form.data.vehicle_details || []).length
      && String(savedVehicle?.dr_number || "") === String(vehicle.dr_number || "");
    if (!numberingIsSaved) {
      window.dispatchEvent(new CustomEvent("dromis:toast", { detail: {
        type: "info",
        title: "Save the vehicle assignment first",
        message: `${vehicle.dr_number} is reserved for this vehicle. Save Draft to generate and preview its updated DR.`,
      } }));
      return;
    }
    setDocumentPreview({
      open: true,
      title: vehicle.dr_number,
      subtitle: `Vehicle ${index + 1}${vehicle.vehicle_plate_number ? ` · ${vehicle.vehicle_plate_number}` : ""}`,
      src: `/dispatches/${selectedDispatch.id}/vehicles/${index}/dr`,
      kind: "DR",
      message: "This Delivery Receipt belongs only to this vehicle. Its item quantities, driver, plate number, and escort update from the saved dispatch record.",
      tabs: null,
      initialTab: null,
    });
  };

  const openSavedVehicleDr = (document) => {
    if (!document?.preview_url || !document?.dr_number) return;
    setDocumentPreview({
      open: true,
      title: document.dr_number,
      subtitle: [document.vehicle, document.driver].filter(Boolean).join(" · ") || `Vehicle ${Number(document.vehicle_index || 0) + 1}`,
      src: document.preview_url,
      kind: "DR",
      message: "Vehicle-specific Delivery Receipt. Only this vehicle’s items and transport details are included.",
      tabs: null,
      initialTab: null,
    });
  };

  const openLocalHandoverDr = () => {
    const handover = selectedDispatch?.local_handover_details || {};
    if (!selectedDispatch?.id || !handover.dr_number) {
      window.dispatchEvent(new CustomEvent("dromis:toast", { detail: {
        type: "info",
        title: "Save the local release first",
        message: "Save Draft to assign and generate the no-transport Delivery Receipt.",
      } }));
      return;
    }
    setDocumentPreview({
      open: true,
      title: handover.dr_number,
      subtitle: `${handover.source_warehouse_name || "Local warehouse"} · No transport needed`,
      src: `/dispatches/${selectedDispatch.id}/local-handover/dr`,
      kind: "DR",
      message: "This Delivery Receipt covers only the stock released at the recipient-held warehouse. Transporter, driver, vehicle, and escort fields are intentionally blank.",
      tabs: null,
      initialTab: null,
    });
  };

  const initial = useMemo(() => {
    const base = selectedDispatch
      ? formFromDispatch(selectedDispatch)
      : sourceRequest
        ? formFromSourceRequest(sourceRequest)
        : emptyForm();
    return {
      ...base,
      // Always the logged-in user filling/saving the plan (not free-text).
      dispatch_officer: dispatchOfficerName,
    };
  }, [selectedDispatch, sourceRequest, dispatchOfficerName]);

  const form = useForm(initial);

  const planStatus = form.data.status || "draft";

  const initialHasLocalAllocation = uniqueSourceWarehouses(
    initial.items || [],
    warehouseCatalog,
    {
      lgu: (selectedDispatch?.request || sourceRequest)?.lgu || "",
      municipality: (selectedDispatch?.request || sourceRequest)?.municipality || "",
      province: (selectedDispatch?.request || sourceRequest)?.province || "",
      requesting_agency: (selectedDispatch?.request || sourceRequest)?.requesting_agency || "",
      receiving_agency_lgu: initial.receiving_agency_lgu || "",
      recipient: (selectedDispatch?.ris || sourceRequest?.ris)?.recipient || "",
    },
  ).some((warehouse) => !warehouseRequiresTransport(warehouse));

  useEffect(() => {
    const primeReceivingContext = {
      lgu: (selectedDispatch?.request || sourceRequest)?.lgu || "",
      municipality: (selectedDispatch?.request || sourceRequest)?.municipality || "",
      province: (selectedDispatch?.request || sourceRequest)?.province || "",
      lgu_level: (selectedDispatch?.request || sourceRequest)?.lgu_level || "",
      requesting_agency: (selectedDispatch?.request || sourceRequest)?.requesting_agency || "",
      receiving_agency_lgu: initial.receiving_agency_lgu || "",
      recipient: (selectedDispatch?.ris || sourceRequest?.ris)?.recipient || "",
      receiving_representative_office:
        (selectedDispatch?.ris || sourceRequest?.ris)?.receiving_representative_office || "",
      recipient_label:
        initial.receiving_agency_lgu
        || (selectedDispatch?.ris || sourceRequest?.ris)?.recipient
        || (selectedDispatch?.request || sourceRequest)?.requesting_agency
        || "",
    };
    const sharedRemarks =
      String(
        (initial.vehicle_details || []).find((row) => String(row.loading_remarks || "").trim())
          ?.loading_remarks
        || initial.local_handover_details?.remarks
        || (selectedDispatch?.ris || sourceRequest?.ris)?.purpose_of_release
        || initial.purpose
        || "",
      ).trim();
    const basePrimedVehicles = (initial.vehicle_details || []).map((row) => {
      const autofill = resolveReleaseWitnessAutofill(
        row,
        warehouseCatalog,
        primeReceivingContext,
      ).fields;
      const withRemarks = {
        ...row,
        loading_remarks: sharedRemarks || row.loading_remarks || "",
      };
      if (row.release_witness_affiliation === "lgu") {
        return {
          ...withRemarks,
          release_witnessed_by:
            row.release_witnessed_by || autofill.release_witnessed_by || "",
          release_witness_contact_number:
            row.release_witness_contact_number || autofill.release_witness_contact_number || "",
          release_witness_position:
            row.release_witness_position || autofill.release_witness_position || "LGU Employee",
          release_witness_office:
            resolveLguWitnessOffice(row, primeReceivingContext)
            || autofill.release_witness_office
            || "",
        };
      }
      if (!isReleaseWitnessBlank(row)) {
        return {
          ...withRemarks,
          release_witness_contact_number:
            row.release_witness_contact_number || autofill.release_witness_contact_number || "",
          release_witness_position:
            row.release_witness_position || autofill.release_witness_position || "",
          release_witness_office:
            row.release_witness_office || autofill.release_witness_office || "",
        };
      }
      return {
        ...withRemarks,
        ...autofill,
      };
    });
    const risPayload = selectedDispatch?.ris || sourceRequest?.ris || {};
    const initialHandoverReceipt = {
      received_by: initial.local_handover_details?.received_by || "",
      receiver_contact: initial.local_handover_details?.receiver_contact || "",
      received_by_id_number: initial.local_handover_details?.receiver_id_number || "",
      received_by_position: initial.local_handover_details?.receiver_position || "",
      received_by_office: initial.local_handover_details?.receiver_office || "",
    };
    const savedReceipt = [...basePrimedVehicles, initialHandoverReceipt].find((row) =>
      String(row.received_by || "").trim(),
    ) || {};
    const canonicalReceipt = applyRisReceiptDefaults(savedReceipt, risPayload);
    if (
      canonicalReceipt.received_by
      && (!canonicalReceipt.received_by_office
        || isRegionalFoOfficeLabel(canonicalReceipt.received_by_office))
    ) {
      canonicalReceipt.received_by_office = lguWitnessOfficeName(primeReceivingContext);
    }
    const primedVehicles = basePrimedVehicles.map((row) => ({
      ...row,
      ...(canonicalReceipt.received_by
        ? {
            received_by: canonicalReceipt.received_by,
            receiver_contact: canonicalReceipt.receiver_contact || "",
            received_by_id_number: canonicalReceipt.received_by_id_number || "",
            received_by_position: canonicalReceipt.received_by_position || "",
            received_by_office: canonicalReceipt.received_by_office || "",
          }
        : {}),
    }));
    const numberedVehicles = assignVehicleDrNumbers(
      primedVehicles,
      selectedDispatch?.ris?.dr_number || sourceRequest?.ris?.dr_number || "",
      Number(selectedDispatch?.dr_series_offset || 0),
      Number(selectedDispatch?.delivery_sequence || 1) > 1 || initialHasLocalAllocation,
    );
    const primedHandover = {
      ...(initial.local_handover_details || {}),
      dr_number: initial.local_handover_details?.released_at
        ? initial.local_handover_details?.dr_number || ""
        : "",
      ...(sharedRemarks
        ? { remarks: sharedRemarks }
        : {}),
      ...(canonicalReceipt.received_by
        ? {
            received_by: canonicalReceipt.received_by,
            receiver_contact: canonicalReceipt.receiver_contact || "",
            receiver_id_number: canonicalReceipt.received_by_id_number || "",
            receiver_position: canonicalReceipt.received_by_position || "",
            receiver_office: canonicalReceipt.received_by_office || "",
          }
        : {}),
      ...(initial.local_handover_details?.release_witness_affiliation === "lgu"
        ? {
            releaser_office:
              resolveLguWitnessOffice(
                { releaser_office: initial.local_handover_details?.releaser_office },
                primeReceivingContext,
              )
              || initial.local_handover_details?.releaser_office
              || "",
          }
        : {}),
    };
    const primed = {
      ...initial,
      vehicle_details: numberedVehicles,
      local_handover_details: primedHandover,
      ...mirrorLegacyVehicleFields(numberedVehicles),
    };
    form.setData(primed);
    releaseWitnessAutofillRef.current = {
      initialized: true,
      keys: Object.fromEntries(
        numberedVehicles.map((row, index) => [
          index,
          resolveReleaseWitnessAutofill(row, warehouseCatalog, primeReceivingContext).key,
        ]),
      ),
    };
    setEditorOpen(Boolean(selectedDispatch || sourceRequest));
    setRequirementFocusStatus(null);
    setClientMissingHints([]);
    setForcedStageKeys({});
    setVehicleOpenMap({});
    setActiveVehicleIndex(0);
    setSelectedPlanningWarehouseKey("");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    selectedDispatch?.id,
    selectedDispatch?.status,
    selectedDispatch?.updated_at,
    sourceRequest?.id,
    dispatchOfficerName,
  ]);

  const scrollToStep = (stepId) => {
    if (typeof document === "undefined") return;
    const el = document.getElementById(stepId);
    if (el?.scrollIntoView) {
      el.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  };

  const isVehicleOpen = (index) => {
    if (Object.prototype.hasOwnProperty.call(vehicleOpenMap, index)) {
      return Boolean(vehicleOpenMap[index]);
    }
    return index === 0 || (form.data.vehicle_details || []).length <= 2;
  };

  const softReceiptWarnings = () => {
    const warnings = [];
    (form.data.items || []).forEach((item) => {
      const received = item.received_quantity;
      if (received === "" || received == null) return;
      const sourceWh = sourceWarehouses.find((wh) =>
        itemMatchesSourceWarehouse(item, wh.id, wh.name),
      );
      if (sourceWh && !warehouseRequiresTransport(sourceWh)) {
        return;
      }
      const loaded = sumLoadedAcrossVehicles(form.data.vehicle_details, item);
      if (Number(received) > loaded) {
        warnings.push(
          `${item.item_name || "Item"}: received qty (${received}) is greater than loaded (${loaded}).`,
        );
      }
    });
    const anyFullyYes = (form.data.vehicle_details || []).some(
      (row) => yesNoValue(row?.fully_delivered) === "Yes",
    );
    if (anyFullyYes) {
      const blankReceived = (form.data.items || []).filter(
        (item) => item.received_quantity === "" || item.received_quantity == null,
      );
      if (blankReceived.length > 0) {
        warnings.push(
          "Fully delivered is Yes on a vehicle, but one or more received quantities are still blank.",
        );
      }
    }
    return warnings;
  };

  const vehicleTypeOptions = useMemo(() => {
    const rows = [...(libraryOptions.vehicle_type ?? []), ...extraVehicleTypes];
    const seen = new Set();
    return rows
      .map((value) =>
        typeof value === "string"
          ? { value, label: value }
          : {
              value: value.value || value.label || value,
              label: value.label || value.value || value,
            },
      )
      .filter((option) => {
        const key = String(option.value || "")
          .trim()
          .toLowerCase();
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
      });
  }, [libraryOptions.vehicle_type, extraVehicleTypes]);

  const landSourceOptions = useMemo(() => [...landTransportationSources, ...extraLandSources]
    .filter((value, index, rows) => value && rows.findIndex((item) => String(item).toLowerCase() === String(value).toLowerCase()) === index)
    .map((value) => ({ value, label: value })), [landTransportationSources, extraLandSources]);

  const witClassificationOptions = (libraryType) => [
    ...(libraryOptions[libraryType] || []),
    ...(extraWitOptions[libraryType] || []),
  ].filter((value, index, rows) => value && rows.findIndex((item) => String(item).toLowerCase() === String(value).toLowerCase()) === index)
    .map((value) => ({ value, label: value }));

  const createWitClassificationOption = async (libraryType, formField, rawValue) => {
    const name = String(rawValue || "").trim().replace(/\s+/g, " ");
    if (!name) return;
    setCreatingWitOption(libraryType);
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const response = await fetch("/dispatches/wit-classification-options", {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrf, "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify({ library_type: libraryType, value: name }),
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload?.errors?.value?.[0] || payload?.message || "Could not add the option.");
      const value = String(payload.value || name);
      setExtraWitOptions((current) => ({
        ...current,
        [libraryType]: (current[libraryType] || []).some((item) => String(item).toLowerCase() === value.toLowerCase())
          ? current[libraryType]
          : [...(current[libraryType] || []), value],
      }));
      form.setData(formField, value);
      setWitOptionNotice({ libraryType, type: "success", message: `${value} added and selected.` });
    } catch (error) {
      setWitOptionNotice({ libraryType, type: "error", message: error.message || "Could not add the option." });
    } finally {
      setCreatingWitOption(null);
    }
  };

  const createLandSource = async (vehicleIndex, rawName) => {
    const name = String(rawName || "").trim().replace(/\s+/g, " ");
    if (!name) return;
    setCreatingLandSource(true);
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const response = await fetch("/dispatches/land-transportation-sources", {
        method: "POST", credentials: "same-origin",
        headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrf, "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify({ value: name }),
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload?.errors?.value?.[0] || payload?.message || "Could not add the source.");
      const value = String(payload.value || name);
      setExtraLandSources((rows) => rows.includes(value) ? rows : [...rows, value]);
      setVehicleDetail(vehicleIndex, "land_transportation_source", value);
      setLandSourceNotice({ vehicleIndex, type: "success", message: `${value} added.` });
    } catch (error) {
      setLandSourceNotice({ vehicleIndex, type: "error", message: error.message || "Could not add the source." });
    } finally {
      setCreatingLandSource(false);
    }
  };

  useEffect(() => {
    if (!vehicleTypeNotice) return undefined;
    const timer = window.setTimeout(() => setVehicleTypeNotice(null), 4000);
    return () => window.clearTimeout(timer);
  }, [vehicleTypeNotice]);

  useEffect(() => {
    if (!landSourceNotice) return undefined;
    const timer = window.setTimeout(() => setLandSourceNotice(null), 4000);
    return () => window.clearTimeout(timer);
  }, [landSourceNotice]);

  useEffect(() => {
    if (!witOptionNotice) return undefined;
    const timer = window.setTimeout(() => setWitOptionNotice(null), 4000);
    return () => window.clearTimeout(timer);
  }, [witOptionNotice]);

  const applyCreatedVehicleType = (vehicleIndex, typeName) => {
    const name = String(typeName || "").trim();
    if (!name) return;
    setExtraVehicleTypes((current) =>
      current.some((item) => String(item).trim().toLowerCase() === name.toLowerCase())
        ? current
        : [...current, name],
    );
    if (vehicleIndex !== null && vehicleIndex !== undefined) {
      setVehicleDetail(vehicleIndex, "vehicle_type", name);
    }
    setVehicleTypeNotice({
      type: "success",
      vehicleIndex,
      message: `Vehicle type “${name}” added.`,
    });
  };

  const createVehicleType = async (vehicleIndex, rawName, isActive = true) => {
    const name = String(rawName || "").trim().replace(/\s+/g, " ");
    if (!name) {
      return { ok: false, error: "Vehicle type name is required." };
    }

    const existing = vehicleTypeOptions.find(
      (option) => String(option.value || "").trim().toLowerCase() === name.toLowerCase(),
    );
    if (existing) {
      setVehicleDetail(vehicleIndex, "vehicle_type", existing.value);
      setVehicleTypeNotice({
        type: "success",
        vehicleIndex,
        message: `Selected existing vehicle type “${existing.value}”.`,
      });
      return { ok: true, value: existing.value };
    }

    setCreatingVehicleType(true);
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const response = await fetch("/dispatches/vehicle-types", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": csrf,
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({ value: name, is_active: Boolean(isActive) }),
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        const message =
          payload?.errors?.value?.[0]
          || payload?.message
          || "Could not add vehicle type.";
        return { ok: false, error: message };
      }
      const created = String(payload.value || name).trim();
      applyCreatedVehicleType(vehicleIndex, created);
      return { ok: true, value: created };
    } catch {
      return { ok: false, error: "Could not add vehicle type. Check your connection and try again." };
    } finally {
      setCreatingVehicleType(false);
    }
  };

  const openVehicleTypeModal = (vehicleIndex, suggestedName = "") => {
    setVehicleTypeModal({
      open: true,
      vehicleIndex,
      name: String(suggestedName || "").trim(),
      is_active: true,
      error: "",
    });
  };

  const submitVehicleTypeModal = async () => {
    const result = await createVehicleType(
      vehicleTypeModal.vehicleIndex,
      vehicleTypeModal.name,
      vehicleTypeModal.is_active,
    );
    if (!result.ok) {
      setVehicleTypeModal((current) => ({ ...current, error: result.error || "Could not add vehicle type." }));
      return;
    }
    setVehicleTypeModal({
      open: false,
      vehicleIndex: null,
      name: "",
      is_active: true,
      error: "",
    });
  };

  const driverOptions = useMemo(() => {
    const rows = [
      ...(dispatchContactLibraries.dispatch_driver || []),
      ...extraDrivers,
    ];
    const seen = new Set();
    return rows
      .map((row) => {
        if (typeof row === "string") {
          const name = personNameOnly(row);
          return { value: name, label: name, description: "", contact_number: "", position: "", office: "" };
        }
        const name = personNameOnly(row.value || row.label || "");
        const contact = String(
          row.contact_number
          || row.metadata?.contact_number
          || "",
        ).trim();
        const position = String(row.position || row.metadata?.position || "").trim();
        const office = String(row.office || row.metadata?.office || "").trim();
        return {
          value: name,
          label: name,
          description: contact || "",
          contact_number: contact,
          position,
          office,
          metadata: row.metadata || {},
        };
      })
      .filter((option) => {
        const key = String(option.value || "").trim().toLowerCase();
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
      });
  }, [dispatchContactLibraries.dispatch_driver, extraDrivers]);

  const receivedByOptions = useMemo(() => {
    const rows = [
      ...(dispatchContactLibraries.dispatch_received_by || []),
      ...extraReceivedBy,
    ];
    const seen = new Set();
    return rows
      .map((row) => {
        if (typeof row === "string") {
          const name = personNameOnly(row);
          return {
            value: name,
            label: name,
            description: "",
            contact_number: "",
            id_number: "",
            position: "",
            office: "",
          };
        }
        const name = personNameOnly(row.value || row.label || "");
        const position = String(row.position || row.metadata?.position || "").trim();
        const office = String(row.office || row.metadata?.office || "").trim();
        const contact = String(
          row.contact_number
          || row.metadata?.contact_number
          || "",
        ).trim();
        const idNumber = String(
          row.id_number
          || row.metadata?.id_number
          || "",
        ).trim();
        const detail = [position, office].filter(Boolean).join(" · ");
        return {
          value: name,
          label: name,
          description: detail,
          contact_number: contact,
          id_number: idNumber,
          position,
          office,
          metadata: row.metadata || {},
        };
      })
      .filter((option) => {
        const key = String(option.value || "").trim().toLowerCase();
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
      });
  }, [dispatchContactLibraries.dispatch_received_by, extraReceivedBy]);

  // Backfill empty recipient receipt metadata from RIS / Received By library once options are ready.
  useEffect(() => {
    if (!editorOpen) return;
    const risPayload = selectedDispatch?.ris || sourceRequest?.ris || {};
    const rows = form.data.vehicle_details || [];
    const handover = form.data.local_handover_details || {};
    const handoverReceipt = {
      received_by: handover.received_by || "",
      receiver_contact: handover.receiver_contact || "",
      received_by_id_number: handover.receiver_id_number || "",
      received_by_position: handover.receiver_position || "",
      received_by_office: handover.receiver_office || "",
    };
    // A dispatch can have one receipt block per source warehouse. Reuse the
    // completed representative details from another warehouse before falling
    // back to RIS/library data, matching the local warehouse handover flow.
    const peerReceipt = [...rows, handoverReceipt].find((candidate) =>
      String(candidate?.received_by || "").trim()
      && (
        String(candidate?.receiver_contact || "").trim()
        || String(candidate?.received_by_id_number || "").trim()
        || String(candidate?.received_by_position || "").trim()
        || String(candidate?.received_by_office || "").trim()
      ),
    ) || {};
    const receivingOfficeContext = {
      lgu: (selectedDispatch?.request || sourceRequest)?.lgu || "",
      municipality: (selectedDispatch?.request || sourceRequest)?.municipality || "",
      province: (selectedDispatch?.request || sourceRequest)?.province || "",
      lgu_level: (selectedDispatch?.request || sourceRequest)?.lgu_level || "",
      requesting_agency: (selectedDispatch?.request || sourceRequest)?.requesting_agency || "",
      receiving_agency_lgu: form.data.receiving_agency_lgu || "",
      recipient: risPayload.recipient || "",
      receiving_representative_office: risPayload.receiving_representative_office || "",
    };
    let changed = false;
    const next = rows.map((row) => {
      const name = personNameOnly(peerReceipt.received_by || "")
        || personNameOnly(risPayload.receiving_representative || "");
      if (!name) return row;
      const match = findReceivedByLibraryMatch(name, receivedByOptions);
      const peerDefaults = {
        ...row,
        received_by: peerReceipt.received_by || "",
        receiver_contact: peerReceipt.receiver_contact || "",
        received_by_id_number: peerReceipt.received_by_id_number || "",
        received_by_position: peerReceipt.received_by_position || "",
        received_by_office:
          isRegionalFoOfficeLabel(peerReceipt.received_by_office)
            ? ""
            : peerReceipt.received_by_office || "",
      };
      const updated = applyRisReceiptDefaults(peerDefaults, risPayload, match);
      if (!updated.received_by_office || isRegionalFoOfficeLabel(updated.received_by_office)) {
        updated.received_by_office = lguWitnessOfficeName(receivingOfficeContext);
      }
      if (
        updated.received_by !== row.received_by
        || updated.receiver_contact !== row.receiver_contact
        || updated.received_by_position !== row.received_by_position
        || updated.received_by_office !== row.received_by_office
        || updated.received_by_id_number !== row.received_by_id_number
      ) {
        changed = true;
        return updated;
      }
      return row;
    });
    const canonical = next.find((row) => String(row.received_by || "").trim())
      || (String(peerReceipt.received_by || "").trim() ? peerReceipt : null);
    const nextHandover = canonical
      ? {
          ...handover,
          received_by: canonical.received_by || "",
          receiver_contact: canonical.receiver_contact || "",
          receiver_id_number: canonical.received_by_id_number || "",
          receiver_position: canonical.received_by_position || "",
          receiver_office: canonical.received_by_office || "",
        }
      : handover;
    const handoverChanged = nextHandover.received_by !== handover.received_by
      || nextHandover.receiver_contact !== handover.receiver_contact
      || nextHandover.receiver_id_number !== handover.receiver_id_number
      || nextHandover.receiver_position !== handover.receiver_position
      || nextHandover.receiver_office !== handover.receiver_office;
    if (!changed && !handoverChanged) return;
    form.setData({
      ...form.data,
      vehicle_details: next,
      local_handover_details: nextHandover,
      ...mirrorLegacyVehicleFields(next),
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    editorOpen,
    receivedByOptions,
    selectedDispatch?.id,
    selectedDispatch?.ris?.receiving_representative,
    selectedDispatch?.ris?.receiving_representative_position,
    sourceRequest?.id,
    sourceRequest?.ris?.receiving_representative,
    sourceRequest?.ris?.receiving_representative_position,
    sourceRequest?.ris?.receiving_representative_office,
    selectedDispatch?.ris?.receiving_representative_office,
    form.data.receiving_agency_lgu,
    JSON.stringify((form.data.vehicle_details || []).map((row) => [
      row.received_by,
      row.receiver_contact,
      row.received_by_id_number,
      row.received_by_position,
      row.received_by_office,
    ])),
    JSON.stringify([
      form.data.local_handover_details?.received_by,
      form.data.local_handover_details?.receiver_contact,
      form.data.local_handover_details?.receiver_id_number,
      form.data.local_handover_details?.receiver_position,
      form.data.local_handover_details?.receiver_office,
    ]),
  ]);

  // Same RIS / Received By library prefill for local warehouse custody receipt.
  useEffect(() => {
    if (!editorOpen) return;
    const risPayload = selectedDispatch?.ris || sourceRequest?.ris || {};
    const handover = form.data.local_handover_details || {};
    const name = personNameOnly(handover.received_by || "")
      || personNameOnly(risPayload.receiving_representative || "");
    if (!name) return;
    const needsMeta =
      !String(handover.received_by || "").trim()
      || !String(handover.receiver_contact || "").trim()
      || !String(handover.receiver_position || "").trim()
      || !String(handover.receiver_office || "").trim()
      || !String(handover.receiver_id_number || "").trim()
      || isRegionalFoOfficeLabel(handover.receiver_office);
    if (!needsMeta) return;
    const match = findReceivedByLibraryMatch(name, receivedByOptions);
    const updated = applyLocalHandoverReceiptDefaults(
      handover,
      risPayload,
      match,
      {
        lgu: (selectedDispatch?.request || sourceRequest)?.lgu || "",
        municipality: (selectedDispatch?.request || sourceRequest)?.municipality || "",
        province: (selectedDispatch?.request || sourceRequest)?.province || "",
        lgu_level: (selectedDispatch?.request || sourceRequest)?.lgu_level || "",
        requesting_agency: (selectedDispatch?.request || sourceRequest)?.requesting_agency || "",
        receiving_agency_lgu: form.data.receiving_agency_lgu || "",
        recipient: risPayload.recipient || "",
      },
    );
    if (
      updated.received_by === handover.received_by
      && updated.receiver_contact === handover.receiver_contact
      && updated.receiver_position === handover.receiver_position
      && updated.receiver_office === handover.receiver_office
      && updated.receiver_id_number === handover.receiver_id_number
    ) {
      return;
    }
    form.setData("local_handover_details", updated);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    editorOpen,
    receivedByOptions,
    selectedDispatch?.id,
    selectedDispatch?.ris?.receiving_representative,
    selectedDispatch?.ris?.receiving_representative_office,
    sourceRequest?.id,
    sourceRequest?.ris?.receiving_representative,
    form.data.receiving_agency_lgu,
  ]);

  useEffect(() => {
    if (!driverNotice) return undefined;
    const timer = window.setTimeout(() => setDriverNotice(null), 4000);
    return () => window.clearTimeout(timer);
  }, [driverNotice]);

  useEffect(() => {
    if (!receivedByNotice) return undefined;
    const timer = window.setTimeout(() => setReceivedByNotice(null), 4000);
    return () => window.clearTimeout(timer);
  }, [receivedByNotice]);

  const applyCreatedDriver = (vehicleIndex, payload) => {
    const name = String(payload?.value || payload?.name || "").trim();
    if (!name) return;
    const contact = String(payload?.contact_number || "").trim();
    const position = String(payload?.position || "").trim();
    const office = String(payload?.office || "").trim();
    setExtraDrivers((current) => {
      const exists = current.some(
        (item) => String(item.value || item).trim().toLowerCase() === name.toLowerCase(),
      );
      if (exists) return current;
      return [
        ...current,
        {
          value: name,
          label: name,
          contact_number: contact,
          position,
          office,
          metadata: { contact_number: contact, position, office },
        },
      ];
    });
    if (vehicleIndex !== null && vehicleIndex !== undefined) {
      patchVehicleDetail(vehicleIndex, {
        driver: name,
        driver_contact_number: contact,
        driver_id_number: "",
        driver_position: position,
        driver_office: office,
      });
    }
    setDriverNotice({
      type: "success",
      vehicleIndex,
      message: `Driver “${name}” added.`,
    });
  };

  const createDriver = async (
    vehicleIndex,
    rawName,
    rawContact = "",
    rawPosition = "",
    rawOffice = "",
    isActive = true,
  ) => {
    const name = String(rawName || "").trim().replace(/\s+/g, " ");
    const contact = String(rawContact || "").trim().replace(/\s+/g, " ");
    const position = String(rawPosition || "").trim().replace(/\s+/g, " ");
    const office = String(rawOffice || "").trim().replace(/\s+/g, " ");
    if (!name) {
      return { ok: false, error: "Driver name is required." };
    }
    if (!contact) {
      return { ok: false, error: "Driver contact number is required." };
    }

    const existing = driverOptions.find(
      (option) => String(option.value || "").trim().toLowerCase() === name.toLowerCase(),
    );
    if (existing) {
      patchVehicleDetail(vehicleIndex, {
        driver: existing.value,
        driver_contact_number: existing.contact_number || contact || "",
        driver_id_number: "",
        driver_position: existing.position || position || "",
        driver_office: existing.office || office || "",
      });
      setDriverNotice({
        type: "success",
        vehicleIndex,
        message: `Selected existing driver “${existing.value}”.`,
      });
      return { ok: true, value: existing.value };
    }

    setCreatingDriver(true);
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const response = await fetch("/dispatches/drivers", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": csrf,
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          name,
          contact_number: contact,
          position: position || null,
          office: office || null,
          is_active: Boolean(isActive),
        }),
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        const message =
          payload?.errors?.contact_number?.[0]
          || payload?.errors?.name?.[0]
          || payload?.message
          || "Could not add driver.";
        return { ok: false, error: message };
      }
      applyCreatedDriver(vehicleIndex, payload);
      return { ok: true, value: payload.value || name };
    } catch {
      return { ok: false, error: "Could not add driver. Check your connection and try again." };
    } finally {
      setCreatingDriver(false);
    }
  };

  const openDriverModal = (vehicleIndex, suggestedName = "") => {
    setDriverModal({
      open: true,
      vehicleIndex,
      name: String(suggestedName || "").trim(),
      contact_number: "",
      position: "",
      office: "",
      is_active: true,
      error: "",
    });
  };

  const submitDriverModal = async () => {
    const result = await createDriver(
      driverModal.vehicleIndex,
      driverModal.name,
      driverModal.contact_number,
      driverModal.position,
      driverModal.office,
      driverModal.is_active,
    );
    if (!result.ok) {
      setDriverModal((current) => ({ ...current, error: result.error || "Could not add driver." }));
      return;
    }
    setDriverModal({
      open: false,
      vehicleIndex: null,
      name: "",
      contact_number: "",
      position: "",
      office: "",
      is_active: true,
      error: "",
    });
  };

  const applyCreatedReceivedBy = (vehicleIndex, payload) => {
    const name = String(payload?.value || payload?.name || "").trim();
    if (!name) return;
    const position = String(payload?.position || "").trim();
    const office = String(payload?.office || "").trim();
    const contact = String(payload?.contact_number || "").trim();
    const idNumber = String(payload?.id_number || "").trim();
    setExtraReceivedBy((current) => {
      const exists = current.some(
        (item) => String(item.value || item).trim().toLowerCase() === name.toLowerCase(),
      );
      if (exists) return current;
      return [
        ...current,
        {
          value: name,
          label: name,
          position,
          office,
          contact_number: contact,
          id_number: idNumber,
          metadata: { position, office, contact_number: contact, id_number: idNumber },
        },
      ];
    });
    if (vehicleIndex === "local") {
      patchLocalHandover({
        received_by: name,
        receiver_position: position,
        receiver_office: office,
        ...(contact ? { receiver_contact: contact } : {}),
        ...(idNumber ? { receiver_id_number: idNumber } : {}),
      });
    } else if (vehicleIndex !== null && vehicleIndex !== undefined) {
      patchRecipientDetailsForAllVehicles({
        received_by: name,
        received_by_position: position,
        received_by_office: office,
        receiver_contact: contact,
        received_by_id_number: idNumber,
      });
    }
    setReceivedByNotice({
      type: "success",
      vehicleIndex,
      message: `Received By “${name}” added.`,
    });
  };

  const createReceivedBy = async (vehicleIndex, rawName, rawPosition = "", rawOffice = "", isActive = true) => {
    const name = String(rawName || "").trim().replace(/\s+/g, " ");
    const position = String(rawPosition || "").trim().replace(/\s+/g, " ");
    const office = String(rawOffice || "").trim().replace(/\s+/g, " ");
    if (!name) {
      return { ok: false, error: "Name is required." };
    }

    const existing = receivedByOptions.find(
      (option) => String(option.value || "").trim().toLowerCase() === name.toLowerCase(),
    );
    if (existing) {
      if (vehicleIndex === "local") {
        patchLocalHandover({
          received_by: existing.value,
          receiver_position: existing.position || position || "",
          receiver_office: existing.office || office || "",
          ...(existing.contact_number ? { receiver_contact: existing.contact_number } : {}),
          ...(existing.id_number ? { receiver_id_number: existing.id_number } : {}),
        });
      } else {
        patchRecipientDetailsForAllVehicles({
          received_by: existing.value,
          received_by_position: existing.position || position || "",
          received_by_office: existing.office || office || "",
          receiver_contact: existing.contact_number || "",
          received_by_id_number: existing.id_number || "",
        });
      }
      setReceivedByNotice({
        type: "success",
        vehicleIndex,
        message: `Selected existing person “${existing.value}”.`,
      });
      return { ok: true, value: existing.value };
    }

    setCreatingReceivedBy(true);
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const response = await fetch("/dispatches/received-by", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": csrf,
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          name,
          position: position || null,
          office: office || null,
          is_active: Boolean(isActive),
        }),
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        const message =
          payload?.errors?.name?.[0]
          || payload?.message
          || "Could not add person.";
        return { ok: false, error: message };
      }
      applyCreatedReceivedBy(vehicleIndex, payload);
      return { ok: true, value: payload.value || name };
    } catch {
      return { ok: false, error: "Could not add person. Check your connection and try again." };
    } finally {
      setCreatingReceivedBy(false);
    }
  };

  const openReceivedByModal = (vehicleIndex, suggestedName = "") => {
    setReceivedByModal({
      open: true,
      vehicleIndex,
      name: String(suggestedName || "").trim(),
      position: "",
      office: "",
      is_active: true,
      error: "",
    });
  };

  const submitReceivedByModal = async () => {
    const result = await createReceivedBy(
      receivedByModal.vehicleIndex,
      receivedByModal.name,
      receivedByModal.position,
      receivedByModal.office,
      receivedByModal.is_active,
    );
    if (!result.ok) {
      setReceivedByModal((current) => ({
        ...current,
        error: result.error || "Could not add person.",
      }));
      return;
    }
    setReceivedByModal({
      open: false,
      vehicleIndex: null,
      name: "",
      position: "",
      office: "",
      is_active: true,
      error: "",
    });
  };

  const reference = selectedDispatch?.request || sourceRequest;
  const ris = selectedDispatch?.ris || sourceRequest?.ris;
  const receivingContext = useMemo(
    () => ({
      lgu: reference?.lgu || "",
      municipality: reference?.municipality || "",
      province: reference?.province || "",
      lgu_level: reference?.lgu_level || "",
      requesting_agency: reference?.requesting_agency || "",
      receiving_agency_lgu: form.data.receiving_agency_lgu || "",
      recipient: ris?.recipient || "",
      receiving_representative_office: ris?.receiving_representative_office || "",
      recipient_label:
        form.data.receiving_agency_lgu
        || ris?.recipient
        || reference?.requesting_agency
        || "",
    }),
    [
      reference?.lgu,
      reference?.municipality,
      reference?.province,
      reference?.lgu_level,
      reference?.requesting_agency,
      form.data.receiving_agency_lgu,
      ris?.recipient,
      ris?.receiving_representative_office,
    ],
  );

  // Repair LGU witness office whenever the receiving-LGU identity is known.
  // Saved rows and warehouse FO codes (CARAGA) must not stick on LGU personnel.
  useEffect(() => {
    if (!editorOpen) return;
    const suggested = lguWitnessOfficeName(receivingContext);
    if (!suggested) return;

    const vehicles = form.data.vehicle_details || [];
    let vehiclesChanged = false;
    const nextVehicles = vehicles.map((row) => {
      if (row.release_witness_affiliation !== "lgu") return row;
      const current = String(row.release_witness_office || "").trim();
      if (current === suggested) return row;
      if (
        current
        && looksLikeLguOfficeLabel(current)
        && !isRegionalFoOfficeLabel(current)
        && current !== suggested
      ) {
        // Prefer canonical receiving label over a different LGU-looking leftover.
        vehiclesChanged = true;
        return { ...row, release_witness_office: suggested };
      }
      if (!current || isRegionalFoOfficeLabel(current) || !looksLikeLguOfficeLabel(current)) {
        vehiclesChanged = true;
        return { ...row, release_witness_office: suggested };
      }
      return row;
    });

    const handover = form.data.local_handover_details || {};
    let handoverChanged = false;
    let nextHandover = handover;
    if (handover.release_witness_affiliation === "lgu") {
      const current = String(handover.releaser_office || "").trim();
      if (
        current !== suggested
        && (
          !current
          || isRegionalFoOfficeLabel(current)
          || !looksLikeLguOfficeLabel(current)
          || current !== suggested
        )
      ) {
        handoverChanged = true;
        nextHandover = { ...handover, releaser_office: suggested };
      }
    }

    if (!vehiclesChanged && !handoverChanged) return;
    form.setData({
      ...form.data,
      ...(vehiclesChanged
        ? {
            vehicle_details: nextVehicles,
            ...mirrorLegacyVehicleFields(nextVehicles),
          }
        : {}),
      ...(handoverChanged ? { local_handover_details: nextHandover } : {}),
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    editorOpen,
    receivingContext.receiving_agency_lgu,
    receivingContext.requesting_agency,
    receivingContext.recipient,
    receivingContext.receiving_representative_office,
    receivingContext.municipality,
    receivingContext.province,
    receivingContext.lgu_level,
  ]);
  const planningWarehouseStock = useMemo(
    () =>
      buildPlanningWarehouseStock(
        warehouseStock,
        warehouseReservations,
        form.data.request_id || reference?.id || null,
      ),
    [warehouseStock, warehouseReservations, form.data.request_id, reference?.id],
  );

  const sourceWarehouses = useMemo(
    () => uniqueSourceWarehouses(form.data.items, warehouseCatalog, receivingContext)
      .map((warehouse, originalIndex) => ({ warehouse, originalIndex }))
      .sort((left, right) => {
        const leftLocal = warehouseRequiresTransport(left.warehouse) ? 1 : 0;
        const rightLocal = warehouseRequiresTransport(right.warehouse) ? 1 : 0;
        return leftLocal - rightLocal || left.originalIndex - right.originalIndex;
      })
      .map(({ warehouse }) => warehouse),
    [form.data.items, warehouseCatalog, receivingContext],
  );
  const remoteSourceWarehouses = useMemo(
    () => sourceWarehouses.filter(warehouseRequiresTransport),
    [sourceWarehouses],
  );
  const localSourceWarehouses = useMemo(
    () => sourceWarehouses.filter((warehouse) => !warehouseRequiresTransport(warehouse)),
    [sourceWarehouses],
  );
  const hasLocalSourcePlan = localSourceWarehouses.length > 0;
  const localOnlyPlan =
    sourceWarehouses.length > 0 && remoteSourceWarehouses.length === 0;
  const partnerPickupPlan = form.data.fulfillment_type === "warehouse_pickup";
  const deliveryModeConfirmed = Boolean(form.data.fulfillment_type_confirmed && form.data.fulfillment_type);

  useEffect(() => {
    const keys = Object.keys(form.errors || {});
    if (keys.length === 0) return;
    if (keys.some((key) => String(key).startsWith("local_handover_details."))) {
      const localWarehouse = sourceWarehouses.find((warehouse) => !warehouseRequiresTransport(warehouse));
      if (localWarehouse) setSelectedPlanningWarehouseKey(localWarehouse.key);
    }
    const firstVehicleError = keys.map(parseVehicleErrorIndex).find((index) => index !== null);
    if (firstVehicleError !== undefined) setActiveVehicleIndex(firstVehicleError);
    setVehicleOpenMap((prev) => {
      const next = { ...prev };
      keys.forEach((key) => {
        const idx = parseVehicleErrorIndex(key);
        if (idx !== null) next[idx] = true;
      });
      return next;
    });
    setForcedStageKeys((prev) => {
      const next = { ...prev };
      keys.forEach((key) => {
        const idx = parseVehicleErrorIndex(key);
        const stage = stageFromErrorKey(key);
        if (idx !== null && stage) next[`${idx}-${stage}`] = true;
        if (stage === "returns") next.returns = true;
      });
      return next;
    });
    scrollToFieldError(keys[0]);
    setClientMissingHints(keys.map(humanizeFieldKey));
  }, [form.errors, sourceWarehouses]);

  const confirmDeliveryMode = (mode) => {
    const pickup = mode === "warehouse_pickup";
    const nextVehicles = (form.data.vehicle_details || []).map((vehicle) => {
      const affiliation = pickup && !vehicle.release_witness_affiliation
        ? "lgu"
        : vehicle.release_witness_affiliation;
      const next = {
        ...vehicle,
        mode_of_transportation: pickup ? "Partner" : vehicle.mode_of_transportation,
        release_witness_affiliation: affiliation,
      };
      if (affiliation !== "lgu") return next;
      const autofill = resolveReleaseWitnessAutofill(next, warehouseCatalog, receivingContext).fields;
      return {
        ...next,
        ...autofill,
        release_witnessed_by: next.release_witnessed_by || autofill.release_witnessed_by || "",
        release_witness_office: autofill.release_witness_office || "",
      };
    });
    form.setData({
      ...form.data,
      fulfillment_type: mode,
      fulfillment_type_confirmed: true,
      vehicle_details: nextVehicles,
    });
    form.clearErrors("fulfillment_type");
    setDeliveryModeConfirmation(null);
  };

  const cancelDeliveryMode = () => {
    if (planStatus !== "draft") return;
    const clean = emptyForm();
    form.setData({
      ...form.data,
      fulfillment_type: "",
      fulfillment_type_confirmed: false,
      vehicle_details: clean.vehicle_details,
      number_of_vehicles: clean.number_of_vehicles,
      local_handover_details: clean.local_handover_details,
      source_of_goods: "",
      purpose: "",
    });
    setSelectedPlanningWarehouseKey("");
    setVehicleOpenMap({});
    setActiveVehicleIndex(0);
    setRequirementFocusStatus(null);
    form.clearErrors();
    window.dispatchEvent(new CustomEvent("dromis:toast", { detail: {
      type: "info",
      title: "Delivery mode cancelled",
      message: "Mode-dependent vehicle, release and receipt entries were cleared. Select and confirm a delivery mode to continue.",
    } }));
  };
  const starStatus =
    requirementFocusStatus
    || nextRecommendedStatus(planStatus, {
      localOnly: localOnlyPlan,
      combinedReleaseReceipt: partnerPickupPlan,
    });
  const reqGroups = requiredGroupsForStatus(starStatus, { fromStatus: planStatus });
  const timelineEntries = selectedDispatch?.history || selectedDispatch?.status_timeline || [];
  const stageUi = (stage) => {
    const mode = stageModeForStatus(stage, planStatus, {
      localOnly: localOnlyPlan,
      combinedReleaseReceipt: partnerPickupPlan,
    });
    const editor = resolveStageLastEditor(timelineEntries, stage);
    const accomplished = mode === "accomplished";
    const locked = accomplished && !canEditAccomplishedStage(editor, authUser);
    let lockNotice = null;
    if (accomplished) {
      const who = editor?.name || "an earlier updater";
      lockNotice = locked
        ? `Completed — view only. Last updated by ${who}. Only they can edit.`
        : editor?.name
          ? `Completed. Last updated by you (${editor.name}). You can still update this stage.`
          : "Completed. No recorded editor — you can update this stage.";
    }
    return { mode, locked, lockNotice, editor };
  };
  const primaryCtaStatus =
    planStatus === "received"
      ? null
      : nextRecommendedStatus(planStatus, {
        localOnly: localOnlyPlan,
        combinedReleaseReceipt: partnerPickupPlan,
      });
  const footerHintStatus = requirementFocusStatus || primaryCtaStatus || "planned";

  useEffect(() => {
    if (!editorOpen) return undefined;
    const stage = currentStageForStatus(planStatus, {
      localOnly: localOnlyPlan,
      combinedReleaseReceipt: partnerPickupPlan,
    });
    const anchor = stageAnchorId(stage);
    // Vehicle stage anchors live inside <details>; keep vehicle 0 open so scroll/focus works.
    if (["release", "transit", "receipt"].includes(stage)) {
      setVehicleOpenMap((prev) => ({ ...prev, 0: true }));
    }
    let attempts = 0;
    let timer = null;
    let cancelled = false;
    const tryScroll = () => {
      if (cancelled) return;
      attempts += 1;
      const el = document.getElementById(anchor);
      if (el?.scrollIntoView) {
        el.scrollIntoView({ behavior: "smooth", block: "start" });
        const focusable = el.querySelector?.(
          'input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])',
        );
        if (focusable && typeof focusable.focus === "function") {
          try {
            focusable.focus({ preventScroll: true });
          } catch {
            focusable.focus();
          }
        }
        return;
      }
      if (attempts < 10) {
        timer = window.setTimeout(tryScroll, 80);
      }
    };
    timer = window.setTimeout(tryScroll, 60);
    return () => {
      cancelled = true;
      if (timer) window.clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editorOpen, selectedDispatch?.id, sourceRequest?.id, planStatus, localOnlyPlan, partnerPickupPlan]);

  useEffect(() => {
    if (sourceWarehouses.length === 0) {
      setSelectedPlanningWarehouseKey("");
      return;
    }
    setSelectedPlanningWarehouseKey((prev) => {
      if (prev && sourceWarehouses.some((wh) => wh.key === prev)) {
        return prev;
      }
      return sourceWarehouses[0].key;
    });
  }, [sourceWarehouses, remoteSourceWarehouses]);

  const selectedPlanningWarehouse =
    sourceWarehouses.find((wh) => wh.key === selectedPlanningWarehouseKey) || null;
  const selectedWarehouseIsLocal = isReceivingLguWarehouse(selectedPlanningWarehouse);
  const selectedWarehouseIndex = sourceWarehouses.findIndex(
    (warehouse) => warehouse.key === selectedPlanningWarehouseKey,
  );

  useEffect(() => {
    if (!partnerPickupPlan) return;

    const activeIndexes = (form.data.vehicle_details || [])
      .map((row, index) => ({ row, index }))
      .filter(({ row }) => itemMatchesSourceWarehouse(
        { warehouse_id: selectedPlanningWarehouse?.id, warehouse_name: selectedPlanningWarehouse?.name },
        row?.source_warehouse_id,
        row?.source_warehouse_name,
      ))
      .map(({ index }) => index);
    const nextActiveIndex = activeIndexes.includes(activeVehicleIndex)
      ? activeVehicleIndex
      : activeIndexes[0];

    if (nextActiveIndex != null) {
      setActiveVehicleIndex(nextActiveIndex);
      setVehicleOpenMap((previous) => ({ ...previous, [nextActiveIndex]: true }));
    }

  }, [partnerPickupPlan, selectedPlanningWarehouseKey, planStatus]);

  const selectAdjacentWarehouse = (direction) => {
    const nextIndex = selectedWarehouseIndex + direction;
    const nextWarehouse = sourceWarehouses[nextIndex];
    if (!nextWarehouse) return;
    setSelectedPlanningWarehouseKey(nextWarehouse.key);
    window.setTimeout(() => {
      document.getElementById("dispatch-source-warehouse-selector")?.scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
    }, 0);
  };
  const dispatchNavigatorSections = useMemo(() => {
    const draft = {
      number: "S",
      label: "Draft / Source",
      target: "dispatch-source",
      children: [
        { label: "Delivery mode", target: "dispatch-delivery-mode" },
        { label: "Allocation", target: "dispatch-allocation" },
      ],
    };
    const variances = {
      number: "05",
      label: "Delivery Variances",
      target: "dispatch-exceptions",
      enabled: planStatus === "received",
      children: [{ label: "Disposition & resolution", target: "dispatch-exceptions" }],
    };

    if (selectedWarehouseIsLocal) {
      return [
        draft,
        {
          number: "01",
          label: "Plan · Estimated Schedule",
          target: "dispatch-stage-planning",
          enabled: true,
          children: [
            { label: "Source warehouse", target: "dispatch-source-warehouse-selector" },
            { label: "Expected release & items", target: "dispatch-selected-warehouse" },
          ],
        },
        {
          number: "02",
          label: "Release & Recipient Receipt",
          target: "dispatch-stage-release",
          enabled: planStatus !== "draft",
          children: [
            { label: "Classification & witness", target: "dispatch-stage-release" },
            { label: "Custody acceptance", target: "dispatch-local-receipt" },
            { label: "Reconciliation", target: "dispatch-receipt-reconciliation" },
          ],
        },
        variances,
      ];
    }

    const pickup = form.data.fulfillment_type === "warehouse_pickup";
    return [
      draft,
      {
        number: "01",
        label: "Plan · Estimated Schedule",
        target: "dispatch-stage-planning",
        enabled: true,
        children: [
          { label: "Source warehouse", target: "dispatch-source-warehouse-selector" },
          { label: "Vehicles", target: "dispatch-selected-warehouse" },
          { label: "Items to be loaded", target: "dispatch-release-items" },
        ],
      },
      {
        number: "02",
        label: pickup ? "Release & Recipient Receipt" : "Release (Confirm Release)",
        target: "dispatch-stage-release",
        enabled: planStatus !== "draft",
        children: [
          { label: "Classification & witness", target: "dispatch-stage-release" },
          { label: "Loaded items", target: "dispatch-release-items" },
          ...(pickup ? [
            { label: "Custody acceptance", target: "dispatch-stage-receipt" },
            { label: "Reconciliation", target: "dispatch-receipt-reconciliation" },
          ] : []),
        ],
      },
      ...(!pickup ? [{
        number: "03",
        label: "In Transit (Mark In Transit)",
        target: "dispatch-stage-transit",
        enabled: statusIndex(planStatus) >= statusIndex("released"),
        children: [{ label: "Departure update", target: "dispatch-stage-transit" }],
      }] : []),
      ...(!pickup ? [{
        number: "04",
        label: "Recipient Receipt (Confirm Receipt)",
        target: "dispatch-stage-receipt",
        enabled: statusIndex(planStatus) >= statusIndex("released"),
        children: [
          { label: "Receiving details", target: "dispatch-stage-receipt" },
          { label: "Reconciliation", target: "dispatch-receipt-reconciliation" },
        ],
      }] : []),
      variances,
    ];
  }, [selectedWarehouseIsLocal, form.data.fulfillment_type, planStatus]);

  // The form starts with one vehicle row. Selecting the source warehouse should
  // assign that starter row immediately; requiring a second "Add vehicle" click
  // left the required Source Warehouse dropdown visibly blank and confusing.
  useEffect(() => {
    if (
      planStatus !== "draft"
      || !selectedPlanningWarehouse
      || !warehouseRequiresTransport(selectedPlanningWarehouse)
    ) return;

    const rows = form.data.vehicle_details || [];
    if (rows.length !== 1) return;
    const first = rows[0] || {};
    if (first.source_warehouse_id || first.source_warehouse_name) return;

    const next = [{
      ...first,
      source_warehouse_id: selectedPlanningWarehouse.id ?? "",
      source_warehouse_name: selectedPlanningWarehouse.name || "",
    }];
    form.setData({
      ...form.data,
      vehicle_details: next,
      number_of_vehicles: "1",
      ...mirrorLegacyVehicleFields(next),
    });
  }, [selectedPlanningWarehouseKey, planStatus]);

  const vehiclesByWarehouse = useMemo(() => {
    const groups = sourceWarehouses.map((warehouse) => ({
      warehouse,
      vehicles: (form.data.vehicle_details || [])
        .map((row, index) => ({ row, index }))
        .filter(({ row }) =>
          itemMatchesSourceWarehouse(
            { warehouse_id: warehouse.id, warehouse_name: warehouse.name },
            row.source_warehouse_id,
            row.source_warehouse_name,
          ),
        ),
    }));
    const assignedIndexes = new Set(
      groups.flatMap((group) => group.vehicles.map(({ index }) => index)),
    );
    const unassigned = (form.data.vehicle_details || [])
      .map((row, index) => ({ row, index }))
      .filter(({ row, index }) => {
        if (assignedIndexes.has(index)) return false;
        // Hide the blank starter row until ops picks a warehouse and clicks Add vehicle.
        if (
          sourceWarehouses.length > 0
          && isEmptyVehicleRow(row)
          && !(row.source_warehouse_id || row.source_warehouse_name)
        ) {
          return false;
        }
        return true;
      });
    return { groups, unassigned };
  }, [form.data.vehicle_details, sourceWarehouses]);

  const visibleVehicleCount = useMemo(() => {
    if (sourceWarehouses.length === 0) {
      return (form.data.vehicle_details || []).length;
    }
    return (
      vehiclesByWarehouse.groups.reduce((sum, group) => sum + group.vehicles.length, 0)
      + vehiclesByWarehouse.unassigned.length
    );
  }, [form.data.vehicle_details, sourceWarehouses, vehiclesByWarehouse]);

  const displayedVehicleGroups = useMemo(() => {
    if (sourceWarehouses.length === 0) {
      return [{ key: "all", warehouse: null, vehicles: (form.data.vehicle_details || []).map((row, index) => ({ row, index })) }];
    }
    if (selectedPlanningWarehouse) {
      const selectedGroup = vehiclesByWarehouse.groups.find((group) => group.warehouse.key === selectedPlanningWarehouse.key);
      return selectedGroup ? [{ key: selectedGroup.warehouse.key, warehouse: selectedGroup.warehouse, vehicles: selectedGroup.vehicles }] : [];
    }
    return [
      ...vehiclesByWarehouse.groups.map((group) => ({ key: group.warehouse.key, warehouse: group.warehouse, vehicles: group.vehicles })),
      ...(vehiclesByWarehouse.unassigned.length ? [{ key: "unassigned", warehouse: { key: "unassigned", name: "Unassigned vehicles", classification: { label: "Needs source warehouse", tone: "slate" } }, vehicles: vehiclesByWarehouse.unassigned }] : []),
    ];
  }, [sourceWarehouses, selectedPlanningWarehouse, vehiclesByWarehouse, form.data.vehicle_details]);
  const displayedVehicleIndexes = displayedVehicleGroups.flatMap((group) => group.vehicles.map(({ index }) => index));
  const displayedVehicleCount = displayedVehicleIndexes.length;

  const remoteWarehouseReleaseProgress = useMemo(() => {
    const remoteGroups = vehiclesByWarehouse.groups.filter(({ warehouse }) =>
      warehouseRequiresTransport(warehouse),
    );
    const releasedGroups = remoteGroups.filter(({ vehicles }) =>
      vehicles.length > 0
      && vehicles.every(({ row }) => Boolean(String(row?.warehouse_released_at || "").trim())),
    );
    return {
      total: remoteGroups.length,
      released: releasedGroups.length,
      pendingNames: remoteGroups
        .filter(({ vehicles }) =>
          vehicles.length === 0
          || vehicles.some(({ row }) => !String(row?.warehouse_released_at || "").trim()),
        )
        .map(({ warehouse }) => warehouse.name),
    };
  }, [vehiclesByWarehouse]);

  const selectedWarehouseReleaseIndexes = useMemo(() => {
    if (!selectedPlanningWarehouse || !warehouseRequiresTransport(selectedPlanningWarehouse)) {
      return [];
    }
    return displayedVehicleIndexes.includes(activeVehicleIndex) ? [activeVehicleIndex] : [];
  }, [selectedPlanningWarehouse, displayedVehicleIndexes, activeVehicleIndex]);

  const selectedWarehouseAlreadyReleased = selectedWarehouseReleaseIndexes.length > 0
    && selectedWarehouseReleaseIndexes.every((index) =>
      Boolean(String((form.data.vehicle_details || [])[index]?.warehouse_released_at || "").trim()),
    );
  const activeVehicle = (form.data.vehicle_details || [])[activeVehicleIndex] || null;
  const activeVehicleReleased = Boolean(String(activeVehicle?.warehouse_released_at || "").trim());
  const activeVehicleDeparted = Boolean(String(activeVehicle?.departed_at || "").trim());

  const confirmSelectedWarehouseRelease = () => {
    const indexes = selectedWarehouseReleaseIndexes;
    if (!indexes.length) return;

    const transportIndexes = vehiclesByWarehouse.groups
      .filter(({ warehouse }) => warehouseRequiresTransport(warehouse))
      .flatMap(({ vehicles }) => vehicles.map(({ index }) => index));
    const allTransportReleasedAfter = transportIndexes.every((index) => {
      if (indexes.includes(index)) {
        return Boolean(String((form.data.vehicle_details || [])[index]?.warehouse_released_at || "").trim());
      }
      return Boolean(String((form.data.vehicle_details || [])[index]?.warehouse_released_at || "").trim());
    });
    const nextStatus = allTransportReleasedAfter ? "released" : "planned";
    const vehicle = (form.data.vehicle_details || [])[indexes[0]] || {};
    const vehicleLabel = vehicle.vehicle_plate_number || vehicle.driver || `Vehicle ${indexes[0] + 1}`;
    const warehouseLabel = `${vehicleLabel}`;
    const multiWarehouse = true;

    requestConfirmation({
      title: allTransportReleasedAfter ? "Confirm final vehicle release?" : `Confirm release for ${vehicleLabel}?`,
      message: allTransportReleasedAfter
        ? "All source warehouses will be released. Inventory for this warehouse’s DRs will be deducted now."
        : `Only ${warehouseLabel} will be released now. Other warehouses can be confirmed later while this plan stays Planned. Inventory is deducted only for this warehouse’s DRs.`,
      confirmLabel: multiWarehouse ? `Confirm Release — ${warehouseLabel}` : "Confirm Release",
      onConfirm: () => submit(nextStatus, { releaseVehicleIndexes: indexes }),
    });
  };

  useEffect(() => {
    if (displayedVehicleIndexes.length > 0 && !displayedVehicleIndexes.includes(activeVehicleIndex)) {
      setActiveVehicleIndex(displayedVehicleIndexes[0]);
    }
  }, [selectedPlanningWarehouseKey, displayedVehicleIndexes.join(",")]);

  const setItem = (index, key, value) => {
    const next = [...form.data.items];
    next[index] = { ...next[index], [key]: value };
    form.setData("items", next);
  };
  const setLocalHandover = (key, value) => form.setData("local_handover_details", {
    ...(form.data.local_handover_details || {}),
    [key]: value,
  });

  const patchLocalHandover = (patch = {}) => form.setData("local_handover_details", {
    ...(form.data.local_handover_details || {}),
    ...patch,
  });

  const localWitnessFieldsFromWarehouse = (warehouse = {}) => {
    const fields = releaseWitnessFieldsFromStorekeeper(warehouse);
    // Witness office = LGU personnel's organization (request/RIS), never warehouse FO data.
    return {
      released_by: fields.release_witnessed_by || "",
      releaser_contact: fields.release_witness_contact_number || "",
      releaser_id_number: fields.release_witness_id_number || "",
      releaser_position: fields.release_witness_position || "LGU Employee",
      releaser_office: resolveLguWitnessOffice({}, receivingContext),
    };
  };

  const changeLocalWitnessAffiliation = (affiliation, warehouse) => {
    const cleared = {
      released_by: "",
      releaser_contact: "",
      releaser_id_number: "",
      releaser_position: "",
      releaser_office: "",
    };
    patchLocalHandover({
      release_witness_affiliation: affiliation,
      ...cleared,
      ...(affiliation === "lgu" ? localWitnessFieldsFromWarehouse(warehouse || {}) : {}),
    });
  };

  const selectLocalDswdWitness = (employee) => patchLocalHandover({
    released_by: employee?.value || "",
    releaser_contact:
      employee?.contact_number
      || employee?.mobile_number
      || employee?.mobile_no
      || employee?.mobile
      || employee?.phone
      || "",
    releaser_id_number: employee?.id_number || "",
    releaser_position: employee?.position || "",
    releaser_office: employee?.section_unit_program || employee?.office || "",
  });

  useEffect(() => {
    if (!selectedPlanningWarehouse || warehouseRequiresTransport(selectedPlanningWarehouse)) return;
    const handover = form.data.local_handover_details || {};
    if (handover.release_witness_affiliation !== "lgu") return;
    const suggested = localWitnessFieldsFromWarehouse(selectedPlanningWarehouse);
    const sourceChanged = String(handover.source_warehouse_id || "") !== String(selectedPlanningWarehouse.id || "")
      || String(handover.source_warehouse_name || "") !== String(selectedPlanningWarehouse.name || "");
    const officeNeedsRepair = isRegionalFoOfficeLabel(handover.releaser_office)
      || !String(handover.releaser_office || "").trim();
    if (!sourceChanged && handover.released_by && !officeNeedsRepair) return;
    patchLocalHandover({
      source_warehouse_id: selectedPlanningWarehouse.id ?? "",
      source_warehouse_name: selectedPlanningWarehouse.name || "",
      ...suggested,
      ...(handover.released_by && !sourceChanged
        ? { released_by: handover.released_by }
        : {}),
    });
    // Re-evaluate personnel only when the active local warehouse tab changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedPlanningWarehouse?.key, receivingContext.receiving_agency_lgu, receivingContext.recipient, receivingContext.receiving_representative_office, receivingContext.recipient_label]);

  const returnVarianceRows = useMemo(() => (form.data.items || []).map((item, index) => {
    const loaded = sumLoadedAcrossVehicles(form.data.vehicle_details, item);
    const expected = Number(item.allocated_quantity || 0);
    const received = item.received_quantity === "" || item.received_quantity == null
      ? null
      : Number(item.received_quantity);
    return { item, index, loaded, expected, received, variance: received == null ? 0 : Math.max(0, expected - received) };
  }).filter((row) => row.variance > 0), [form.data.items, form.data.vehicle_details]);

  const setVehicleCount = (rawCount, assignedWarehouse = null) => {
    const nextCount = rawCount === "" ? "" : String(rawCount);
    if (nextCount === "") {
      form.setData("number_of_vehicles", "");
      return;
    }
    const synced = syncVehicleDetailsToCount(
      form.data.vehicle_details,
      nextCount,
      form.data.items,
      assignedWarehouse,
      { warehouseCatalog, receivingContext },
    );
    const legacy = mirrorLegacyVehicleFields(synced.vehicle_details);
    form.setData({
      ...form.data,
      number_of_vehicles: synced.number_of_vehicles,
      vehicle_details: synced.vehicle_details,
      ...legacy,
    });
  };

  const addVehicle = () => {
    if (String(planStatus || "draft") !== "draft") {
      window.dispatchEvent(new CustomEvent("dromis:toast", { detail: {
        type: "error",
        title: "Vehicle planning is closed",
        message: "Vehicles can only be added before the dispatch is marked Planned.",
      } }));
      return;
    }
    if (sourceWarehouses.length > 0 && !selectedPlanningWarehouse) {
      return;
    }
    if (selectedPlanningWarehouse && !warehouseRequiresTransport(selectedPlanningWarehouse)) {
      return;
    }
    const current = [...(form.data.vehicle_details || [])];
    const soleEmpty =
      current.length === 1
      && isEmptyVehicleRow(current[0])
      && !(current[0].source_warehouse_id || current[0].source_warehouse_name);

    if (soleEmpty && selectedPlanningWarehouse) {
      patchVehicleDetail(0, {
        source_warehouse_id: selectedPlanningWarehouse.id ?? "",
        source_warehouse_name: selectedPlanningWarehouse.name || "",
      });
      setVehicleOpenMap((prev) => ({ ...prev, 0: true }));
      return;
    }

    const newRow = applyRisReceiptDefaults(
      emptyVehicleRow(form.data.items, selectedPlanningWarehouse, {
        warehouseCatalog,
        receivingContext,
      }),
      ris || {},
      findReceivedByLibraryMatch(
        (ris || {}).receiving_representative || "",
        receivedByOptions,
      ),
    );
    const autofill = resolveReleaseWitnessAutofill(newRow, warehouseCatalog, receivingContext);
    const primedRow = {
      ...(isReleaseWitnessBlank(newRow) ? { ...newRow, ...autofill.fields } : newRow),
      loading_remarks:
        (form.data.vehicle_details || []).find((row) => String(row.loading_remarks || "").trim())
          ?.loading_remarks
        || form.data.local_handover_details?.remarks
        || newRow.loading_remarks
        || ris?.purpose_of_release
        || "",
    };
    const next = assignVehicleDrNumbers(
      [...current, primedRow],
      ris?.dr_number || selectedDispatch?.ris?.dr_number || "",
      Number(selectedDispatch?.dr_series_offset || 0) + (hasLocalSourcePlan ? 1 : 0),
      Number(selectedDispatch?.delivery_sequence || 1) > 1 || hasLocalSourcePlan,
    );
    const legacy = mirrorLegacyVehicleFields(next);
    const newIndex = next.length - 1;
    releaseWitnessAutofillRef.current.keys[newIndex] = autofill.key;
    form.setData({
      ...form.data,
      vehicle_details: next,
      number_of_vehicles: String(next.length),
      ...legacy,
    });
    setVehicleOpenMap((prev) => ({ ...prev, [newIndex]: true }));
    setActiveVehicleIndex(newIndex);
  };

  const removeVehicle = (index) => {
    const current = [...(form.data.vehicle_details || [])];
    if (current.length <= 1) return;
    current.splice(index, 1);
    const next = assignVehicleDrNumbers(
      current.map((row) => normalizeVehicleRow(row, form.data.items)),
      ris?.dr_number || selectedDispatch?.ris?.dr_number || "",
      Number(selectedDispatch?.dr_series_offset || 0) + (hasLocalSourcePlan ? 1 : 0),
      Number(selectedDispatch?.delivery_sequence || 1) > 1 || hasLocalSourcePlan,
    );
    const legacy = mirrorLegacyVehicleFields(next);
    const keys = { ...releaseWitnessAutofillRef.current.keys };
    delete keys[index];
    // Re-index keys after splice
    const rekeyed = {};
    next.forEach((row, i) => {
      const oldIndex = i < index ? i : i + 1;
      rekeyed[i] =
        keys[oldIndex]
        ?? resolveReleaseWitnessAutofill(row, warehouseCatalog, receivingContext).key;
    });
    releaseWitnessAutofillRef.current.keys = rekeyed;
    form.setData({
      ...form.data,
      vehicle_details: next,
      number_of_vehicles: String(next.length),
      ...legacy,
    });
    setActiveVehicleIndex((currentIndex) => Math.max(0, currentIndex > index ? currentIndex - 1 : Math.min(currentIndex, next.length - 1)));
  };

  const patchTouchesWitnessSource = (patch = {}) =>
    [
      "has_dswd_escort",
      "escort_name",
      "escort_contact_number",
      "escort_id_number",
      "escort_position",
      "escort_office",
      "source_warehouse_id",
      "source_warehouse_name",
    ].some((key) => Object.prototype.hasOwnProperty.call(patch, key));

  const patchSetsReleaseWitness = (patch = {}) =>
    [
      "release_witnessed_by",
      "release_witness_contact_number",
      "release_witness_id_number",
      "release_witness_position",
      "release_witness_office",
    ].some((key) => Object.prototype.hasOwnProperty.call(patch, key));

  const patchVehicleDetail = (index, patch) => {
    const next = [...(form.data.vehicle_details || [])];
    let merged = {
      ...(next[index] || emptyVehicleRow(form.data.items, null, {
        warehouseCatalog,
        receivingContext,
      })),
      ...patch,
    };

    // Escort / warehouse source changed → replace witness (unless patch already sets witness explicitly).
    if (patchTouchesWitnessSource(patch) && !patchSetsReleaseWitness(patch)) {
      const autofill = resolveReleaseWitnessAutofill(merged, warehouseCatalog, receivingContext);
      merged = { ...merged, ...autofill.fields };
      releaseWitnessAutofillRef.current.keys[index] = autofill.key;
    } else if (patchTouchesWitnessSource(patch)) {
      releaseWitnessAutofillRef.current.keys[index] =
        resolveReleaseWitnessAutofill(merged, warehouseCatalog, receivingContext).key;
    }

    next[index] = normalizeVehicleRow(merged, form.data.items);
    const legacy = mirrorLegacyVehicleFields(next);
    form.setData({
      ...form.data,
      vehicle_details: next,
      number_of_vehicles: String(next.length || 1),
      ...legacy,
    });
  };

  const setVehicleDetail = (index, key, value) => {
    patchVehicleDetail(index, { [key]: value });
  };

  /** Receiving representative identity is shared by every warehouse/vehicle receipt. */
  const patchRecipientDetailsForAllVehicles = (patch = {}) => {
    const next = (form.data.vehicle_details || []).map((row) =>
      normalizeVehicleRow({ ...row, ...patch }, form.data.items),
    );
    const handover = form.data.local_handover_details || {};
    form.setData({
      ...form.data,
      vehicle_details: next,
      local_handover_details: {
        ...handover,
        received_by: patch.received_by ?? handover.received_by ?? "",
        receiver_contact: patch.receiver_contact ?? handover.receiver_contact ?? "",
        receiver_id_number:
          patch.received_by_id_number ?? handover.receiver_id_number ?? "",
        receiver_position:
          patch.received_by_position ?? handover.receiver_position ?? "",
        receiver_office: patch.received_by_office ?? handover.receiver_office ?? "",
      },
      ...mirrorLegacyVehicleFields(next),
    });
  };

  /** Keep release remarks identical on every vehicle DR (and local handover remarks). */
  const setSharedLoadingRemarks = (value) => {
    const remarks = String(value || "");
    const next = (form.data.vehicle_details || []).map((row) => ({
      ...row,
      loading_remarks: remarks,
    }));
    form.setData({
      ...form.data,
      vehicle_details: next,
      local_handover_details: {
        ...(form.data.local_handover_details || {}),
        remarks,
      },
      ...mirrorLegacyVehicleFields(next),
    });
  };

  const setVehicleLoadedItem = (vehicleIndex, itemIndex, key, value) => {
    const next = [...(form.data.vehicle_details || [])];
    const vehicle = {
      ...(next[vehicleIndex] || emptyVehicleRow(form.data.items)),
    };
    const loadedItems = [...(vehicle.loaded_items || [])];
    loadedItems[itemIndex] = {
      ...(loadedItems[itemIndex] || emptyLoadedItem(form.data.items?.[itemIndex])),
      [key]: value,
    };
    vehicle.loaded_items = loadedItems;
    next[vehicleIndex] = normalizeVehicleRow(vehicle, form.data.items);
    const legacy = mirrorLegacyVehicleFields(next);
    form.setData({
      ...form.data,
      vehicle_details: next,
      number_of_vehicles: String(next.length || 1),
      ...legacy,
    });
  };

  const setVehiclePlannedItem = (vehicleIndex, itemIndex, value) => {
    const next = [...(form.data.vehicle_details || [])];
    const vehicle = { ...(next[vehicleIndex] || emptyVehicleRow(form.data.items)) };
    const loadedItems = [...(vehicle.loaded_items || [])];
    const current = loadedItems[itemIndex] || emptyLoadedItem(form.data.items?.[itemIndex]);
    const previousPlan = current.planned_quantity ?? "";
    const prefillRelease = current.loaded_quantity === ""
      || current.loaded_quantity == null
      || String(current.loaded_quantity) === String(previousPlan);
    loadedItems[itemIndex] = {
      ...current,
      planned_quantity: value,
      ...(prefillRelease ? { loaded_quantity: value } : {}),
    };
    vehicle.loaded_items = loadedItems;
    next[vehicleIndex] = normalizeVehicleRow(vehicle, form.data.items);
    form.setData({
      ...form.data,
      vehicle_details: next,
      number_of_vehicles: String(next.length || 1),
      ...mirrorLegacyVehicleFields(next),
    });
  };

  const openCreate = (requestRow) => {
    router.get(
      "/dispatches",
      { bucket: "still_for_action", request_id: requestRow.id },
      { preserveState: false, preserveScroll: true },
    );
  };

  const openEdit = (dispatch) => {
    router.get(
      escortWorkspace ? "/delivery-escort" : "/dispatches",
      { bucket: dispatch.bucket || bucket, dispatch_id: dispatch.id },
      { preserveState: false, preserveScroll: true },
    );
  };

  const closeEditor = () => {
    router.get(
      escortWorkspace ? "/delivery-escort" : "/dispatches",
      { bucket },
      { preserveState: false, preserveScroll: true },
    );
  };

  const submit = (nextStatus, options = {}) => {
    setRequirementFocusStatus(nextStatus);
    setClientMissingHints([]);

    if (String(nextStatus || "draft") !== "draft" && !form.data.fulfillment_type_confirmed) {
      const modeError = { fulfillment_type: "Select and confirm a delivery mode before proceeding." };
      form.clearErrors();
      form.setError(modeError);
      setClientMissingHints(["Delivery Mode"]);
      notifyMissingVehicleFields(modeError, nextStatus);
      return;
    }

    const releaseVehicleIndexes = Array.isArray(options.releaseVehicleIndexes)
      ? options.releaseVehicleIndexes.map((index) => Number(index))
      : null;
    const scopedWarehouseRelease = Boolean(releaseVehicleIndexes?.length)
      && planStatus === "planned"
      && ["planned", "released"].includes(String(nextStatus || ""));
    const transitVehicleIndexes = Array.isArray(options.transitVehicleIndexes)
      ? options.transitVehicleIndexes.map((index) => Number(index))
      : null;
    const scopedVehicleTransit = Boolean(transitVehicleIndexes?.length)
      && ["planned", "released"].includes(planStatus)
      && String(nextStatus || "") === planStatus;

    const allScheduleErrors = collectVehicleScheduleErrors(
      form.data.vehicle_details || [],
      selectedDispatch?.vehicle_details || [],
    );
    const scheduleErrors = scopedVehicleTransit
      ? Object.fromEntries(Object.entries(allScheduleErrors).filter(([key]) =>
          transitVehicleIndexes.some((index) => key.startsWith(`vehicle_details.${index}.`)),
        ))
      : allScheduleErrors;
    if (Object.keys(scheduleErrors).length > 0) {
      form.clearErrors();
      form.setError(scheduleErrors);
      setClientMissingHints(Object.keys(scheduleErrors).map(humanizeFieldKey));
      notifyMissingVehicleFields(scheduleErrors, nextStatus);
      return;
    }

    const vehiclesForValidation = (form.data.vehicle_details || []).filter((row) => {
      const hasWarehouse =
        String(row?.source_warehouse_id || "").trim()
        || String(row?.source_warehouse_name || "").trim();
      if (sourceWarehouses.length > 0 && !hasWarehouse && isEmptyVehicleRow(row)) {
        return false;
      }
      if (localOnlyPlan) {
        return false;
      }
      const assigned = sourceWarehouses.find((wh) =>
        itemMatchesSourceWarehouse(
          { warehouse_id: wh.id, warehouse_name: wh.name },
          row?.source_warehouse_id,
          row?.source_warehouse_name,
        ),
      );
      if (assigned && !warehouseRequiresTransport(assigned)) {
        return false;
      }
      return true;
    });

    // Scoped warehouse release validates against the full vehicle_details index space.
    const statusValidationRows = scopedWarehouseRelease
      ? (form.data.vehicle_details || [])
      : vehiclesForValidation;

    if (scopedVehicleTransit) {
      const transitErrors = {};
      transitVehicleIndexes.forEach((index) => {
        const row = (form.data.vehicle_details || [])[index] || {};
        if (!String(row.warehouse_released_at || "").trim()) {
          transitErrors[`vehicle_details.${index}.warehouse_released_at`] =
            "Confirm this vehicle release before marking it In Transit.";
        }
        if (!String(row.departed_at || "").trim()) {
          transitErrors[`vehicle_details.${index}.departed_at`] =
            "Actual departure date and time is required for this vehicle.";
        }
      });
      if (Object.keys(transitErrors).length > 0) {
        form.clearErrors();
        form.setError(transitErrors);
        setClientMissingHints(Object.keys(transitErrors).map(humanizeFieldKey));
        notifyMissingVehicleFields(transitErrors, "in_transit");
        return;
      }
    }

    const statusErrors = collectStatusRequirementErrors(
      {
        ...form.data,
        vehicle_details: statusValidationRows,
        number_of_vehicles: statusValidationRows.length || 0,
      },
      nextStatus,
      {
        warehouseCatalog,
        receivingContext,
        fromStatus: planStatus,
        ...(scopedWarehouseRelease ? { releaseVehicleIndexes } : {}),
      },
    );
    if (Object.keys(statusErrors).length > 0) {
      form.clearErrors();
      form.setError(statusErrors);
      setClientMissingHints(Object.keys(statusErrors).map(humanizeFieldKey));
      notifyMissingVehicleFields(statusErrors, nextStatus);
      return;
    }

    const sameDayIssues = localOnlyPlan
      ? []
      : scopedWarehouseRelease
        ? collectSameDayScheduleIssues(form.data.vehicle_details || []).filter(({ index }) =>
            releaseVehicleIndexes.includes(index),
          )
        : collectSameDayScheduleIssues(vehiclesForValidation);
    if (sameDayIssues.length > 0 && nextStatus === "planned" && !scopedWarehouseRelease) {
      if (!options.skipSameDayWarning) {
        requestConfirmation({
          title: "Confirm planning schedule",
          message: SAME_DAY_PLANNED_WARN,
          confirmLabel: "Continue to Mark Planned",
          tone: "amber",
          onConfirm: () => submit(nextStatus, { ...options, skipSameDayWarning: true }),
        });
        return;
      }
    }
    if (
      sameDayIssues.length > 0
      && (scopedWarehouseRelease || ["released", "in_transit", "received"].includes(String(nextStatus || "")))
    ) {
      const sameDayErrors = {};
      sameDayIssues.forEach((issue) => {
        sameDayErrors[issue.key] = issue.message;
      });
      form.clearErrors();
      form.setError(sameDayErrors);
      setClientMissingHints(sameDayIssues.map((issue) => humanizeFieldKey(issue.key)));
      notifyMissingVehicleFields(sameDayErrors, nextStatus);
      return;
    }

    if ((nextStatus === "released" || scopedWarehouseRelease) && selectedDispatch?.id && !localOnlyPlan) {
      const savedVehicles = selectedDispatch.vehicle_details || [];
      const checkIndexes = scopedWarehouseRelease
        ? releaseVehicleIndexes
        : vehiclesForValidation.map((_, index) => index);
      const savedIssues = collectSameDayScheduleIssues(savedVehicles).filter(({ index }) =>
        checkIndexes.includes(index),
      );
      const blocking = savedIssues.filter(({ index }) => {
        const saved = savedVehicles[index] || {};
        const current = (form.data.vehicle_details || [])[index] || {};
        return String(saved.estimated_departure || "") !== String(current.estimated_departure || "")
          || String(saved.estimated_arrival || "") !== String(current.estimated_arrival || "")
          || vehicleAllowsMultiDayRun(saved) !== vehicleAllowsMultiDayRun(current);
      });
      if (blocking.length > 0) {
        const revisionErrors = {};
        blocking.forEach(({ index }) => {
          revisionErrors[`vehicle_details.${index}.estimated_arrival`] =
            "Save the revised Planning dates (or Multi-day run exception) first. After the plan update is recorded in history, confirm release.";
        });
        form.clearErrors();
        form.setError(revisionErrors);
        setClientMissingHints(Object.keys(revisionErrors).map(humanizeFieldKey));
        notifyMissingVehicleFields(revisionErrors, nextStatus);
        return;
      }
    }

    if (nextStatus === "received") {
      const soft = softReceiptWarnings();
      if (soft.length > 0 && !options.skipReceiptWarning) {
        requestConfirmation({
          title: "Review recipient receipt",
          message: `Please review before confirming recipient receipt:\n\n• ${soft.join("\n• ")}\n\nContinue anyway?`,
          confirmLabel: "Confirm Receipt",
          tone: "amber",
          onConfirm: () => submit(nextStatus, { ...options, skipReceiptWarning: true }),
        });
        return;
      }
    }

    const vehicleDetails = (form.data.vehicle_details || [])
      .filter((row) => {
        const hasWarehouse =
          String(row?.source_warehouse_id || "").trim()
          || String(row?.source_warehouse_name || "").trim();
        if (sourceWarehouses.length > 0 && !hasWarehouse && isEmptyVehicleRow(row)) {
          return false;
        }
        if (localOnlyPlan) {
          return false;
        }
        const assigned = sourceWarehouses.find((wh) =>
          itemMatchesSourceWarehouse(
            { warehouse_id: wh.id, warehouse_name: wh.name },
            row?.source_warehouse_id,
            row?.source_warehouse_name,
          ),
        );
        if (assigned && !warehouseRequiresTransport(assigned)) {
          return false;
        }
        return true;
      })
      .map((row) => ({
      source_vehicle_index: row.source_vehicle_index ?? null,
      source_warehouse_id:
        row.source_warehouse_id === "" || row.source_warehouse_id == null
          ? null
          : Number(row.source_warehouse_id) || row.source_warehouse_id,
      source_warehouse_name: row.source_warehouse_name || null,
      dr_number: row.dr_number || null,
      vehicle_type: row.vehicle_type || null,
      driver: personNameOnly(row.driver) || null,
      driver_contact_number: row.driver_contact_number || null,
      driver_id_number: row.driver_id_number || null,
      driver_position: row.driver_position || null,
      driver_office: row.driver_office || null,
      vehicle_plate_number: row.vehicle_plate_number || null,
      has_dswd_escort: Boolean(row.has_dswd_escort),
      escort_name: row.has_dswd_escort ? (row.escort_name || null) : null,
      escort_contact_number: row.has_dswd_escort
        ? (row.escort_contact_number || null)
        : null,
      escort_id_number: row.has_dswd_escort ? (row.escort_id_number || null) : null,
      escort_position: row.has_dswd_escort ? (row.escort_position || null) : null,
      escort_office: row.has_dswd_escort ? (row.escort_office || null) : null,
      estimated_departure: row.estimated_departure || null,
      estimated_arrival: row.estimated_arrival || null,
      allows_multi_day_run: Boolean(row.allows_multi_day_run),
      mode_of_transportation: primaryModeOfTransportation(row.mode_of_transportation) || null,
      land_transportation_source: row.land_transportation_source || null,
      warehouse_released_at: row.warehouse_released_at || null,
      release_authorized_by: row.release_authorized_by || null,
      release_authorizer_position: row.release_authorizer_position || null,
      release_authorizer_office: row.release_authorizer_office || null,
      release_witness_affiliation: row.release_witness_affiliation || null,
      release_witnessed_by: row.release_witnessed_by || null,
      release_witness_contact_number: row.release_witness_contact_number || null,
      release_witness_id_number: row.release_witness_id_number || null,
      release_witness_position: row.release_witness_position || null,
      release_witness_office: row.release_witness_office || null,
      loaded_at: row.loaded_at || null,
      loading_remarks: row.loading_remarks || null,
      loaded_items: (row.loaded_items || []).map((line) => ({
        requisition_issuance_item_id: line.requisition_issuance_item_id ?? null,
        item_name: line.item_name || null,
        loaded_quantity:
          line.loaded_quantity === "" || line.loaded_quantity == null
            ? null
            : Number(line.loaded_quantity),
        planned_quantity:
          line.planned_quantity === "" || line.planned_quantity == null
            ? null
            : Number(line.planned_quantity),
        remarks: line.remarks || null,
      })),
      departed_at: row.departed_at || null,
      actual_arrival: row.actual_arrival || null,
      delivered_at: row.actual_arrival
        ? String(row.actual_arrival).slice(0, 10)
        : (row.delivered_at || null),
      fully_delivered: row.fully_delivered || null,
      received_by: personNameOnly(row.received_by) || null,
      received_by_id_number: row.received_by_id_number || null,
      received_by_position: row.received_by_position || null,
      received_by_office: row.received_by_office || null,
      received_at: row.received_at || null,
      receiver_contact: row.receiver_contact || null,
      receipt_acknowledged: Boolean(row.receipt_acknowledged),
      receipt_remarks: row.receipt_remarks || null,
    }));
    const legacy = mirrorLegacyVehicleFields(
      vehicleDetails.length > 0 ? vehicleDetails : form.data.vehicle_details || [],
    );
    const payload = {
      ...form.data,
      local_handover_details: hasLocalSourcePlan ? {
        ...(form.data.local_handover_details || {}),
        source_warehouse_id: localSourceWarehouses[0]?.id ?? null,
        source_warehouse_name: localSourceWarehouses[0]?.name || null,
      } : form.data.local_handover_details,
      status: nextStatus || form.data.status || "draft",
      vehicle_details: vehicleDetails,
      number_of_vehicles: vehicleDetails.length || null,
      vehicle_id: null,
      driver: legacy.driver || null,
      driver_contact_number: legacy.driver_contact_number || null,
      vehicle_plate_number: legacy.vehicle_plate_number || null,
      vehicle_types: legacy.vehicle_types,
      mode_of_transportation: legacy.mode_of_transportation,
      dispatch_officer: dispatchOfficerName || form.data.dispatch_officer || null,
      dispatch_date: legacy.dispatch_date || null,
      estimated_arrival: legacy.estimated_arrival || null,
      warehouse_released_at: legacy.warehouse_released_at || null,
      release_witnessed_by: legacy.release_witnessed_by || null,
      loaded_at: legacy.loaded_at || null,
      loading_remarks: legacy.loading_remarks || null,
      departed_at: legacy.departed_at || null,
      actual_arrival: legacy.actual_arrival || null,
      delivered_at: legacy.delivered_at || null,
      fully_delivered: legacy.fully_delivered || null,
      received_by: legacy.received_by || null,
      received_at: legacy.received_at || null,
      receiver_contact: legacy.receiver_contact || null,
      receipt_acknowledged: Boolean(legacy.receipt_acknowledged),
      receipt_remarks: legacy.receipt_remarks || null,
      returned_quantity:
        returnVarianceRows.reduce((sum, row) => sum + row.variance, 0),
      has_returned_items: returnVarianceRows.length > 0 ? "Yes" : "No",
      returned_particulars: returnVarianceRows.map((row) => row.item.item_name).join(", ") || null,
      returned_reason: returnVarianceRows
        .map((row) => `${row.item.item_name}: ${row.item.return_reason || ""}`)
        .join("\n") || null,
      items: (form.data.items || []).map((item) => {
        const loadedSum = sumLoadedAcrossVehicles(form.data.vehicle_details, item);
        return {
          ...item,
          loaded_quantity: loadedSum > 0 ? loadedSum : null,
          received_quantity: item.received_quantity === "" ? null : Number(item.received_quantity),
          return_reason: item.return_reason || null,
          variance_disposition: item.variance_disposition || null,
          variance_resolution: item.variance_resolution || item.return_reason || null,
        };
      }),
      ...(scopedWarehouseRelease
        ? { release_vehicle_indexes: releaseVehicleIndexes }
        : {}),
      ...(scopedVehicleTransit
        ? { transit_vehicle_indexes: transitVehicleIndexes }
        : {}),
      ...(options.releaseLocal ? { release_local_handover: true } : {}),
    };

    // Inertia v2 useForm.transform() returns void — do not chain .post/.put.
    form.transform(() => payload);
    const submitOptions = {
      preserveScroll: true,
      // PUT/POST visits preserve React state by default. A workflow transition
      // changes the bucket, active stage, DR numbering, and saved form payload;
      // retaining the old modal instance makes it render the pre-transition form
      // until a manual reload. Rehydrate from the redirected server record instead.
      // Partial warehouse release stays on "planned" but still needs a full rehydrate
      // so warehouse_released_at stamps and inventory history refresh.
      preserveState: String(nextStatus || form.data.status || "draft") === String(planStatus)
        && String(nextStatus || "draft") === "draft"
        && !scopedWarehouseRelease
        && !scopedVehicleTransit,
      onError: (errors) => {
        setClientMissingHints(Object.keys(errors || {}).map(humanizeFieldKey));
        notifyMissingVehicleFields(errors, nextStatus);
      },
      onSuccess: () => {
        form.clearErrors();
        setClientMissingHints([]);
        setRequirementFocusStatus(null);
      },
    };
    if (editing) {
      form.put(`/dispatches/${selectedDispatch.id}`, submitOptions);
      return;
    }

    form.post("/dispatches", submitOptions);
  };

  const previewTabs = documentPreview?.tabs ?? null;

  const readyRows = eligibleRequests.map((row) => {
    const risNo = formatRisDrNo(row.ris);
    const slipStatus = risSlipStatusMeta(row.ris?.status);
    const preparedDate = formatRisPreparedDate(row.ris);
    return (
      <tr key={row.id} className="hover:bg-emerald-50/40">
        <td className="px-4 py-3 font-semibold text-slate-900">{row.reference_number || "—"}</td>
        <td className="px-4 py-3">
          <div className="flex flex-col gap-0.5">
            <span className="font-semibold text-slate-900">{risNo}</span>
            {row.ris?.ris_number && !row.ris?.dr_number ? (
              <span className="text-[10px] font-semibold uppercase tracking-wide text-amber-600">
                RIS · DR pending
              </span>
            ) : null}
          </div>
        </td>
        <td className="px-4 py-3">{row.ris?.recipient || row.requesting_agency || row.lgu || "—"}</td>
        <td className="px-4 py-3">{row.ris?.delivery_site || "—"}</td>
        <td className="px-4 py-3">
          <div
            className="dromis-tip flex w-fit flex-col gap-1"
            data-tip={RIS_STATUS_TIP}
            data-tip-side="top"
          >
            <span
              className={`inline-flex w-fit rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide ${slipStatus.className}`}
            >
              {slipStatus.label}
            </span>
            <span className="text-xs text-slate-500">
              {preparedDate ? `Prepared: ${preparedDate}` : "Prepared date unavailable"}
            </span>
          </div>
        </td>
        <td className="px-4 py-3 text-right">
          <div className="inline-flex items-center justify-end gap-2">
            <button
              type="button"
              onClick={() => openReadyDocuments(row)}
              className="dromis-tip inline-flex items-center justify-center rounded-md border border-slate-200 bg-white p-2 text-slate-700 hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800"
              data-tip="View LGU request, assessment, and RIS"
              data-tip-side="left"
              aria-label="View LGU request, assessment, and RIS"
              title="View LGU request, assessment, and RIS"
            >
              <Eye className="h-4 w-4" />
            </button>
            <button
              type="button"
              onClick={() => openCreate(row)}
              className="dromis-tip inline-flex items-center gap-1 rounded-md bg-emerald-700 px-2.5 py-1.5 text-[10px] font-black uppercase text-white hover:bg-emerald-800"
              data-tip="Create a dispatch plan for this prepared RIS/DR."
              data-tip-side="left"
              aria-label={`Create plan for ${row.reference_number || "prepared RIS/DR"}`}
            >
              <Plus className="h-3 w-3" /> Plan
            </button>
          </div>
        </td>
      </tr>
    );
  });

  const listRows = (dispatches?.data || []).map((dispatch) => (
    <tr
      key={dispatch.id}
      className="cursor-pointer hover:bg-emerald-50/50"
      onClick={() => openEdit(dispatch)}
    >
      <td className="px-4 py-3 font-semibold text-slate-900">{dispatch.dispatch_number}</td>
      <td className="px-4 py-3">{dispatch.request?.reference_number || "—"}</td>
      <td className="px-4 py-3">
        {dispatch.status === "draft" ? ((dispatch.ris || dispatch.requisition_issuance_slip)?.ris_number || "—") : formatRisDrNo(dispatch.ris || dispatch.requisition_issuance_slip)}
      </td>
      <td className="px-4 py-3">{dispatch.destination || "—"}</td>
      <td className="px-4 py-3">{dispatch.receiving_agency_lgu || "—"}</td>
      <td className="px-4 py-3">
        {dispatch.created_by_name || dispatch.creator?.name || "—"}
      </td>
      <td className="px-4 py-3">{formatDateTime(dispatch.dispatch_date, "—")}</td>
      <td className="px-4 py-3">
        <div className="flex flex-col gap-1.5">
          <StatusBadge status={dispatch.status} />
          <DispatchProgressTracker
            variant="compact"
            status={dispatch.status}
            vehicleDetails={dispatch.vehicle_details || []}
            fulfillmentType={dispatch.fulfillment_type}
            localOnly={dispatchIsLocalOnly(dispatch, warehouseCatalog)}
            timeline={dispatch.history || dispatch.status_timeline || []}
          />
        </div>
      </td>
      <td className="px-4 py-3 text-right">
        <div className="inline-flex items-center justify-end gap-2" onClick={(event) => event.stopPropagation()}>
          <TableActionButton
            icon={Eye}
            label={dispatch.status === "draft" ? "View request, assessment, and RIS" : "View documents and Delivery Receipts"}
            tone="indigo"
            onClick={() => openDispatchDocuments(dispatch)}
          />
          <TableActionButton
            icon={Clock3}
            label="View plan history"
            tone="slate"
            onClick={() => setHistoryModal({ open: true, dispatch })}
          />
          <TableActionButton
            icon={Pencil}
            label="Edit dispatch / delivery"
            tone="emerald"
            onClick={() => openEdit(dispatch)}
          />
        </div>
      </td>
    </tr>
  ));

  const editorVisible = editorOpen && (reference || selectedDispatch);
  const bucketContent = ({
    still_for_action: {
      title: "Draft Dispatches",
      description: "Dispatches that have been started but have not yet been marked Planned.",
      empty: "No draft dispatches are waiting for action.",
    },
    in_progress: {
      title: "In-Progress Dispatches",
      description: "Planned dispatches currently awaiting release, transit/pickup, or recipient receipt.",
      empty: "No planned or active dispatch transactions are in progress.",
    },
    completed: {
      title: "Completed Dispatches",
      description: "Dispatch transactions with confirmed recipient receipt, retained for reference and audit.",
      empty: "No completed dispatch transactions are available yet.",
    },
    all: {
      title: "All Dispatches",
      description: "All draft, active, and completed dispatch transactions.",
      empty: "No dispatches are available yet.",
    },
  })[bucket] || {
    title: "Dispatch / Delivery",
    description: "Transport, release, transit/pickup, and recipient receipt transactions.",
    empty: "No dispatches are available in this view.",
  };

  return (
    <AppLayout title={escortWorkspace ? "Delivery Escort Workspace" : "Dispatch/Delivery"}>
      <Head title={escortWorkspace ? "Delivery Escort Workspace" : "Dispatch/Delivery"} />

      <SectionTabs
        label={escortWorkspace ? "Delivery Escort Workspace Views" : "Dispatch/Delivery Views"}
        appearance="stack"
        value={bucket}
        ariaLabel="Dispatch plan views"
        tabs={[
          ...(!escortWorkspace
            ? [
                {
                  id: "still_for_action",
                  label: "Still for Action",
                  icon: ClipboardList,
                  count: counts.still_for_action ?? 0,
                  href: "/dispatches?bucket=still_for_action",
                },
              ]
            : []),
          {
            id: "in_progress",
            label: "In Progress",
            icon: Clock3,
            count: counts.in_progress ?? 0,
            href: `${escortWorkspace ? "/delivery-escort" : "/dispatches"}?bucket=in_progress`,
          },
          {
            id: "completed",
            label: "Completed",
            icon: CheckCircle2,
            count: counts.completed ?? 0,
            href: `${escortWorkspace ? "/delivery-escort" : "/dispatches"}?bucket=completed`,
          },
        ]}
      />

      <div className="space-y-6">
        {showReadyQueue && (
        <Card id="dispatch-ready" className="scroll-mt-28">
          <div className="mb-4 flex items-start justify-between gap-3">
            <div>
              <h2 className="font-semibold text-slate-950">Ready for Dispatch / Delivery</h2>
              <p className="text-xs text-slate-500">
                Prepared RIS/DR with no dispatch yet. Use + Plan to start logistics.
              </p>
            </div>
            <span className="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-black uppercase text-amber-800">
              {eligibleRequests.length}
            </span>
          </div>
          <DataTable
            columns={[
              "Request",
              "RIS / DR No.",
              "Destination / LGU",
              "Delivery site",
              "RIS / DR Status",
              { label: "Actions", align: "right", actionColumn: true },
            ]}
            rows={readyRows}
          />
          {eligibleRequests.length === 0 && (
            <p className="px-4 py-8 text-center text-sm text-slate-500">
              No prepared RIS / DR waiting for Dispatch / Delivery.
            </p>
          )}
        </Card>
        )}

        <Card id="dispatch-list" className="scroll-mt-28">
          <div className="mb-4 flex items-center justify-between gap-3">
            <div>
              <h2 className="font-semibold text-slate-950">{bucketContent.title}</h2>
              <p className="text-xs text-slate-500">
                {bucketContent.description}
              </p>
            </div>
            {showHeaderCreateCta && (
              <button
                type="button"
                onClick={() => setRisPickerOpen(true)}
                className="dromis-tip inline-flex items-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white"
                data-tip="Choose which prepared RIS/DR to create a dispatch plan for."
                data-tip-side="bottom"
                data-tip-align="right"
                aria-label="Choose prepared RIS to create a dispatch plan"
              >
                <Plus className="h-4 w-4" /> Create plan
              </button>
            )}
          </div>
          <DataTable
            columns={[
              "Dispatch No.",
              "Request",
              "RIS / DR",
              "Destination",
              "Agency / LGU",
              "Created by",
              "Date",
              "Progress",
              { label: "Actions", align: "right", actionColumn: true },
            ]}
            rows={listRows}
          />
          {(dispatches?.data || []).length === 0 && (
            <p className="px-4 py-8 text-center text-sm text-slate-500">
              {bucketContent.empty}
            </p>
          )}
          {dispatches?.links?.length > 3 && (
            <div className="mt-4 flex flex-wrap gap-2 px-1">
              {dispatches.links.map((link, index) => (
                <Link
                  key={`${link.label}-${index}`}
                  href={link.url || "#"}
                  className={`rounded-md px-2 py-1 text-xs font-semibold ${
                    link.active
                      ? "bg-emerald-700 text-white"
                      : link.url
                        ? "border border-slate-200 text-slate-700"
                        : "pointer-events-none text-slate-300"
                  }`}
                  dangerouslySetInnerHTML={{ __html: link.label }}
                />
              ))}
            </div>
          )}
        </Card>
      </div>

      {editorVisible && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-1.5 backdrop-blur-sm sm:p-2"
          role="dialog"
          aria-modal="true"
          aria-labelledby="dispatch-plan-editor-title"
        >
          <div
            className="relative z-10 flex h-[calc(100vh-0.75rem)] w-full max-w-none flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-950 sm:h-[calc(100vh-1rem)]"
            onClick={(event) => event.stopPropagation()}
            onMouseDown={(event) => event.stopPropagation()}
          >
            <div className="relative z-20 shrink-0 border-b border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
              <div className="flex items-center gap-4 px-4 py-2.5">
                <div className="min-w-0">
                  <p className="text-[10px] font-black uppercase tracking-wide text-emerald-700">
                    Dispatch / Delivery
                  </p>
                  <h2
                    id="dispatch-plan-editor-title"
                    className="mt-0.5 truncate font-black text-slate-900 dark:text-zinc-50"
                  >
                    {editing ? selectedDispatch.dispatch_number : "New Dispatch / Delivery"}
                  </h2>
                  <p className="truncate text-xs text-slate-500">
                    {reference?.reference_number || "—"}
                    {ris?.ris_number ? ` · ${ris.ris_number}` : ""}
                    {planStatus !== "draft" && ris?.dr_number ? ` · ${ris.dr_number}` : ""}
                  </p>
                </div>
                <div className="hidden min-w-0 flex-1 border-l border-slate-200 pl-4 md:block">
                  <p className="mb-1 text-[9px] font-black uppercase tracking-[.16em] text-slate-400">
                    Progress Tracker
                  </p>
                  <DispatchStepper
                    dense
                    status={planStatus}
                    onStepClick={scrollToStep}
                    localOnly={localOnlyPlan}
                    fulfillmentType={form.data.fulfillment_type || selectedDispatch?.fulfillment_type}
                    vehicleDetails={form.data.vehicle_details || []}
                    timeline={selectedDispatch?.history || selectedDispatch?.status_timeline || []}
                  />
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <StatusBadge status={form.data.status} />
                  <button
                    type="button"
                    onClick={closeEditor}
                    className="dromis-tip rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800"
                    data-tip="Close editor"
                    data-tip-side="bottom"
                    aria-label="Close editor"
                  >
                    <X className="h-5 w-5" />
                  </button>
                </div>
              </div>
              <div className="border-t border-slate-100 px-4 py-1.5 md:hidden">
                <p className="mb-1 text-[9px] font-black uppercase tracking-[.16em] text-slate-400">
                  Progress Tracker
                </p>
                <DispatchStepper
                  dense
                  status={planStatus}
                  onStepClick={scrollToStep}
                  localOnly={localOnlyPlan}
                  fulfillmentType={form.data.fulfillment_type || selectedDispatch?.fulfillment_type}
                  vehicleDetails={form.data.vehicle_details || []}
                  timeline={selectedDispatch?.history || selectedDispatch?.status_timeline || []}
                />
              </div>
            </div>

            <form
              className="flex min-h-0 flex-1 flex-col"
              onSubmit={(event) => {
                event.preventDefault();
                submit(form.data.status || "draft");
              }}
            >
              <div className="flex min-h-0 flex-1 overflow-hidden">
                <aside className={`hidden shrink-0 border-r border-slate-200 bg-slate-50/90 transition-[width] duration-200 lg:flex lg:flex-col ${dispatchNavCollapsed ? "w-14" : "w-56"}`}>
                  <div className="flex items-center justify-between border-b border-slate-200 px-3 py-2">
                    {!dispatchNavCollapsed && <p className="text-[10px] font-black uppercase tracking-[.14em] text-slate-500">Form navigator</p>}
                    <button
                      type="button"
                      onClick={() => setDispatchNavCollapsed((value) => !value)}
                      className="ml-auto rounded-md border border-slate-200 bg-white p-1.5 text-slate-500 hover:border-emerald-300 hover:text-emerald-700"
                      title={dispatchNavCollapsed ? "Expand form navigator" : "Collapse form navigator"}
                      aria-label={dispatchNavCollapsed ? "Expand form navigator" : "Collapse form navigator"}
                    >
                      {dispatchNavCollapsed ? <ChevronRight className="h-4 w-4" /> : <ChevronLeft className="h-4 w-4" />}
                    </button>
                  </div>
                  <nav className="min-h-0 flex-1 space-y-1 overflow-y-auto p-2" aria-label="Dispatch form sections">
                    {dispatchNavigatorSections.map((section) => (
                      <div key={section.target} className="rounded-lg">
                        <button type="button" disabled={section.enabled === false} onClick={() => section.enabled !== false && scrollToStep(section.target)} title={section.enabled === false ? `${section.label} is not yet available` : section.label} className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-[11px] font-black text-slate-700 hover:bg-emerald-100 hover:text-emerald-900 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent">
                          <span className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-[9px] ${section.enabled === false ? "bg-slate-200 text-slate-400" : "bg-emerald-700 text-white"}`}>{section.number}</span>
                          {!dispatchNavCollapsed && <span className="truncate">{section.label}</span>}
                        </button>
                        {!dispatchNavCollapsed && <div className="ml-5 border-l border-slate-200 pl-3">
                          {section.children.map((child) => <button key={`${section.target}-${child.label}`} type="button" disabled={section.enabled === false} onClick={() => section.enabled !== false && scrollToStep(child.target)} className="block w-full truncate py-1 text-left text-[10px] font-semibold text-slate-500 hover:text-emerald-700 disabled:cursor-not-allowed disabled:text-slate-300">{child.label}</button>)}
                        </div>}
                      </div>
                    ))}
                  </nav>
                </aside>
                <div className="relative z-0 min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                <StatusTimeline
                  status={form.data.status}
                  timeline={selectedDispatch?.history || selectedDispatch?.status_timeline || []}
                  creatorName={
                    selectedDispatch?.created_by_name
                    || selectedDispatch?.creator?.name
                    || null
                  }
                />
                {selectedDispatch?.fulfillment_status === "partially_delivered" && (
                  <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-950">
                    <div>
                      <p className="text-xs font-black uppercase tracking-wide">Partially delivered - follow-up required</p>
                      <p className="mt-1 text-xs font-semibold">
                        {formatWholeQuantity(selectedDispatch.fulfillment_summary?.deferred || 0, "0")} deferred, returned, or cancelled unit(s) require a separate follow-up dispatch. The original DR and vehicle record stay unchanged; assign a new vehicle and generate a different DR for the replacement trip.
                      </p>
                    </div>
                    {!escortWorkspace && canManageDispatches && (
                      <button
                        type="button"
                        disabled={form.processing}
                        onClick={() => router.post(`/dispatches/${selectedDispatch.id}/follow-up`, {}, { preserveScroll: true })}
                        className="rounded-lg bg-amber-700 px-3 py-2 text-xs font-black text-white shadow-sm hover:bg-amber-800 disabled:opacity-60"
                      >
                        Create New Dispatch / DR
                      </button>
                    )}
                  </div>
                )}
                {editing && (
                  <div className="flex justify-end">
                    <button
                      type="button"
                      onClick={() => setHistoryModal({ open: true, dispatch: selectedDispatch })}
                      className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 text-[10px] font-black uppercase tracking-wide text-slate-600 hover:bg-slate-50"
                    >
                      <Clock3 className="h-3.5 w-3.5" /> History
                    </button>
                  </div>
                )}

              <FormSection
                id="dispatch-source"
                number="01"
                title="Draft / Source"
                responsible={`Assigned Dispatch Officer - ${form.data.dispatch_officer || dispatchOfficerName || "Not assigned"}`}
                subtitle="Save Draft — request, RIS reference, destination, and receiving details"
                icon={ClipboardList}
              >
                <div id="dispatch-delivery-mode" className="scroll-mt-4 mb-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                  <p className="text-[10px] font-black uppercase tracking-[.16em] text-slate-600">Delivery mode <span className="text-rose-600">*</span></p>
                  <p className="mt-1 text-xs text-slate-600">Select who will take custody of and transport the released items. This controls the applicable stages and receipt location.</p>
                  <div className="mt-3 grid gap-3 md:grid-cols-2">
                    {[
                      { value: "field_delivery", title: "DSWD / Partner Delivery", text: "Items are delivered to the destination. Arrival, unloading, and destination receipt remain part of the workflow." },
                      { value: "warehouse_pickup", title: "Partner/Recipient Pickup", text: "The authorized partner or recipient collects and receives the items at the source warehouse. Destination monitoring remains optional." },
                    ].map((option) => {
                      const active = form.data.fulfillment_type === option.value;
                      return <button key={option.value} type="button" disabled={deliveryModeConfirmed} onClick={() => setDeliveryModeConfirmation(option)} className={`rounded-lg border p-3 text-left disabled:cursor-default ${active && deliveryModeConfirmed ? "border-emerald-500 bg-emerald-50 ring-1 ring-emerald-400" : "border-slate-200 bg-white hover:border-slate-300"}`}>
                        <span className="block text-xs font-black text-slate-900">{option.title}</span>
                        <span className="mt-1 block text-xs leading-5 text-slate-600">{option.text}</span>
                      </button>;
                    })}
                  </div>
                  {deliveryModeConfirmed && planStatus === "draft" && <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-emerald-200 bg-white px-3 py-2"><p className="text-xs font-semibold text-emerald-800">Delivery mode confirmed. It is locked to protect workflow-specific entries.</p><button type="button" onClick={() => requestConfirmation({ title: "Cancel delivery mode?", message: "Vehicle, release and receipt entries created for this mode will be cleared. You will need to select and confirm a delivery mode again.", confirmLabel: "Cancel Delivery Mode", tone: "rose", onConfirm: cancelDeliveryMode })} className="rounded-md border border-rose-200 px-2.5 py-1.5 text-[10px] font-black uppercase text-rose-700 hover:bg-rose-50">Cancel delivery mode</button></div>}
                  {form.errors.fulfillment_type && <p className="mt-2 text-xs font-semibold text-rose-600">{form.errors.fulfillment_type}</p>}
                </div>
                {!deliveryModeConfirmed && <div className="rounded-xl border border-dashed border-amber-300 bg-amber-50 px-4 py-5 text-center"><p className="text-sm font-black text-amber-900">Confirm a delivery mode to continue</p><p className="mt-1 text-xs font-semibold text-amber-800">Destination, source warehouses, planning, vehicles, release and receipt fields remain hidden until a mode is confirmed.</p></div>}
                {deliveryModeConfirmed && <>
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                  <Field label="Assistance Request">
                    <Input value={reference?.reference_number || "—"} disabled />
                  </Field>
                  <Field label="RIS Number">
                    <Input value={ris?.ris_number || "—"} disabled />
                  </Field>
                  <Field label="RIS DRN">
                    <Input value={ris?.ris_drn || "—"} disabled />
                  </Field>
                </div>
                <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                  <Field
                    label="Destination / Delivery Site"
                    required={reqGroups.destination}
                    fieldKey="destination"
                    hint="Address from the RIS; select another registered site when needed"
                    error={form.errors.destination}
                  >
                    <SearchableSelect
                      options={[form.data.destination, ...(libraryOptions.delivery_site || [])]
                        .filter((value, index, values) => value && values.findIndex((entry) => String(entry).trim().toLowerCase() === String(value).trim().toLowerCase()) === index)
                        .map((value) => ({ value, label: value }))}
                      value={form.data.destination || ""}
                      onChange={(value) => form.setData("destination", Array.isArray(value) ? value[0] || "" : value || "")}
                      placeholder="Search or select delivery site"
                    />
                  </Field>
                  <Field
                    label="Receiving Agency / Organization"
                    required={reqGroups.destination}
                    fieldKey="receiving_agency_lgu"
                    hint="From LGU request / RIS"
                    error={form.errors.receiving_agency_lgu}
                  >
                    <Input value={form.data.receiving_agency_lgu || "—"} disabled readOnly />
                  </Field>
                  <Field label="Receiving Representative">
                    <Input value={ris?.receiving_representative || "—"} disabled />
                  </Field>
                  <Field label="Contact (from RIS)">
                    <Input value={ris?.contact_number || "—"} disabled />
                  </Field>
                </div>
                <div id="dispatch-allocation" className="scroll-mt-4 mt-4 space-y-4">
                  {sourceWarehouses.length > 0 ? (
                    sourceWarehouses.map((warehouse) => {
                      const warehouseItems = (form.data.items || [])
                        .map((item, index) => ({ item, index }))
                        .filter(({ item }) =>
                          itemMatchesSourceWarehouse(
                            item,
                            warehouse.id,
                            warehouse.name,
                          ),
                        );
                      return (
                        <div
                          key={warehouse.key}
                          className="overflow-x-auto rounded-xl border border-slate-200"
                        >
                          <div className="border-b border-slate-100 bg-slate-50 px-3 py-2">
                            <div className="flex flex-wrap items-center gap-2">
                              <p className="text-[11px] font-black uppercase tracking-wide text-emerald-800">
                                Warehouse: {warehouse.name}
                              </p>
                              {warehouse.classification?.label && (
                                <span
                                  className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
                                    warehouseToneClasses(warehouse.classification.tone).badge
                                  }`}
                                >
                                  {warehouse.classification.label}
                                </span>
                              )}
                              {isReceivingLguWarehouse(warehouse) && (
                                <span className="text-[10px] font-bold uppercase tracking-wide text-emerald-700">
                                  No transport needed
                                </span>
                              )}
                            </div>
                          </div>
                          <table className="min-w-full text-sm">
                            <thead className="bg-white text-left text-[10px] font-black uppercase tracking-wide text-slate-500">
                              <tr>
                                <th className="px-3 py-2">Item</th>
                                <th className="px-3 py-2">Unit</th>
                                <th className="px-3 py-2">Brand</th>
                                <th className="px-3 py-2">Expiry</th>
                                <th className="px-3 py-2 text-right">Current Stockpile</th>
                                <th className="px-3 py-2 text-right">Available to Plan</th>
                                <th className="px-3 py-2 text-right">Allocated</th>
                                <th className="px-3 py-2 text-right">Unit Cost</th>
                                <th className="px-3 py-2 text-right">Total Cost</th>
                              </tr>
                            </thead>
                            <tbody>
                              {warehouseItems.map(({ item, index }) => {
                                const liveFallback = stockReferenceForItem(item, planningWarehouseStock);
                                const stock = {
                                  current_stockpile: item.current_stockpile ?? liveFallback.current_stockpile,
                                  available_to_plan: item.available_to_plan ?? liveFallback.available_to_plan,
                                };
                                return (
                                  <tr key={`alloc-${index}`} className="border-t border-slate-100">
                                    <td className="px-3 py-2 font-medium">{item.item_name}</td>
                                    <td className="px-3 py-2">{item.unit || "—"}</td>
                                    <td className="px-3 py-2">{meaningfulDimension(item.brand_description) || "—"}</td>
                                    <td className="px-3 py-2">{meaningfulDimension(item.expiry) || "—"}</td>
                                    <td className="px-3 py-2 text-right tabular-nums text-slate-600">
                                      {stock.current_stockpile == null
                                        ? "—"
                                        : formatWholeQuantity(stock.current_stockpile, "0")}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums text-slate-600">
                                      {stock.available_to_plan == null
                                        ? "—"
                                        : formatWholeQuantity(stock.available_to_plan, "0")}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                      {formatWholeQuantity(item.allocated_quantity, "0")}
                                    </td>
                                    <AllocationCostCells item={item} />
                                  </tr>
                                );
                              })}
                              {warehouseItems.length > 0 && <tr className="border-t-2 border-slate-300 bg-slate-50 font-black">
                                <td colSpan={6} className="px-3 py-2 text-right uppercase">Total</td>
                                <td className="px-3 py-2 text-right tabular-nums">{formatWholeQuantity(warehouseItems.reduce((sum, row) => sum + Number(row.item.allocated_quantity || 0), 0), "0")}</td>
                                <td className="px-3 py-2 text-right text-slate-400">—</td>
                                <td className="px-3 py-2 text-right tabular-nums">{formatPeso(warehouseItems.reduce((sum, row) => sum + Number(allocationTotalCost(row.item) || 0), 0))}</td>
                              </tr>}
                            </tbody>
                          </table>
                        </div>
                      );
                    })
                  ) : (
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                      <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-[10px] font-black uppercase tracking-wide text-slate-500">
                          <tr>
                            <th className="px-3 py-2">Item</th>
                            <th className="px-3 py-2">Warehouse</th>
                            <th className="px-3 py-2">Unit</th>
                            <th className="px-3 py-2">Brand</th>
                            <th className="px-3 py-2">Expiry</th>
                            <th className="px-3 py-2 text-right">Current Stockpile</th>
                            <th className="px-3 py-2 text-right">Available to Plan</th>
                            <th className="px-3 py-2 text-right">Allocated</th>
                            <th className="px-3 py-2 text-right">Unit Cost</th>
                            <th className="px-3 py-2 text-right">Total Cost</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(form.data.items || []).map((item, index) => {
                            const liveFallback = stockReferenceForItem(item, planningWarehouseStock);
                            const stock = {
                              current_stockpile: item.current_stockpile ?? liveFallback.current_stockpile,
                              available_to_plan: item.available_to_plan ?? liveFallback.available_to_plan,
                            };
                            return (
                              <tr key={`alloc-${index}`} className="border-t border-slate-100">
                                <td className="px-3 py-2 font-medium">{item.item_name}</td>
                                <td className="px-3 py-2">{item.warehouse_name || "—"}</td>
                                <td className="px-3 py-2">{item.unit || "—"}</td>
                                <td className="px-3 py-2">{meaningfulDimension(item.brand_description) || "—"}</td>
                                <td className="px-3 py-2">{meaningfulDimension(item.expiry) || "—"}</td>
                                <td className="px-3 py-2 text-right tabular-nums text-slate-600">
                                  {stock.current_stockpile == null
                                    ? "—"
                                    : formatWholeQuantity(stock.current_stockpile, "0")}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums text-slate-600">
                                  {stock.available_to_plan == null
                                    ? "—"
                                    : formatWholeQuantity(stock.available_to_plan, "0")}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                  {formatWholeQuantity(item.allocated_quantity, "0")}
                                </td>
                                <AllocationCostCells item={item} />
                              </tr>
                            );
                          })}
                          {(form.data.items || []).length > 0 && <tr className="border-t-2 border-slate-300 bg-slate-50 font-black">
                            <td colSpan={7} className="px-3 py-2 text-right uppercase">Total</td>
                            <td className="px-3 py-2 text-right tabular-nums">{formatWholeQuantity((form.data.items || []).reduce((sum, item) => sum + Number(item.allocated_quantity || 0), 0), "0")}</td>
                            <td className="px-3 py-2 text-right text-slate-400">—</td>
                            <td className="px-3 py-2 text-right tabular-nums">{formatPeso((form.data.items || []).reduce((sum, item) => sum + Number(allocationTotalCost(item) || 0), 0))}</td>
                          </tr>}
                          {(form.data.items || []).length === 0 && (
                            <tr>
                              <td colSpan={10} className="px-3 py-4 text-center text-slate-500">
                                No allocation lines linked yet.
                              </td>
                            </tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
                </>}
              </FormSection>

              {deliveryModeConfirmed && <FormSection
                id="dispatch-stage-planning"
                number="02"
                title="Plan"
                responsible={`Assigned Dispatch Officer - ${form.data.dispatch_officer || dispatchOfficerName || "Not assigned"}`}
                subtitle="Assign vehicles and estimated schedules. Same-day departure/arrival is required for Confirm Release unless Multi-day run is checked."
                icon={Truck}
              >
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                  <Field
                    label="No. of Vehicles"
                    required={reqGroups.destination && remoteSourceWarehouses.length > 0}
                    fieldKey="number_of_vehicles"
                    error={form.errors.number_of_vehicles}
                    hint={
                      localOnlyPlan
                        ? "Not required — all allocations are receiving-LGU local stock"
                        : "Select a remote source warehouse, then + Add vehicle"
                    }
                  >
                    <Input
                      type="text"
                      inputMode="numeric"
                      value={wholeQuantityInputValue(
                        displayedVehicleCount
                        || form.data.number_of_vehicles
                        || 0,
                      )}
                      disabled
                      readOnly
                    />
                  </Field>
                  <Field
                    label="Dispatch Officer"
                    hint="Assigned user responsible for the Draft and Plan stages (not editable)"
                    error={form.errors.dispatch_officer || form.errors.dispatcher}
                  >
                    <Input
                      value={form.data.dispatch_officer || dispatchOfficerName}
                      disabled
                      readOnly
                    />
                  </Field>
                </div>

                <div className="mt-4">
                  <Field label="Planning Remarks" error={form.errors.remarks}>
                    <textarea
                      className="form-input min-h-20 w-full"
                      value={form.data.remarks || ""}
                      onChange={(e) => form.setData("remarks", e.target.value)}
                    />
                  </Field>
                </div>

                <div className="mt-5 space-y-3">
                  <div id="dispatch-source-warehouse-selector" className="scroll-mt-4 space-y-3 rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                    <div>
                      <p className="text-[11px] font-black uppercase tracking-wide text-slate-600">
                        1. Select source warehouse
                      </p>
                      <p className="text-xs font-semibold text-slate-400">
                        Warehouses already holding stock for the recipient need no transport. Other LGU, DSWD, or partner source warehouses require vehicle delivery planning.
                      </p>
                    </div>
                    {localOnlyPlan && (
                      <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900">
                        All allocated stock is already at the recipient custody location. Vehicle delivery planning is not required; the recipient handles onward distribution.
                      </p>
                    )}
                    {planStatus === "planned"
                      && !localOnlyPlan
                      && remoteWarehouseReleaseProgress.total > 1
                      && remoteWarehouseReleaseProgress.released > 0 && (
                      <p className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-900">
                        Released {remoteWarehouseReleaseProgress.released} of {remoteWarehouseReleaseProgress.total} warehouses
                        {remoteWarehouseReleaseProgress.pendingNames.length
                          ? ` — ${remoteWarehouseReleaseProgress.pendingNames.join(", ")} still planned`
                          : ""}. Confirm Release applies only to the selected warehouse tab.
                      </p>
                    )}
                    {sourceWarehouses.length > 0 ? (
                      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {sourceWarehouses.map((warehouse) => {
                          const selected = selectedPlanningWarehouseKey === warehouse.key;
                          const tones = warehouseToneClasses(warehouse.classification?.tone);
                          const localWh = isReceivingLguWarehouse(warehouse);
                          const group = vehiclesByWarehouse.groups.find(
                            (entry) => entry.warehouse.key === warehouse.key,
                          );
                          const vehicleCountForWh = group?.vehicles.length || 0;
                          const warehouseReleased = !localWh
                            && vehicleCountForWh > 0
                            && group.vehicles.every(({ row }) =>
                              Boolean(String(row?.warehouse_released_at || "").trim()),
                            );
                          return (
                            <button
                              key={warehouse.key}
                              type="button"
                              onClick={() => setSelectedPlanningWarehouseKey(warehouse.key)}
                              className={`rounded-xl border px-3 py-3 text-left transition ${
                                selected
                                  ? `${tones.card} ring-2`
                                  : "border-slate-200 bg-white hover:border-slate-300"
                              }`}
                            >
                              <div className="flex flex-wrap items-start justify-between gap-2">
                                <p className={`text-sm font-bold ${selected ? tones.heading : "text-slate-800"}`}>
                                  {warehouse.name}
                                </p>
                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${tones.badge}`}>
                                  {warehouse.classification?.label || "Warehouse"}
                                </span>
                              </div>
                              <p className="mt-1 text-[11px] font-semibold text-slate-500">
                                {[warehouse.warehouse_type, warehouse.ownership, warehouse.municipality]
                                  .filter(Boolean)
                                  .join(" · ") || "From allocation"}
                              </p>
                              <p className="mt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                                {localWh
                                  ? "LGU local stock — no transport needed"
                                  : warehouseReleased
                                    ? "Released"
                                    : `${vehicleCountForWh} vehicle${vehicleCountForWh === 1 ? "" : "s"} assigned`}
                              </p>
                            </button>
                          );
                        })}
                      </div>
                    ) : (
                      <p className="rounded-lg border border-dashed border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-500">
                        No source warehouses on allocation lines yet. You can still add vehicles; assign warehouses after allocations are linked.
                      </p>
                    )}
                  </div>

                  {false && (
                    <div className="sticky top-0 z-30 -mx-1 rounded-xl border border-slate-200 bg-white/95 p-2 shadow-md backdrop-blur">
                      <div className="flex items-center gap-2 overflow-x-auto pb-0.5">
                        <span className="shrink-0 px-1 text-[10px] font-black uppercase tracking-wide text-slate-500">Warehouse</span>
                        {sourceWarehouses.map((warehouse, warehouseIndex) => {
                          const selected = selectedPlanningWarehouseKey === warehouse.key;
                          const localWh = isReceivingLguWarehouse(warehouse);
                          return <button
                            key={`warehouse-nav-${warehouse.key}`}
                            type="button"
                            onClick={() => setSelectedPlanningWarehouseKey(warehouse.key)}
                            className={`shrink-0 rounded-lg border px-3 py-2 text-left transition ${selected ? "border-emerald-600 bg-emerald-50 text-emerald-900 ring-1 ring-emerald-500" : "border-slate-200 bg-white text-slate-600 hover:border-emerald-300 hover:bg-emerald-50/50"}`}
                          >
                            <span className="block text-[9px] font-black uppercase tracking-wide opacity-70">
                              {warehouseIndex + 1} of {sourceWarehouses.length} · {localWh ? "Local release" : "Transport required"}
                            </span>
                            <span className="block max-w-56 truncate text-xs font-bold">{warehouse.name}</span>
                          </button>;
                        })}
                      </div>
                      <p className="mt-1 px-1 text-[10px] font-semibold text-slate-400">Select another warehouse here without scrolling back to the source cards.</p>
                    </div>
                  )}

                  <div id="dispatch-selected-warehouse" className="scroll-mt-4 flex flex-wrap items-end justify-between gap-2 border-b border-slate-100 pb-2">
                    <div>
                      <p className="text-[11px] font-black uppercase tracking-wide text-slate-600">
                        2. Vehicles ({displayedVehicleCount})
                      </p>
                      <p className="text-xs font-semibold text-slate-400">
                        {selectedWarehouseIsLocal
                          ? "Items at this warehouse are already at the recipient custody location. Vehicle delivery planning is not required; the recipient handles onward distribution."
                          : selectedPlanningWarehouse
                            ? `Add vehicle loads from ${selectedPlanningWarehouse.name}. Planning is due first; Release, Transit, and Receipt unlock as the plan advances.`
                            : remoteSourceWarehouses.length > 0
                              ? "Select a remote source warehouse above, then add a vehicle for that warehouse."
                              : localOnlyPlan
                                ? "No transport needed for this plan."
                                : "Planning is due first. Release, Transit, and Receipt unlock as the plan advances — use Show early to encode ahead if needed."}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      {form.errors.vehicle_details && (
                        <p className="text-xs font-semibold text-rose-600">{form.errors.vehicle_details}</p>
                      )}
                      {!selectedWarehouseIsLocal && !localOnlyPlan && planStatus === "draft" && (
                        <button
                          type="button"
                          onClick={addVehicle}
                          disabled={
                            (sourceWarehouses.length > 0 && !selectedPlanningWarehouse)
                            || selectedWarehouseIsLocal
                          }
                          className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-white px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-emerald-800 shadow-sm hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          <Plus className="h-3.5 w-3.5" />
                          Add vehicle
                          {selectedPlanningWarehouse ? ` · ${selectedPlanningWarehouse.name}` : ""}
                        </button>
                      )}
                    </div>
                  </div>

                  {displayedVehicleCount > 1 && (
                    <div className="flex gap-2 overflow-x-auto rounded-xl border border-slate-200 bg-slate-50 p-2" role="tablist" aria-label="Dispatch vehicles">
                      {(form.data.vehicle_details || []).map((vehicle, index) => ({ vehicle, index })).filter(({ index }) => displayedVehicleIndexes.includes(index)).map(({ vehicle, index }) => {
                        const hasErrors = Object.keys(form.errors || {}).some((key) => key.startsWith(`vehicle_details.${index}.`));
                        return (
                          <button
                            key={`vehicle-tab-${index}`}
                            type="button"
                            role="tab"
                            aria-selected={activeVehicleIndex === index}
                            onClick={() => {
                              setActiveVehicleIndex(index);
                              setVehicleOpenMap((prev) => ({ ...prev, [index]: true }));
                            }}
                            className={`shrink-0 rounded-lg border px-3 py-2 text-left text-xs font-black transition ${
                              activeVehicleIndex === index
                                ? "border-emerald-600 bg-emerald-700 text-white shadow-sm"
                                : hasErrors
                                  ? "border-rose-300 bg-rose-50 text-rose-700"
                                  : "border-slate-200 bg-white text-slate-700 hover:border-emerald-300"
                            }`}
                          >
                            <span className="block">Vehicle {index + 1}{hasErrors ? " · Needs attention" : ""}</span>
                            <span className={`mt-0.5 block max-w-48 truncate text-[10px] font-semibold ${activeVehicleIndex === index ? "text-emerald-100" : "text-slate-400"}`}>
                              {[vehicle.vehicle_plate_number, vehicle.driver].filter(Boolean).join(" · ") || "Planning details"}
                            </span>
                          </button>
                        );
                      })}
                    </div>
                  )}

                  <div className="space-y-5">
                    {!selectedWarehouseIsLocal && displayedVehicleCount > 0 && (
                      <div className="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
                        <p className="font-black uppercase tracking-wide">One Delivery Receipt per vehicle</p>
                        <p className="mt-1 leading-relaxed">
                          A DR number is assigned only when warehouse release is confirmed. With multiple releases, suffixes follow actual release order: -A, -B, and onward.
                        </p>
                      </div>
                    )}
                    {selectedWarehouseIsLocal && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-sm font-black text-emerald-900">Local warehouse release detected</p><p className="mt-1 text-xs leading-5 text-emerald-800">The source warehouse already holds stock for the recipient. Vehicle, driver, escort, transit, arrival, and unloading fields are not required. Its Delivery Receipt is assigned only when release is confirmed.</p></div>{selectedDispatch?.local_handover_details?.released_at && selectedDispatch?.local_handover_details?.dr_number && <button type="button" onClick={openLocalHandoverDr} className="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-xs font-black text-emerald-800 hover:bg-emerald-100"><Eye size={14} />{selectedDispatch.local_handover_details.dr_number}</button>}</div></div>}
                    {displayedVehicleGroups.map((group) => (
                      <div key={group.key} className={`space-y-3 ${group.vehicles.some(({ index }) => index === activeVehicleIndex) ? "" : "hidden"}`}>
                        {group.warehouse && (
                          <div className="flex flex-wrap items-center gap-2">
                            <p
                              className={`text-[11px] font-black uppercase tracking-wide ${
                                warehouseToneClasses(group.warehouse.classification?.tone).heading
                              }`}
                            >
                              {group.warehouse.name}
                            </p>
                            {group.warehouse.classification?.label && (
                              <span
                                className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
                                  warehouseToneClasses(group.warehouse.classification?.tone).badge
                                }`}
                              >
                                {group.warehouse.classification.label}
                              </span>
                            )}
                            <span className="text-[10px] font-bold uppercase tracking-wide text-slate-400">
                              {group.vehicles.length} vehicle{group.vehicles.length === 1 ? "" : "s"}
                            </span>
                          </div>
                        )}
                        {group.vehicles.length === 0 ? (
                          <p
                            className={`rounded-lg border border-dashed px-3 py-2 text-xs font-semibold ${
                              isReceivingLguWarehouse(group.warehouse)
                                ? "border-emerald-200 bg-emerald-50 text-emerald-800"
                                : "border-slate-200 bg-white text-slate-400"
                            }`}
                          >
                            {isReceivingLguWarehouse(group.warehouse)
                              ? "Recipient-held stock — no transport needed. Items remain with the recipient for onward distribution."
                              : "No vehicles yet for this warehouse. Select it above, then click Add vehicle."}
                          </p>
                        ) : (
                          <div className="grid gap-4">
                            {group.vehicles.filter(({ index }) => index === activeVehicleIndex).map(({ row, index }) => (
                      <details
                        key={`vehicle-row-${index}`}
                        open={isVehicleOpen(index)}
                        onToggle={(event) => {
                          // Read open before setState — React nulls currentTarget after the handler,
                          // and controlled <details> fires toggle on mount when request_id auto-opens the editor.
                          const detailsEl = event.currentTarget;
                          if (!(detailsEl instanceof HTMLDetailsElement)) return;
                          const nextOpen = detailsEl.open;
                          setVehicleOpenMap((prev) => ({
                            ...prev,
                            [index]: nextOpen,
                          }));
                        }}
                        className="group rounded-xl border border-slate-200 bg-slate-50/70 open:bg-white open:shadow-sm"
                      >
                        <summary className="cursor-pointer list-none px-4 py-3 marker:content-none [&::-webkit-details-marker]:hidden">
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-[11px] font-black uppercase tracking-wide text-emerald-800">
                              Vehicle {index + 1}
                              {row.source_warehouse_name ? ` · ${row.source_warehouse_name}` : ""}
                              {row.vehicle_plate_number ? ` · ${row.vehicle_plate_number}` : ""}
                              {row.driver ? ` · ${row.driver}` : ""}
                            </p>
                            <div className="flex items-center gap-3">
                              {row.warehouse_released_at && row.dr_number && selectedDispatch?.id && vehicleIsAssignedForDr(row) ? (
                                <button
                                  type="button"
                                  className="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-[10px] font-black uppercase tracking-wide text-blue-800 hover:bg-blue-100"
                                  onClick={(event) => {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    openVehicleDr(row, index);
                                  }}
                                  title={`Preview ${row.dr_number}`}
                                >
                                  <Eye className="h-3.5 w-3.5" />
                                  {row.dr_number}
                                </button>
                              ) : row.warehouse_released_at && row.dr_number && selectedDispatch?.id ? (
                                <span
                                  className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-1.5 text-[10px] font-black uppercase tracking-wide text-slate-400"
                                  title={vehicleDrAssignmentHint(row)}
                                >
                                  <Eye className="h-3.5 w-3.5" />
                                  DR locked
                                </span>
                              ) : null}
                              {(form.data.vehicle_details || []).length > 1 && (
                                <button
                                  type="button"
                                  className="text-[10px] font-bold uppercase tracking-wide text-rose-600 hover:text-rose-700"
                                  onClick={(event) => {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    removeVehicle(index);
                                  }}
                                >
                                  Remove vehicle
                                </button>
                              )}
                              <span className="text-[10px] font-bold uppercase tracking-wide text-slate-400 group-open:hidden">
                                Expand
                              </span>
                            </div>
                          </div>
                        </summary>

                        <div className="space-y-5 border-t border-slate-100 px-4 py-4">
                          <StageBlock
                            title="Plan · Estimated schedule"
                            titleClassName="text-amber-700"
                            mode={stageUi("planning").mode}
                            locked={stageUi("planning").locked}
                            lockNotice={stageUi("planning").lockNotice}
                            forceOpen={Boolean(forcedStageKeys[`${index}-planning`])}
                            helper="Needed for Mark Planned. Same calendar day (Asia/Manila) is required before Confirm Release unless Multi-day run is checked."
                          >
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                              <Field
                                label="Source Warehouse"
                                required={reqGroups.planning && remoteSourceWarehouses.length > 0}
                                fieldKey={`vehicle_details.${index}.source_warehouse_id`}
                                hint={
                                  remoteSourceWarehouses.length > 1
                                    ? "Pre-filled from warehouse selection — change only if reassigning this vehicle"
                                    : remoteSourceWarehouses.length === 1
                                      ? "Auto-assigned from remote allocation"
                                      : "Remote source warehouses only (recipient-held stock needs no vehicle)"
                                }
                                error={
                                  form.errors[`vehicle_details.${index}.source_warehouse_id`]
                                  || form.errors[`vehicle_details.${index}.source_warehouse_name`]
                                }
                                className="sm:col-span-2"
                              >
                                <SearchableSelect
                                  options={remoteSourceWarehouses.map((wh) => ({
                                    value: String(wh.id ?? wh.key),
                                    label: wh.classification?.label
                                      ? `${wh.name} (${wh.classification.label})`
                                      : wh.name,
                                  }))}
                                  value={
                                    row.source_warehouse_id != null && row.source_warehouse_id !== ""
                                      ? String(row.source_warehouse_id)
                                      : remoteSourceWarehouses.find(
                                          (wh) =>
                                            wh.name
                                            && wh.name.toLowerCase()
                                              === String(row.source_warehouse_name || "").toLowerCase(),
                                        )?.key
                                        || ""
                                  }
                                  onChange={(value) => {
                                    const raw = Array.isArray(value) ? value[0] || "" : value || "";
                                    const selected = remoteSourceWarehouses.find(
                                      (wh) =>
                                        String(wh.id ?? wh.key) === String(raw)
                                        || wh.key === String(raw),
                                    );
                                    patchVehicleDetail(index, {
                                      source_warehouse_id: selected?.id ?? "",
                                      source_warehouse_name: selected?.name || "",
                                    });
                                  }}
                                  placeholder={
                                    remoteSourceWarehouses.length === 0
                                      ? "No remote warehouses on allocation"
                                      : "Select remote source warehouse"
                                  }
                                  disabled={remoteSourceWarehouses.length <= 1}
                                />
                              </Field>
                              <Field
                                label="Estimated Departure / Expected Delivery Date"
                                required={reqGroups.planning}
                                fieldKey={`vehicle_details.${index}.estimated_departure`}
                                hint="This date is recorded as the WIT Expected Delivery Date"
                                error={form.errors[`vehicle_details.${index}.estimated_departure`]}
                              >
                                <Input
                                  type="datetime-local"
                                  min={toDateTimeLocalValue()}
                                  value={row.estimated_departure || ""}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "estimated_departure", e.target.value)
                                  }
                                />
                              </Field>
                              <Field
                                label="Estimated Arrival"
                                required={reqGroups.planning}
                                fieldKey={`vehicle_details.${index}.estimated_arrival`}
                                hint="Planned date & time (not actual)"
                                error={form.errors[`vehicle_details.${index}.estimated_arrival`]}
                              >
                                <Input
                                  type="datetime-local"
                                  min={(() => {
                                    const nowMin = toDateTimeLocalValue();
                                    const dep = normalizeDateTimeLocal(row.estimated_departure);
                                    return dep && dep > nowMin ? dep : nowMin;
                                  })()}
                                  value={row.estimated_arrival || ""}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "estimated_arrival", e.target.value)
                                  }
                                />
                              </Field>
                            </div>

                            <div className="mt-3 rounded-lg border border-amber-100 bg-amber-50/60 px-3 py-2">
                              <label className="flex cursor-pointer items-start gap-2 text-xs font-semibold text-amber-950">
                                <input
                                  type="checkbox"
                                  className="mt-0.5 rounded border-slate-300"
                                  checked={Boolean(row.allows_multi_day_run)}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "allows_multi_day_run", e.target.checked)
                                  }
                                />
                                <span>
                                  <span className="font-black uppercase tracking-wide">Multi-day run</span>
                                  <span className="mt-0.5 block font-semibold normal-case text-amber-800/80">
                                    Skip the same-calendar-day check for Confirm Release when this vehicle
                                    must travel overnight or across days.
                                  </span>
                                </span>
                              </label>
                              {!row.allows_multi_day_run
                                && manilaCalendarDateFromLocal(row.estimated_departure)
                                && manilaCalendarDateFromLocal(row.estimated_arrival)
                                && manilaCalendarDateFromLocal(row.estimated_departure)
                                  !== manilaCalendarDateFromLocal(row.estimated_arrival) && (
                                <p className="mt-2 text-[11px] font-semibold text-amber-900">
                                  Departure and arrival are on different dates. Revise Planning dates before
                                  Confirm Release, or check Multi-day run if justified.
                                </p>
                              )}
                            </div>

                            <div className="mt-4 space-y-4">
                              <div>
                                <p className="mb-2 text-[10px] font-black uppercase tracking-[.16em] text-slate-600">
                                  Vehicle details
                                </p>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                  <Field
                                    label="Mode of Transportation"
                                    required={reqGroups.planning}
                                    fieldKey={`vehicle_details.${index}.mode_of_transportation`}
                                    error={
                                      form.errors[`vehicle_details.${index}.mode_of_transportation`]
                                      || form.errors[`vehicle_details.${index}.mode_of_transportation.0`]
                                    }
                                  >
                                    <SearchableSelect
                                      options={MODE_OF_TRANSPORTATION_OPTIONS}
                                      value={primaryModeOfTransportation(row.mode_of_transportation)}
                                      disabled={form.data.fulfillment_type === "warehouse_pickup"}
                                      onChange={(value) =>
                                        {
                                          const nextMode = Array.isArray(value)
                                            ? primaryModeOfTransportation(value)
                                            : primaryModeOfTransportation(value || "");
                                          const prevMode = primaryModeOfTransportation(
                                            row.mode_of_transportation,
                                          );
                                          const patch = {
                                            mode_of_transportation: nextMode,
                                          };
                                          // Clear driver details whenever mode changes so MyPortal
                                          // and library selections never linger across the DSWD boundary.
                                          if (nextMode !== prevMode) {
                                            patch.driver = "";
                                            patch.driver_id_number = "";
                                            patch.driver_contact_number = "";
                                            patch.driver_position = "";
                                            patch.driver_office = "";
                                          }
                                          patchVehicleDetail(index, patch);
                                        }
                                      }
                                      placeholder="Select mode of transportation"
                                    />
                                    {form.data.fulfillment_type === "warehouse_pickup" && <p className="mt-1 text-[10px] font-semibold text-emerald-700">Partner/Recipient Pickup uses Partner transportation.</p>}
                                  </Field>
                                  <Field
                                    label="Land Transportation Source"
                                    required={reqGroups.planning}
                                    fieldKey={`vehicle_details.${index}.land_transportation_source`}
                                    error={form.errors[`vehicle_details.${index}.land_transportation_source`]}
                                  >
                                    <SearchableSelect
                                      options={landSourceOptions}
                                      value={row.land_transportation_source || ""}
                                      onChange={(value) => setVehicleDetail(index, "land_transportation_source", Array.isArray(value) ? value[0] || "" : value || "")}
                                      placeholder="Select land transportation source"
                                      creatable
                                      createLabel="Add new land transportation source…"
                                      creating={creatingLandSource}
                                      onCreate={(suggested) => createLandSource(index, suggested)}
                                    />
                                    {landSourceNotice && Number(landSourceNotice.vehicleIndex) === index && (
                                      <p className={`mt-1 text-xs font-semibold ${landSourceNotice.type === "error" ? "text-rose-600" : "text-emerald-700"}`}>
                                        {landSourceNotice.message}
                                      </p>
                                    )}
                                  </Field>
                                  <Field
                                    label="Vehicle Type"
                                    required={reqGroups.planning}
                                    fieldKey={`vehicle_details.${index}.vehicle_type`}
                                    error={form.errors[`vehicle_details.${index}.vehicle_type`]}
                                  >
                                    <SearchableSelect
                                      options={vehicleTypeOptions}
                                      value={row.vehicle_type || ""}
                                      onChange={(value) =>
                                        setVehicleDetail(
                                          index,
                                          "vehicle_type",
                                          Array.isArray(value) ? value[0] || "" : value || "",
                                        )
                                      }
                                      placeholder="Select vehicle type"
                                      creatable
                                      createLabel="Add new vehicle type…"
                                      creating={creatingVehicleType}
                                      onCreate={(suggested) => openVehicleTypeModal(index, suggested)}
                                    />
                                    {vehicleTypeNotice
                                      && Number(vehicleTypeNotice.vehicleIndex) === index && (
                                      <p
                                        className={`mt-1 text-xs font-semibold ${
                                          vehicleTypeNotice.type === "error"
                                            ? "text-rose-600"
                                            : "text-emerald-700"
                                        }`}
                                      >
                                        {vehicleTypeNotice.message}
                                      </p>
                                    )}
                                  </Field>
                                  <Field
                                    label="Plate Number"
                                    required={reqGroups.planning}
                                    fieldKey={`vehicle_details.${index}.vehicle_plate_number`}
                                    error={form.errors[`vehicle_details.${index}.vehicle_plate_number`]}
                                  >
                                    <Input
                                      value={row.vehicle_plate_number}
                                      onChange={(e) =>
                                        setVehicleDetail(index, "vehicle_plate_number", e.target.value)
                                      }
                                    />
                                  </Field>
                                </div>
                              </div>

                              <div>
                                <p className="mb-2 text-[10px] font-black uppercase tracking-[.16em] text-slate-600">
                                  Driver details
                                </p>
                                <div
                                  className={
                                    primaryModeOfTransportation(row.mode_of_transportation) === "DSWD-Owned"
                                      ? "grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5"
                                      : "grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
                                  }
                                >
                                  <Field
                                    label="Driver's Name (Transported By)"
                                    required={reqGroups.planning}
                                    fieldKey={`vehicle_details.${index}.driver`}
                                    error={form.errors[`vehicle_details.${index}.driver`]}
                                    hint={
                                      primaryModeOfTransportation(row.mode_of_transportation) === "DSWD-Owned"
                                        ? `Search MyPortal — ${PREFILL_EDITABLE_HINT.toLowerCase()}`
                                        : `Search Driver library or add a new driver — ${PREFILL_EDITABLE_HINT.toLowerCase()}`
                                    }
                                  >
                                    {primaryModeOfTransportation(row.mode_of_transportation) === "DSWD-Owned" ? (
                                      <MyPortalEmployeeSelect
                                        value={row.driver || ""}
                                        onChange={(name) =>
                                          patchVehicleDetail(index, {
                                            driver: name,
                                            driver_id_number: "",
                                            driver_position: "",
                                            driver_office: "",
                                            driver_contact_number: "",
                                          })
                                        }
                                        onSelect={(employee) =>
                                          patchVehicleDetail(index, {
                                            driver: employee?.value || "",
                                            driver_id_number: employee?.id_number || "",
                                            driver_position: employee?.position || "",
                                            driver_office:
                                              employee?.section_unit_program
                                              || employee?.office
                                              || "",
                                            driver_contact_number:
                                              employee?.contact_number
                                              || employee?.mobile_no
                                              || employee?.mobile
                                              || employee?.phone
                                              || "",
                                          })
                                        }
                                        placeholder="Search MyPortal employee directory"
                                      />
                                    ) : (
                                      <SearchableSelect
                                        options={driverOptions}
                                        value={personNameOnly(row.driver || "")}
                                        onChange={(value) => {
                                          const name = personNameOnly(
                                            Array.isArray(value) ? value[0] || "" : value || "",
                                          );
                                          const selected = driverOptions.find(
                                            (option) => String(option.value) === String(name),
                                          );
                                          patchVehicleDetail(index, {
                                            driver: name,
                                            driver_contact_number: selected?.contact_number || "",
                                            driver_id_number: "",
                                            driver_position: selected?.position || "",
                                            driver_office: selected?.office || "",
                                          });
                                        }}
                                        onSelect={(option) =>
                                          patchVehicleDetail(index, {
                                            driver: personNameOnly(option?.value || option?.label || ""),
                                            driver_contact_number: option?.contact_number || "",
                                            driver_id_number: "",
                                            driver_position: option?.position || "",
                                            driver_office: option?.office || "",
                                          })
                                        }
                                        placeholder="Select driver"
                                        creatable
                                        createLabel="Add new driver…"
                                        creating={creatingDriver}
                                        onCreate={(suggested) => openDriverModal(index, suggested)}
                                      />
                                    )}
                                    {driverNotice
                                      && Number(driverNotice.vehicleIndex) === index && (
                                      <p
                                        className={`mt-1 text-xs font-semibold ${
                                          driverNotice.type === "error"
                                            ? "text-rose-600"
                                            : "text-emerald-700"
                                        }`}
                                      >
                                        {driverNotice.message}
                                      </p>
                                    )}
                                  </Field>
                                  {primaryModeOfTransportation(row.mode_of_transportation) === "DSWD-Owned" && (
                                    <Field
                                      label="Driver's ID Number"
                                      required={reqGroups.planning}
                                      fieldKey={`vehicle_details.${index}.driver_id_number`}
                                      error={form.errors[`vehicle_details.${index}.driver_id_number`]}
                                      hint={PREFILL_EDITABLE_HINT}
                                    >
                                      <Input
                                        value={row.driver_id_number || ""}
                                        onChange={(e) =>
                                          setVehicleDetail(index, "driver_id_number", e.target.value)
                                        }
                                        placeholder="Autofilled from MyPortal"
                                      />
                                    </Field>
                                  )}
                                  <Field
                                    label="Driver's Contact No."
                                    required={
                                      reqGroups.planning
                                    }
                                    fieldKey={`vehicle_details.${index}.driver_contact_number`}
                                    error={form.errors[`vehicle_details.${index}.driver_contact_number`]}
                                    hint={
                                      primaryModeOfTransportation(row.mode_of_transportation) === "DSWD-Owned"
                                        ? PREFILL_EDITABLE_HINT
                                        : undefined
                                    }
                                  >
                                    <Input
                                      value={row.driver_contact_number}
                                      onChange={(e) =>
                                        setVehicleDetail(index, "driver_contact_number", e.target.value)
                                      }
                                      placeholder={
                                        primaryModeOfTransportation(row.mode_of_transportation) === "DSWD-Owned"
                                          ? "Autofill from MyPortal when available"
                                          : "Required for non-DSWD-Owned"
                                      }
                                    />
                                  </Field>
                                  <Field
                                    label="Driver's Position"
                                    required={reqGroups.planning && primaryModeOfTransportation(row.mode_of_transportation) === "DSWD-Owned"}
                                    fieldKey={`vehicle_details.${index}.driver_position`}
                                    error={form.errors[`vehicle_details.${index}.driver_position`]}
                                    hint={PREFILL_EDITABLE_HINT}
                                  >
                                    <Input
                                      value={row.driver_position || ""}
                                      onChange={(e) =>
                                        setVehicleDetail(index, "driver_position", e.target.value)
                                      }
                                      placeholder="Optional"
                                    />
                                  </Field>
                                  <Field
                                    label="Driver's Office"
                                    required={reqGroups.planning && primaryModeOfTransportation(row.mode_of_transportation) === "DSWD-Owned"}
                                    fieldKey={`vehicle_details.${index}.driver_office`}
                                    error={form.errors[`vehicle_details.${index}.driver_office`]}
                                    hint={PREFILL_EDITABLE_HINT}
                                  >
                                    <Input
                                      value={row.driver_office || ""}
                                      onChange={(e) =>
                                        setVehicleDetail(index, "driver_office", e.target.value)
                                      }
                                      placeholder="Optional"
                                    />
                                  </Field>
                                </div>
                              </div>

                              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <Field
                                  label="With DSWD Escort?"
                                  fieldKey={`vehicle_details.${index}.has_dswd_escort`}
                                  error={form.errors[`vehicle_details.${index}.has_dswd_escort`]}
                                >
                                  <select
                                    className="form-input w-full bg-white"
                                    value={row.has_dswd_escort ? "Yes" : "No"}
                                    onChange={(e) => {
                                      const yes = e.target.value === "Yes";
                                      if (yes) {
                                        patchVehicleDetail(index, { has_dswd_escort: true });
                                        return;
                                      }
                                      patchVehicleDetail(index, {
                                        has_dswd_escort: false,
                                        escort_name: "",
                                        escort_contact_number: "",
                                        escort_id_number: "",
                                        escort_position: "",
                                        escort_office: "",
                                      });
                                    }}
                                  >
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                  </select>
                                </Field>
                              </div>

                              {Boolean(row.has_dswd_escort) && (
                                <div>
                                  <p className="mb-2 text-[10px] font-black uppercase tracking-[.16em] text-slate-600">
                                    Escort Details
                                  </p>
                                  <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                    <Field
                                      label="Escort Name"
                                      required={reqGroups.planning}
                                      fieldKey={`vehicle_details.${index}.escort_name`}
                                      error={form.errors[`vehicle_details.${index}.escort_name`]}
                                      hint={`Search MyPortal — ${PREFILL_EDITABLE_HINT.toLowerCase()}`}
                                    >
                                      <MyPortalEmployeeSelect
                                        value={row.escort_name || ""}
                                        onChange={(name) =>
                                          patchVehicleDetail(index, {
                                            escort_name: name,
                                            escort_id_number: "",
                                            escort_position: "",
                                            escort_office: "",
                                            escort_contact_number: "",
                                          })
                                        }
                                        onSelect={(employee) =>
                                          patchVehicleDetail(index, {
                                            escort_name: employee?.value || "",
                                            escort_id_number: employee?.id_number || "",
                                            escort_position: employee?.position || "",
                                            escort_office:
                                              employee?.section_unit_program
                                              || employee?.office
                                              || "",
                                            escort_contact_number:
                                              employee?.contact_number
                                              || employee?.mobile_no
                                              || employee?.mobile
                                              || employee?.phone
                                              || "",
                                          })
                                        }
                                        placeholder="Search MyPortal employee directory"
                                      />
                                    </Field>
                                    <Field
                                      label="Escort Contact Number"
                                      required={reqGroups.planning}
                                      fieldKey={`vehicle_details.${index}.escort_contact_number`}
                                      error={form.errors[`vehicle_details.${index}.escort_contact_number`]}
                                      hint={PREFILL_EDITABLE_HINT}
                                    >
                                      <Input
                                        value={row.escort_contact_number || ""}
                                        onChange={(e) =>
                                          setVehicleDetail(
                                            index,
                                            "escort_contact_number",
                                            e.target.value,
                                          )
                                        }
                                        placeholder="Autofill from MyPortal when available"
                                      />
                                    </Field>
                                    <Field
                                      label="Escort ID Number"
                                      required={reqGroups.planning}
                                      fieldKey={`vehicle_details.${index}.escort_id_number`}
                                      error={form.errors[`vehicle_details.${index}.escort_id_number`]}
                                      hint={PREFILL_EDITABLE_HINT}
                                    >
                                      <Input
                                        value={row.escort_id_number || ""}
                                        onChange={(e) =>
                                          setVehicleDetail(index, "escort_id_number", e.target.value)
                                        }
                                        placeholder="Autofilled from MyPortal"
                                      />
                                    </Field>
                                    <Field
                                      label="Escort Position"
                                      required={reqGroups.planning}
                                      fieldKey={`vehicle_details.${index}.escort_position`}
                                      error={form.errors[`vehicle_details.${index}.escort_position`]}
                                      hint={PREFILL_EDITABLE_HINT}
                                    >
                                      <Input
                                        value={row.escort_position || ""}
                                        onChange={(e) =>
                                          setVehicleDetail(index, "escort_position", e.target.value)
                                        }
                                        placeholder="Optional"
                                      />
                                    </Field>
                                    <Field
                                      label="Escort Office"
                                      required={reqGroups.planning}
                                      fieldKey={`vehicle_details.${index}.escort_office`}
                                      error={form.errors[`vehicle_details.${index}.escort_office`]}
                                      hint={PREFILL_EDITABLE_HINT}
                                    >
                                      <Input
                                        value={row.escort_office || ""}
                                        onChange={(e) =>
                                          setVehicleDetail(index, "escort_office", e.target.value)
                                        }
                                        placeholder="Optional"
                                      />
                                    </Field>
                                  </div>
                                </div>
                              )}

                              <div id={index === 0 ? "dispatch-release-items" : undefined} className="scroll-mt-4 rounded-xl border border-amber-200 bg-amber-50/40">
                                <div className="border-b border-amber-100 px-3 py-2">
                                  <p className="text-[10px] font-black uppercase tracking-[.16em] text-amber-800">
                                    Items To Be Loaded
                                  </p>
                                  <p className="mt-0.5 text-[11px] text-slate-600">
                                    Assign this vehicle's intended load. These quantities will prefill the Release stage and this vehicle's DR.
                                  </p>
                                </div>
                                {form.errors[`vehicle_details.${index}.loaded_items`] && (
                                  <p
                                    data-field={`vehicle_details.${index}.loaded_items`}
                                    className="px-3 pt-2 text-xs font-semibold text-rose-600"
                                  >
                                    {form.errors[`vehicle_details.${index}.loaded_items`]}
                                  </p>
                                )}
                                <div className="overflow-x-auto">
                                  <table className="min-w-full text-sm">
                                    <thead className="bg-white/70 text-left text-[10px] font-black uppercase tracking-wide text-slate-500">
                                      <tr>
                                        <th className="px-3 py-2">Item</th>
                                        <th className="px-3 py-2">Allocated</th>
                                        <th className="px-3 py-2">Brand</th>
                                        <th className="px-3 py-2">Expiry</th>
                                        <th className="px-3 py-2 text-right">Current Stockpile</th>
                                        <th className="px-3 py-2 text-right">Available to Plan</th>
                                        <th className="px-3 py-2 text-right">Unit cost</th>
                                        <th className="px-3 py-2 text-right">Total cost</th>
                                        <th className="px-3 py-2">
                                          To Be Loaded on This Vehicle
                                          {reqGroups.planning ? <span className="text-rose-600"> *</span> : null}
                                        </th>
                                        <th className="px-3 py-2">Total To Be Loaded (All Vehicles)</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      {!(row.source_warehouse_id || row.source_warehouse_name) ? (
                                        <tr>
                                          <td colSpan={10} className="px-3 py-3 text-center text-slate-500">
                                            Select a source warehouse to assign items to this vehicle.
                                          </td>
                                        </tr>
                                      ) : (
                                        (form.data.items || [])
                                          .map((item, itemIndex) => ({ item, itemIndex }))
                                          .filter(({ item }) => itemMatchesSourceWarehouse(
                                            item,
                                            row.source_warehouse_id,
                                            row.source_warehouse_name,
                                          ))
                                          .map(({ item, itemIndex }) => {
                                            const line = row.loaded_items?.[itemIndex] || emptyLoadedItem(item);
                                            const totalToBeLoaded = sumPlannedAcrossVehicles(
                                              form.data.vehicle_details,
                                              item,
                                            );
                                            const allocated = Number(item.allocated_quantity || 0);
                                            const maxForThisVehicle = remainingVehicleItemQuantity(
                                              form.data.vehicle_details,
                                              item,
                                              index,
                                              "planned_quantity",
                                            );
                                            const currentPlanned = Number(line.planned_quantity || 0);
                                            const over = totalToBeLoaded > allocated || currentPlanned > maxForThisVehicle;
                                            const under = totalToBeLoaded < allocated;
                                            const lineErrorKey = `vehicle_details.${index}.loaded_items.${itemIndex}.planned_quantity`;
                                            const lineError = form.errors[lineErrorKey];
                                            return (
                                              <tr key={`v${index}-plan-load-${itemIndex}`} className="border-t border-amber-100">
                                                <td className="px-3 py-2 font-medium">{item.item_name}</td>
                                                <td className="px-3 py-2 tabular-nums">{formatWholeQuantity(allocated, "0")}</td>
                                                <AllocationStockCells item={item} />
                                                <AllocationCostCells item={item} />
                                                <td className="px-3 py-2">
                                                  <Input
                                                    type="text"
                                                    inputMode="numeric"
                                                    value={wholeQuantityInputValue(line.planned_quantity ?? "")}
                                                    onChange={(e) => {
                                                      const next = coerceWholeQuantity(e.target.value, { min: 0 });
                                                      form.clearErrors(lineErrorKey, `items.${itemIndex}.loaded_quantity`);
                                                      if (next !== "" && next > maxForThisVehicle) {
                                                        setVehiclePlannedItem(
                                                          index,
                                                          itemIndex,
                                                          String(maxForThisVehicle),
                                                        );
                                                        window.dispatchEvent(new CustomEvent("dromis:toast", { detail: {
                                                          type: "info",
                                                          title: "Quantity adjusted to available allocation",
                                                          message: `${item.item_name} was set to ${formatWholeQuantity(maxForThisVehicle, "0")}. This is the quantity still available for Vehicle ${index + 1} after considering assignments to the other vehicles.`,
                                                        } }));
                                                        return;
                                                      }
                                                      setVehiclePlannedItem(
                                                        index,
                                                        itemIndex,
                                                        next === "" ? "" : String(next),
                                                      );
                                                    }}
                                                  />
                                                  <p className="mt-1 text-[10px] font-semibold text-slate-500">
                                                    Available to assign to Vehicle {index + 1}: {formatWholeQuantity(maxForThisVehicle, "0")}
                                                  </p>
                                                  {currentPlanned > maxForThisVehicle && (
                                                    <p data-field={lineErrorKey} className="mt-1 text-[10px] font-semibold text-rose-600">
                                                      Planned quantity exceeds this vehicle&apos;s available allocation. Reduce it to {formatWholeQuantity(maxForThisVehicle, "0")} or less.
                                                    </p>
                                                  )}
                                                  {lineError && currentPlanned <= maxForThisVehicle && (
                                                    <p data-field={lineErrorKey} className="mt-1 text-[10px] font-semibold text-rose-600">{lineError}</p>
                                                  )}
                                                </td>
                                                <td className={`px-3 py-2 tabular-nums font-bold ${over ? "text-rose-600" : under ? "text-amber-700" : "text-emerald-700"}`}>
                                                  {formatWholeQuantity(totalToBeLoaded, "0")}
                                                  {over ? ` (over by ${formatWholeQuantity(totalToBeLoaded - allocated, "0")})` : null}
                                                  {!over && under ? ` (${formatWholeQuantity(allocated - totalToBeLoaded, "0")} still unassigned)` : null}
                                                  {form.errors[`items.${itemIndex}.loaded_quantity`] && (
                                                    <p data-field={`items.${itemIndex}.loaded_quantity`} className="mt-1 max-w-xs text-[10px] font-semibold normal-case text-rose-600">
                                                      {form.errors[`items.${itemIndex}.loaded_quantity`]}
                                                    </p>
                                                  )}
                                                </td>
                                              </tr>
                                            );
                                          })
                                      )}
                                      {(row.source_warehouse_id || row.source_warehouse_name)
                                        && planItemsForVehicleWarehouse(form.data.items, row).length === 0 && (
                                        <tr>
                                          <td colSpan={5} className="px-3 py-3 text-center text-slate-500">
                                            No allocation lines for this source warehouse.
                                          </td>
                                        </tr>
                                      )}
                                      {(row.source_warehouse_id || row.source_warehouse_name) && (
                                        <AllocationTotalsRow
                                          items={planItemsForVehicleWarehouse(form.data.items, row)}
                                          trailingColSpan={2}
                                        />
                                      )}
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </StageBlock>

                          <StageBlock
                            id={index === 0 ? "dispatch-stage-release" : undefined}
                            title={form.data.fulfillment_type === "warehouse_pickup"
                              ? "Release and Recipient Receipt (Confirm Release)"
                              : "Release (Confirm Release)"}
                            responsible="WIT Focal"
                            titleClassName="text-sky-700"
                            mode={stageUi("release").mode}
                            locked={stageUi("release").locked}
                            lockNotice={stageUi("release").lockNotice}
                            forceOpen={Boolean(forcedStageKeys[`${index}-release`])}
                            helper="Confirm Release — warehouse release date/time, witness, and loaded quantities."
                          >
                            <div className="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-[11px] leading-4 text-sky-900">
                              <PackageCheck className="mt-0.5 h-4 w-4 shrink-0" />
                              <p><span className="font-black">Official system release.</span> Confirming posts and deducts each vehicle’s loaded items. The matching WIT entry is reconciled without a second deduction.</p>
                            </div>
                            {index === 0 && (
                              <div className="rounded-lg border border-slate-200 bg-slate-50/60 p-3">
                                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-1">
                                  <p className="text-[10px] font-black uppercase tracking-[.16em] text-slate-600">WIT release classification</p>
                                  <p className="text-[10px] text-slate-500">Applies to all vehicle DRs for this RIS.</p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                  <Field label="Source of Goods" required={reqGroups.release} fieldKey="source_of_goods" error={form.errors.source_of_goods}>
                                    <SearchableSelect
                                      options={witClassificationOptions("source_of_goods")}
                                      value={form.data.source_of_goods || ""}
                                      onChange={(value) => form.setData("source_of_goods", Array.isArray(value) ? value[0] || "" : value || "")}
                                      placeholder="Select"
                                      creatable
                                      createLabel="Add new source of goods…"
                                      creating={creatingWitOption === "source_of_goods"}
                                      onCreate={(value) => createWitClassificationOption("source_of_goods", "source_of_goods", value)}
                                    />
                                    {witOptionNotice?.libraryType === "source_of_goods" && <p className={`mt-1 text-xs font-semibold ${witOptionNotice.type === "error" ? "text-rose-600" : "text-emerald-700"}`}>{witOptionNotice.message}</p>}
                                  </Field>
                                  <Field label="Purpose" required={reqGroups.release} fieldKey="purpose" error={form.errors.purpose}>
                                    <SearchableSelect
                                      options={witClassificationOptions("transaction_purpose")}
                                      value={form.data.purpose || ""}
                                      onChange={(value) => form.setData("purpose", Array.isArray(value) ? value[0] || "" : value || "")}
                                      placeholder="Select"
                                      creatable
                                      createLabel="Add new purpose…"
                                      creating={creatingWitOption === "transaction_purpose"}
                                      onCreate={(value) => createWitClassificationOption("transaction_purpose", "purpose", value)}
                                    />
                                    {witOptionNotice?.libraryType === "transaction_purpose" && <p className={`mt-1 text-xs font-semibold ${witOptionNotice.type === "error" ? "text-rose-600" : "text-emerald-700"}`}>{witOptionNotice.message}</p>}
                                  </Field>
                                </div>
                              </div>
                            )}
                            <div className="grid gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-2 xl:grid-cols-3">
                              <Field
                                label={form.data.fulfillment_type === "warehouse_pickup"
                                  ? "Release and Receipt Date and Time"
                                  : "Warehouse Release Date and Time"}
                                required={reqGroups.release}
                                fieldKey={`vehicle_details.${index}.warehouse_released_at`}
                                hint={form.data.fulfillment_type === "warehouse_pickup"
                                  ? "One timestamp for physical release and custody acceptance"
                                  : "When warehouse authorized/released the goods"}
                                error={form.errors[`vehicle_details.${index}.warehouse_released_at`]
                                  || (form.data.fulfillment_type === "warehouse_pickup"
                                    ? form.errors[`vehicle_details.${index}.received_at`]
                                    : null)}
                              >
                                <Input
                                  type="datetime-local"
                                  value={row.warehouse_released_at || ""}
                                  onChange={(e) => form.data.fulfillment_type === "warehouse_pickup"
                                    ? patchVehicleDetail(index, {
                                      warehouse_released_at: e.target.value,
                                      received_at: e.target.value,
                                    })
                                    : setVehicleDetail(index, "warehouse_released_at", e.target.value)}
                                />
                              </Field>
                              <Field
                                label="Vehicle Loaded Date/Time"
                                hint="When loading onto this vehicle was completed"
                                error={form.errors[`vehicle_details.${index}.loaded_at`]}
                              >
                                <Input
                                  type="datetime-local"
                                  value={row.loaded_at || ""}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "loaded_at", e.target.value)
                                  }
                                />
                              </Field>
                              <div className="border-t border-slate-100 pt-2 text-[10px] font-black uppercase tracking-[.16em] text-slate-500 sm:col-span-2 xl:col-span-3">
                                Release witness
                              </div>
                              {needsReleaseWitnessAffiliation(row) && (
                                <Field
                                  label="Released/Witnessed by Organization"
                                  required={reqGroups.release}
                                  fieldKey={`vehicle_details.${index}.release_witness_affiliation`}
                                  className="sm:col-span-2 xl:col-span-3"
                                  hint="Select the organization of the personnel who actually released or witnessed the goods"
                                  error={form.errors[`vehicle_details.${index}.release_witness_affiliation`]}
                                >
                                  <select
                                    className="form-input w-full bg-white"
                                    value={row.release_witness_affiliation || ""}
                                    onChange={(event) => {
                                      const affiliation = event.target.value;
                                      const candidate = {
                                        ...row,
                                        release_witness_affiliation: affiliation,
                                        ...emptyReleaseWitnessFields(),
                                      };
                                      const warehouse = catalogWarehouseForVehicle(row, warehouseCatalog);
                                      const storekeeper = releaseWitnessFieldsFromStorekeeper(warehouse || {});
                                      const autofill = affiliation === "dswd"
                                        ? resolveReleaseWitnessAutofill(
                                            candidate,
                                            warehouseCatalog,
                                            receivingContext,
                                          ).fields
                                        : affiliation === "lgu"
                                          ? {
                                              release_witnessed_by: storekeeper.release_witnessed_by,
                                              release_witness_contact_number: storekeeper.release_witness_contact_number,
                                              release_witness_id_number: storekeeper.release_witness_id_number,
                                              release_witness_position: "LGU Employee",
                                              release_witness_office: resolveLguWitnessOffice(
                                                candidate,
                                                receivingContext,
                                              ),
                                            }
                                          : emptyReleaseWitnessFields();
                                      patchVehicleDetail(index, {
                                        release_witness_affiliation: affiliation,
                                        ...autofill,
                                      });
                                    }}
                                  >
                                    <option value="">Select personnel source</option>
                                    <option value="dswd">DSWD personnel (storekeeper or escort)</option>
                                    <option value="lgu">LGU personnel (storekeeper or warehouse focal person)</option>
                                  </select>
                                </Field>
                              )}
                              {(!needsReleaseWitnessAffiliation(row) || row.release_witness_affiliation) && (
                              <Field
                                label="Released/Witnessed by:"
                                required={reqGroups.release}
                                fieldKey={`vehicle_details.${index}.release_witnessed_by`}
                                className="sm:col-span-2 xl:col-span-1 xl:col-start-1"
                                hint={releaseWitnessHelperText(
                                  row,
                                  warehouseCatalog,
                                  receivingContext,
                                )}
                                error={form.errors[`vehicle_details.${index}.release_witnessed_by`]}
                              >
                                {row.release_witness_affiliation === "lgu" ? (
                                  <Input
                                    value={row.release_witnessed_by || ""}
                                    onChange={(event) =>
                                      setVehicleDetail(index, "release_witnessed_by", event.target.value)
                                    }
                                    placeholder="Enter LGU witness name"
                                  />
                                ) : (
                                <MyPortalEmployeeSelect
                                  value={row.release_witnessed_by || ""}
                                  onChange={(name) =>
                                    patchVehicleDetail(index, {
                                      release_witnessed_by: name,
                                      release_witness_contact_number: "",
                                      release_witness_id_number: "",
                                      release_witness_position: "",
                                      release_witness_office: "",
                                    })
                                  }
                                  onSelect={(employee) =>
                                    patchVehicleDetail(index, {
                                      release_witnessed_by: employee?.value || "",
                                      release_witness_contact_number:
                                        employee?.contact_number
                                        || employee?.mobile_number
                                        || employee?.mobile_no
                                        || employee?.mobile
                                        || employee?.phone
                                        || "",
                                      release_witness_id_number: employee?.id_number || "",
                                      release_witness_position: employee?.position || "",
                                      release_witness_office:
                                        employee?.section_unit_program
                                        || employee?.office
                                        || "",
                                    })
                                  }
                                  placeholder="Search MyPortal employee directory"
                                />
                                )}
                              </Field>
                              )}
                              {(!needsReleaseWitnessAffiliation(row) || row.release_witness_affiliation) && (
                              <>
                              <Field
                                label="Witness Contact Number"
                                required={reqGroups.release}
                                fieldKey={`vehicle_details.${index}.release_witness_contact_number`}
                                error={form.errors[`vehicle_details.${index}.release_witness_contact_number`]}
                                hint={PREFILL_EDITABLE_HINT}
                              >
                                <Input
                                  value={row.release_witness_contact_number || ""}
                                  onChange={(event) =>
                                    setVehicleDetail(index, "release_witness_contact_number", event.target.value)
                                  }
                                  placeholder="Enter contact number"
                                />
                              </Field>
                              <Field
                                label="Witness ID Number"
                                required={reqGroups.release}
                                fieldKey={`vehicle_details.${index}.release_witness_id_number`}
                                error={form.errors[`vehicle_details.${index}.release_witness_id_number`]}
                                hint={
                                  Boolean(row.has_dswd_escort)
                                    ? PREFILL_EDITABLE_HINT
                                    : "Not on warehouse storekeeper record — search MyPortal or enter manually"
                                }
                              >
                                <Input
                                  value={row.release_witness_id_number || ""}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "release_witness_id_number", e.target.value)
                                  }
                                  placeholder={
                                    Boolean(row.has_dswd_escort)
                                      ? "From escort / MyPortal"
                                      : "Search MyPortal or enter manually"
                                  }
                                />
                              </Field>
                              <Field
                                label="Witness Position"
                                required={reqGroups.release}
                                fieldKey={`vehicle_details.${index}.release_witness_position`}
                                error={form.errors[`vehicle_details.${index}.release_witness_position`]}
                                hint={PREFILL_EDITABLE_HINT}
                              >
                                <Input
                                  value={row.release_witness_position || ""}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "release_witness_position", e.target.value)
                                  }
                                  placeholder={
                                    Boolean(row.has_dswd_escort)
                                      ? "From escort / MyPortal"
                                      : "Search MyPortal or enter manually"
                                  }
                                />
                              </Field>
                              <Field
                                label="Witness Office"
                                required={reqGroups.release}
                                fieldKey={`vehicle_details.${index}.release_witness_office`}
                                error={form.errors[`vehicle_details.${index}.release_witness_office`]}
                                hint={
                                  row.release_witness_affiliation === "lgu"
                                    ? "Organization of the LGU personnel who witnessed release — prefilled from the requesting/receiving LGU"
                                    : PREFILL_EDITABLE_HINT
                                }
                              >
                                <Input
                                  value={row.release_witness_office || ""}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "release_witness_office", e.target.value)
                                  }
                                  placeholder={
                                    row.release_witness_affiliation === "lgu"
                                      ? "e.g. MLGU - Tubod, SDN"
                                      : Boolean(row.has_dswd_escort)
                                        ? "From escort / MyPortal"
                                        : "From MyPortal employee office"
                                  }
                                />
                              </Field>
                              </>
                              )}
                              <Field
                                label="Remarks"
                                className="sm:col-span-2 xl:col-span-3"
                                hint="Shared across all Delivery Receipts for this dispatch — prefilled from the RIS Purpose"
                                error={form.errors[`vehicle_details.${index}.loading_remarks`]}
                              >
                                <Input
                                  value={row.loading_remarks || ""}
                                  onChange={(e) => setSharedLoadingRemarks(e.target.value)}
                                />
                              </Field>
                            </div>
                            {form.errors[`vehicle_details.${index}.loaded_items`] && (
                              <p
                                data-field={`vehicle_details.${index}.loaded_items`}
                                className="mt-2 text-xs font-semibold text-rose-600"
                              >
                                {form.errors[`vehicle_details.${index}.loaded_items`]}
                              </p>
                            )}
                            <div className="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                              <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-left text-[10px] font-black uppercase tracking-wide text-slate-500">
                                  <tr>
                                    <th className="px-3 py-2">Item</th>
                                    <th className="px-3 py-2">Allocated</th>
                                    <th className="px-3 py-2">Brand</th>
                                    <th className="px-3 py-2">Expiry</th>
                                    <th className="px-3 py-2 text-right">Current Stockpile</th>
                                    <th className="px-3 py-2 text-right">Available to Plan</th>
                                    <th className="px-3 py-2 text-right">Unit cost</th>
                                    <th className="px-3 py-2 text-right">Total cost</th>
                                    <th className="px-3 py-2">
                                      Loaded on this vehicle
                                      {reqGroups.release ? (
                                        <span className="text-rose-600"> *</span>
                                      ) : null}
                                    </th>
                                    <th className="px-3 py-2">Total loaded (all trucks)</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {!(row.source_warehouse_id || row.source_warehouse_name) ? (
                                    <tr>
                                      <td colSpan={10} className="px-3 py-3 text-center text-slate-500">
                                        Select a source warehouse to load allocated items for this vehicle.
                                      </td>
                                    </tr>
                                  ) : (
                                    (form.data.items || [])
                                      .map((item, itemIndex) => ({ item, itemIndex }))
                                      .filter(({ item }) =>
                                        itemMatchesSourceWarehouse(
                                          item,
                                          row.source_warehouse_id,
                                          row.source_warehouse_name,
                                        ),
                                      )
                                      .map(({ item, itemIndex }) => {
                                        const line =
                                          row.loaded_items?.[itemIndex] || emptyLoadedItem(item);
                                        const totalLoaded = sumLoadedAcrossVehicles(
                                          form.data.vehicle_details,
                                          item,
                                        );
                                        const allocated = Number(item.allocated_quantity || 0);
                                        const maxForThisVehicle = remainingVehicleItemQuantity(
                                          form.data.vehicle_details,
                                          item,
                                          index,
                                          "loaded_quantity",
                                        );
                                        const currentLoaded = Number(line.loaded_quantity || 0);
                                        const over = totalLoaded > allocated || currentLoaded > maxForThisVehicle;
                                        const lineErrorKey = `vehicle_details.${index}.loaded_items.${itemIndex}.loaded_quantity`;
                                        const lineError = form.errors[lineErrorKey];
                                        return (
                                          <tr
                                            key={`v${index}-load-${itemIndex}`}
                                            className="border-t border-slate-100"
                                          >
                                            <td className="px-3 py-2 font-medium">{item.item_name}</td>
                                            <td className="px-3 py-2 tabular-nums">
                                              {formatWholeQuantity(item.allocated_quantity, "0")}
                                            </td>
                                            <AllocationStockCells item={item} />
                                            <AllocationCostCells item={item} />
                                            <td className="px-3 py-2">
                                              <Input
                                                type="text"
                                                inputMode="numeric"
                                                value={wholeQuantityInputValue(
                                                  line.loaded_quantity ?? "",
                                                )}
                                                onChange={(e) => {
                                                  const next = coerceWholeQuantity(e.target.value, {
                                                    min: 0,
                                                  });
                                                  form.clearErrors(lineErrorKey, `items.${itemIndex}.loaded_quantity`);
                                                  if (next !== "" && next > maxForThisVehicle) {
                                                    setVehicleLoadedItem(
                                                      index,
                                                      itemIndex,
                                                      "loaded_quantity",
                                                      String(maxForThisVehicle),
                                                    );
                                                    window.dispatchEvent(new CustomEvent("dromis:toast", { detail: {
                                                      type: "info",
                                                      title: "Loaded quantity adjusted",
                                                      message: `${item.item_name} was set to ${formatWholeQuantity(maxForThisVehicle, "0")}, the remaining quantity authorized for Vehicle ${index + 1} under this RIS allocation.`,
                                                    } }));
                                                    return;
                                                  }
                                                  setVehicleLoadedItem(
                                                    index,
                                                    itemIndex,
                                                    "loaded_quantity",
                                                    next === "" ? "" : String(next),
                                                  );
                                                }}
                                              />
                                              <p className="mt-1 text-[10px] font-semibold text-slate-500">
                                                Authorized for Vehicle {index + 1}: {formatWholeQuantity(maxForThisVehicle, "0")}
                                              </p>
                                              {currentLoaded > maxForThisVehicle && (
                                                <p data-field={lineErrorKey} className="mt-1 text-[10px] font-semibold text-rose-600">
                                                  Loaded quantity exceeds the remaining RIS allocation for this vehicle. Reduce it to {formatWholeQuantity(maxForThisVehicle, "0")} or less.
                                                </p>
                                              )}
                                              {lineError && currentLoaded <= maxForThisVehicle && (
                                                <p data-field={lineErrorKey} className="mt-1 text-[10px] font-semibold text-rose-600">{lineError}</p>
                                              )}
                                            </td>
                                            <td
                                              className={`px-3 py-2 tabular-nums ${
                                                over ? "font-bold text-rose-600" : "text-slate-600"
                                              }`}
                                            >
                                              {formatWholeQuantity(totalLoaded, "0")}
                                              {over ? " (over allocated)" : ""}
                                            </td>
                                          </tr>
                                        );
                                      })
                                  )}
                                  {(row.source_warehouse_id || row.source_warehouse_name)
                                    && planItemsForVehicleWarehouse(form.data.items, row).length
                                      === 0 && (
                                    <tr>
                                      <td colSpan={5} className="px-3 py-3 text-center text-slate-500">
                                        No allocation lines for this source warehouse.
                                      </td>
                                    </tr>
                                  )}
                                  {(row.source_warehouse_id || row.source_warehouse_name) && (
                                    <AllocationTotalsRow
                                      items={planItemsForVehicleWarehouse(form.data.items, row)}
                                      trailingColSpan={2}
                                    />
                                  )}
                                </tbody>
                              </table>
                            </div>
                          </StageBlock>

                          {form.data.fulfillment_type !== "warehouse_pickup" && <StageBlock
                            id={index === 0 ? "dispatch-stage-transit" : undefined}
                            title="In Transit (Mark In Transit)"
                            responsible="Regional Dispatch Officer; nearby DSWD Storekeeper for satellite/LGU/prepositioning warehouses"
                            titleClassName="text-indigo-700"
                            mode={stageUi("transit").mode}
                            locked={stageUi("transit").locked}
                            lockNotice={stageUi("transit").lockNotice}
                            forceOpen={Boolean(forcedStageKeys[`${index}-transit`])}
                            helper="Mark In Transit — when this vehicle actually left."
                          >
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                              <Field
                                label="Actual Departure Date and Time"
                                required={reqGroups.transit}
                                fieldKey={`vehicle_details.${index}.departed_at`}
                                hint="When this vehicle actually left"
                                error={form.errors[`vehicle_details.${index}.departed_at`]}
                              >
                                <Input
                                  type="datetime-local"
                                  min={datetimeLocalMinForField(row.departed_at, {
                                    allowPastUnchanged: true,
                                    dateOnly: true,
                                    originalValue:
                                      selectedDispatch?.vehicle_details?.[index]?.departed_at || "",
                                  })}
                                  value={row.departed_at || ""}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "departed_at", e.target.value)
                                  }
                                />
                              </Field>
                            </div>
                            {statusIndex(planStatus) < statusIndex("in_transit") && (
                              <p className="mt-2 text-xs font-semibold normal-case text-indigo-700/80">
                                You can confirm receipt without Mark In Transit, but Actual Departure
                                Date and Time is still required on each vehicle.
                              </p>
                            )}
                            {selectedDispatch?.id && (() => {
                              const update = deliveryUpdateForm(index, row);
                              const updates = (selectedDispatch.delivery_updates || []).filter((entry) => Number(entry.vehicle_index) === index);
                              const canPostUpdate = statusIndex(planStatus) >= statusIndex("released") && statusIndex(planStatus) <= statusIndex("in_transit");
                              return <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,.8fr)]">
                                <div className={`rounded-xl border p-4 ${canPostUpdate ? "border-indigo-200 bg-indigo-50/50" : "border-slate-200 bg-slate-50"}`}>
                                  <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div><p className="text-xs font-black uppercase tracking-wide text-indigo-800">Vehicle movement and unloading updates</p><p className="mt-1 text-xs text-slate-600">Post each actual stage separately with its time, location, narrative, and photo evidence.</p></div>
                                    <span className="rounded-full bg-white px-2 py-1 text-[10px] font-black text-indigo-700">Vehicle {index + 1} · {row.vehicle_plate_number || "No plate"}</span>
                                  </div>
                                  {!canPostUpdate && <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">Available after Confirm Release. Once released, the assigned escort, dispatch officer, or nearby DSWD storekeeper can post departure and in-transit updates here.</div>}
                                  <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                                    {deliveryStageOptions.map((option, stageIndex) => {
                                      const completed = updates.some((entry) => entry.stage === option.value);
                                      const active = update.stage === option.value;
                                      return <button key={option.value} type="button" disabled={!canPostUpdate} onClick={() => patchDeliveryUpdate(index, { stage: option.value, message: generatedDeliveryMessage(index, row, option.value, update.location) })} className={`rounded-lg border px-3 py-2 text-left transition ${active ? "border-indigo-500 bg-indigo-600 text-white" : completed ? "border-emerald-200 bg-emerald-50 text-emerald-800" : "border-slate-200 bg-white text-slate-700"}`}>
                                        <span className="block text-[10px] font-black uppercase">{stageIndex + 1}. {option.label}{completed ? " · Posted" : ""}</span>
                                        <span className={`mt-1 block text-[10px] ${active ? "text-indigo-100" : "text-slate-500"}`}>{option.hint}</span>
                                      </button>;
                                    })}
                                  </div>
                                  <fieldset disabled={!canPostUpdate} className={`mt-3 grid gap-3 sm:grid-cols-2 ${!canPostUpdate ? "opacity-55" : ""}`}>
                                    <label className="space-y-1"><span className="text-[10px] font-black uppercase text-slate-600">Update type <span className="text-rose-600">*</span></span><select required className="form-input w-full bg-white" value={update.stage} onChange={(event) => {
                                      const stage = event.target.value;
                                      patchDeliveryUpdate(index, { stage, message: generatedDeliveryMessage(index, row, stage, update.location) });
                                    }}>
                                      {deliveryStageOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}<option value="checkpoint">Checkpoint / location update</option><option value="delay">Delay</option><option value="incident">Incident / concern</option>
                                    </select></label>
                                    <label className="space-y-1"><span className="text-[10px] font-black uppercase text-slate-600">Update date and time <span className="text-rose-600">*</span></span><Input required type="datetime-local" value={update.occurred_at} onChange={(event) => patchDeliveryUpdate(index, { occurred_at: event.target.value })} /></label>
                                    <div className="sm:col-span-2"><span className="mb-1 block text-[10px] font-black uppercase text-slate-600">Location / GPS <span className="text-rose-600">*</span></span><div className="flex gap-2"><Input required className="flex-1" value={update.location} onChange={(event) => {
                                      const location = event.target.value;
                                      patchDeliveryUpdate(index, { location, message: generatedDeliveryMessage(index, row, update.stage, location) });
                                    }} placeholder="Capture GPS or enter the actual location / landmark" /><button type="button" onClick={() => captureDeliveryLocation(index)} className="rounded-md border border-indigo-200 bg-white px-3 text-xs font-black text-indigo-700">Capture GPS</button></div></div>
                                    <div className="sm:col-span-2"><div className="mb-1 flex items-center justify-between"><span className="text-[10px] font-black uppercase text-slate-500">Field update message <span className="text-rose-600">*</span> · auto-generated and editable</span><button type="button" onClick={() => patchDeliveryUpdate(index, { message: generatedDeliveryMessage(index, row, update.stage, update.location) })} className="text-[10px] font-black text-indigo-700">Regenerate</button></div><textarea required className="form-input min-h-28 w-full" value={update.message} onChange={(event) => patchDeliveryUpdate(index, { message: event.target.value })} /></div>
                                    <label className="sm:col-span-2 flex cursor-pointer items-center justify-between rounded-lg border border-dashed border-indigo-300 bg-white px-3 py-3 text-xs font-bold text-indigo-700"><span>Photo evidence <span className="text-rose-600">*</span> (at least 1, maximum 6)</span><input required type="file" accept="image/*" capture="environment" multiple className="max-w-48 text-[10px]" onChange={(event) => patchDeliveryUpdate(index, { photos: Array.from(event.target.files || []).slice(0, 6) })} /></label>
                                  </fieldset>
                                  {update.photos.length > 0 && <p className="mt-2 text-xs font-semibold text-emerald-700">{update.photos.length} photo(s) ready to upload.</p>}
                                  <div className="mt-3 flex justify-end"><button type="button" disabled={!canPostUpdate || postingDeliveryUpdate === index} onClick={() => postDeliveryUpdate(index, row)} className="rounded-md bg-indigo-600 px-4 py-2 text-xs font-black text-white disabled:opacity-50">{postingDeliveryUpdate === index ? "Posting…" : canPostUpdate ? "Post update" : "Available after release"}</button></div>
                                </div>
                                <div className="rounded-xl border border-slate-200 bg-white p-4">
                                  <p className="text-xs font-black uppercase tracking-wide text-slate-700">Vehicle update timeline</p>
                                  <div className="mt-3 max-h-96 space-y-3 overflow-y-auto pr-1">
                                    {updates.length === 0 ? <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-500">No field updates posted for this vehicle yet.</p> : updates.map((entry) => <article key={entry.id} className="rounded-lg border border-slate-100 p-3">
                                      <div className="flex justify-between gap-3"><p className="text-xs font-black uppercase text-indigo-700">{String(entry.stage || "update").replaceAll("_", " ")}</p><time className="text-[10px] text-slate-500">{formatDateTime(entry.occurred_at)}</time></div>
                                      <p className="mt-2 whitespace-pre-wrap text-xs leading-5 text-slate-700">{entry.message}</p>
                                      {entry.location && <p className="mt-2 text-[10px] font-semibold text-slate-500">Location: {entry.location}</p>}
                                      {entry.latitude && entry.longitude && <a className="text-[10px] font-bold text-indigo-600" href={`https://www.google.com/maps?q=${entry.latitude},${entry.longitude}`} target="_blank" rel="noreferrer">Open captured coordinates</a>}
                                      <p className="mt-2 text-[10px] text-slate-500">{entry.reporter_name} · {entry.reporter_role}</p>
                                      {entry.photos?.length > 0 && <div className="mt-2 grid grid-cols-3 gap-2">{entry.photos.map((photo, photoIndex) => <a key={photo.url} href={photo.url} target="_blank" rel="noreferrer"><img src={photo.url} alt={`${photo.label} ${photoIndex + 1}`} className="h-20 w-full rounded-md object-cover" /></a>)}</div>}
                                    </article>)}
                                  </div>
                                </div>
                              </div>;
                            })()}
                          </StageBlock>}

                          <StageBlock
                            id={index === 0 ? "dispatch-stage-receipt" : undefined}
                            title={form.data.fulfillment_type === "warehouse_pickup" ? "Release and Recipient Receipt (Confirm Release)" : "Recipient Receipt (Confirm Receipt)"}
                            responsible={form.data.fulfillment_type === "warehouse_pickup" ? "Releasing warehouse personnel and the authorized representative of the partner/recipient" : "Regional Dispatch Officer; nearby DSWD Storekeeper for satellite/LGU/prepositioning warehouses"}
                            titleClassName="text-emerald-700"
                            mode={stageUi("receipt").mode}
                            locked={stageUi("receipt").locked}
                            lockNotice={stageUi("receipt").lockNotice}
                            forceOpen={Boolean(forcedStageKeys[`${index}-receipt`])}
                            embedded={form.data.fulfillment_type === "warehouse_pickup"}
                            helper={form.data.fulfillment_type === "warehouse_pickup" ? "Confirm Release — record the physical release and the authorized partner or recipient's custody acceptance together." : "Confirm Recipient Receipt — actual arrival, who received the goods, and acknowledgment."}
                          >
                            <div className="space-y-3">
                              {form.data.fulfillment_type === "warehouse_pickup" && <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-900"><span className="font-black">Receipt at source warehouse.</span> The authorized partner/recipient representative verifies the quantities and accepts custody during pickup. Destination arrival and unloading reports remain optional monitoring updates.</div>}
                              <div className="grid gap-3 sm:grid-cols-2">
                                {form.data.fulfillment_type !== "warehouse_pickup" && (
                                <Field
                                  label="Actual Arrival Date and Time"
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.actual_arrival`}
                                  hint="When this vehicle actually arrived"
                                  error={actualArrivalSequenceError(row.departed_at, row.actual_arrival)
                                    || form.errors[`vehicle_details.${index}.actual_arrival`]}
                                >
                                  <Input
                                    type="datetime-local"
                                    aria-invalid={Boolean(actualArrivalSequenceError(row.departed_at, row.actual_arrival))}
                                    className={actualArrivalSequenceError(row.departed_at, row.actual_arrival) ? "border-rose-500 ring-2 ring-rose-100" : ""}
                                    min={(() => {
                                      const departedMin = addMinutesDateTimeLocal(row.departed_at);
                                      const nowOrGrandfather = datetimeLocalMinForField(
                                        row.actual_arrival,
                                        {
                                          allowPastUnchanged: true,
                                          dateOnly: true,
                                          originalValue:
                                            selectedDispatch?.vehicle_details?.[index]
                                              ?.actual_arrival || "",
                                        },
                                      );
                                      // A saved historical departure may be completed later;
                                      // allow arrival on that same date, but strictly afterward.
                                      return departedMin || nowOrGrandfather;
                                    })()}
                                    value={row.actual_arrival || ""}
                                    onChange={(e) => {
                                      const value = e.target.value;
                                      form.clearErrors(`vehicle_details.${index}.actual_arrival`);
                                      patchVehicleDetail(index, {
                                        actual_arrival: value,
                                        delivered_at: value
                                          ? String(value).slice(0, 10)
                                          : "",
                                      });
                                    }}
                                  />
                                </Field>
                                )}
                                <Field
                                  label={form.data.fulfillment_type === "warehouse_pickup" ? "Was the Pickup Fully Received?" : "Was the Delivery Completed?"}
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.fully_delivered`}
                                  error={form.errors[`vehicle_details.${index}.fully_delivered`]}
                                >
                                  <YesNo
                                    value={row.fully_delivered || ""}
                                    onChange={(e) =>
                                      setVehicleDetail(index, "fully_delivered", e.target.value)
                                    }
                                  />
                                </Field>
                              </div>

                              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                                <Field
                                  label="Actual Receiving Representative"
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.received_by`}
                                  hint={`${PREFILL_EDITABLE_HINT} Search Received By library or add a person.`}
                                  error={form.errors[`vehicle_details.${index}.received_by`]}
                                >
                                  <SearchableSelect
                                    options={receivedByOptions}
                                    value={personNameOnly(row.received_by || "")}
                                    onChange={(value) => {
                                      const name = personNameOnly(
                                        Array.isArray(value) ? value[0] || "" : value || "",
                                      );
                                      const selected = receivedByOptions.find(
                                        (option) => String(option.value) === String(name),
                                      );
                                      const meta = selected?.metadata || {};
                                      const contact = String(
                                        selected?.contact_number
                                        || meta.contact_number
                                        || "",
                                      ).trim();
                                      const idNumber = String(
                                        selected?.id_number
                                        || meta.id_number
                                        || "",
                                      ).trim();
                                      const position = String(
                                        selected?.position || meta.position || "",
                                      ).trim();
                                      const office = String(
                                        selected?.office || meta.office || "",
                                      ).trim();
                                      patchRecipientDetailsForAllVehicles({
                                        received_by: name,
                                        received_by_position: position,
                                        received_by_office: office,
                                        received_by_id_number: idNumber,
                                        receiver_contact: contact,
                                      });
                                    }}
                                    onSelect={(option) => {
                                      const meta = option?.metadata || {};
                                      const contact = String(
                                        option?.contact_number
                                        || meta.contact_number
                                        || "",
                                      ).trim();
                                      const idNumber = String(
                                        option?.id_number
                                        || meta.id_number
                                        || "",
                                      ).trim();
                                      const position = String(
                                        option?.position || meta.position || "",
                                      ).trim();
                                      const office = String(
                                        option?.office || meta.office || "",
                                      ).trim();
                                      patchRecipientDetailsForAllVehicles({
                                        received_by: personNameOnly(
                                          option?.value || option?.label || "",
                                        ),
                                        received_by_position: position,
                                        received_by_office: office,
                                        received_by_id_number: idNumber,
                                        receiver_contact: contact,
                                      });
                                    }}
                                    placeholder="Select receiving representative"
                                    creatable
                                    createLabel="Add person…"
                                    creating={creatingReceivedBy}
                                    onCreate={(suggested) => openReceivedByModal(index, suggested)}
                                  />
                                  {receivedByNotice
                                    && Number(receivedByNotice.vehicleIndex) === index && (
                                    <p
                                      className={`mt-1 text-xs font-semibold ${
                                        receivedByNotice.type === "error"
                                          ? "text-rose-600"
                                          : "text-emerald-700"
                                      }`}
                                    >
                                      {receivedByNotice.message}
                                    </p>
                                  )}
                                </Field>
                                <Field
                                  label="Recipient Contact Number"
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.receiver_contact`}
                                  hint={PREFILL_EDITABLE_HINT}
                                  error={form.errors[`vehicle_details.${index}.receiver_contact`]}
                                >
                                  <Input
                                    value={row.receiver_contact || ""}
                                    onChange={(e) =>
                                      patchRecipientDetailsForAllVehicles({ receiver_contact: e.target.value })
                                    }
                                  />
                                </Field>
                                <Field
                                  label="Recipient ID Number"
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.received_by_id_number`}
                                  hint={PREFILL_EDITABLE_HINT}
                                  error={form.errors[`vehicle_details.${index}.received_by_id_number`]}
                                >
                                  <Input
                                    value={row.received_by_id_number || ""}
                                    onChange={(e) =>
                                      patchRecipientDetailsForAllVehicles({ received_by_id_number: e.target.value })
                                    }
                                    placeholder="Autofill from library when available"
                                  />
                                </Field>
                                <Field
                                  label="Recipient Position"
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.received_by_position`}
                                  hint={PREFILL_EDITABLE_HINT}
                                  error={form.errors[`vehicle_details.${index}.received_by_position`]}
                                >
                                  <Input
                                    value={row.received_by_position || ""}
                                    onChange={(e) =>
                                      patchRecipientDetailsForAllVehicles({ received_by_position: e.target.value })
                                    }
                                    placeholder="Autofill from library when available"
                                  />
                                </Field>
                                <Field
                                  label="Recipient Office"
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.received_by_office`}
                                  hint={PREFILL_EDITABLE_HINT}
                                  error={form.errors[`vehicle_details.${index}.received_by_office`]}
                                >
                                  <Input
                                    value={row.received_by_office || ""}
                                    onChange={(e) =>
                                      patchRecipientDetailsForAllVehicles({ received_by_office: e.target.value })
                                    }
                                    placeholder="Autofill from library when available"
                                  />
                                </Field>
                              </div>

                              <div className="grid gap-3 sm:grid-cols-2">
                                {form.data.fulfillment_type !== "warehouse_pickup" && <Field
                                  label="Receipt Date and Time"
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.received_at`}
                                  error={form.errors[`vehicle_details.${index}.received_at`]}
                                >
                                  <Input
                                    type="datetime-local"
                                    value={row.received_at || ""}
                                    onChange={(e) =>
                                      setVehicleDetail(index, "received_at", e.target.value)
                                    }
                                  />
                                </Field>}
                                <Field
                                  label="Receipt Acknowledgment"
                                  required={reqGroups.receipt}
                                  fieldKey={`vehicle_details.${index}.receipt_acknowledged`}
                                  error={form.errors[`vehicle_details.${index}.receipt_acknowledged`]}
                                >
                                  <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case text-slate-700">
                                    <input
                                      type="checkbox"
                                      checked={Boolean(row.receipt_acknowledged)}
                                      onChange={(e) =>
                                        setVehicleDetail(
                                          index,
                                          "receipt_acknowledged",
                                          e.target.checked,
                                        )
                                      }
                                    />
                                    Recipient received the goods (this vehicle)
                                  </label>
                                </Field>
                              </div>

                              {index === 0 && (
                                <div id="dispatch-receipt-reconciliation" className="scroll-mt-4">
                                  <ReceiptReconciliation
                                    form={form}
                                    setItem={setItem}
                                    disabled={statusIndex(planStatus) < statusIndex("in_transit")}
                                    disabledMessage="Received quantities become available after the vehicle has departed and the dispatch reaches In Transit."
                                  />
                                </div>
                              )}
                              <Field
                                label="Receipt Remarks"
                                error={form.errors[`vehicle_details.${index}.receipt_remarks`]}
                              >
                                <textarea
                                  className="form-input min-h-16 w-full"
                                  value={row.receipt_remarks || ""}
                                  onChange={(e) =>
                                    setVehicleDetail(index, "receipt_remarks", e.target.value)
                                  }
                                />
                              </Field>
                            </div>
                          </StageBlock>
                        </div>
                      </details>
                            ))}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>

                  {selectedWarehouseIsLocal && (() => {
                    const handover = form.data.local_handover_details || {};
                    const localItems = (form.data.items || []).filter((item) => itemMatchesSourceWarehouse(item, selectedPlanningWarehouse?.id, selectedPlanningWarehouse?.name));
                    return <div className="space-y-4">
                      <StageBlock
                        title="Plan · Estimated Schedule"
                        titleClassName="text-amber-700"
                        mode={stageUi("planning").mode}
                        locked={stageUi("planning").locked}
                        lockNotice={stageUi("planning").lockNotice}
                        helper="Set the expected date for releasing stock already held at the recipient's warehouse or custody location. No vehicle schedule is needed."
                      >
                        <div className="grid gap-3 md:grid-cols-2">
                          <Field label="Source Warehouse" required><Input value={selectedPlanningWarehouse?.name || "—"} disabled readOnly /></Field>
                          <Field label="Estimated / Expected Release Date" required fieldKey="local_handover_details.expected_release_at" error={form.errors["local_handover_details.expected_release_at"]}><Input type="datetime-local" value={handover.expected_release_at || ""} onChange={(e) => setLocalHandover("expected_release_at", e.target.value)} /></Field>
                        </div>
                        <div className="mt-3 overflow-x-auto rounded-lg border border-amber-200"><table className="min-w-full text-sm"><thead className="bg-amber-50 text-left text-[10px] font-black uppercase text-amber-800"><tr><th className="px-3 py-2">Item</th><th className="px-3 py-2">Unit</th><th className="px-3 py-2">Brand</th><th className="px-3 py-2">Expiry</th><th className="px-3 py-2 text-right">Current Stockpile</th><th className="px-3 py-2 text-right">Available to Plan</th><th className="px-3 py-2 text-right">To be Released</th><th className="px-3 py-2 text-right">Unit Cost</th><th className="px-3 py-2 text-right">Total Cost</th></tr></thead><tbody>{localItems.map((item) => <tr key={item.id || item.requisition_issuance_item_id} className="border-t border-amber-100"><td className="px-3 py-2 font-bold">{item.item_name}</td><td className="px-3 py-2">{item.unit || "—"}</td><AllocationStockCells item={item} /><td className="px-3 py-2 text-right font-black tabular-nums">{formatWholeQuantity(item.allocated_quantity, "0")}</td><AllocationCostCells item={item} quantity={item.allocated_quantity} /></tr>)}<tr className="border-t-2 border-slate-300 bg-slate-50 font-black"><td colSpan={6} className="px-3 py-2 text-right uppercase">Total</td><td className="px-3 py-2 text-right tabular-nums">{formatWholeQuantity(localItems.reduce((sum, item) => sum + Number(item.allocated_quantity || 0), 0), "0")}</td><td className="px-3 py-2 text-right text-slate-400">—</td><td className="px-3 py-2 text-right tabular-nums">{formatPeso(localItems.reduce((sum, item) => sum + Number(allocationTotalCost(item) || 0), 0))}</td></tr></tbody></table></div>
                      </StageBlock>

                      <StageBlock id="dispatch-stage-release" title="Release and Recipient Receipt (Confirm Release)" titleClassName="text-sky-700" mode={stageUi("release").mode} locked={stageUi("release").locked} lockNotice={stageUi("release").lockNotice} helper="Record authorization, physical release, quantity verification, and recipient acceptance at the warehouse in one confirmation.">
                        <div className="rounded-lg border border-sky-200 bg-sky-50 p-3"><p className="text-xs font-black uppercase text-sky-900">Release classification</p><div className="mt-3 grid gap-3 md:grid-cols-2"><Field label="Source of Goods" required={reqGroups.release} fieldKey="source_of_goods" error={form.errors.source_of_goods}><SearchableSelect options={witClassificationOptions("source_of_goods")} value={form.data.source_of_goods || ""} onChange={(value) => form.setData("source_of_goods", Array.isArray(value) ? value[0] || "" : value || "")} placeholder="Select" /></Field><Field label="Purpose" required={reqGroups.release} fieldKey="purpose" error={form.errors.purpose}><SearchableSelect options={witClassificationOptions("transaction_purpose")} value={form.data.purpose || ""} onChange={(value) => form.setData("purpose", Array.isArray(value) ? value[0] || "" : value || "")} placeholder="Select" /></Field></div></div>
                        <div className="mt-3 border-t border-slate-200 pt-3">
                          <p className="text-[10px] font-black uppercase tracking-[.16em] text-slate-600">Release witness</p>
                          <div className="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <Field label="Release and Receipt Date and Time" required fieldKey="local_handover_details.released_at" error={form.errors["local_handover_details.released_at"] || form.errors["local_handover_details.received_at"]}><Input type="datetime-local" value={handover.released_at || ""} onChange={(e) => patchLocalHandover({ released_at: e.target.value, received_at: e.target.value })} /></Field>
                            <Field label="Released/Witnessed by Organization" required className="sm:col-span-2" fieldKey="local_handover_details.release_witness_affiliation" error={form.errors["local_handover_details.release_witness_affiliation"]}>
                              <select className="form-input w-full bg-white" value={handover.release_witness_affiliation || ""} onChange={(e) => changeLocalWitnessAffiliation(e.target.value, selectedPlanningWarehouse)}>
                                <option value="">Select personnel source</option>
                                <option value="lgu">LGU personnel (storekeeper or warehouse focal person)</option>
                                <option value="dswd">DSWD personnel (storekeeper or authorized witness)</option>
                              </select>
                              <p className="mt-1 text-[10px] font-semibold text-slate-500">LGU personnel are suggested from this warehouse's storekeeper or focal-person record. DSWD personnel are searched through MyPortal.</p>
                            </Field>
                            {handover.release_witness_affiliation && <>
                              <Field label="Released/Witnessed by" required fieldKey="local_handover_details.released_by" error={form.errors["local_handover_details.released_by"]} hint={handover.release_witness_affiliation === "lgu" ? RELEASE_WITNESS_HELPER.storekeeper : `Search MyPortal — ${PREFILL_EDITABLE_HINT.toLowerCase()}`}>
                                {handover.release_witness_affiliation === "dswd" ? <MyPortalEmployeeSelect value={handover.released_by || ""} onChange={(name) => patchLocalHandover({ released_by: name, releaser_contact: "", releaser_id_number: "", releaser_position: "", releaser_office: "" })} onSelect={selectLocalDswdWitness} placeholder="Search MyPortal employee directory" /> : <Input value={handover.released_by || ""} onChange={(e) => setLocalHandover("released_by", e.target.value)} placeholder="Warehouse storekeeper or focal person" />}
                              </Field>
                              <Field label="Witness Contact Number" required fieldKey="local_handover_details.releaser_contact" error={form.errors["local_handover_details.releaser_contact"]} hint={PREFILL_EDITABLE_HINT}><Input value={handover.releaser_contact || ""} onChange={(e) => setLocalHandover("releaser_contact", e.target.value)} /></Field>
                              <Field label="Witness ID Number" required fieldKey="local_handover_details.releaser_id_number" error={form.errors["local_handover_details.releaser_id_number"]}><Input value={handover.releaser_id_number || ""} onChange={(e) => setLocalHandover("releaser_id_number", e.target.value)} placeholder="Enter if not available in the personnel record" /></Field>
                              <Field label="Witness Position" required fieldKey="local_handover_details.releaser_position" error={form.errors["local_handover_details.releaser_position"]} hint={PREFILL_EDITABLE_HINT}><Input value={handover.releaser_position || ""} onChange={(e) => setLocalHandover("releaser_position", e.target.value)} /></Field>
                              <Field label="Witness Office" required fieldKey="local_handover_details.releaser_office" error={form.errors["local_handover_details.releaser_office"]} hint={PREFILL_EDITABLE_HINT}><Input value={handover.releaser_office || ""} onChange={(e) => setLocalHandover("releaser_office", e.target.value)} /></Field>
                            </>}
                            <Field label="Remarks" className="sm:col-span-2 xl:col-span-3" hint="Shared across all Delivery Receipts for this dispatch — prefilled from the RIS Purpose">
                              <Input
                                value={handover.remarks || form.data.purpose || ""}
                                onChange={(e) => setSharedLoadingRemarks(e.target.value)}
                              />
                            </Field>
                          </div>
                        </div>
                        <div className="mt-3 border-t border-slate-200 pt-3">
                          <p className="text-[10px] font-black uppercase tracking-wide text-emerald-700">Recipient receipt and custody acceptance</p>
                          <div className="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                            <Field
                              label="Actual Receiving Representative"
                              required
                              fieldKey="local_handover_details.received_by"
                              hint={`${PREFILL_EDITABLE_HINT} Search Received By library or add a person.`}
                              error={form.errors["local_handover_details.received_by"]}
                            >
                              <SearchableSelect
                                options={receivedByOptions}
                                value={personNameOnly(handover.received_by || "")}
                                onChange={(value) => {
                                  const name = personNameOnly(
                                    Array.isArray(value) ? value[0] || "" : value || "",
                                  );
                                  const selected = receivedByOptions.find(
                                    (option) => String(option.value) === String(name),
                                  );
                                  const { contact, idNumber, position, office } = receivedBySelectionPatch(selected);
                                  patchRecipientDetailsForAllVehicles({
                                    received_by: name,
                                    received_by_position: position,
                                    received_by_office: office,
                                    received_by_id_number: idNumber,
                                    receiver_contact: contact,
                                  });
                                }}
                                onSelect={(option) => {
                                  const { contact, idNumber, position, office } = receivedBySelectionPatch(option);
                                  patchRecipientDetailsForAllVehicles({
                                    received_by: personNameOnly(option?.value || option?.label || ""),
                                    received_by_position: position,
                                    received_by_office: office,
                                    received_by_id_number: idNumber,
                                    receiver_contact: contact,
                                  });
                                }}
                                placeholder="Select receiving representative"
                                creatable
                                createLabel="Add person…"
                                creating={creatingReceivedBy}
                                onCreate={(suggested) => openReceivedByModal("local", suggested)}
                              />
                              {receivedByNotice && receivedByNotice.vehicleIndex === "local" && (
                                <p className={`mt-1 text-xs font-semibold ${receivedByNotice.type === "error" ? "text-rose-600" : "text-emerald-700"}`}>
                                  {receivedByNotice.message}
                                </p>
                              )}
                            </Field>
                            <Field
                              label="Recipient Contact Number"
                              required
                              fieldKey="local_handover_details.receiver_contact"
                              hint={PREFILL_EDITABLE_HINT}
                              error={form.errors["local_handover_details.receiver_contact"]}
                            >
                              <Input
                                value={handover.receiver_contact || ""}
                                onChange={(e) => patchRecipientDetailsForAllVehicles({ receiver_contact: e.target.value })}
                              />
                            </Field>
                            <Field
                              label="Recipient ID Number"
                              required
                              fieldKey="local_handover_details.receiver_id_number"
                              hint={PREFILL_EDITABLE_HINT}
                              error={form.errors["local_handover_details.receiver_id_number"]}
                            >
                              <Input
                                value={handover.receiver_id_number || ""}
                                onChange={(e) => patchRecipientDetailsForAllVehicles({ received_by_id_number: e.target.value })}
                                placeholder="Autofill from library when available"
                              />
                            </Field>
                            <Field
                              label="Recipient Position"
                              required
                              fieldKey="local_handover_details.receiver_position"
                              hint={PREFILL_EDITABLE_HINT}
                              error={form.errors["local_handover_details.receiver_position"]}
                            >
                              <Input
                                value={handover.receiver_position || ""}
                                onChange={(e) => patchRecipientDetailsForAllVehicles({ received_by_position: e.target.value })}
                                placeholder="Autofill from library when available"
                              />
                            </Field>
                            <Field
                              label="Recipient Office"
                              required
                              fieldKey="local_handover_details.receiver_office"
                              hint={PREFILL_EDITABLE_HINT}
                              error={form.errors["local_handover_details.receiver_office"]}
                            >
                              <Input
                                value={handover.receiver_office || ""}
                                onChange={(e) => patchRecipientDetailsForAllVehicles({ received_by_office: e.target.value })}
                                placeholder="Autofill from library when available"
                              />
                            </Field>
                          </div>
                          <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {false && <Field
                              label="Receipt Date and Time"
                              required
                              fieldKey="local_handover_details.received_at"
                              error={form.errors["local_handover_details.received_at"]}
                            >
                              <Input
                                type="datetime-local"
                                min={handover.released_at || undefined}
                                value={handover.received_at || ""}
                                onChange={(e) => setLocalHandover("received_at", e.target.value)}
                              />
                            </Field>}
                            <Field
                              label="Receipt Acknowledgment"
                              required
                              fieldKey="local_handover_details.receipt_acknowledged"
                              error={form.errors["local_handover_details.receipt_acknowledged"]}
                            >
                              <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case text-slate-700">
                                <input
                                  type="checkbox"
                                  checked={Boolean(handover.receipt_acknowledged)}
                                  onChange={(e) => setLocalHandover("receipt_acknowledged", e.target.checked)}
                                />
                                Recipient received the goods from this warehouse.
                              </label>
                            </Field>
                          </div>
                        </div>
                        <div className="mt-3"><ReceiptReconciliation form={form} setItem={setItem} /></div>
                      </StageBlock>
                    </div>;
                  })()}
                </div>

                {false && <div className="mt-5 overflow-hidden rounded-xl border border-emerald-200 bg-white">
                  <div className="border-b border-emerald-100 bg-emerald-50/70 px-4 py-3">
                    <p className="text-xs font-black uppercase tracking-wide text-emerald-800">Allocation and Recipient Receipt Reconciliation</p>
                    <p className="mt-1 text-xs font-semibold normal-case text-slate-600">Enter what the recipient actually received. Any shortage automatically generates a Returned/Cancelled Item below.</p>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                      <thead className="bg-slate-50 text-left text-[10px] font-black uppercase tracking-wide text-slate-500">
                        <tr><th className="px-3 py-2">Item</th><th className="px-3 py-2">Allocated</th><th className="px-3 py-2">Loaded</th><th className="px-3 py-2">Expected</th><th className="min-w-40 px-3 py-2">Received Qty</th><th className="px-3 py-2">Variance</th></tr>
                      </thead>
                      <tbody>
                        {(form.data.items || []).map((item, index) => {
                          const loaded = sumLoadedAcrossVehicles(form.data.vehicle_details, item);
                          const expected = Number(item.allocated_quantity || 0);
                          const received = item.received_quantity === "" || item.received_quantity == null ? null : Number(item.received_quantity);
                          const variance = received == null ? null : Math.max(0, expected - received);
                          return <tr key={`receipt-${item.id || index}`} className="border-t border-slate-100">
                            <td className="px-3 py-2 font-bold">{item.item_name}</td>
                            <td className="px-3 py-2 tabular-nums">{formatWholeQuantity(item.allocated_quantity, "0")}</td>
                            <td className="px-3 py-2 tabular-nums">{formatWholeQuantity(loaded, "0")}</td>
                            <td className="px-3 py-2 tabular-nums font-bold">{formatWholeQuantity(expected, "0")}</td>
                            <td className="px-3 py-2"><Input type="text" inputMode="numeric" value={wholeQuantityInputValue(item.received_quantity ?? "")} onChange={(event) => {
                              const entered = coerceWholeQuantity(event.target.value, { min: 0 });
                              const next = entered === "" ? "" : Math.min(entered, expected);
                              setItem(index, "received_quantity", next === "" ? "" : String(next));
                            }} />{form.errors[`items.${index}.received_quantity`] && <p className="mt-1 text-xs font-semibold text-rose-600">{form.errors[`items.${index}.received_quantity`]}</p>}</td>
                            <td className={`px-3 py-2 tabular-nums font-black ${variance > 0 ? "text-amber-700" : "text-emerald-700"}`}>{variance == null ? "—" : formatWholeQuantity(variance, "0")}</td>
                          </tr>;
                        })}
                      </tbody>
                    </table>
                  </div>
                </div>

                }
                {false && <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                  <Field label="Planning Remarks" className="md:col-span-2 xl:col-span-4" error={form.errors.remarks}>
                    <textarea
                      className="form-input min-h-20 w-full"
                      value={form.data.remarks || ""}
                      onChange={(e) => form.setData("remarks", e.target.value)}
                    />
                  </Field>
                </div>}
              </FormSection>}

              {deliveryModeConfirmed && returnVarianceRows.length > 0 && <FormSection
                id="dispatch-exceptions"
                number="03"
                title="Delivery Variances"
                subtitle="Generated automatically when the received quantity is below the expected quantity"
                icon={PackageCheck}
              >
                <StageBlock
                  id="dispatch-exceptions"
                  title="Undelivered Balances Requiring Disposition"
                  titleClassName="text-amber-700"
                  mode={stageUi("returns").mode}
                  locked={stageUi("returns").locked}
                  lockNotice={stageUi("returns").lockNotice}
                  forceOpen
                  helper="Choose whether each balance will be delivered later, returned, cancelled, lost/damaged, or handled another way. Deferred balances keep this RIS partially delivered."
                >
                <div className="overflow-x-auto rounded-xl border border-amber-200">
                  <table className="min-w-full text-sm">
                    <thead className="bg-amber-50 text-left text-[10px] font-black uppercase tracking-wide text-amber-800">
                      <tr>
                        <th className="px-3 py-2">Item</th>
                        <th className="px-3 py-2">Expected</th>
                        <th className="px-3 py-2">Brand</th>
                        <th className="px-3 py-2">Expiry</th>
                        <th className="px-3 py-2 text-right">Current Stockpile</th>
                        <th className="px-3 py-2 text-right">Available to Plan</th>
                        <th className="px-3 py-2 text-right">Unit cost</th>
                        <th className="px-3 py-2 text-right">Total cost</th>
                        <th className="px-3 py-2">Received</th>
                        <th className="px-3 py-2">Undelivered Qty</th>
                        <th className="min-w-56 px-3 py-2">Disposition</th>
                        <th className="min-w-80 px-3 py-2">Reason / Resolution / Remarks</th>
                      </tr>
                    </thead>
                    <tbody>
                      {returnVarianceRows.map(({ item, index, expected, received, variance }) => (
                          <tr key={`return-${item.id || index}`} className="border-t border-amber-100">
                            <td className="px-3 py-2 font-bold">{item.item_name}</td>
                            <td className="px-3 py-2 tabular-nums">{formatWholeQuantity(expected, "0")}</td>
                            <AllocationStockCells item={item} />
                            <AllocationCostCells item={item} quantity={expected} />
                            <td className="px-3 py-2 tabular-nums">{formatWholeQuantity(received, "0")}</td>
                            <td className="px-3 py-2 tabular-nums font-black text-amber-700">{formatWholeQuantity(variance, "0")}</td>
                            <td className="px-3 py-2">
                              <select
                                className="form-input w-full bg-white"
                                value={item.variance_disposition || ""}
                                onChange={(event) => setItem(index, "variance_disposition", event.target.value)}
                              >
                                <option value="">Select disposition</option>
                                <option value="deferred">Deferred for later delivery</option>
                                <option value="returned">Returned to warehouse</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="lost_damaged">Lost / damaged in transit</option>
                                <option value="other">Other</option>
                              </select>
                              {["deferred", "returned", "cancelled"].includes(item.variance_disposition) && (
                                <p className="mt-1 text-[10px] font-semibold text-amber-700">
                                  This quantity remains outstanding. Its replacement requires another dispatch, a newly assigned vehicle, and a different DR before the RIS can be completed.
                                </p>
                              )}
                              {form.errors[`items.${index}.variance_disposition`] && <p className="mt-1 text-xs font-semibold text-rose-600">{form.errors[`items.${index}.variance_disposition`]}</p>}
                              {item.variance_disposition === "returned" && <p className="mt-1 text-[10px] font-semibold text-sky-700">Returned items remain outside available stock until warehouse receipt and inspection are recorded.</p>}
                            </td>
                            <td className="px-3 py-2">
                              <Input
                                value={item.variance_resolution || ""}
                                onChange={(event) => setItem(index, "variance_resolution", event.target.value)}
                                placeholder={item.variance_disposition === "deferred" ? "Why is this balance deferred? A date is not required." : "Explain what happened and the resolution"}
                              />
                              {form.errors[`items.${index}.variance_resolution`] && <p className="mt-1 text-xs font-semibold text-rose-600">{form.errors[`items.${index}.variance_resolution`]}</p>}
                              {item.variance_disposition === "returned" && <div className="mt-3 space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-3">
                                <p className="text-[10px] font-black uppercase tracking-wide text-sky-800">Warehouse return inspection</p>
                                <p className="text-[10px] leading-4 text-slate-600">Returned items are not restored automatically. The warehouse must receive and inspect them first.</p>
                                <select className="form-input w-full bg-white" value={item.return_condition || ""} onChange={(event) => {
                                  const condition = event.target.value;
                                  const disposition = condition === "serviceable" ? "restock_available" : condition === "near_expiry" ? "restricted_priority" : ["damaged", "expired"].includes(condition) ? "quarantine_disposal" : "";
                                  setItem(index, "return_condition", condition);
                                  setItem(index, "return_stock_disposition", disposition);
                                }}>
                                  <option value="">Select inspected condition</option>
                                  <option value="serviceable">Serviceable / safe for reissue</option>
                                  <option value="near_expiry">Near expiry</option>
                                  <option value="damaged">Damaged / compromised</option>
                                  <option value="expired">Expired</option>
                                </select>
                                {form.errors[`items.${index}.return_condition`] && <p className="text-xs font-semibold text-rose-600">{form.errors[`items.${index}.return_condition`]}</p>}
                                <select className="form-input w-full bg-white" value={item.return_stock_disposition || ""} onChange={(event) => setItem(index, "return_stock_disposition", event.target.value)}>
                                  <option value="">Select stock disposition</option>
                                  <option value="restock_available">Restore to available stock</option>
                                  <option value="restricted_priority">Restricted — priority disposition</option>
                                  <option value="quarantine_disposal">Quarantine — disposal/condemnation</option>
                                </select>
                                {form.errors[`items.${index}.return_stock_disposition`] && <p className="text-xs font-semibold text-rose-600">{form.errors[`items.${index}.return_stock_disposition`]}</p>}
                                <Input type="datetime-local" value={item.return_received_at || ""} onChange={(event) => setItem(index, "return_received_at", event.target.value)} />
                                {form.errors[`items.${index}.return_received_at`] && <p className="text-xs font-semibold text-rose-600">{form.errors[`items.${index}.return_received_at`]}</p>}
                                <Input value={item.return_inspected_by || ""} onChange={(event) => setItem(index, "return_inspected_by", event.target.value)} placeholder="Warehouse personnel who inspected the return" />
                                {form.errors[`items.${index}.return_inspected_by`] && <p className="text-xs font-semibold text-rose-600">{form.errors[`items.${index}.return_inspected_by`]}</p>}
                              </div>}
                            </td>
                          </tr>
                      ))}
                      <AllocationTotalsRow
                        items={returnVarianceRows.map(({ item }) => item)}
                        trailingColSpan={4}
                      />
                    </tbody>
                  </table>
                </div>
                </StageBlock>
              </FormSection>}

                </div>
              </div>

              <div className="relative z-10 flex shrink-0 flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-white px-4 py-2 dark:border-zinc-800 dark:bg-zinc-950">
                {sourceWarehouses.length > 1 && selectedWarehouseIndex >= 0 && (
                  <div className="order-1 flex min-w-0 items-center gap-2">
                    <div className="hidden min-w-0 lg:block">
                      <p className="text-[9px] font-black uppercase tracking-[.14em] text-slate-400">
                        Warehouse {selectedWarehouseIndex + 1} of {sourceWarehouses.length}
                      </p>
                      <p className="truncate text-xs font-bold text-slate-700">{selectedPlanningWarehouse?.name}</p>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <button
                        type="button"
                        disabled={selectedWarehouseIndex === 0}
                        onClick={() => selectAdjacentWarehouse(-1)}
                        className="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2.5 py-2 text-xs font-black text-slate-700 transition hover:border-emerald-400 hover:text-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-300"
                      >
                        <ChevronLeft className="h-4 w-4" /> Previous
                      </button>
                      <button
                        type="button"
                        disabled={selectedWarehouseIndex === sourceWarehouses.length - 1}
                        onClick={() => selectAdjacentWarehouse(1)}
                        className="inline-flex items-center gap-1 rounded-md border border-emerald-700 bg-emerald-700 px-2.5 py-2 text-xs font-black text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-300"
                      >
                        Next <ChevronRight className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                )}
                <div className="order-2 flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    disabled={form.processing}
                    onClick={() => submit(editing ? planStatus : "draft")}
                    className="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-black text-slate-700 disabled:opacity-60"
                  >
                    <Save className="h-4 w-4" /> {editing ? "Save Changes" : "Save Draft"}
                  </button>
                  {primaryCtaStatus === "planned" && (
                    <button
                      type="button"
                      disabled={form.processing}
                      onClick={() => submit("planned")}
                      className="rounded-md bg-amber-600 px-3 py-2 text-xs font-black text-white shadow-sm disabled:opacity-60"
                    >
                      Mark Planned
                    </button>
                  )}
                  {primaryCtaStatus === "released" && localOnlyPlan && (
                    <button
                      type="button"
                      disabled={form.processing}
                      onClick={() => requestConfirmation({ title: "Confirm release and recipient receipt?", message: "Inventory will be deducted once and custody will be recorded as accepted by the recipient.", confirmLabel: "Confirm Release", onConfirm: () => submit("received") })}
                      className="inline-flex items-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white shadow-sm disabled:opacity-60"
                    >
                      <CheckCircle2 className="h-4 w-4" /> Confirm Release and Recipient Receipt
                    </button>
                  )}
                  {primaryCtaStatus === "released" && !localOnlyPlan && (
                    selectedWarehouseIsLocal ? (
                    <button
                      type="button"
                      disabled={form.processing || Boolean(selectedDispatch?.local_handover_details?.released_at)}
                      onClick={() => requestConfirmation({
                        title: "Confirm release?",
                        message: "This records release and recipient receipt for this local/LGU-owned warehouse only. Other warehouses remain Planned.",
                        confirmLabel: "Confirm Release",
                        onConfirm: () => submit("planned", { releaseLocal: true }),
                      })}
                      className="rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white shadow-sm disabled:opacity-60"
                    >
                      Confirm Release
                    </button>
                    ) : (
                    <button
                      type="button"
                      disabled={
                        form.processing
                        || !selectedPlanningWarehouse
                        || !warehouseRequiresTransport(selectedPlanningWarehouse)
                        || selectedWarehouseReleaseIndexes.length === 0
                        || selectedWarehouseAlreadyReleased
                      }
                      onClick={confirmSelectedWarehouseRelease}
                      className="rounded-md bg-sky-600 px-3 py-2 text-xs font-black text-white shadow-sm disabled:opacity-60"
                    >
                      {remoteWarehouseReleaseProgress.total > 1 && selectedPlanningWarehouse
                        ? `Confirm Release — ${selectedPlanningWarehouse.name}`
                        : "Confirm Release"}
                    </button>
                    )
                  )}
                  {planStatus === "planned"
                    && !localOnlyPlan
                    && remoteWarehouseReleaseProgress.total > 1
                    && remoteWarehouseReleaseProgress.released > 0
                    && remoteWarehouseReleaseProgress.released < remoteWarehouseReleaseProgress.total && (
                    <span className="rounded-md border border-sky-200 bg-sky-50 px-2.5 py-2 text-[11px] font-bold text-sky-900">
                      Released {remoteWarehouseReleaseProgress.released} of {remoteWarehouseReleaseProgress.total} warehouses
                      {remoteWarehouseReleaseProgress.pendingNames.length
                        ? ` · ${remoteWarehouseReleaseProgress.pendingNames.join(", ")} still planned`
                        : ""}
                    </span>
                  )}
                  {planStatus === "planned"
                    && !localOnlyPlan
                    && selectedWarehouseAlreadyReleased
                    && primaryCtaStatus === "released" && (
                    <span className="rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-2 text-[11px] font-bold text-emerald-800">
                      {activeVehicle?.vehicle_plate_number || `Vehicle ${activeVehicleIndex + 1}`} already released
                    </span>
                  )}
                  {!localOnlyPlan
                    && ["planned", "released"].includes(planStatus)
                    && activeVehicleReleased
                    && !activeVehicleDeparted && (
                    <button
                      type="button"
                      disabled={form.processing}
                      onClick={() => submit(planStatus, { transitVehicleIndexes: [activeVehicleIndex] })}
                      className="rounded-md bg-indigo-600 px-3 py-2 text-xs font-black text-white shadow-sm disabled:opacity-60"
                    >
                      Mark Vehicle In Transit
                    </button>
                  )}
                  {primaryCtaStatus === "received" && planStatus !== "received" && (
                    <button
                      type="button"
                      disabled={form.processing}
                      onClick={() => submit("received")}
                      className="inline-flex items-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white shadow-sm disabled:opacity-60"
                    >
                      <CheckCircle2 className="h-4 w-4" /> {partnerPickupPlan ? "Confirm Release and Recipient Receipt" : "Confirm Recipient Receipt"}
                    </button>
                  )}
                  <button
                    type="button"
                    disabled={form.processing}
                    onClick={closeEditor}
                    className="rounded-md border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 disabled:opacity-60"
                  >
                    Cancel
                  </button>
                  {form.processing && (
                    <span className="text-xs font-semibold text-slate-500">Saving…</span>
                  )}
                </div>
                <details className="group order-3 w-full text-[11px]">
                  <summary className="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-md px-1 py-0.5 font-bold text-slate-500 hover:bg-slate-50 hover:text-slate-700 [&::-webkit-details-marker]:hidden">
                    <ChevronDown className="h-3.5 w-3.5 transition-transform group-open:rotate-180" />
                    View process guidance
                  </summary>
                  <div className="mt-1.5 max-w-4xl space-y-1 rounded-lg border border-slate-200 bg-slate-50/80 px-3 py-2 font-semibold text-slate-600">
                    {localOnlyPlan ? (
                      <p className="text-emerald-700">
                          Process: Save Draft → Mark Planned → Confirm System Release → Confirm Recipient Receipt (no vehicles for receiving-LGU local stock). Encode WIT manually, then synchronize it for reconciliation.
                      </p>
                    ) : (
                      <>
                        <p>
                          Process: Save Draft → Mark Planned → Confirm System Release → Mark In Transit → Confirm Recipient Receipt (+ Returns as needed). Encode WIT manually, then synchronize it for reconciliation.
                        </p>
                        <p>
                          Same-day rule: estimated departure and arrival must share one Asia/Manila calendar date before Confirm Release, unless Multi-day run is checked. Revise Planning dates, save/update, then Confirm Release.
                        </p>
                        {planStatus !== "received" && statusIndex(planStatus) < statusIndex("in_transit") ? (
                          <p>
                            You can confirm receipt without Mark In Transit, but the Actual Departure Date and Time is still required for each vehicle.
                          </p>
                        ) : null}
                        {primaryCtaStatus && STAGE_HELPERS[primaryCtaStatus] ? (
                          <p className="text-emerald-800">{STAGE_HELPERS[primaryCtaStatus]}</p>
                        ) : null}
                      </>
                    )}
                  </div>
                </details>
                {(Object.keys(form.errors || {}).length > 0 || clientMissingHints.length > 0) && (
                  <details defaultOpen className="group mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-black text-rose-700 [&::-webkit-details-marker]:hidden">
                      <span>
                        Cannot proceed to {STATUS_META[footerHintStatus]?.label || footerHintStatus}: {Object.keys(form.errors || {}).length || clientMissingHints.length} issue(s) found.
                      </span>
                      <span className="inline-flex shrink-0 items-center gap-1 text-[10px] uppercase tracking-wide">
                        <span className="group-open:hidden">Show errors</span>
                        <span className="hidden group-open:inline">Hide errors</span>
                        <ChevronDown className="h-4 w-4 transition-transform group-open:rotate-180" />
                      </span>
                    </summary>
                    <div className="pt-1">
                    <p className="mt-0.5 text-[11px] font-semibold text-rose-600">Select an issue to open its warehouse, vehicle and stage, then focus the field.</p>
                    <ul className="mt-1 grid gap-1 text-xs font-semibold text-rose-700 sm:grid-cols-2 xl:grid-cols-3">
                      {Object.entries(form.errors || {}).slice(0, 12).map(([key, message]) => (
                        <li key={key}>
                          <button type="button" className="w-full rounded px-1.5 py-1 text-left hover:bg-rose-100 hover:underline" onClick={() => scrollToFieldError(key)}>
                            {humanizeFieldKey(key)} <span className="font-normal">— {message}</span>
                          </button>
                        </li>
                      ))}
                      {Object.keys(form.errors || {}).length > 12 && <li className="px-1.5 py-1">+{Object.keys(form.errors || {}).length - 12} more highlighted issues</li>}
                    </ul>
                    </div>
                  </details>
                )}
              </div>

            </form>
          </div>
        </div>
      )}

      <PdfPreviewModal
        open={Boolean(documentPreview?.open)}
        title={documentPreview?.title}
        subtitle={documentPreview?.subtitle}
        src={documentPreview?.src}
        kind={documentPreview?.kind}
        message={documentPreview?.message}
        tabs={previewTabs}
        initialTab={documentPreview?.initialTab}
        wide
        onClose={closeDocumentPreview}
      />

      <PlanHistoryPanel
        open={Boolean(historyModal?.open)}
        dispatch={historyModal?.dispatch}
        onClose={() => setHistoryModal({ open: false, dispatch: null })}
      />

      {deliveryModeConfirmation && (
        <div className="fixed inset-0 z-[140] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="confirm-delivery-mode-title">
          <div className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div className="border-b border-slate-200 px-5 py-4">
              <p className="text-[10px] font-black uppercase tracking-[.16em] text-emerald-700">Confirm delivery mode</p>
              <h2 id="confirm-delivery-mode-title" className="mt-1 text-lg font-black text-slate-950">{deliveryModeConfirmation.title}</h2>
            </div>
            <div className="space-y-3 px-5 py-4 text-sm text-slate-700">
              <p>{deliveryModeConfirmation.text}</p>
              <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold leading-5 text-amber-900">After confirmation, the delivery mode cannot be changed. While the transaction remains Draft, you may cancel the selected mode; doing so clears its vehicle, release and receipt entries before another mode can be selected.</div>
            </div>
            <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
              <button type="button" onClick={() => setDeliveryModeConfirmation(null)} className="rounded-md border border-slate-200 px-3 py-2 text-xs font-black text-slate-700">Go back</button>
              <button type="button" onClick={() => confirmDeliveryMode(deliveryModeConfirmation.value)} className="rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white">Confirm {deliveryModeConfirmation.title}</button>
            </div>
          </div>
        </div>
      )}

      {confirmationAlert && (
        <div className="fixed inset-0 z-[150] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="alertdialog" aria-modal="true" aria-labelledby="dispatch-confirmation-title" onMouseDown={(event) => { if (event.target === event.currentTarget) closeConfirmationAlert(); }}>
          <div className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl" onMouseDown={(event) => event.stopPropagation()}>
            <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
              <div>
                <p className="text-[10px] font-black uppercase tracking-[.16em] text-slate-500">Confirmation required</p>
                <h2 id="dispatch-confirmation-title" className="mt-1 text-lg font-black text-slate-950">{confirmationAlert.title}</h2>
              </div>
              <button type="button" onClick={closeConfirmationAlert} className="rounded-md border border-slate-200 p-2 text-slate-500 hover:bg-slate-50" aria-label="Close confirmation"><X className="h-4 w-4" /></button>
            </div>
            <div className="px-5 py-4"><p className="whitespace-pre-line text-sm leading-6 text-slate-700">{confirmationAlert.message}</p></div>
            <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
              <button type="button" onClick={closeConfirmationAlert} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-100">Go Back</button>
              <button type="button" onClick={acceptConfirmationAlert} className={`rounded-md px-3 py-2 text-xs font-black text-white shadow-sm ${confirmationAlert.tone === "rose" ? "bg-rose-700 hover:bg-rose-800" : confirmationAlert.tone === "amber" ? "bg-amber-600 hover:bg-amber-700" : "bg-emerald-700 hover:bg-emerald-800"}`}>{confirmationAlert.confirmLabel}</button>
            </div>
          </div>
        </div>
      )}

      {vehicleTypeModal?.open && (
        <div
          className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-labelledby="dispatch-vehicle-type-title"
        >
          <div className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
              <div>
                <h2 id="dispatch-vehicle-type-title" className="font-semibold text-slate-950">
                  Add vehicle type
                </h2>
                <p className="text-xs text-slate-500">
                  Saves to the Vehicle Type library for this and future plans.
                </p>
              </div>
              <button
                type="button"
                onClick={() =>
                  setVehicleTypeModal({
                    open: false,
                    vehicleIndex: null,
                    name: "",
                    is_active: true,
                    error: "",
                  })
                }
                className="rounded-md border border-slate-200 p-2 text-slate-500 hover:bg-slate-50"
                aria-label="Close"
                disabled={creatingVehicleType}
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="space-y-3 px-5 py-4">
              <label className="block text-sm font-semibold text-slate-700">
                Name <span className="text-rose-600">*</span>
                <Input
                  className="mt-1"
                  autoFocus
                  value={vehicleTypeModal.name}
                  disabled={creatingVehicleType}
                  placeholder="e.g. 10 Wheeler Wing Van"
                  onChange={(e) =>
                    setVehicleTypeModal((current) => ({
                      ...current,
                      name: e.target.value,
                      error: "",
                    }))
                  }
                  onKeyDown={(e) => {
                    if (e.key === "Enter") {
                      e.preventDefault();
                      submitVehicleTypeModal();
                    }
                  }}
                />
              </label>
              <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <input
                  type="checkbox"
                  className="rounded border-slate-300"
                  checked={vehicleTypeModal.is_active}
                  disabled={creatingVehicleType}
                  onChange={(e) =>
                    setVehicleTypeModal((current) => ({
                      ...current,
                      is_active: e.target.checked,
                    }))
                  }
                />
                Active (available in dropdowns)
              </label>
              {vehicleTypeModal.error && (
                <p className="text-xs font-semibold text-rose-600">{vehicleTypeModal.error}</p>
              )}
              <div className="flex justify-end gap-2 pt-1">
                <button
                  type="button"
                  disabled={creatingVehicleType}
                  onClick={() =>
                    setVehicleTypeModal({
                      open: false,
                      vehicleIndex: null,
                      name: "",
                      is_active: true,
                      error: "",
                    })
                  }
                  className="rounded-md border border-slate-200 px-3 py-2 text-xs font-black uppercase tracking-wide text-slate-600 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={creatingVehicleType || !String(vehicleTypeModal.name || "").trim()}
                  onClick={submitVehicleTypeModal}
                  className="inline-flex items-center gap-1 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black uppercase tracking-wide text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <Plus className="h-3.5 w-3.5" />
                  {creatingVehicleType ? "Saving…" : "Add type"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {driverModal?.open && (
        <div
          className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-labelledby="dispatch-driver-title"
        >
          <div className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
              <div>
                <h2 id="dispatch-driver-title" className="font-semibold text-slate-950">
                  Add new driver
                </h2>
                <p className="text-xs text-slate-500">
                  Saves to the Driver (Transported By) library for this and future plans.
                </p>
              </div>
              <button
                type="button"
                onClick={() =>
                  setDriverModal({
                    open: false,
                    vehicleIndex: null,
                    name: "",
                    contact_number: "",
                    position: "",
                    office: "",
                    is_active: true,
                    error: "",
                  })
                }
                className="rounded-md border border-slate-200 p-2 text-slate-500 hover:bg-slate-50"
                aria-label="Close"
                disabled={creatingDriver}
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="space-y-3 px-5 py-4">
              <label className="block text-sm font-semibold text-slate-700">
                Name <span className="text-rose-600">*</span>
                <Input
                  className="mt-1"
                  autoFocus
                  value={driverModal.name}
                  disabled={creatingDriver}
                  placeholder="Driver full name"
                  onChange={(e) =>
                    setDriverModal((current) => ({
                      ...current,
                      name: e.target.value,
                      error: "",
                    }))
                  }
                />
              </label>
              <label className="block text-sm font-semibold text-slate-700">
                Contact No. <span className="text-rose-600">*</span>
                <Input
                  className="mt-1"
                  value={driverModal.contact_number}
                  disabled={creatingDriver}
                  placeholder="09XXXXXXXXX"
                  onChange={(e) =>
                    setDriverModal((current) => ({
                      ...current,
                      contact_number: e.target.value,
                      error: "",
                    }))
                  }
                />
              </label>
              <label className="block text-sm font-semibold text-slate-700">
                Position
                <Input
                  className="mt-1"
                  value={driverModal.position}
                  disabled={creatingDriver}
                  placeholder="Optional"
                  onChange={(e) =>
                    setDriverModal((current) => ({
                      ...current,
                      position: e.target.value,
                      error: "",
                    }))
                  }
                />
              </label>
              <label className="block text-sm font-semibold text-slate-700">
                Office
                <Input
                  className="mt-1"
                  value={driverModal.office}
                  disabled={creatingDriver}
                  placeholder="Optional"
                  onChange={(e) =>
                    setDriverModal((current) => ({
                      ...current,
                      office: e.target.value,
                      error: "",
                    }))
                  }
                />
              </label>
              <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <input
                  type="checkbox"
                  className="rounded border-slate-300"
                  checked={driverModal.is_active}
                  disabled={creatingDriver}
                  onChange={(e) =>
                    setDriverModal((current) => ({
                      ...current,
                      is_active: e.target.checked,
                    }))
                  }
                />
                Active (available in dropdowns)
              </label>
              {driverModal.error && (
                <p className="text-xs font-semibold text-rose-600">{driverModal.error}</p>
              )}
              <div className="flex justify-end gap-2 pt-1">
                <button
                  type="button"
                  disabled={creatingDriver}
                  onClick={() =>
                    setDriverModal({
                      open: false,
                      vehicleIndex: null,
                      name: "",
                      contact_number: "",
                      position: "",
                      office: "",
                      is_active: true,
                      error: "",
                    })
                  }
                  className="rounded-md border border-slate-200 px-3 py-2 text-xs font-black uppercase tracking-wide text-slate-600 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={
                    creatingDriver
                    || !String(driverModal.name || "").trim()
                    || !String(driverModal.contact_number || "").trim()
                  }
                  onClick={submitDriverModal}
                  className="inline-flex items-center gap-1 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black uppercase tracking-wide text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <Plus className="h-3.5 w-3.5" />
                  {creatingDriver ? "Saving…" : "Add driver"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {receivedByModal?.open && (
        <div
          className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-labelledby="dispatch-received-by-title"
        >
          <div className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
              <div>
                <h2 id="dispatch-received-by-title" className="font-semibold text-slate-950">
                  Add person
                </h2>
                <p className="text-xs text-slate-500">
                  Saves to the Received By library for this and future plans.
                </p>
              </div>
              <button
                type="button"
                onClick={() =>
                  setReceivedByModal({
                    open: false,
                    vehicleIndex: null,
                    name: "",
                    position: "",
                    office: "",
                    is_active: true,
                    error: "",
                  })
                }
                className="rounded-md border border-slate-200 p-2 text-slate-500 hover:bg-slate-50"
                aria-label="Close"
                disabled={creatingReceivedBy}
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="space-y-3 px-5 py-4">
              <label className="block text-sm font-semibold text-slate-700">
                Name <span className="text-rose-600">*</span>
                <Input
                  className="mt-1"
                  autoFocus
                  value={receivedByModal.name}
                  disabled={creatingReceivedBy}
                  placeholder="LGU representative full name"
                  onChange={(e) =>
                    setReceivedByModal((current) => ({
                      ...current,
                      name: e.target.value,
                      error: "",
                    }))
                  }
                />
              </label>
              <label className="block text-sm font-semibold text-slate-700">
                Position
                <Input
                  className="mt-1"
                  value={receivedByModal.position}
                  disabled={creatingReceivedBy}
                  placeholder="Optional"
                  onChange={(e) =>
                    setReceivedByModal((current) => ({
                      ...current,
                      position: e.target.value,
                      error: "",
                    }))
                  }
                />
              </label>
              <label className="block text-sm font-semibold text-slate-700">
                Office
                <Input
                  className="mt-1"
                  value={receivedByModal.office}
                  disabled={creatingReceivedBy}
                  placeholder="Optional"
                  onChange={(e) =>
                    setReceivedByModal((current) => ({
                      ...current,
                      office: e.target.value,
                      error: "",
                    }))
                  }
                />
              </label>
              <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <input
                  type="checkbox"
                  className="rounded border-slate-300"
                  checked={receivedByModal.is_active}
                  disabled={creatingReceivedBy}
                  onChange={(e) =>
                    setReceivedByModal((current) => ({
                      ...current,
                      is_active: e.target.checked,
                    }))
                  }
                />
                Active (available in dropdowns)
              </label>
              {receivedByModal.error && (
                <p className="text-xs font-semibold text-rose-600">{receivedByModal.error}</p>
              )}
              <div className="flex justify-end gap-2 pt-1">
                <button
                  type="button"
                  disabled={creatingReceivedBy}
                  onClick={() =>
                    setReceivedByModal({
                      open: false,
                      vehicleIndex: null,
                      name: "",
                      position: "",
                      office: "",
                      is_active: true,
                      error: "",
                    })
                  }
                  className="rounded-md border border-slate-200 px-3 py-2 text-xs font-black uppercase tracking-wide text-slate-600 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={creatingReceivedBy || !String(receivedByModal.name || "").trim()}
                  onClick={submitReceivedByModal}
                  className="inline-flex items-center gap-1 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black uppercase tracking-wide text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <Plus className="h-3.5 w-3.5" />
                  {creatingReceivedBy ? "Saving…" : "Add person"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {risPickerOpen && (
        <div
          className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-labelledby="dispatch-ris-picker-title"
        >
          <div className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
              <div>
                <h2 id="dispatch-ris-picker-title" className="font-semibold text-slate-950">
                  Choose prepared RIS
                </h2>
                <p className="text-xs text-slate-500">
                  Select which prepared RIS/DR to create a dispatch plan for.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setRisPickerOpen(false)}
                className="dromis-tip rounded-md border border-slate-200 p-2 text-slate-500 hover:bg-slate-50"
                data-tip="Close"
                data-tip-side="bottom"
                aria-label="Close"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="max-h-[60vh] space-y-2 overflow-y-auto p-4">
              {eligibleRequests.map((row) => {
                const preparedDate = formatRisPreparedDate(row.ris);
                return (
                <button
                  key={row.id}
                  type="button"
                  onClick={() => {
                    setRisPickerOpen(false);
                    openCreate(row);
                  }}
                  className="dromis-tip flex w-full items-start justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-3 text-left hover:border-emerald-300 hover:bg-emerald-50/40"
                  data-tip="Create a dispatch plan for this prepared RIS/DR."
                  data-tip-side="left"
                  aria-label={`Create plan for ${row.reference_number || "prepared RIS/DR"}`}
                >
                  <div>
                    <p className="font-semibold text-slate-900">{row.reference_number}</p>
                    <p className="text-xs text-slate-500">
                      {formatRisDrNo(row.ris)} · {row.ris?.recipient || row.requesting_agency || "—"}
                    </p>
                    <p className="text-[10px] font-bold uppercase tracking-wide text-amber-700">
                      {risSlipStatusMeta(row.ris?.status).label}
                      {preparedDate ? ` · Prepared: ${preparedDate}` : ""}
                    </p>
                    <p className="text-xs text-slate-400">{row.ris?.delivery_site || "No delivery site"}</p>
                  </div>
                  <span className="inline-flex items-center gap-1 rounded-md bg-emerald-700 px-2 py-1 text-[10px] font-black uppercase text-white">
                    <Plus className="h-3 w-3" /> Plan
                  </span>
                </button>
                );
              })}
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}

/** Reuses `/myportal-employees` (same MyPortal directory as RIS / FNI Library signatory pickers). */
function MyPortalEmployeeSelect({ value, onChange, onSelect, placeholder }) {
  const [query, setQuery] = useState(value || "");
  const [options, setOptions] = useState([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [directoryError, setDirectoryError] = useState("");
  const wrapper = useRef(null);
  const triggerRef = useRef(null);

  useEffect(() => {
    if (value) setQuery(value);
  }, [value]);

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    // Capture phase so modal panels that stopPropagation still allow outside-close.
    const onPointerDown = (event) => {
      if (!wrapper.current?.contains(event.target)) {
        setOpen(false);
      }
    };
    const onKeyDown = (event) => {
      if (event.key !== "Escape") {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      setOpen(false);
      window.requestAnimationFrame(() => triggerRef.current?.focus());
    };

    document.addEventListener("pointerdown", onPointerDown, true);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("pointerdown", onPointerDown, true);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

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
        const response = await fetch(`/myportal-employees?search=${encodeURIComponent(search)}`, {
          headers: { Accept: "application/json" },
          signal: controller.signal,
        });
        const payload = response.ok ? await response.json() : { employees: [] };
        setOptions(payload.employees || []);
        setDirectoryError(
          payload.directory_error
          || (!response.ok ? "MyPortal directory request failed." : ""),
        );
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
  }, [query, open, value]);

  return (
    <div ref={wrapper} className="relative">
      <input
        ref={triggerRef}
        value={query}
        placeholder={placeholder}
        autoComplete="off"
        role="combobox"
        aria-expanded={open}
        aria-haspopup="listbox"
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
        <div
          className="absolute z-[160] mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-2xl"
          role="listbox"
        >
          <div className="border-b border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-bold normal-case text-amber-800">
            Search using at least 2 letters from the employee&apos;s last name or first name.
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
              <p className="px-3 py-2 text-slate-500">No employee matched.</p>
            )}
            {!loading && options.map((option) => (
              <button
                key={`${option.id_number || ""}-${option.value}`}
                type="button"
                className="block w-full border-b border-slate-100 px-3 py-2.5 text-left normal-case transition last:border-b-0 hover:bg-emerald-50"
                onClick={() => {
                  onSelect?.(option);
                  setQuery(option.value);
                  setOpen(false);
                }}
              >
                <span className="block font-black text-slate-900">
                  {option.id_number ? `[${option.id_number}] ` : ""}
                  {option.value}
                </span>
                {option.position && (
                  <span className="mt-0.5 block text-xs font-bold text-slate-600">{option.position}</span>
                )}
                {option.section_unit_program && (
                  <span className="mt-1 block text-xs text-emerald-700">{option.section_unit_program}</span>
                )}
                {option.division && (
                  <span className="block text-[11px] font-semibold text-slate-500">{option.division}</span>
                )}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
