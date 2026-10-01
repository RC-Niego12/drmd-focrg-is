import SearchableSelect from "@/Components/SearchableSelect";
import { composeDocumentDrn, currentDrnParts, DocumentDrnFields } from "@/Components/DocumentDrnFields";
import DocumentPreviewModal from "@/Components/DocumentPreviewModal";
import axios from "axios";
import { Eye, Sparkles, Trash2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import {
  coerceWholeQuantity,
  formatWholeQuantity,
  wholeQuantityInputValue,
} from "@/Utils/wholeQuantity";
import { formatIncidentDateDisplay, incidentOccurrenceBounds } from "@/Utils/incidentDisplay";

const stripNarrativeSignoff = (value = "") => String(value)
  .split(/\r?\n/)
  .filter((line) => !/^(Approved\s+by|Prepared\s+by|Reviewed\s+by|Signature|Date)\s*:\s*(?:_+.*)?$/i.test(line.trim()))
  .join("\n")
  .trim();

const CellInput = ({
  value,
  onChange,
  type = "text",
  className = "",
  ...props
}) => (
  <input
    type={type}
    value={value ?? ""}
    onInput={(e) => onChange(e.target.value)}
    onChange={(e) => onChange(e.target.value)}
    className={`h-full min-h-9 w-full border-0 bg-transparent px-2 py-1 text-xs font-semibold outline-none focus:bg-amber-50 focus:ring-2 focus:ring-inset focus:ring-emerald-600 ${className}`}
    {...props}
  />
);
const Header = ({ children }) => (
  <div className="border-b border-black bg-slate-200 px-2 py-1 text-center text-xs font-black text-black">
    {children}
  </div>
);
const countNoun = (value, singular, plural) =>
  Math.max(0, Math.round(Number(value) || 0)) === 1 ? singular : plural;
const formattedCount = (value, singular, plural) => {
  const count = Math.max(0, Math.round(Number(value) || 0));
  return `${count.toLocaleString()} ${countNoun(count, singular, plural)}`;
};

export default function AssessmentExcelForm({
  form,
  currentUser,
  partyOptions,
  selectRequestParty,
  requestParties = [],
  psgc = {},
  inventoryItems,
  fniLibraryItems,
  incidentOptions,
  drrsSignatories,
  action = "/requests",
  method = "post",
  onSuccess,
  submitLabel = "Save Assessment",
  warehouseStock = [],
  warehouseReservations = [],
  drnPrefixes = [],
  requestId = null,
}) {
  const [aiAction, setAiAction] = useState(null);
  const [aiError, setAiError] = useState("");
  const [submitError, setSubmitError] = useState("");
  const [reliefSuggestionsActive, setReliefSuggestionsActive] = useState(false);
  const [previousAugmentationHint, setPreviousAugmentationHint] = useState("");
  const [draftPreviewOpen, setDraftPreviewOpen] = useState(false);
  const [draftPreviewUrl, setDraftPreviewUrl] = useState(null);
  const [draftPreviewBusy, setDraftPreviewBusy] = useState(false);
  const [draftPreviewError, setDraftPreviewError] = useState("");
  const draftPreviewUrlRef = useRef(null);
  const meta = form.data.assessment_form_data ?? {};
  const hasFieldError = (...fields) => {
    const errorKeys = Object.keys(form.errors);
    return fields.some((field) =>
      errorKeys.some((key) => key === field || key.startsWith(`${field}.`)),
    );
  };
  const errorCell = (...fields) =>
    hasFieldError(...fields) ? "bg-rose-50 ring-1 ring-inset ring-rose-300" : "";
  const isDisaster = meta.request_type === "Disaster" && form.data.purpose === "Relief Augmentation";
  const setMeta = (key, value) =>
    form.setData("assessment_form_data", { ...meta, [key]: value });
  const incidents = Array.isArray(meta.incidents) ? meta.incidents : [];
  const incidentFniAllocations = Array.isArray(meta.incident_fni_allocations) ? meta.incident_fni_allocations : [];
  const incidentAllocationKey = (incident, index) => String(incident?.series_key || incident?.source_reference || `incident-${index}`);
  const itemAllocationKey = (item, index) => String(item?.fni_library_item_id || item?.item_name || `item-${index}`).trim().toLowerCase();
  const incidentAllocationQuantity = (incident, incidentIndex, item, itemIndex) => {
    const incidentKey = incidentAllocationKey(incident, incidentIndex);
    const itemKey = itemAllocationKey(item, itemIndex);
    const row = incidentFniAllocations.find((allocation) => String(allocation.incident_key) === incidentKey);
    const allocation = (row?.items || []).find((entry) => String(entry.item_key) === itemKey);
    return allocation?.quantity ?? "";
  };
  const setIncidentAllocationQuantity = (incident, incidentIndex, item, itemIndex, value) => {
    const incidentKey = incidentAllocationKey(incident, incidentIndex);
    const itemKey = itemAllocationKey(item, itemIndex);
    const allocations = incidents.map((row, rowIndex) => {
      const rowKey = incidentAllocationKey(row, rowIndex);
      const existing = incidentFniAllocations.find((allocation) => String(allocation.incident_key) === rowKey) || {};
      return {
        ...existing,
        incident_key: rowKey,
        source_reference: row.source_reference || null,
        series_key: row.series_key || null,
        incident_type: row.incident_type || null,
        barangay: row.barangay || null,
        items: form.data.items.map((itemRow, currentItemIndex) => {
          const currentItemKey = itemAllocationKey(itemRow, currentItemIndex);
          const existingItem = (existing.items || []).find((entry) => String(entry.item_key) === currentItemKey) || {};
          return {
            ...existingItem,
            item_key: currentItemKey,
            fni_library_item_id: itemRow.fni_library_item_id || null,
            item_name: itemRow.item_name || null,
            quantity: rowKey === incidentKey && currentItemKey === itemKey ? value : (existingItem.quantity ?? ""),
          };
        }),
      };
    });
    setMeta("incident_fni_allocations", allocations);
  };
  const occurrenceBounds = incidentOccurrenceBounds(incidents);
  const occurrenceStart = occurrenceBounds.start
    || String(meta.occurrence_started_at || form.data.incident_date || "").slice(0, 10);
  const occurrenceEnd = occurrenceBounds.end
    || String(meta.occurrence_ended_span || occurrenceStart).slice(0, 10);
  const incidentDateIsRange = Boolean(
    isDisaster && occurrenceStart && occurrenceEnd && occurrenceStart !== occurrenceEnd,
  );
  const incidentDateDisplay = !isDisaster
    ? ""
    : (incidentDateIsRange
      ? formatIncidentDateDisplay(occurrenceStart, occurrenceEnd)
      : occurrenceStart);
  const setIncident = (index, key, value) => {
    const next = incidents.map((row, rowIndex) => rowIndex === index ? { ...row, [key]: value } : row);
    const bounds = incidentOccurrenceBounds(next);
    form.setData((current) => ({
      ...current,
      incident_name: index === 0 && key === "incident_type" ? value : current.incident_name,
      incident_date: bounds.start || (index === 0 && key === "occurrence_at" ? String(value).slice(0, 10) : current.incident_date),
      incident_count: next.length,
      assessment_form_data: {
        ...(current.assessment_form_data ?? {}),
        incidents: next,
        occurrence_started_at: bounds.start || current.assessment_form_data?.occurrence_started_at,
        occurrence_ended_span: bounds.end || bounds.start || null,
        incident_occurrence_display: bounds.display || bounds.start || "",
      },
    }));
  };
  const setIncidentDate = (value) => {
    if (incidents.length) {
      setIncident(0, "occurrence_at", value);
      return;
    }
    form.setData((current) => ({
      ...current,
      incident_date: value,
      assessment_form_data: {
        ...(current.assessment_form_data ?? {}),
        occurrence_started_at: value,
        occurrence_ended_span: value,
        incident_occurrence_display: formatIncidentDateDisplay(value) || value,
      },
    }));
  };
  const assessmentPrefixOptions = drnPrefixes.filter((row) => row.context === "assessment").map((row) => row.value);
  const assessmentDrnParts = {
    ...currentDrnParts(assessmentPrefixOptions[0]),
    prefix: meta.assessment_drn_prefix || assessmentPrefixOptions[0] || currentDrnParts().prefix,
    year: meta.assessment_drn_year || currentDrnParts().year,
    month: meta.assessment_drn_month || currentDrnParts().month,
    specified: meta.assessment_drn_specified || "",
  };
  const setAssessmentDrn = (parts, full = composeDocumentDrn(parts)) => form.setData((current) => ({
    ...current,
    assessment_drn: full,
    assessment_form_data: {
      ...(current.assessment_form_data ?? {}),
      assessment_drn_prefix: parts.prefix,
      assessment_drn_year: parts.year,
      assessment_drn_month: parts.month,
      assessment_drn_specified: parts.specified,
    },
  }));
  const batches =
    meta.delivery_batches ??
    Array.from({ length: 5 }, () => ({
      quantity: "",
      date: "",
      available: "",
      details: "",
    }));
  const setBatch = (index, key, value) => {
    const next = batches.map((row, i) =>
      i === index ? { ...row, [key]: value } : row,
    );
    setMeta("delivery_batches", next);
  };
  const previousAugmentations =
    meta.previous_augmentations ??
    Array.from({ length: 3 }, () => ({
      unit: "",
      description: "",
      quantity: "",
      remarks: "",
    }));
  const setPreviousAugmentation = (index, key, value) => {
    const next = previousAugmentations.map((row, rowIndex) =>
      rowIndex === index ? { ...row, [key]: value } : row,
    );
    setMeta("previous_augmentations", next);
  };
  const setItem = (index, key, value) => {
    setReliefSuggestionsActive(false);
    const items = [...form.data.items];
    items[index] = { ...items[index], [key]: value };
    if (key === "fni_library_item_id") {
      const found = combinedFniLibraryItems.find(
        (row) => String(row.id) === String(value),
      );
      const inventory = inventoryItems.find(
        (row) =>
          row.name.toLowerCase() === found?.item_name.toLowerCase() &&
          row.unit.toLowerCase() === found?.unit_of_measure.toLowerCase(),
      );
      if (found)
        items[index] = {
          ...items[index],
          inventory_item_id: inventory?.id ?? "",
          item_name: found.item_name,
          unit: found.unit_of_measure,
          source_warehouse_id: "",
          source_warehouse_name: "",
          available_quantity: totalAvailability(found.item_name),
        };
    }
    form.setData("items", items);
  };
  const normalized = (value) => String(value ?? "").toLowerCase().replace(/[^a-z0-9]+/g, "");
  /** Strip trailing " - brand" so LGU-prefilled lines match inventory item names. */
  const inventoryItemKey = (value) => {
    const raw = String(value ?? "").trim();
    if (!raw) return "";
    const base = raw.replace(/\s+-\s+.+$/, "").trim();
    return normalized(base || raw);
  };
  const combinedFniLibraryItems = Object.values(fniLibraryItems.reduce((items, row) => {
    const key = normalized(row.item_name);
    if (!key) return items;
    const current = items[key];
    const rowHasNoBrand = !String(row.brand_description ?? "").trim();
    const currentHasBrand = Boolean(String(current?.brand_description ?? "").trim());
    if (!current || (rowHasNoBrand && currentHasBrand)) items[key] = row;
    return items;
  }, {}));
  // Match Inventory Warehouse Stockpile: sum current balances (net of negatives), then RIS reservations.
  const totalAvailability = (itemName) => {
    const key = inventoryItemKey(itemName);
    if (!key) return 0;
    const physical = warehouseStock
      .filter((row) => inventoryItemKey(row.item) === key)
      .reduce((sum, row) => sum + Number(row.current ?? row.available ?? 0), 0);
    const reserved = warehouseReservations
      .filter((row) => {
        const reservationKey = row.item_key || inventoryItemKey(row.item_name);
        return reservationKey === key || inventoryItemKey(row.item_name) === key;
      })
      .reduce((sum, row) => sum + Math.max(0, Number(row.quantity) || 0), 0);
    return Math.trunc(Math.max(0, physical - reserved));
  };
  const unavailableItems = form.data.items.filter((item) =>
    item.fni_library_item_id && Number(item.requested_quantity || 0) > Number(totalAvailability(item.item_name) || 0)
  );
  const hasUnavailableItems = unavailableItems.length > 0;

  useEffect(() => {
    if (!requestId || !isDisaster) {
      return undefined;
    }

    const existingRows = Array.isArray(meta.previous_augmentations) ? meta.previous_augmentations : [];
    const alreadyEncoded = existingRows.some((row) =>
      ["unit", "description", "quantity", "remarks"].some((field) => String(row?.[field] ?? "").trim() !== ""),
    );
    if (alreadyEncoded || meta.previous_augmentations_resolved) {
      return undefined;
    }

    let cancelled = false;
    (async () => {
      try {
        const { data } = await axios.get(`/requests/${requestId}/previous-augmentations`, {
          headers: { Accept: "application/json" },
        });
        if (cancelled || !data) {
          return;
        }
        const rows = Array.isArray(data.rows) ? data.rows : [];
        form.setData((current) => ({
          ...current,
          assessment_form_data: {
            ...(current.assessment_form_data ?? {}),
            has_previous_augmentation: Boolean(data.has_previous),
            previous_augmentations: rows.length
              ? rows
              : Array.from({ length: 3 }, () => ({ unit: "", description: "", quantity: "", remarks: "" })),
            previous_augmentations_resolved: true,
          },
        }));
        setPreviousAugmentationHint(
          data.has_previous
            ? `Loaded ${rows.filter((row) => String(row.description || "").trim()).length} prior augmentation line(s) for this same incident and LGU.`
            : "No prior augmentation found for this same incident type, date, and LGU.",
        );
      } catch (error) {
        if (!cancelled) {
          const payload = error?.response?.data;
          const serverMessage = typeof payload?.message === "string" && payload.message.trim()
            ? payload.message.trim()
            : null;
          const status = error?.response?.status;
          setPreviousAugmentationHint(
            serverMessage
              || (status === 403
                ? "Previous augmentations could not be loaded (access denied). You can still encode them manually."
                : "Could not look up previous augmentations automatically."),
          );
        }
      }
    })();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- resolve once per assessment open
  }, [requestId, isDisaster, form.data.incident_name, form.data.incident_date]);

  const suggestedReliefItems = (families) => {
    const templates = [
      ["Family Food Pack", families * 5],
      ["Hygiene Kit", families],
      ["Kitchen Kit", families],
      ["Sleeping Kit", families],
      ["Family Clothing Kit", families],
    ];
    return templates.map(([name, quantity]) => {
      const library = combinedFniLibraryItems.find((row) => normalized(row.item_name) === normalized(name));
      if (!library) return null;
      const inventory = inventoryItems.find((row) => normalized(row.name) === normalized(library.item_name) && normalized(row.unit) === normalized(library.unit_of_measure));
      return { inventory_item_id: inventory?.id ?? "", fni_library_item_id: String(library.id), item_name: library.item_name, requested_quantity: quantity, unit: library.unit_of_measure, priority: "normal", remarks: "", source_warehouse_id: "", source_warehouse_name: "", available_quantity: totalAvailability(library.item_name), _reliefSuggestion: true };
    }).filter(Boolean);
  };
  const setAffectedFamilies = (value) => {
    const families = Number(value);
    if ((!String(value).trim() || families <= 0) && form.data.items.some((item) => item._reliefSuggestion)) {
      const manuallyAddedItems = form.data.items.filter((item) => !item._reliefSuggestion);
      const defaultItem = { inventory_item_id: "", fni_library_item_id: "", item_name: "", requested_quantity: 1, unit: "", priority: "normal", remarks: "", source_warehouse_id: "", source_warehouse_name: "", available_quantity: "" };
      form.setData((current) => ({ ...current, affected_families: value, items: manuallyAddedItems.length ? manuallyAddedItems : [defaultItem] }));
      setReliefSuggestionsActive(false);
      return;
    }
    const itemsAreBlank = form.data.items.every((item) => !item.fni_library_item_id && !item.item_name);
    if (families > 0 && (itemsAreBlank || reliefSuggestionsActive)) {
      const suggestions = suggestedReliefItems(families);
      form.setData((current) => ({ ...current, affected_families: value, items: suggestions.length ? suggestions : current.items }));
      if (suggestions.length) setReliefSuggestionsActive(true);
      return;
    }
    form.setData("affected_families", value);
  };
  const setPurpose = (purpose) => {
    const disaster = purpose === "Relief Augmentation";
    form.setData((current) => ({
      ...current,
      purpose,
      incident_name: disaster ? current.incident_name : "",
      incident_date: disaster ? current.incident_date : "",
      incident_details: disaster ? current.incident_details : "",
      assessment_form_data: {
        ...(current.assessment_form_data ?? {}),
        response_purpose: purpose,
        request_type: disaster ? "Disaster" : null,
        provide_augmentation: disaster,
      },
    }));
  };
  const runAssessmentAi = async (mode) => {
    if (mode === "polish" && !form.data.recommendations?.trim()) {
      setAiError("Enter an assessment narrative before polishing it.");
      return;
    }
    setAiAction(mode); setAiError("");
    try {
      const aiFormContext = {
        incidents: meta.incidents,
        date_received_by_drmd: form.data.date_received_by_drmd,
        assessment_date: meta.assessment_date,
        assessment_drn: form.data.assessment_drn,
        office_agency_details: form.data.office_agency_details,
        lgu_level: form.data.lgu_level,
        province: form.data.province,
        municipality: form.data.municipality,
        barangay: form.data.barangay,
        affected_areas: meta.affected_areas,
        affected_persons: meta.affected_persons,
        date_requested: form.data.date_requested,
        incident_date: form.data.incident_date,
        incident_occurrence_display: incidentDateIsRange
          ? formatIncidentDateDisplay(occurrenceStart, occurrenceEnd)
          : (formatIncidentDateDisplay(occurrenceStart) || form.data.incident_date),
        incident_status: meta.incident_status,
        incident_ended_at: meta.incident_ended_at,
        source_report_classification: meta.source_report_classification,
        assessment_summary: form.data.assessment_summary,
        source_dromic_narrative: meta.source_dromic_narrative,
        source_official_advisories: meta.source_official_advisories,
        source_lgu_response_actions: meta.source_lgu_response_actions,
        source_displacement: meta.source_displacement,
        identified_needs: meta.identified_needs,
        lgu_report_remarks: meta.lgu_report_remarks,
        requester: form.data.requester,
        requester_position: form.data.requester_position,
        contact_number: form.data.contact_number,
        information_source: meta.information_source,
        information_date: meta.information_date,
        families_served: meta.families_served,
        has_previous_augmentation: meta.has_previous_augmentation,
        previous_augmentations: meta.previous_augmentations,
        delivery_batches: meta.delivery_batches,
        provide_augmentation: meta.provide_augmentation,
        response_purpose: meta.response_purpose,
      };
      const { data: payload } = await axios.post("/requests/polish-assessment", {
        mode,
        text: form.data.recommendations,
        requesting_agency: form.data.requesting_agency,
        incident_name: form.data.incident_name,
        incident_details: form.data.incident_details,
        purpose: form.data.purpose,
        affected_families: form.data.affected_families,
        items: form.data.items.map((item) => ({
          item_name: item.item_name,
          requested_quantity: item.requested_quantity,
          unit: item.unit,
          available_quantity: totalAvailability(item.item_name),
        })),
        form_context: aiFormContext,
      }, { headers: { Accept: "application/json" }, withXSRFToken: true });
      form.setData("recommendations", stripNarrativeSignoff(payload.polished));
    } catch (error) { setAiError(error.response?.data?.message || error.message || "Unable to process the assessment."); } finally { setAiAction(null); }
  };
  const addItem = () =>
    (setReliefSuggestionsActive(false), form.setData("items", [
      ...form.data.items,
      {
        inventory_item_id: "",
        fni_library_item_id: "",
        item_name: "",
        requested_quantity: 1,
        unit: "",
        priority: "normal",
        remarks: "",
        source_warehouse_id: "",
        source_warehouse_name: "",
        available_quantity: "",
      },
    ]));
  const deleteItem = (index) => {
    setReliefSuggestionsActive(false);
    form.setData(
      "items",
      form.data.items.filter((_, rowIndex) => rowIndex !== index),
    );
  };
  const signatoryOptions = (role) =>
    drrsSignatories.filter((row) => row.context === role);
  const signatoryName = (value) => (value ?? "").split("|")[0].trim().toUpperCase();
  const signatoryPosition = (value) =>
    (value ?? "").split("|").slice(1).join("|").trim();
  const normalizeSignatoryValue = (value) => {
    const raw = String(value ?? "").trim();
    if (!raw) return "";
    const [name, ...rest] = raw.split("|");
    return [String(name || "").trim().toUpperCase(), ...rest].join("|");
  };
  const preparedRole = [
    ...new Set(
      [currentUser?.position, currentUser?.designation].filter(Boolean),
    ),
  ].join(" / ");

  const revokeDraftPreviewUrl = () => {
    if (draftPreviewUrlRef.current) {
      URL.revokeObjectURL(draftPreviewUrlRef.current);
      draftPreviewUrlRef.current = null;
    }
    setDraftPreviewUrl(null);
  };

  const openDraftPreview = () => {
    setDraftPreviewError("");
    setDraftPreviewOpen(true);
  };

  useEffect(() => {
    if (!draftPreviewOpen) {
      revokeDraftPreviewUrl();
      setDraftPreviewBusy(false);
      setDraftPreviewError("");
      return undefined;
    }

    let cancelled = false;
    const loadDraftPdf = async () => {
      setDraftPreviewBusy(true);
      setDraftPreviewError("");
      revokeDraftPreviewUrl();
      try {
        const response = await axios.post(
          "/requests/assessment-draft-pdf",
          {
            ...form.data,
            preview_request_id: requestId,
            margin: 18,
            items: (form.data.items || []).map((item) => ({
              ...item,
              available_quantity: item.item_name
                ? totalAvailability(item.item_name)
                : item.available_quantity,
            })),
          },
          {
            responseType: "blob",
            headers: { Accept: "application/pdf" },
            withXSRFToken: true,
          },
        );
        if (cancelled) return;
        const url = URL.createObjectURL(response.data);
        draftPreviewUrlRef.current = url;
        setDraftPreviewUrl(url);
      } catch (error) {
        if (cancelled) return;
        setDraftPreviewError(
          error?.response?.data?.message
            || "Unable to build the assessment PDF preview from the current worksheet values.",
        );
      } finally {
        if (!cancelled) setDraftPreviewBusy(false);
      }
    };

    loadDraftPdf();

    return () => {
      cancelled = true;
    };
  }, [draftPreviewOpen]);

  const submit = (e) => {
    e.preventDefault();
    setSubmitError("");
    if (hasUnavailableItems) {
      setSubmitError(`Assessment cannot be submitted. Insufficient available-to-plan stock for: ${unavailableItems.map((item) => item.item_name).join(", ")}.`);
      document.getElementById("assessment-items")?.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }
    form.transform((data) => ({
      ...data,
      items: data.items.map((item) => ({
        ...item,
        available_quantity: item.item_name ? totalAvailability(item.item_name) : 0,
      })),
    }));
    form[method](action, {
      preserveScroll: true,
      onSuccess: (...args) => {
        setSubmitError("");
        onSuccess?.(...args);
      },
      onError: (errors) => {
        if (!errors || Object.keys(errors).length === 0) {
          setSubmitError(
            "Unable to save this assessment. You may not have permission, or the request is locked.",
          );
        }
        document
          .getElementById("assessment-validation")
          ?.scrollIntoView({ behavior: "smooth", block: "center" });
      },
    });
  };

  return (
    <form
      onSubmit={submit}
      className="overflow-hidden rounded-md border border-slate-300 bg-slate-200 shadow-sm dark:border-zinc-700"
    >
      <div className="flex items-center justify-between gap-3 border-b border-slate-300 bg-white p-3 print:hidden dark:bg-zinc-900">
        <div>
          <p className="text-xs font-black uppercase text-emerald-700">
            Official assessment worksheet
          </p>
          <p className="text-sm text-slate-500">
            DSWD-DRMG-GF-001 · Rev 00 · 21 March 2022
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={openDraftPreview}
            className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-black text-slate-700"
            title="Preview assessment from the values currently encoded"
          >
            <Eye className="h-4 w-4" /> Preview Documents
          </button>
          <button
            disabled={form.processing || hasUnavailableItems}
            className="rounded-md bg-emerald-700 px-5 py-2 text-sm font-black text-white disabled:opacity-50"
          >
            {form.processing ? "Saving..." : submitLabel}
          </button>
        </div>
      </div>
      {hasUnavailableItems && <div className="border-b border-rose-300 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800 print:hidden">Submission is blocked because the requested stock is unavailable or insufficient: {unavailableItems.map((item) => item.item_name).join(", ")}.</div>}
      <div className="max-h-[calc(100vh-14rem)] overflow-auto p-3">
        {meta.source_data_prefilled && (
          <div className="mx-auto mb-3 max-w-[1280px] rounded-md border border-blue-300 bg-blue-50 p-4 font-sans text-sm text-blue-950 print:hidden">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="font-black">Prefilled from the validated LGU DROMIC submission</p>
                <p className="mt-1 text-xs text-blue-800">
                  Report {meta.source_lgu_dromic_reference || "linked report"}
                  {meta.source_lgu_request_reference ? ` · Request ${meta.source_lgu_request_reference}` : ""}
                </p>
              </div>
              <span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-black text-blue-800">Editable DRRS assessment</span>
            </div>
            <div className="mt-3 grid gap-2 text-xs sm:grid-cols-2">
              <p><span className="font-black">Affected:</span> {formattedCount(form.data.affected_families, "family", "families")} / {formattedCount(meta.affected_persons, "person", "persons")}</p>
              <p><span className="font-black">Requested FNIs:</span> {form.data.items?.filter((item) => item.item_name || item.fni_library_item_id).length || 0} line item(s)</p>
            </div>
            <p className="mt-2 text-xs text-blue-800">Verify the LGU-supplied facts and requested quantities against the attached documents, then complete DRRS availability, assessment, and recommendation fields.</p>
          </div>
        )}
        {(Object.keys(form.errors).length > 0 || submitError) && (
          <div
            id="assessment-validation"
            className="mx-auto mb-3 max-w-[1280px] rounded-md border-2 border-rose-500 bg-rose-50 p-4 font-sans text-sm text-rose-800"
          >
            <p className="font-black">
              {submitError && Object.keys(form.errors).length === 0
                ? "Unable to save this assessment"
                : "Complete the required worksheet cells before submitting:"}
            </p>
            {submitError && (
              <p className="mt-2 font-semibold">{submitError}</p>
            )}
            {Object.keys(form.errors).length > 0 && (
              <ul className="mt-2 grid list-disc gap-x-8 pl-5 md:grid-cols-2">
                {Object.entries(form.errors).map(([field, message]) => (
                  <li key={field}>{message}</li>
                ))}
              </ul>
            )}
            <p className="mt-2 text-xs font-semibold">
              RIS Number, document DRNs, incident specification, delivery batches,
              families served, and remarks may remain blank when not applicable.
              DRRS AA assigns the Assessment and Response Letter DRNs after forwarding.
            </p>
          </div>
        )}
        <div className="mx-auto min-w-[1050px] max-w-[1280px] border-2 border-black bg-white font-serif text-slate-950 shadow-lg">
          <div className="grid min-h-32 grid-cols-12 text-xs">
            <div className="col-span-5 flex items-center gap-4 px-5 py-3">
              <img
                src="/images/dswd_logo_3.png"
                alt="DSWD Field Office Caraga"
                className="h-[76px] w-auto max-w-[235px] object-contain"
              />
              <img
                src="/images/Bagong_PilipinasTransparent.png"
                alt="Bagong Pilipinas"
                className="h-[70px] w-[82px] object-contain"
              />
            </div>
            <div className="col-span-7 grid grid-rows-[1fr_auto]">
              <div className="flex flex-col items-center justify-center px-4 text-center">
                <p className="text-base font-black">
                  DISASTER RESPONSE MANAGEMENT DIVISION
                </p>
                <p className="mt-1 text-[10px] font-bold italic">
                  DSWD-DRMG-GF-001 | REV 00 | 21 MAR 2022
                </p>
              </div>
              <div className={`border-t border-black px-3 py-2 font-bold ${errorCell(
                "assessment_drn",
                "assessment_form_data.assessment_drn_prefix",
                "assessment_form_data.assessment_drn_year",
                "assessment_form_data.assessment_drn_month",
                "assessment_form_data.assessment_drn_specified",
              )}`}>
                <span className="mb-1 block font-serif text-xs">DRN: <em className="font-sans text-[10px] text-slate-500">Assigned by DRRS AA after forwarding</em></span>
                <DocumentDrnFields compact disabled parts={assessmentDrnParts} onChange={setAssessmentDrn} prefixOptions={assessmentPrefixOptions} />
              </div>
            </div>
          </div>
          <div className="border-b-2 border-black py-2 text-center text-xl font-black">
            FNI ASSESSMENT AND DELIVERY FORM
          </div>
          <div className="grid grid-cols-12 text-xs">
            <label className="col-span-8 flex items-center border-b border-r border-black p-2 font-bold">
              RIS Number:
              <CellInput
                value=""
                onChange={() => {}}
                readOnly
                aria-label="RIS Number (intentionally blank)"
                className="cursor-not-allowed bg-slate-50 focus:bg-slate-50 focus:ring-0"
              />
            </label>
            <label className={`col-span-4 flex items-center border-b border-black p-2 font-bold ${errorCell("assessment_form_data.assessment_date")}`}>
              Assessment Date:
              <CellInput
                type="date"
                value={meta.assessment_date ?? ""}
                onChange={(v) => setMeta("assessment_date", v)}
              />
            </label>
            <div className="col-span-2 border-b border-r border-black p-2 font-bold">
              Requesting Party
            </div>
            <div className={`col-span-10 border-b border-black p-1 ${errorCell("request_party_id", "requesting_agency")}`}>
              <CellInput
                value={form.data.requesting_agency}
                onChange={() => {}}
                readOnly
                aria-label="Requesting Party"
                className="cursor-not-allowed bg-slate-50 focus:bg-slate-50 focus:ring-0"
              />
            </div>
            <div className="col-span-2 border-b border-r border-black p-2 font-bold">
              Purpose
            </div>
            <div className={`col-span-1 flex items-center border-b border-r border-black px-2 py-1 ${errorCell("purpose", "assessment_form_data.response_purpose")}`}>
              <label className="flex items-center gap-1.5 whitespace-nowrap text-[11px] font-bold">
                <input
                  type="radio"
                  className="h-3.5 w-3.5 shrink-0"
                  checked={meta.request_type === "Disaster"}
                  onChange={() => setPurpose("Relief Augmentation")}
                />
                <span>Disaster</span>
              </label>
            </div>
            <div className={`col-span-4 grid grid-cols-[minmax(0,1.4fr)_minmax(150px,1fr)] border-b border-r border-black p-1 ${errorCell("incident_name", "incident_date")}`}>
              <SearchableSelect disabled={!isDisaster} options={incidentOptions} value={isDisaster ? form.data.incident_name : ""} onChange={(v) => incidents.length ? setIncident(0, "incident_type", v) : form.setData("incident_name", v)} placeholder={isDisaster ? "Type of disaster" : ""} />
              {incidentDateIsRange ? (
                <CellInput
                  disabled={!isDisaster}
                  readOnly
                  value={incidentDateDisplay}
                  aria-label="Date range of disasters"
                  title="Earliest to latest incident occurrence for this request"
                  className="ml-1 border-l border-slate-300 print:hidden disabled:cursor-not-allowed disabled:bg-slate-100"
                />
              ) : (
                <CellInput
                  disabled={!isDisaster}
                  type="date"
                  value={incidentDateDisplay}
                  onChange={setIncidentDate}
                  aria-label="Date of disaster"
                  className="ml-1 border-l border-slate-300 print:hidden disabled:cursor-not-allowed disabled:bg-slate-100"
                />
              )}
            </div>
            <div className="col-span-3 border-b border-r border-black">
              <CellInput
                disabled={!isDisaster}
                value={isDisaster ? form.data.incident_details : ""}
                onChange={(v) => form.setData("incident_details", v)}
                placeholder="Specify incident, if necessary"
              />
            </div>
            <div className={`col-span-2 border-b border-black p-1 ${errorCell("purpose", "assessment_form_data.response_purpose")}`}>
              <select className="h-full w-full border-0 bg-transparent text-xs font-bold" value={meta.response_purpose ?? form.data.purpose ?? "Relief Augmentation"} onChange={(event) => setPurpose(event.target.value)}>
                <option value="Relief Augmentation">Relief Augmentation</option>
                <option value="Preparedness for Response">Preparedness for Response</option>
              </select>
            </div>
            <div className="col-span-2 border-b border-r border-black p-2 font-bold">
              Date of Request
            </div>
            <div className={`col-span-10 border-b border-black ${errorCell("date_requested")}`}>
              <CellInput
                type="date"
                value={form.data.date_requested}
                onChange={(v) => form.setData("date_requested", v)}
              />
            </div>
          </div>
          <div id="assessment-items" className="grid scroll-mt-6 grid-cols-[4fr_3.7fr_52px] border-b border-black bg-slate-200 text-center text-xs font-black">
            <div className="border-r border-black py-1">DETAILS OF REQUEST</div>
            <div className="border-r border-black py-1">
              AVAILABILITY OF STOCKPILE
            </div>
            <div></div>
          </div>
          <div className="grid grid-cols-[3fr_1fr_.7fr_.7fr_2.3fr_52px] border-b border-black bg-slate-50 text-center text-xs font-black">
            <div className="border-r border-black p-2">Description</div>
            <div className="border-r border-black p-2">Quantity</div>
            <div className="border-r border-black p-2">Procured</div>
            <div className="border-r border-black p-2">Donated</div>
            <div className="border-r border-black p-2">Available</div>
            <div className="p-2">Delete</div>
          </div>
          {form.data.items.map((item, index) => (
            <div
              key={index}
              className="grid grid-cols-[3fr_1fr_.7fr_.7fr_2.3fr_52px] border-b border-black text-xs"
            >
              <div className={`border-r border-black p-1 ${errorCell(`items.${index}.fni_library_item_id`, `items.${index}.item_name`, `items.${index}.unit`)}`}>
                <SearchableSelect
                  options={combinedFniLibraryItems.map((row) => ({
                    value: String(row.id),
                    label: row.item_name,
                  }))}
                  value={String(item.fni_library_item_id || "")}
                  onChange={(value) =>
                    setItem(index, "fni_library_item_id", value)
                  }
                  placeholder="Search and select FNI"
                />
                <select
                  className="hidden"
                  value={item.fni_library_item_id}
                  onChange={(e) =>
                    setItem(index, "fni_library_item_id", e.target.value)
                  }
                >
                  <option value="">Select FNI</option>
                  {combinedFniLibraryItems.map((row) => (
                    <option key={row.id} value={row.id}>
                      {row.item_name}
                    </option>
                  ))}
                </select>
              </div>
              <div className={`border-r border-black ${errorCell(`items.${index}.requested_quantity`)}`}>
                <CellInput
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]*"
                  min={1}
                  value={wholeQuantityInputValue(item.requested_quantity)}
                  onChange={(v) => {
                    setItem(
                      index,
                      "requested_quantity",
                      coerceWholeQuantity(v, { min: 1 }),
                    );
                  }}
                />
              </div>
              <div className="border-r border-black bg-slate-50 p-2 text-center">
                {item.procured_quantity ?? "—"}
              </div>
              <div className="border-r border-black bg-slate-50 p-2 text-center">
                {item.donated_quantity ?? "—"}
              </div>
              <div className={`border-r border-black p-2 text-center font-black print:bg-white print:text-black ${item.fni_library_item_id ? (Number(item.requested_quantity || 0) > Number(totalAvailability(item.item_name) || 0) ? "bg-rose-200 text-rose-900" : "bg-emerald-200 text-emerald-900") : "bg-slate-50"}`}>
                {item.fni_library_item_id ? formatWholeQuantity(totalAvailability(item.item_name), "0") : "—"}
              </div>
              <button
                type="button"
                onClick={() => deleteItem(index)}
                disabled={form.data.items.length === 1}
                title="Delete row"
                className="flex items-center justify-center text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-30 print:hidden"
              >
                <Trash2 className="h-4 w-4" />
              </button>
            </div>
          ))}
          <button
            type="button"
            onClick={addItem}
            className="w-full border-b border-black bg-slate-50 py-2 text-xs font-black text-emerald-700 hover:bg-emerald-50 print:hidden"
          >
            + Add requested item
          </button>
          {incidents.length > 1 && (
            <section className="border-b border-black bg-amber-50 p-3 print:hidden">
              <h3 className="text-xs font-black uppercase text-amber-950">FNI Breakdown per Separate Incident</h3>
              <p className="mt-1 text-[11px] text-amber-900">Allocate every requested item among the separate incidents. Each item column must equal its requested quantity so confirmed releases can be reported under the correct incident.</p>
              <div className="mt-3 overflow-x-auto rounded border border-amber-300 bg-white">
                <table className="w-full min-w-[720px] border-collapse text-xs">
                  <thead className="bg-[#0a2f6b] text-white"><tr><th className="border border-slate-300 px-3 py-2 text-left">Incident / Affected Area</th>{form.data.items.map((item, itemIndex) => <th key={itemIndex} className="border border-slate-300 px-3 py-2 text-right">{item.item_name || `Item ${itemIndex + 1}`}<span className="block font-normal">Required: {formatWholeQuantity(item.requested_quantity, "0")}</span></th>)}</tr></thead>
                  <tbody>{incidents.map((incident, incidentIndex) => <tr key={incidentAllocationKey(incident, incidentIndex)}><td className="border border-slate-300 px-3 py-2"><span className="font-bold">{incident.incident_type || `Incident ${incidentIndex + 1}`}</span><span className="block text-[10px] text-slate-600">{[incident.barangay, incident.city_municipality].filter(Boolean).join(", ") || incident.source_reference || "Area not specified"}</span></td>{form.data.items.map((item, itemIndex) => <td key={itemIndex} className="border border-slate-300 p-1"><input type="number" min="0" step="1" className="w-full rounded border border-slate-300 px-2 py-1 text-right" aria-label={`${incident.incident_type || `Incident ${incidentIndex + 1}`} ${item.item_name || `Item ${itemIndex + 1}`} allocation`} value={incidentAllocationQuantity(incident, incidentIndex, item, itemIndex)} onChange={(event) => setIncidentAllocationQuantity(incident, incidentIndex, item, itemIndex, event.target.value === "" ? "" : Math.max(0, Math.trunc(Number(event.target.value) || 0)))} /></td>)}</tr>)}</tbody>
                  <tfoot><tr className="bg-slate-100 font-black"><td className="border border-slate-300 px-3 py-2">Allocated Total</td>{form.data.items.map((item, itemIndex) => { const allocated = incidents.reduce((total, incident, incidentIndex) => total + Number(incidentAllocationQuantity(incident, incidentIndex, item, itemIndex) || 0), 0); const valid = allocated === Number(item.requested_quantity || 0); return <td key={itemIndex} className={`border border-slate-300 px-3 py-2 text-right ${valid ? "text-emerald-700" : "text-rose-700"}`}>{formatWholeQuantity(allocated, "0")} / {formatWholeQuantity(item.requested_quantity, "0")}</td>; })}</tr></tfoot>
                </table>
              </div>
              {hasFieldError("assessment_form_data.incident_fni_allocations") && <p className="mt-2 text-xs font-bold text-rose-700">Complete the per-incident allocation. Every item total must equal the requested quantity.</p>}
            </section>
          )}
          <Header>ASSESSMENT AND VALIDATION</Header>
          <div className="grid grid-cols-2 text-xs">
            <label className="col-span-2 flex border-b border-black px-2 font-bold">
              Date of Disaster Occurrence:
              {incidentDateIsRange ? (
                <CellInput
                  readOnly
                  value={incidentDateDisplay}
                  aria-label="Date range of disasters"
                  className="ml-2"
                />
              ) : (
                <CellInput
                  type="date"
                  value={incidentDateDisplay}
                  onChange={setIncidentDate}
                />
              )}
            </label>
            <label className={`grid grid-cols-[11rem_minmax(4rem,0.7fr)_auto_minmax(4rem,0.7fr)_auto] items-center border-b border-r border-black px-2 font-bold ${errorCell("affected_families")}`}>
              <span>Actual Affected Families:</span>
              <CellInput
                type="number"
                min="1"
                step="1"
                value={form.data.affected_families}
                onChange={setAffectedFamilies}
              />
              <span className="whitespace-nowrap pr-1 font-semibold text-slate-600">
                {countNoun(form.data.affected_families, "family", "families")}
              </span>
              <CellInput
                type="number"
                min="0"
                step="1"
                value={meta.affected_persons ?? ""}
                onChange={(v) => setMeta("affected_persons", v)}
                aria-label="Actual affected persons"
              />
              <span className="whitespace-nowrap pr-2 font-semibold text-slate-600">
                {countNoun(meta.affected_persons, "person", "persons")}
              </span>
            </label>
            <label className="flex border-b border-black px-2 font-bold">
              No. of Families Served:
              <CellInput
                type="number"
                min="0"
                step="1"
                value={meta.families_served}
                onChange={(v) => setMeta("families_served", v)}
              />
            </label>
            <label className={`flex border-b border-r border-black px-2 font-bold ${errorCell("assessment_form_data.information_source")}`}>
              Source of Information:
              <CellInput
                value={meta.information_source}
                onChange={(v) => setMeta("information_source", v)}
                placeholder="e.g. CSWDO / DROMIC"
              />
            </label>
            <label className={`flex border-b border-black px-2 font-bold ${errorCell("assessment_form_data.information_date")}`}>
              Date of Information:
              <CellInput
                type="date"
                value={meta.information_date}
                onChange={(v) => setMeta("information_date", v)}
              />
            </label>
          </div>
          <Header>PREVIOUS AUGMENTATION</Header>
          <div className="grid grid-cols-[2fr_.7fr_.55fr_.7fr_4.5fr] border-b border-black text-xs">
            <div className="border-r border-black px-2 font-semibold">
              With previous augmentation?
            </div>
            <label className="flex items-center justify-center border-r border-black font-black">
              YES
            </label>
            <label className="flex items-center justify-center border-r border-black bg-sky-50 font-black">
              <input
                type="radio"
                checked={meta.has_previous_augmentation === true}
                onChange={() => setMeta("has_previous_augmentation", true)}
              />
            </label>
            <label className="flex items-center justify-center border-r border-black font-black">
              NO
            </label>
            <label className="flex items-center px-3">
              <input
                type="radio"
                checked={meta.has_previous_augmentation === false}
                onChange={() => setMeta("has_previous_augmentation", false)}
              />
            </label>
          </div>
          <div className="border-b border-black px-2 py-1 text-xs">
            Details of previous augmentation (Indicate Month and Year), If any:
            {previousAugmentationHint && (
              <span className="ml-2 font-semibold text-emerald-800 print:hidden">{previousAugmentationHint}</span>
            )}
          </div>
          <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.75fr)_minmax(0,.65fr)_minmax(0,5.6fr)] border-b border-black bg-slate-50 text-center text-xs font-black">
            <div className="border-r border-black py-1">UNIT</div>
            <div className="border-r border-black py-1">DESCRIPTION</div>
            <div className="border-r border-black py-1">QUANTITY</div>
            <div className="py-1">REMARKS</div>
          </div>
          {previousAugmentations.map((row, index) => (
            <div
              key={index}
              className={`grid grid-cols-[minmax(0,1.1fr)_minmax(0,1.9fr)_minmax(0,.8fr)_minmax(0,5.2fr)] border-b border-black text-xs ${errorCell(`assessment_form_data.previous_augmentations.${index}`)}`}
            >
              <div className="border-r border-black">
                <CellInput
                  value={row.unit}
                  onChange={(v) => setPreviousAugmentation(index, "unit", v)}
                />
              </div>
              <div className="border-r border-black">
                <CellInput
                  value={row.description}
                  onChange={(v) =>
                    setPreviousAugmentation(index, "description", v)
                  }
                />
              </div>
              <div className="border-r border-black">
                <CellInput
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]*"
                  min={0}
                  value={wholeQuantityInputValue(row.quantity)}
                  onChange={(v) =>
                    setPreviousAugmentation(
                      index,
                      "quantity",
                      coerceWholeQuantity(v, { min: 0 }),
                    )
                  }
                />
              </div>
              <div>
                <CellInput
                  value={row.remarks}
                  onChange={(v) => setPreviousAugmentation(index, "remarks", v)}
                />
              </div>
            </div>
          ))}
          <Header>
            <span className="block">DELIVERY / HAULING DETAILS</span>
            <span className="block text-[10px] font-normal italic">
              (use separate sheet if necessary)
            </span>
          </Header>
          <div className="border-b border-black bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-950 print:hidden">
            Leave blank for now. RROS completes delivery / hauling details before or after delivery.
          </div>
          <div className="grid grid-cols-[.65fr_.9fr_1.6fr_.5fr_.5fr_4.5fr] grid-rows-[auto_auto] border-b border-black bg-slate-50 text-center text-xs font-black">
            <div className="row-span-2 flex items-center justify-center border-r border-black p-2"></div>
            <div className="row-span-2 flex items-center justify-center border-r border-black p-2">Quantity</div>
            <div className="row-span-2 flex items-center justify-center border-r border-black p-2">Date</div>
            <div className="col-span-3 border-b border-black p-1 text-[10px] font-normal leading-tight">
              If YES, indicate which vehicle will be used for hauling/pickup;
              <br />
              If NO, put projected date of transportation asset availability
            </div>
            <div className="flex items-center justify-center border-r border-black p-1">YES</div>
            <div className="flex items-center justify-center border-r border-black p-1">NO</div>
            <div className="p-1"></div>
          </div>
          {batches.map((row, index) => (
            <div
              key={index}
              className={`grid grid-cols-[.65fr_.9fr_1.6fr_.5fr_.5fr_4.5fr] border-b border-black text-xs ${errorCell(`assessment_form_data.delivery_batches.${index}`)}`}
            >
              <div className="border-r border-black p-2 font-bold">
                Batch {index + 1}
              </div>
              <div className="border-r border-black">
                <CellInput
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]*"
                  min={0}
                  value={wholeQuantityInputValue(row.quantity)}
                  onChange={(v) =>
                    setBatch(index, "quantity", coerceWholeQuantity(v, { min: 0 }))
                  }
                />
              </div>
              <div className="border-r border-black">
                <CellInput
                  type="date"
                  value={row.date}
                  onChange={(v) => setBatch(index, "date", v)}
                />
              </div>
              <label className="flex items-center justify-center border-r border-black">
                <input
                  type="radio"
                  checked={row.available === "YES"}
                  onChange={() => setBatch(index, "available", "YES")}
                />
              </label>
              <label className="flex items-center justify-center border-r border-black">
                <input
                  type="radio"
                  checked={row.available === "NO"}
                  onChange={() => setBatch(index, "available", "NO")}
                />
              </label>
              <div>
                <CellInput
                  value={row.details}
                  onChange={(v) => setBatch(index, "details", v)}
                />
              </div>
            </div>
          ))}
          <Header>RECOMMENDATION</Header>
          <div className="grid grid-cols-[2fr_.7fr_.55fr_.7fr_4.5fr] border-b border-black text-xs">
            <div className="border-r border-black px-2 font-semibold">
              Provide Augmentation?
            </div>
            <div className="flex items-center justify-center border-r border-black font-black">
              YES
            </div>
            <label className="flex items-center justify-center border-r border-black bg-sky-50">
              <input
                type="radio"
                checked={meta.provide_augmentation === true}
                onChange={() => setMeta("provide_augmentation", true)}
              />
            </label>
            <div className="flex items-center justify-center border-r border-black font-black">
              NO
            </div>
            <label className="flex items-center px-3">
              <input
                type="radio"
                checked={meta.provide_augmentation === false}
                onChange={() => setMeta("provide_augmentation", false)}
              />
            </label>
          </div>
          <div className="grid grid-cols-[minmax(0,1.1fr)_minmax(0,1.9fr)_minmax(0,.8fr)_minmax(0,5.2fr)] border-b border-black bg-slate-50 text-center text-xs font-black">
            <div className="border-r border-black py-1">UNIT</div>
            <div className="border-r border-black py-1">DESCRIPTION</div>
            <div className="border-r border-black py-1">QUANTITY</div>
            <div className="py-1">REMARKS</div>
          </div>
          <div className="grid min-h-64 grid-cols-[minmax(0,3.4fr)_minmax(0,5.6fr)] border-b border-black text-xs">
            <div className="border-r border-black">
              {form.data.items.map((item, index) => (
                <div
                  key={index}
                  className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.75fr)_minmax(0,.65fr)] border-b border-black"
                >
                  <div className="border-r border-black px-2 py-1 text-center font-bold uppercase">
                    {item.unit || "-"}
                  </div>
                  <div className="border-r border-black px-2 py-1 text-center font-bold">
                    {item.item_name || "Select an FNI above"}
                  </div>
                  <div className="px-2 py-1 text-center font-bold">
                    {formatWholeQuantity(item.requested_quantity, "0")}
                  </div>
                </div>
              ))}
            </div>
            <div className={`flex min-h-[34rem] max-h-[48rem] flex-col print:min-h-0 print:max-h-none ${
              hasFieldError("recommendations")
                ? "bg-rose-50 ring-2 ring-inset ring-rose-400"
                : "bg-white"
            }`}>
              <div className={`flex flex-wrap items-center justify-between gap-2 border-b p-2 print:hidden ${
                hasFieldError("recommendations")
                  ? "border-rose-300 bg-rose-100"
                  : "border-slate-200"
              }`}>
                <span className={`inline-flex items-center gap-2 font-sans text-[10px] font-bold uppercase ${
                  hasFieldError("recommendations") ? "text-rose-800" : "text-slate-500"
                }`}>
                  Assessment narrative
                  {hasFieldError("recommendations") && (
                    <span className="rounded-full bg-rose-700 px-2 py-0.5 text-[9px] font-black text-white">
                      Required
                    </span>
                  )}
                </span>
                <div className="flex flex-wrap gap-2">
                  <button type="button" title="Create a new structured assessment from the linked DROMIC and request data. This replaces the current editor text." onClick={() => runAssessmentAi("generate")} disabled={Boolean(aiAction)} className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 font-sans text-[11px] font-black text-emerald-700 hover:bg-emerald-100 disabled:opacity-50"><Sparkles className="h-3 w-3" />{aiAction === "generate" ? "Generating..." : "Auto-generate Assessment"}</button>
                  <button type="button" title="Refine your current assessment with minimal grammar and clarity edits while preserving your facts, meaning, structure, and manual additions." onClick={() => runAssessmentAi("polish")} disabled={Boolean(aiAction) || !form.data.recommendations?.trim()} className="inline-flex items-center gap-1 rounded-full border border-violet-200 bg-violet-50 px-3 py-1 font-sans text-[11px] font-black text-violet-700 hover:bg-violet-100 disabled:opacity-50"><Sparkles className="h-3 w-3" />{aiAction === "polish" ? "Polishing..." : "Polish Assessment"}</button>
                </div>
              </div>
              <div className="border-b border-sky-200 bg-sky-50 px-3 py-2 font-sans text-[10px] leading-relaxed text-sky-900 print:hidden">
                <b>Auto-generate</b> creates a new structured assessment from the linked records and replaces this text. <b>Polish</b> is for your manually written or edited assessment; it keeps your facts, meaning, paragraph order, and added details while correcting grammar and clarity. Always review the result before saving.
              </div>
              {aiError && <p className="px-2 pt-1 font-sans text-[10px] font-bold text-rose-600 print:hidden">{aiError}</p>}
              <textarea className={`h-[34rem] min-h-[30rem] max-h-[44rem] w-full flex-1 resize-y overflow-y-auto border-0 p-3 leading-relaxed outline-none focus:bg-amber-50 print:hidden ${
                hasFieldError("recommendations")
                  ? "bg-rose-50 text-rose-950 placeholder:text-rose-500"
                  : "bg-white"
              }`} value={form.data.recommendations} onChange={(e) => {
                const value = stripNarrativeSignoff(e.target.value);
                form.setData("recommendations", value);
                if (value.trim()) form.clearErrors("recommendations");
              }} onInput={(event) => { const editor = event.currentTarget; editor.style.height = "auto"; editor.style.height = `${Math.min(editor.scrollHeight, 704)}px`; }} placeholder={hasFieldError("recommendations") ? "Required: enter the assessment findings, validation, justification, and recommendation." : "Assessment findings, validation, justification and recommendation narrative"} />
              <div className="hidden whitespace-pre-wrap break-words p-3 text-[9px] leading-tight print:block">
                {stripNarrativeSignoff(form.data.recommendations)}
              </div>
            </div>
          </div>
          <div className="grid grid-cols-2 text-xs">
            <div className={`flex min-h-40 flex-col border-r border-black p-3 ${errorCell(
              "assessment_form_data.prepared_by",
              "assessment_form_data.prepared_by_position",
              "assessment_form_data.prepared_at",
              "assigned_social_worker",
            )}`}>
              <b>Prepared by:</b>
              <div className="mt-3 border-b border-black px-2 py-2 text-center">
                <p className="font-black uppercase">
                  {(meta.prepared_by || currentUser?.name || "User name not configured").toUpperCase()}
                </p>
              </div>
              <p className="min-h-6 text-center font-semibold">
                {preparedRole || "Position / Designation not configured"}
              </p>
              <label className="mt-auto grid grid-cols-[75px_1fr] items-center gap-2 pt-2 font-semibold">
                <span>Date / Time</span>
                <input
                  type="datetime-local"
                  className="h-8 border-0 border-b border-black bg-transparent px-1 text-xs"
                  value={meta.prepared_at ?? ""}
                  readOnly
                />
              </label>
            </div>
            <div className="flex min-h-40 flex-col p-3">
              <b>Reviewed by:</b>
              <select
                className={`mt-3 w-full border-0 border-b border-black bg-transparent p-2 text-center font-black ${errorCell("assessment_form_data.reviewed_by")}`}
                value={normalizeSignatoryValue(meta.reviewed_by ?? "")}
                onChange={(e) => setMeta("reviewed_by", normalizeSignatoryValue(e.target.value))}
              >
                <option value="">Select reviewed by signatory</option>
                {signatoryOptions("reviewed_by").map((row) => (
                  <option key={row.id} value={normalizeSignatoryValue(row.value)}>
                    {signatoryName(row.value)}
                  </option>
                ))}
              </select>
              <p className="min-h-6 text-center font-semibold">
                {signatoryPosition(meta.reviewed_by)}
              </p>
              <div className="mt-auto grid grid-cols-[75px_1fr] items-end gap-2 pt-2 font-semibold">
                <span>Date / Time</span>
                <span
                  className="block h-7 border-b border-black"
                  aria-label="Reviewed date and time blank line"
                />
              </div>
            </div>
          </div>
          <div className="border-t border-black p-3 text-xs">
            <b>Other Remarks:</b>
            <CellInput
              value={form.data.remarks}
              onChange={(v) => form.setData("remarks", v)}
            />
          </div>
          <div className="border-t border-black p-4 text-xs">
            <p className="text-center">
              <b>Approved by:</b>
            </p>
            <select
              className={`mx-auto mt-3 block w-2/3 border-0 border-b border-black bg-transparent p-2 text-center font-black ${errorCell("assessment_form_data.approved_by")}`}
              value={normalizeSignatoryValue(meta.approved_by ?? "")}
              onChange={(e) => setMeta("approved_by", normalizeSignatoryValue(e.target.value))}
            >
              <option value="">Select approved by signatory</option>
              {signatoryOptions("approved_by").map((row) => (
                <option key={row.id} value={normalizeSignatoryValue(row.value)}>
                  {signatoryName(row.value)}
                </option>
              ))}
            </select>
            <p className="min-h-6 text-center font-semibold">
              {signatoryPosition(meta.approved_by)}
            </p>
            <div className="mx-auto mt-3 grid w-2/3 grid-cols-[75px_1fr] items-end gap-2 font-semibold">
              <span>Date / Time</span>
              <span
                className="block h-7 border-b border-black"
                aria-label="Approved date and time blank line"
              />
            </div>
          </div>
        </div>
      </div>

      <DocumentPreviewModal
        open={draftPreviewOpen}
        onClose={() => setDraftPreviewOpen(false)}
        eyebrow="Document preview"
        badge="Draft / local preview"
        title="FNI Assessment and Delivery Form"
        subtitle="Official DomPDF layout · live values from this worksheet"
        notice="Draft assessment preview only — save the assessment to generate downloadable PDFs and enable e-PIRMA routing."
        usePaperCanvas={false}
      >
        {draftPreviewBusy ? (
          <div className="flex h-full min-h-[60vh] items-center justify-center p-8 text-sm font-bold text-slate-600">
            Building assessment PDF preview…
          </div>
        ) : draftPreviewError ? (
          <div className="flex h-full min-h-[60vh] items-center justify-center p-8 text-center text-sm font-bold text-rose-700">
            {draftPreviewError}
          </div>
        ) : draftPreviewUrl ? (
          <iframe
            title="Assessment PDF draft preview"
            src={`${draftPreviewUrl}#toolbar=1&navpanes=0`}
            className="h-full min-h-[60vh] w-full bg-slate-200"
          />
        ) : null}
      </DocumentPreviewModal>
    </form>
  );
}
