import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import {
  CheckCircle2,
  ClipboardList,
  Clock3,
  FileSpreadsheet,
  FileText,
  Eye,
  Images,
  ListChecks,
  Mail,
  Download,
  Printer,
  XCircle,
  X,
} from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import AppLayout, {
  Card,
  DataTable,
  ExportableCard,
  TableActionButton,
} from "@/Layouts/AppLayout";
import SearchableSelect from "@/Components/SearchableSelect";
import { currentDrnParts, ResponseDrnModal } from "@/Components/DocumentDrnFields";
import { formatDate, formatDateTime } from "@/Utils/dateFormat";
import AssessmentExcelForm from "./AssessmentExcelForm";
import DrrsRequestsWorkspaceTabs from "@/Components/DrrsRequestsWorkspaceTabs";
import SystemTabs from "@/Components/SystemTabs";
import ReliefAssessmentGateBanner, { reliefLetterBlocksAssessment } from "@/Components/ReliefAssessmentGateBanner";

const localDateTimeValue = () => {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  return now.toISOString().slice(0, 16);
};

const hasCompleteDrn = (value) => /^.+-\d{2}-\d{2}-.+$/.test(String(value || "").trim());
const allowedPurposes = ["Relief Augmentation", "Preparedness for Response"];
const requestPurpose = (request) => {
  const value = request.assessment_form_data?.response_purpose || request.purpose;
  return allowedPurposes.includes(value) ? value : "-";
};
const requestIncident = (request) => {
  if (requestPurpose(request) !== "Relief Augmentation") return "-";
  const type = String(request.incident?.name || "").trim();
  const specified = String(request.incident_details || "").trim();
  if (!type && !specified) return "-";
  return `${type}${specified ? ` (${specified})` : ""}`;
};
const localDateValue = () => {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};

const titleCase = (value) => String(value || "")
  .toLocaleLowerCase()
  .replace(/\b\p{L}/gu, (letter) => letter.toLocaleUpperCase());

const extractProvince = (value) => [
  "Agusan del Norte",
  "Agusan del Sur",
  "Dinagat Islands",
  "Surigao del Norte",
  "Surigao del Sur",
].find((province) => String(value || "").toLowerCase().includes(province.toLowerCase())) || "";

const provinceAbbreviation = (value) => ({
  "agusan del norte": "ADN",
  "agusan del sur": "ADS",
  "dinagat islands": "PDI",
  "province of dinagat islands": "PDI",
  "surigao del norte": "SDN",
  "surigao del sur": "SDS",
}[String(value || "").trim().toLocaleLowerCase()] || titleCase(value));

const uniformLguName = (request) => {
  const level = String(request.lgu_level || "").toLowerCase();
  const prefix = level.includes("province") || level === "plgu"
    ? "PLGU"
    : level.includes("city") || level === "clgu"
      ? "CLGU"
      : "MLGU";
  const locality = titleCase(request.municipality || request.lgu || request.requesting_agency || "LGU");
  const province = provinceAbbreviation(request.province || extractProvince(request.office_agency_details));

  return `${prefix} - ${locality}${province ? `, ${province}` : ""}`;
};

const uniformLguOfficeDetails = (request) => {
  const locality = titleCase(request.municipality || request.lgu || request.requesting_agency || "LGU");
  const province = titleCase(request.province || extractProvince(request.office_agency_details));

  return `Local Government Unit of ${locality}${province ? `, ${province}` : ""}`;
};
const stockItemKey = (value) =>
  String(value ?? "").toLocaleLowerCase().replace(/[^a-z0-9]+/g, "");

const displayRequestReference = (request) =>
  request?.source_lgu_dromic_report?.lgu_relief_request_reference
  || request?.assessment_form_data?.source_lgu_request_reference
  || request?.reference_number;

export default function Index({
  requests,
  assessmentTypes,
  inventoryItems,
  fniLibraryItems = [],
  libraryOptions = {},
  drrsSignatories = [],
  requestParties = [],
  psgc = {},
  socialWorkers = [],
  warehouseStock = [],
  assessments = { data: [] },
  drnPrefixes = [],
  workspaceSummary = null,
  reliefAssessmentGate = null,
  highlightRequestId = null,
}) {
  const currentUser = usePage().props.auth.user;
  const permissions = currentUser?.permissions ?? [];
  const canEncode = permissions.includes("encode requests");
  const canProcess = permissions.includes("process requests");
  const currentStockAvailability = (itemName) =>
    warehouseStock
      .filter((row) => stockItemKey(row.item) === stockItemKey(itemName))
      .reduce((total, row) => total + Number(row.available || 0), 0);
  const assessmentDrnDefaults = currentDrnParts(drnPrefixes.find((row) => row.context === "assessment")?.value);
  const [provinceCode, setProvinceCode] = useState("");
  const [municipalityCode, setMunicipalityCode] = useState("");
  const [activeTab, setActiveTab] = useState(
    usePage().props.defaultTab || "tracker",
  );
  const [attentionRequestId, setAttentionRequestId] = useState(
    highlightRequestId ? Number(highlightRequestId) : null,
  );
  const [assessmentRecord, setAssessmentRecord] = useState(null);
  const [readyRecord, setReadyRecord] = useState(null);
  const [responsePrompt, setResponsePrompt] = useState(null);
  const [epirmaStatusError, setEpirmaStatusError] = useState(null);
  const [documentPreview, setDocumentPreview] = useState(null);
  const [documentPreviewTab, setDocumentPreviewTab] = useState("request");
  const editAssessmentOpenedRef = useRef(false);

  useEffect(() => {
    const id = highlightRequestId ? Number(highlightRequestId) : null;
    if (!id) {
      return undefined;
    }

    setAttentionRequestId(id);
    setActiveTab("tracker");

    const scrollTimer = window.setTimeout(() => {
      document.getElementById(`fni-request-row-${id}`)?.scrollIntoView({
        behavior: "smooth",
        block: "center",
      });
    }, 180);

    const clearTimer = window.setTimeout(() => {
      setAttentionRequestId(null);
      try {
        const url = new URL(window.location.href);
        url.searchParams.delete("highlight");
        window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
      } catch {
        // Ignore history cleanup failures.
      }
    }, 7000);

    return () => {
      window.clearTimeout(scrollTimer);
      window.clearTimeout(clearTimer);
    };
  }, [highlightRequestId]);

  const form = useForm({
    request_party_id: "",
    requesting_agency: "",
    lgu: "",
    lgu_level: "",
    province: "",
    municipality: "",
    barangay: "",
    requester: "",
    date_requested: new Date().toISOString().slice(0, 10),
    incident_name: "",
    incident_date: "",
    assessment_type_id: assessmentTypes[0]?.id ?? "",
    purpose: "Relief Augmentation",
    assessment_summary: "",
    recommendations: "",
    remarks: "",
    date_received_by_drmd: new Date().toISOString().slice(0, 10),
    request_drn: "",
    office_agency_details: "",
    endorsed_to_drrs: true,
    date_endorsed_to_drrs: "",
    incident_details: "",
    incident_count: 1,
    response_drn: "",
    assessment_drn: "",
    source_document_url: "",
    response_letter_url: "",
    coordinated_with_rros: false,
    date_coordinated_with_rros: "",
    requester_position: "",
    requester_address: "",
    contact_number: "",
    affected_families: "",
    assigned_social_worker: (currentUser?.name ?? "").toUpperCase(),
    act_on_behalf: false,
    on_behalf_reason: "",
    assessment_form_data: {
      request_type: "Disaster",
      response_purpose: "Relief Augmentation",
      has_previous_augmentation: false,
      provide_augmentation: true,
      prepared_by: (currentUser?.name ?? "").toUpperCase(),
      prepared_by_position: currentUser?.position ?? "",
      prepared_by_designation: currentUser?.designation ?? "",
      prepared_at: localDateTimeValue(),
      assessment_date: localDateValue(),
      reviewed_at: "",
      approved_at: "",
      reviewed_by: drrsSignatories.find((row) => row.context === "reviewed_by")?.value ?? "",
      approved_by: drrsSignatories.find((row) => row.context === "approved_by")?.value ?? "",
      assessment_drn_prefix: assessmentDrnDefaults.prefix,
      assessment_drn_year: assessmentDrnDefaults.year,
      assessment_drn_month: assessmentDrnDefaults.month,
      assessment_drn_specified: "",
      delivery_batches: Array.from({ length: 5 }, () => ({
        quantity: "",
        date: "",
        available: "",
        details: "",
      })),
    },
    items: [
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
    ],
  });
  const provinceOptions = (psgc.provinces ?? []).map((row) => ({
    value: row.code,
    label: row.name,
  }));
  const municipalityOptions = useMemo(
    () =>
      (psgc.municipalities ?? [])
        .filter((row) => row.parent_code === provinceCode)
        .map((row) => ({ value: row.code, label: row.name })),
    [psgc.municipalities, provinceCode],
  );
  const barangayOptions = useMemo(
    () =>
      (psgc.barangays ?? [])
        .filter((row) => row.parent_code === municipalityCode)
        .map((row) => ({ value: row.name, label: row.name })),
    [psgc.barangays, municipalityCode],
  );
  const partyOptions = requestParties.map((party) => ({
    value: String(party.id),
    label:
      !party.lgu_level &&
      party.office_agency_details &&
      party.office_agency_details !== party.requesting_party
        ? `${party.requesting_party} — ${party.office_agency_details}`
        : party.requesting_party,
  }));
  const incidentOptions = (libraryOptions.incident_type ?? []).map((value) => ({
    value,
    label: value,
  }));
  const purposeOptions = (libraryOptions.transaction_purpose ?? []).map(
    (value) => ({ value, label: value }),
  );
  const socialWorkerOptions = socialWorkers.map((user) => ({
    value: user.name,
    label: user.name,
  }));

  const selectRequestParty = (value) => {
    const party = requestParties.find(
      (row) => String(row.id) === String(value),
    );
    if (!party) return;
    form.setData((current) => ({
      ...current,
      request_party_id: value,
      requesting_agency: party.requesting_party,
      lgu: party.office_agency_details ?? party.requesting_party,
      lgu_level: party.lgu_level ?? "",
      office_agency_details: party.office_agency_details ?? "",
      requester: party.office_head || current.requester,
    }));
  };

  const openEndorsedAssessment = (request, options = {}) => {
    if (!request.assessment_status && reliefLetterBlocksAssessment(request)) {
      return;
    }
    const onBehalf = Boolean(options.onBehalf);
    const party = requestParties.find((row) => String(row.id) === String(request.request_party_id));
    const existingMeta = request.assessment_form_data ?? {};
    const sourceReport = request.source_lgu_dromic_report;
    const sourcePayload = sourceReport?.lgu_dromic_payload ?? {};
    const sourceIncident = sourceReport?.incident ?? request.incident;
    const sourceOccurrence = sourcePayload.occurrence_started_at || sourcePayload.incident_date || sourceIncident?.incident_date || "";
    const sourceIncidentName = sourcePayload.incident_name || sourceIncident?.name || "";
    const normalizeIncidentText = (value) => String(value ?? "").trim().toLocaleLowerCase();
    const incidentTypeKey = normalizeIncidentText(sourcePayload.incident_type);
    const incidentNameKey = normalizeIncidentText(sourceIncidentName);
    const sourceIncidentDetails = [
      existingMeta.incident_specific_details,
      sourcePayload.incident_specific_details,
      ...(sourceReport ? [] : [request.incident_details]),
    ]
      .map((value) => String(value ?? "").trim())
      .find((value) => {
        const normalizedValue = normalizeIncidentText(value);
        return normalizedValue && normalizedValue !== incidentTypeKey && normalizedValue !== incidentNameKey;
      }) ?? "";
    const sourceAffectedAreas = Array.isArray(sourcePayload.affected_barangays) ? sourcePayload.affected_barangays : [];
    const sourceAreaRows = Array.isArray(sourcePayload.area_rows) ? sourcePayload.area_rows : [];
    const sourceEvacuationRows = Array.isArray(sourcePayload.evacuation_center_rows) ? sourcePayload.evacuation_center_rows : [];
    const sumSourceRows = (rows, key) => rows.reduce((sum, row) => sum + Number(row?.[key] || 0), 0);
    const insideDisplacement = {
      families_cum: sumSourceRows(sourceEvacuationRows, "families_cum"),
      families_now: sumSourceRows(sourceEvacuationRows, "families_now"),
      persons_cum: sumSourceRows(sourceEvacuationRows, "persons_cum"),
      persons_now: sumSourceRows(sourceEvacuationRows, "persons_now"),
    };
    const outsideDisplacement = {
      families_cum: sumSourceRows(sourceAreaRows, "outside_ec_families_cum"),
      families_now: sumSourceRows(sourceAreaRows, "outside_ec_families_now"),
      persons_cum: sumSourceRows(sourceAreaRows, "outside_ec_persons_cum"),
      persons_now: sumSourceRows(sourceAreaRows, "outside_ec_persons_now"),
    };
    const sourceDisplacement = {
      inside_ec: insideDisplacement,
      outside_ec: outsideDisplacement,
      total: {
        families_cum: insideDisplacement.families_cum + outsideDisplacement.families_cum,
        families_now: insideDisplacement.families_now + outsideDisplacement.families_now,
        persons_cum: insideDisplacement.persons_cum + outsideDisplacement.persons_cum,
        persons_now: insideDisplacement.persons_now + outsideDisplacement.persons_now,
      },
    };
    const sourceAdvisories = (Array.isArray(sourcePayload.official_advisory_rows) ? sourcePayload.official_advisory_rows : [])
      .slice(0, 3)
      .map((row) => ({
        agency: row?.agency ?? "",
        advisory_title: row?.advisory_title ?? "",
        issued_at: row?.issued_at ?? "",
        covered_location: row?.covered_location ?? "",
        summary: String(row?.summary ?? row?.pasted_text ?? "").slice(0, 3500),
      }));
    const sourceResponseActions = (Array.isArray(sourcePayload.response_action_rows) ? sourcePayload.response_action_rows : [])
      .map((row) => String(row?.action_intervention ?? row?.action ?? "").trim())
      .filter(Boolean);
    const displayedRequestingParty = sourceReport
      ? uniformLguName(request)
      : (request.requesting_agency ?? party?.requesting_party ?? "");
    const existingPurpose = allowedPurposes.includes(existingMeta.response_purpose || request.purpose) ? (existingMeta.response_purpose || request.purpose) : "Relief Augmentation";
    const existingItems = (request.items ?? []).map((item) => ({
      inventory_item_id: item.inventory_item_id ?? "",
      fni_library_item_id: String(item.fni_library_item_id ?? ""),
      source_warehouse_id: item.source_warehouse_id ?? "",
      source_warehouse_name: item.source_warehouse_name ?? "",
      available_quantity: currentStockAvailability(item.item_name),
      item_name: item.item_name ?? "",
      requested_quantity: Math.max(1, Math.round(Number(item.requested_quantity ?? 1))),
      unit: item.unit ?? "",
      priority: item.priority ?? "normal",
      remarks: item.remarks ?? "",
    }));
    form.clearErrors();
    form.setData({
      ...form.data,
      request_party_id: String(request.request_party_id ?? ""),
      requesting_agency: displayedRequestingParty,
      lgu: request.office_agency_details ?? "",
      lgu_level: request.lgu_level ?? "",
      province: request.province ?? sourceReport?.province ?? "",
      municipality: request.municipality ?? sourceReport?.municipality ?? "",
      barangay: request.barangay ?? sourceReport?.barangay ?? sourceAffectedAreas[0] ?? "",
      requester: request.requester ?? sourceReport?.requester ?? party?.office_head ?? "",
      date_requested: (request.date_requested ?? request.date_received_by_drmd ?? new Date().toISOString()).slice(0, 10),
      incident_name: existingPurpose === "Relief Augmentation" ? (sourceIncidentName || request.incident?.name || "") : "",
      incident_date: existingPurpose === "Relief Augmentation" ? String(sourceOccurrence).slice(0, 10) : "",
      purpose: existingPurpose,
      assessment_summary: request.assessment_summary ?? sourcePayload.incident_summary ?? sourceReport?.lgu_dromic_narrative ?? "",
      recommendations: request.recommendations ?? "",
      remarks: request.remarks ?? "",
      date_received_by_drmd: (request.date_received_by_drmd ?? new Date().toISOString()).slice(0, 10),
      request_drn: request.request_drn ?? "",
      office_agency_details: request.office_agency_details ?? "",
      endorsed_to_drrs: true,
      date_endorsed_to_drrs: request.date_endorsed_to_drrs?.slice(0, 10) ?? "",
      incident_details: existingPurpose === "Relief Augmentation" ? sourceIncidentDetails : "",
      incident_count: request.incident_count ?? 1,
      response_drn: request.response_drn ?? "",
      assessment_drn: request.assessment_drn ?? "",
      source_document_url: request.source_document_url ?? "",
      response_letter_url: "",
      coordinated_with_rros: false,
      date_coordinated_with_rros: "",
      requester_position: request.requester_position ?? sourceReport?.requester_position ?? "",
      requester_address: request.requester_address ?? sourceReport?.requester_address ?? "",
      contact_number: request.contact_number ?? sourceReport?.contact_number ?? "",
      affected_families: request.affected_families ?? sourcePayload.affected_families ?? sourceReport?.affected_families ?? "",
      assigned_social_worker: (currentUser?.name ?? "").toUpperCase(),
      act_on_behalf: onBehalf,
      on_behalf_reason: "",
      assessment_form_data: {
        ...existingMeta,
        source_lgu_dromic_reference: existingMeta.source_lgu_dromic_reference ?? sourceReport?.reference_number,
        source_lgu_request_reference: existingMeta.source_lgu_request_reference ?? sourceReport?.lgu_relief_request_reference,
        source_data_prefilled: Boolean(sourceReport),
        affected_persons: existingMeta.affected_persons ?? sourcePayload.affected_persons ?? "",
        affected_areas: existingMeta.affected_areas ?? sourceAffectedAreas,
        information_source: sourceReport
          ? displayedRequestingParty
          : (existingMeta.information_source ?? displayedRequestingParty),
        information_date: existingMeta.information_date ?? String(request.date_received_by_drmd ?? request.submitted_at ?? "").slice(0, 10),
        assessment_date: !request.assessment_status || request.assessment_status === "draft"
          ? localDateValue()
          : (existingMeta.assessment_date ?? localDateValue()),
        incident_type: existingMeta.incident_type ?? sourcePayload.incident_type ?? "",
        incident_specific_details: sourceIncidentDetails,
        occurrence_started_at: existingMeta.occurrence_started_at ?? sourceOccurrence,
        incident_status: existingMeta.incident_status || sourcePayload.incident_status || "",
        incident_ended_at: existingMeta.incident_ended_at ?? sourcePayload.incident_ended_at ?? "",
        identified_needs: existingMeta.identified_needs ?? sourcePayload.needs ?? "",
        lgu_report_remarks: existingMeta.lgu_report_remarks ?? sourcePayload.remarks ?? "",
        source_dromic_narrative: existingMeta.source_dromic_narrative ?? String(sourcePayload.narrative ?? sourcePayload.incident_summary ?? "").slice(0, 5000),
        source_official_advisories: existingMeta.source_official_advisories ?? sourceAdvisories,
        source_lgu_response_actions: existingMeta.source_lgu_response_actions ?? sourceResponseActions,
        source_displacement: existingMeta.source_displacement ?? sourceDisplacement,
        source_report_classification: existingMeta.source_report_classification || sourcePayload.report_classification || "",
        request_type: existingPurpose === "Relief Augmentation" ? "Disaster" : null,
        response_purpose: existingPurpose,
        original_request_purpose: request.purpose ?? "",
        has_previous_augmentation: existingMeta.has_previous_augmentation ?? false,
        provide_augmentation: existingMeta.provide_augmentation ?? (existingPurpose === "Relief Augmentation"),
        prepared_by: (currentUser?.name ?? "").toUpperCase(),
        prepared_by_position: currentUser?.position ?? "",
        prepared_by_designation: currentUser?.designation ?? "",
        prepared_at: existingMeta.prepared_at ?? localDateTimeValue(),
        reviewed_at: "",
        approved_at: "",
        reviewed_by: existingMeta.reviewed_by ?? drrsSignatories.find((row) => row.context === "reviewed_by")?.value ?? "",
        approved_by: existingMeta.approved_by ?? drrsSignatories.find((row) => row.context === "approved_by")?.value ?? "",
        assessment_drn_prefix: existingMeta.assessment_drn_prefix ?? assessmentDrnDefaults.prefix,
        assessment_drn_year: existingMeta.assessment_drn_year ?? assessmentDrnDefaults.year,
        assessment_drn_month: existingMeta.assessment_drn_month ?? assessmentDrnDefaults.month,
        assessment_drn_specified: existingMeta.assessment_drn_specified ?? "",
        previous_augmentations: existingMeta.previous_augmentations ?? Array.from({ length: 3 }, () => ({ unit: "", description: "", quantity: "", remarks: "" })),
        delivery_batches: existingMeta.delivery_batches ?? Array.from({ length: 5 }, () => ({ quantity: "", date: "", available: "", details: "" })),
      },
      items: existingItems.length ? existingItems : [{ inventory_item_id: "", fni_library_item_id: "", source_warehouse_id: "", source_warehouse_name: "", available_quantity: "", item_name: "", requested_quantity: 1, unit: "", priority: "normal", remarks: "" }],
    });
    setAssessmentRecord(request);
  };

  useEffect(() => {
    if (typeof window === "undefined" || editAssessmentOpenedRef.current) {
      return undefined;
    }
    const params = new URLSearchParams(window.location.search);
    const editId = params.get("edit_assessment");
    if (!editId) {
      return undefined;
    }

    setActiveTab("assessments");
    const record = (assessments.data ?? []).find((row) => String(row.id) === String(editId))
      || (requests.data ?? []).find((row) => String(row.id) === String(editId));

    if (!record) {
      return undefined;
    }

    editAssessmentOpenedRef.current = true;
    openEndorsedAssessment(record);
    params.delete("edit_assessment");
    const next = `${window.location.pathname}${params.toString() ? `?${params}` : ""}`;
    window.history.replaceState({}, "", next);
    return undefined;
    // Open once when arriving from the documents workspace Edit Draft action.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [assessments.data, requests.data]);

  const setItem = (index, key, value) => {
    const items = [...form.data.items];
    items[index] = { ...items[index], [key]: value };
    const inventory = inventoryItems.find(
      (item) => String(item.id) === String(value),
    );
    if (key === "inventory_item_id" && inventory) {
      items[index].item_name = inventory.name;
      items[index].unit = inventory.unit;
    }
    form.setData("items", items);
  };

  const decide = (request, decision) => {
    router.post(`/requests/${request.id}/decision`, {
      decision,
      remarks: "",
      items: request.items.map((item) => ({
        id: item.id,
        approved_quantity:
          decision === "rejected" ? 0 : item.requested_quantity,
      })),
    });
  };
  const canDecideRequest = (request) => canProcess
    && request.assessment_status === "submitted"
    && !["approved", "partially_approved", "rejected"].includes(request.status);

  const performResponseAction = (request, action) => {
    if (action === "preview") window.location.assign(`/requests/${request.id}/assessment-form?document=response&confirmed=1`);
    else if (action === "print") window.open(`/requests/${request.id}/response-letter-pdf?inline=1`, "_blank", "noopener,noreferrer");
    else if (action === "word") window.location.assign(`/requests/${request.id}/response-letter`);
    else window.location.assign(`/requests/${request.id}/response-letter-pdf`);
  };

  const openResponseAction = (request, action) => {
    if (hasCompleteDrn(request.response_drn)) performResponseAction(request, action);
    else setResponsePrompt({ request, action });
  };

  return (
    <AppLayout title="FNI Requests">
      <Head title="FNI Requests" />
      <DrrsRequestsWorkspaceTabs active="fni" />
      <ReliefAssessmentGateBanner
        awaitingValidation={reliefAssessmentGate?.awaiting_validation}
        needsLguAction={reliefAssessmentGate?.needs_lgu_action}
        context="fni"
      />
      {workspaceSummary && (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3 border-x border-b border-slate-200 bg-slate-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-950/30">
          {[
            [
              ListChecks,
              "Requests",
              workspaceSummary.requests,
              "indigo",
            ],
            [
              Clock3,
              "Still for action",
              workspaceSummary.still_for_action,
              "amber",
              [
                ["Awaiting", workspaceSummary.breakdown?.awaiting_assessment],
                ["Draft", workspaceSummary.breakdown?.draft_under_review],
              ],
            ],
            [
              CheckCircle2,
              "Acted",
              workspaceSummary.acted,
              "emerald",
              [
                ["Final", workspaceSummary.breakdown?.final],
                ["Submitted", workspaceSummary.breakdown?.submitted],
                ["Approved", workspaceSummary.breakdown?.approved],
              ],
            ],
          ].map(([Icon, label, value, tone, breakdown]) => (
            <WorkspaceMetricCard
              key={label}
              icon={Icon}
              label={label}
              value={value}
              tone={tone}
              breakdown={breakdown || []}
            />
          ))}
        </div>
      )}
      <div className="border-x border-b border-slate-200 bg-white px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900">
        <p className="mb-2 text-[10px] font-black uppercase tracking-widest text-slate-400">FNI Request Views</p>
        <SystemTabs
          active={activeTab}
          ariaLabel="FNI request views"
          subtle
          className="rounded-b-none border-b-0"
          items={[
            {
              key: "tracker",
              label: "Requests & Action Status",
              icon: ClipboardList,
              onClick: () => setActiveTab("tracker"),
            },
            {
              key: "assessments",
              label: "Created Assessments",
              icon: FileSpreadsheet,
              onClick: () => setActiveTab("assessments"),
            },
          ]}
        />
      </div>
      <div>
        {activeTab === "assessment" && canEncode && (
          <AssessmentExcelForm
            form={form}
            currentUser={currentUser}
            partyOptions={partyOptions}
            selectRequestParty={selectRequestParty}
            requestParties={requestParties}
            psgc={psgc}
            inventoryItems={inventoryItems}
            fniLibraryItems={fniLibraryItems}
            incidentOptions={incidentOptions}
            drrsSignatories={drrsSignatories}
          />
        )}
        {false && activeTab === "assessment" && canEncode && (
          <Card
            id="request-encode"
            className="mx-auto max-w-7xl scroll-mt-28 overflow-hidden border-slate-300 p-0 dark:border-zinc-700"
          >
            <div className="border-b border-slate-300 bg-emerald-800 px-5 py-4 text-white dark:border-zinc-700">
              <p className="text-xs font-black uppercase tracking-wide text-emerald-100">
                DRRS Assessment Worksheet
              </p>
              <h2 className="mt-1 text-xl font-black">
                Request, Assessment and Response Data Entry
              </h2>
              <p className="mt-1 text-sm text-emerald-100">
                Fields follow the monitoring-sheet sequence. Complete the
                worksheet from top to bottom.
              </p>
            </div>
            <form
              className="max-h-[calc(100vh-16rem)] space-y-5 overflow-y-auto p-5 [&_input]:rounded-none [&_select]:rounded-none [&_textarea]:rounded-none"
              onSubmit={(e) => {
                e.preventDefault();
                form.post("/requests", {
                  onSuccess: () => {
                    form.reset();
                    setActiveTab("tracker");
                  },
                });
              }}
            >
              <p className="border-b border-slate-200 pb-2 text-xs font-black uppercase tracking-wide text-brand-700 dark:border-zinc-800 dark:text-brand-100">
                Request Intake
              </p>
              <div className="grid gap-2 sm:grid-cols-2">
                <label className="text-xs font-bold">
                  Date Received by DRMD
                  <input
                    type="date"
                    className="mt-1 w-full"
                    value={form.data.date_received_by_drmd}
                    onChange={(e) =>
                      form.setData("date_received_by_drmd", e.target.value)
                    }
                  />
                </label>
                <label className="text-xs font-bold">
                  DRN of Request
                  <input
                    className="mt-1 w-full"
                    value={form.data.request_drn}
                    onChange={(e) =>
                      form.setData("request_drn", e.target.value)
                    }
                  />
                </label>
              </div>
              <SearchableSelect
                label="Requesting / Proposing Party *"
                options={partyOptions}
                value={form.data.request_party_id}
                onChange={selectRequestParty}
                placeholder="Search requesting party or office"
              />
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledInput
                  label="Office / Agency Details"
                  value={form.data.office_agency_details}
                  onChange={(value) =>
                    form.setData((current) => ({
                      ...current,
                      office_agency_details: value,
                      lgu: value,
                    }))
                  }
                />
                <LabeledInput
                  label="LGU Level"
                  value={form.data.lgu_level}
                  onChange={(value) => form.setData("lgu_level", value)}
                />
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledInput
                  label="Requester / Signatory *"
                  value={form.data.requester}
                  onChange={(value) => form.setData("requester", value)}
                />
                <LabeledInput
                  label="Position / Title"
                  value={form.data.requester_position}
                  onChange={(value) =>
                    form.setData("requester_position", value)
                  }
                />
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledInput
                  label="Contact Number"
                  value={form.data.contact_number}
                  onChange={(value) => form.setData("contact_number", value)}
                />
                <LabeledInput
                  label="Requester Address"
                  value={form.data.requester_address}
                  onChange={(value) => form.setData("requester_address", value)}
                />
              </div>
              <p className="border-b border-slate-200 pb-2 pt-2 text-xs font-black uppercase tracking-wide text-brand-700 dark:border-zinc-800 dark:text-brand-100">
                PSGC Location
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <SearchableSelect
                  label="Province *"
                  options={provinceOptions}
                  value={provinceCode}
                  onChange={(code) => {
                    const row = (psgc.provinces ?? []).find(
                      (item) => item.code === code,
                    );
                    setProvinceCode(code);
                    setMunicipalityCode("");
                    form.setData((current) => ({
                      ...current,
                      province: row?.name ?? "",
                      municipality: "",
                      barangay: "",
                    }));
                  }}
                  placeholder="Select province"
                />
                <SearchableSelect
                  label="City / Municipality *"
                  options={municipalityOptions}
                  value={municipalityCode}
                  onChange={(code) => {
                    const row = (psgc.municipalities ?? []).find(
                      (item) => item.code === code,
                    );
                    setMunicipalityCode(code);
                    form.setData((current) => ({
                      ...current,
                      municipality: row?.name ?? "",
                      barangay: "",
                    }));
                  }}
                  placeholder="Select city or municipality"
                />
              </div>
              <SearchableSelect
                label="Barangay"
                options={barangayOptions}
                value={form.data.barangay}
                onChange={(value) => form.setData("barangay", value)}
                placeholder="Select barangay"
              />
              <p className="border-b border-slate-200 pb-2 pt-2 text-xs font-black uppercase tracking-wide text-brand-700 dark:border-zinc-800 dark:text-brand-100">
                Purpose and Incident
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <SearchableSelect
                  label="Purpose of Request *"
                  options={purposeOptions}
                  value={form.data.purpose}
                  onChange={(value) => form.setData("purpose", value)}
                  placeholder="Select purpose"
                />
                <SearchableSelect
                  label="Incident / Weather Disturbance *"
                  options={incidentOptions}
                  value={form.data.incident_name}
                  onChange={(value) => form.setData("incident_name", value)}
                  placeholder="Select incident type"
                />
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledInput
                  label="Date Request Was Received *"
                  type="date"
                  value={form.data.date_requested}
                  onChange={(value) => form.setData("date_requested", value)}
                />
                <LabeledInput
                  label="Incident Date"
                  type="date"
                  value={form.data.incident_date}
                  onChange={(value) => form.setData("incident_date", value)}
                />
              </div>
              <label className="block text-sm font-bold">
                Assessment Type
                <select
                  className="mt-1 w-full"
                  value={form.data.assessment_type_id}
                  onChange={(e) =>
                    form.setData("assessment_type_id", e.target.value)
                  }
                >
                  {assessmentTypes.map((type) => (
                    <option key={type.id} value={type.id}>
                      {type.name}
                    </option>
                  ))}
                </select>
              </label>
              <label className="block text-sm font-bold">
                Assessment Summary
                <textarea
                  rows="4"
                  className="mt-1 w-full"
                  value={form.data.assessment_summary}
                  onChange={(e) =>
                    form.setData("assessment_summary", e.target.value)
                  }
                />
              </label>
              <p className="border-b border-slate-200 pb-2 pt-2 text-xs font-black uppercase tracking-wide text-brand-700 dark:border-zinc-800 dark:text-brand-100">
                Assessment & Response Documents
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledInput
                  label="Further Incident Details"
                  placeholder="e.g. TD ADA"
                  value={form.data.incident_details}
                  onChange={(value) => form.setData("incident_details", value)}
                />
                <LabeledInput
                  label="Number of Separate Incidents"
                  type="number"
                  min="1"
                  value={form.data.incident_count}
                  onChange={(value) => form.setData("incident_count", value)}
                />
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledInput
                  label="Affected Families"
                  type="number"
                  min="0"
                  value={form.data.affected_families}
                  onChange={(value) => form.setData("affected_families", value)}
                />
                <SearchableSelect
                  label="Assigned DRRS Social Worker"
                  options={socialWorkerOptions}
                  value={form.data.assigned_social_worker}
                  onChange={(value) =>
                    form.setData("assigned_social_worker", value)
                  }
                  placeholder="Select social worker"
                />
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <LabeledInput
                  label="DRN of Assessment"
                  value={form.data.assessment_drn}
                  onChange={(value) => form.setData("assessment_drn", value)}
                />
                <LabeledInput
                  label="DRN of Response Letter"
                  value={form.data.response_drn}
                  onChange={(value) => form.setData("response_drn", value)}
                />
              </div>
              <LabeledInput
                label="Signed Request / Supporting Documents Link"
                type="url"
                value={form.data.source_document_url}
                onChange={(value) => form.setData("source_document_url", value)}
              />
              <div className="grid gap-2 sm:grid-cols-2">
                <label className="flex items-center gap-2 text-xs font-bold">
                  <input
                    type="checkbox"
                    checked={form.data.endorsed_to_drrs}
                    onChange={(e) =>
                      form.setData("endorsed_to_drrs", e.target.checked)
                    }
                  />
                  Endorsed to DRRS
                </label>
                <input
                  type="date"
                  className="w-full"
                  value={form.data.date_endorsed_to_drrs}
                  onChange={(e) =>
                    form.setData("date_endorsed_to_drrs", e.target.value)
                  }
                />
              </div>
              <div className="grid gap-2 sm:grid-cols-2">
                <label className="flex items-center gap-2 text-xs font-bold">
                  <input
                    type="checkbox"
                    checked={form.data.coordinated_with_rros}
                    onChange={(e) =>
                      form.setData("coordinated_with_rros", e.target.checked)
                    }
                  />
                  Coordinated with RROS
                </label>
                <input
                  type="date"
                  className="w-full"
                  value={form.data.date_coordinated_with_rros}
                  onChange={(e) =>
                    form.setData("date_coordinated_with_rros", e.target.value)
                  }
                />
              </div>
              <p className="border-b border-slate-200 pb-2 pt-2 text-xs font-black uppercase tracking-wide text-brand-700 dark:border-zinc-800 dark:text-brand-100">
                Requested Food and Non-Food Items
              </p>
              <div className="space-y-2">
                {form.data.items.map((item, index) => (
                  <div key={index} className="grid grid-cols-[1fr_90px] gap-2">
                    <SearchableSelect
                      label={`Item ${index + 1}`}
                      options={inventoryItems.map((inventory) => ({
                        value: String(inventory.id),
                        label: `${inventory.name} (${inventory.unit})`,
                      }))}
                      value={item.inventory_item_id}
                      onChange={(value) =>
                        setItem(index, "inventory_item_id", value)
                      }
                      placeholder="Select inventory item"
                    />
                    <LabeledInput
                      label="Quantity"
                      type="number"
                      min="0.01"
                      value={item.requested_quantity}
                      onChange={(value) =>
                        setItem(index, "requested_quantity", value)
                      }
                    />
                  </div>
                ))}
              </div>
              {Object.values(form.errors).length > 0 && (
                <div className="rounded-md border border-rose-200 bg-rose-50 p-3 text-xs font-bold text-rose-700">
                  Please complete all required request fields before submitting.
                </div>
              )}
              <div className="sticky bottom-0 flex gap-2 border-t border-slate-200 bg-white py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <button
                  type="button"
                  className="rounded-md border border-slate-200 px-3 py-2 text-sm font-bold dark:border-zinc-700"
                  onClick={() =>
                    form.setData("items", [
                      ...form.data.items,
                      {
                        inventory_item_id: "",
                        item_name: "",
                        requested_quantity: 1,
                        unit: "",
                        priority: "normal",
                        remarks: "",
                      },
                    ])
                  }
                >
                  Add Another Item
                </button>
                <button
                  disabled={form.processing}
                  className="flex-1 rounded-md bg-brand-600 px-4 py-2 text-sm font-black text-white disabled:opacity-60"
                >
                  {form.processing ? "Submitting..." : "Submit Request"}
                </button>
              </div>
            </form>
          </Card>
        )}
        {activeTab === "responses" && (
          <ExportableCard
            title="Response Letters"
            className="scroll-mt-28"
            showExportButtons={false}
          >
            <div className="mb-4">
              <h2 className="text-lg font-black">Generate Response Letters</h2>
              <p className="text-sm text-slate-500 dark:text-zinc-400">
                Approved or partially approved assessments are eligible for
                response-letter generation.
              </p>
            </div>
            <DataTable
              columns={[
                "Reference",
                "Submission Type",
                "Response DRN",
                "Requesting Party",
                "Incident",
                "Status",
                { label: "Actions", align: "right", actionColumn: true },
              ]}
              rows={requests.data
                .filter((request) =>
                  ["approved", "partially_approved"].includes(request.status),
                )
                .map((request) => (
                  <tr key={request.id}>
                    <td className="px-4 py-3 font-black">
                      {request.reference_number}
                    </td>
                    <td className="px-4 py-3">
                      {request.response_drn || "To be assigned"}
                    </td>
                    <td className="px-4 py-3">{request.requesting_agency}</td>
                    <td className="px-4 py-3">{request.incident?.name}</td>
                    <td className="px-4 py-3 capitalize">
                      {request.status.replaceAll("_", " ")}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <button
                        type="button"
                        onClick={() => openResponseAction(request, "word")}
                        className="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-xs font-black text-white"
                      >
                        <Mail className="h-4 w-4" />
                        Generate Letter
                      </button>
                    </td>
                  </tr>
                ))}
            />
          </ExportableCard>
        )}

        {activeTab === "tracker" && (
          <ExportableCard
            id="request-list"
            title="Request List"
            className="scroll-mt-28 rounded-t-none border-t-0"
            showExportButtons={false}
          >
            <DataTable
              columns={[
                "#",
                "Request Details",
                "Request DRN",
                "Requesting Party / Office",
                "Purpose / Disaster Incident",
                "Items",
                "Remarks",
                { label: "Status", className: "min-w-[130px]" },
                { label: "Actions", align: "center", actionColumn: true },
              ]}
              numbered={false}
              className="!overflow-y-hidden"
              rows={requests.data.map((request, index) => (
                <tr
                  key={request.id}
                  id={`fni-request-row-${request.id}`}
                  className={Number(attentionRequestId) === Number(request.id) ? "fni-row-attention" : undefined}
                >
                  <td className="px-4 py-3 text-center text-xs font-black text-slate-500">{index + 1}</td>
                  <td className="px-4 py-3 font-medium">
                    <p className="font-black">
                      {displayRequestReference(request)}
                    </p>
                    {request.source_lgu_dromic_report && (
                      <p className="mt-1 text-[11px] font-semibold text-violet-700">
                        Linked LGU relief request
                      </p>
                    )}
                    {request.source_lgu_dromic_report?.lgu_relief_request_reference
                      && request.reference_number
                      && request.source_lgu_dromic_report.lgu_relief_request_reference !== request.reference_number && (
                      <p className="mt-1 text-[10px] font-semibold text-slate-400">
                        Legacy system code {request.reference_number}
                      </p>
                    )}
                    <p className="mt-1 text-[11px] font-semibold text-slate-500">
                      {request.submission_type === "proposal" ? `Proposal - ${request.proposal_type || "Unspecified"}` : "FNI Request"} · Received {formatDate(request.date_received_by_drmd ?? request.date_requested)}
                    </p>
                  </td>
                  <td className="whitespace-nowrap px-4 py-3">{request.request_drn || "-"}</td>
                  <td className="min-w-[220px] px-4 py-3">
                    <p className="font-bold">{request.source_lgu_dromic_report ? uniformLguName(request) : request.requesting_agency}</p>
                    <p className="mt-1 text-xs text-slate-500">{request.source_lgu_dromic_report ? uniformLguOfficeDetails(request) : (request.office_agency_details || "-")}</p>
                  </td>
                  <td className="min-w-[220px] px-4 py-3">
                    <p className="font-medium">{requestPurpose(request)}</p>
                    <p className="mt-1 text-xs text-slate-500">{requestIncident(request)}</p>
                  </td>
                  <td className="px-4 py-3">{request.items.length}</td>
                  <td className="max-w-xs px-4 py-3">{request.remarks || "-"}</td>
                  <td className="px-4 py-3 font-bold capitalize">{request.assessment_status === "final" ? "Acted" : request.assessment_status === "draft" ? "Under Review" : request.status === "endorsed" ? "Endorsed" : request.status?.replaceAll("_", " ")}</td>
                  <td className="px-3 py-3 text-center">
                    <div className="inline-flex items-center justify-center gap-2">
                      {(request.source_lgu_dromic_report || request.source_document_url) && (
                        <button
                          type="button"
                          onClick={() => {
                            setDocumentPreview(request);
                            setDocumentPreviewTab("request");
                          }}
                          className="dromis-tip inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-700"
                          data-tip={request.source_lgu_dromic_report ? "Preview request letter and supporting report" : "Preview source document"}
                          data-tip-side="bottom"
                          aria-label="Preview request documents"
                        >
                          <Eye className="h-4 w-4" />
                        </button>
                      )}
                      {request.endorsed_to_drrs && (
                        <TableActionButton
                          icon={FileSpreadsheet}
                          label={
                            request.assessment_status
                              ? "Assessment already created"
                              : reliefLetterBlocksAssessment(request)
                                ? "Validate signed request letter first"
                                : "Create Assessment"
                          }
                          onClick={() => !request.assessment_status && openEndorsedAssessment(request)}
                          disabled={Boolean(request.assessment_status) || reliefLetterBlocksAssessment(request)}
                          tone={reliefLetterBlocksAssessment(request) && !request.assessment_status ? "amber" : "emerald"}
                        />
                      )}
                      {["approved", "partially_approved"].includes(
                        request.status,
                      ) && (
                        <button
                          type="button"
                          title="Response Letter"
                          onClick={() => openResponseAction(request, "preview")}
                          className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-emerald-100 hover:text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-100 dark:hover:bg-emerald-900/60"
                        >
                          <FileText className="h-4 w-4" />
                        </button>
                      )}
                      {canDecideRequest(request) && (
                        <TableActionButton
                          icon={CheckCircle2}
                          label="Approve"
                          onClick={() => decide(request, "approved")}
                          tone="emerald"
                        />
                      )}
                      {canDecideRequest(request) && (
                        <TableActionButton
                          icon={XCircle}
                          label="Reject"
                          onClick={() => decide(request, "rejected")}
                          tone="rose"
                        />
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            />
          </ExportableCard>
        )}

        {activeTab === "assessments" && (
          <ExportableCard
            id="created-assessments"
            title="Created Assessments"
            className="scroll-mt-28 rounded-t-none border-t-0 shadow-none"
            showExportButtons={false}
          >
            <DataTable columns={["#", "Reference", "Proposing Party", "Incident", "Assessment Status", "Updated", { label: "Actions", align: "right", actionColumn: true }]} numbered={false} rows={(assessments.data ?? []).map((request, index) => (
              <tr key={request.id}>
                <td className="px-4 py-3 text-center text-xs font-black text-slate-500">{index + 1}</td>
                <td className="px-4 py-3 font-black">
                  <p>{displayRequestReference(request)}</p>
                  {request.source_lgu_dromic_report?.lgu_relief_request_reference
                    && request.reference_number
                    && request.source_lgu_dromic_report.lgu_relief_request_reference !== request.reference_number && (
                    <p className="mt-1 text-[10px] font-semibold text-slate-400">Legacy system code {request.reference_number}</p>
                  )}
                </td>
                <td className="px-4 py-3">{request.requesting_agency}</td>
                <td className="px-4 py-3">{request.incident?.name || "-"}</td>
                <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs font-black uppercase ${request.assessment_status === "submitted" ? "bg-emerald-100 text-emerald-700" : request.assessment_status === "final" ? "bg-blue-100 text-blue-700" : "bg-amber-100 text-amber-700"}`}>{request.assessment_status}</span></td>
                <td className="whitespace-nowrap px-4 py-3">{formatDateTime(request.updated_at)}</td>
                <td className="px-4 py-3 text-right"><div className="inline-flex flex-wrap items-center justify-end gap-2">
                  {request.assessment_access?.can_access_documents ? (
                    <>
                      <Link href={`/requests/${request.id}/assessment-form`} className="rounded-md border px-3 py-1.5 text-xs font-bold">View Documents</Link>
                      {request.assessment_status === "draft" && <button type="button" onClick={() => openEndorsedAssessment(request)} className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">Edit Draft</button>}
                    </>
                  ) : (
                    <span className="max-w-[12rem] text-left text-[11px] font-black leading-tight text-slate-600 dark:text-zinc-300">
                      Acted by {request.assessment_access?.acted_by?.name || request.assessment_actor?.name || request.assigned_social_worker || "another DRRS PDRC"}
                    </span>
                  )}
                  {request.assessment_access?.can_access_documents && request.assessment_status === "draft" && (
                    <button
                      type="button"
                      onClick={async () => {
                        const viewable = ["completed", "signed"].includes(request.epirma_status);
                        if (viewable) {
                          const response = await fetch(`/requests/${request.id}/epirma/status`, {
                            headers: {
                              Accept: "application/json",
                              "X-Requested-With": "XMLHttpRequest",
                            },
                            credentials: "same-origin",
                          });
                          const payload = await response.json();
                          if (payload?.success) {
                            if (payload?.data?.view_url) {
                              window.open(payload.data.view_url, "_blank", "noopener,noreferrer");
                            }
                            router.reload({ only: ["assessments", "requests"] });
                            return;
                          }

                          if (String(payload?.message || "").toLowerCase().includes("uuid")) {
                            router.post(`/requests/${request.id}/epirma/sign`);
                            return;
                          }

                          setEpirmaStatusError({
                            requestId: request.id,
                            message: payload?.message || "Failed to fetch signed document status.",
                          });
                          return;
                        }

                        router.post(`/requests/${request.id}/epirma/sign`);
                      }}
                      title={["completed", "signed"].includes(request.epirma_status) ? "Open the signed e-PIRMA document" : request.epirma_status === "pending" ? "Continue sending this draft to e-PIRMA" : "Send this draft assessment to e-PIRMA for signing"}
                      className="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-black text-white"
                    >
                      {["completed", "signed"].includes(request.epirma_status) ? "View Document" : request.epirma_status === "pending" ? "Continue e-PIRMA" : "Sign with e-PIRMA"}
                    </button>
                  )}
                  {request.assessment_access?.can_access_documents && request.assessment_status === "final" && <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: "draft" })} className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">Reopen Draft</button>}
                  {request.assessment_access?.can_access_documents && request.assessment_status === "final" && <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: "submitted" })} className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-black text-white">Submit</button>}
                  {request.assessment_access?.can_access_documents && request.status === "submitted" && request.assessment_status === "submitted" && <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: "draft" })} className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">Recall to Draft</button>}
                  {request.assessment_access?.can_access_documents && request.status === "rejected" && request.assessment_status === "submitted" && <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: "draft" })} className="rounded-md bg-rose-600 px-3 py-1.5 text-xs font-black text-white">Revise Disapproved Assessment</button>}
                </div></td>
              </tr>
            ))} />
          </ExportableCard>
        )}

        {epirmaStatusError && (
          <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-2xl dark:bg-zinc-900">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-xs font-black uppercase text-rose-600">e-PIRMA Status</p>
                  <h2 className="mt-1 text-lg font-black text-slate-900 dark:text-white">Unable to view document</h2>
                  <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">{epirmaStatusError.message}</p>
                </div>
                <button type="button" onClick={() => setEpirmaStatusError(null)} className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800">
                  <X className="h-5 w-5" />
                </button>
              </div>
              <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setEpirmaStatusError(null)}
                  className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-black text-slate-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-slate-100"
                >
                  Close
                </button>
                <button
                  type="button"
                  onClick={async () => {
                    const requestId = epirmaStatusError.requestId;
                    const response = await fetch(`/requests/${requestId}/epirma/retry`, {
                      method: "POST",
                      headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                        "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
                      },
                      credentials: "same-origin",
                    });
                    const payload = await response.json();
                    if (payload?.success && payload?.redirect_url) {
                      setEpirmaStatusError(null);
                      window.location.href = payload.redirect_url;
                      return;
                    }
                    setEpirmaStatusError({
                      requestId,
                      message: payload?.message || "Failed to retry e-PIRMA signing.",
                    });
                  }}
                  className="rounded-md bg-blue-600 px-4 py-2 text-sm font-black text-white"
                >
                  Retry Signing
                </button>
              </div>
            </div>
          </div>
        )}
        {documentPreview && (
          <RequestDocumentPreview
            request={documentPreview}
            tab={documentPreviewTab}
            setTab={setDocumentPreviewTab}
            onClose={() => setDocumentPreview(null)}
          />
        )}

        {assessmentRecord && (
          <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm">
            <div className="max-h-[96vh] w-[96vw] overflow-hidden rounded-lg bg-slate-100 shadow-2xl dark:bg-zinc-950">
              <div className="flex items-center justify-between border-b border-slate-300 bg-white px-5 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div>
                  <p className="text-xs font-black uppercase text-emerald-700">DRMD AA Endorsement · {assessmentRecord.reference_number}</p>
                  <h2 className="text-lg font-black">{form.data.act_on_behalf ? "Act on Behalf · Create Assessment" : "Create Assessment"}</h2>
                </div>
                <button type="button" onClick={() => setAssessmentRecord(null)} className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"><X className="h-5 w-5" /></button>
              </div>
              {form.data.act_on_behalf && (
                <div className="border-b border-amber-200 bg-amber-50 px-5 py-3 dark:border-amber-900 dark:bg-amber-950/40">
                  <p className="text-xs font-black uppercase tracking-wide text-amber-800 dark:text-amber-100">Acting on behalf</p>
                  <p className="mt-1 text-sm font-semibold text-amber-900 dark:text-amber-50">
                    Assigned AOR owner: {assessmentRecord.assessment_access?.primary_owner?.name || "Unavailable DRRS PDRC"}.
                    Provide a reason before saving.
                  </p>
                  <label className="mt-3 block text-xs font-black uppercase text-amber-800 dark:text-amber-100">
                    Reason
                    <textarea
                      className="mt-1 w-full rounded-md border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 dark:border-amber-800 dark:bg-zinc-950 dark:text-zinc-100"
                      rows={3}
                      value={form.data.on_behalf_reason}
                      onChange={(event) => form.setData("on_behalf_reason", event.target.value)}
                      placeholder="Example: Assigned DRRS PDRC is on leave / unavailable today."
                    />
                  </label>
                  {form.errors.on_behalf_reason && <p className="mt-1 text-xs font-bold text-rose-600">{form.errors.on_behalf_reason}</p>}
                </div>
              )}
              <div className="max-h-[calc(96vh-64px)] overflow-auto p-3">
                <AssessmentExcelForm form={form} currentUser={currentUser} partyOptions={partyOptions} selectRequestParty={selectRequestParty} requestParties={requestParties} psgc={psgc} inventoryItems={inventoryItems} fniLibraryItems={fniLibraryItems} incidentOptions={incidentOptions} drrsSignatories={drrsSignatories} warehouseStock={warehouseStock} drnPrefixes={drnPrefixes} requestId={assessmentRecord.id} action={`/requests/${assessmentRecord.id}/complete-assessment`} method="patch" submitLabel="Save Draft & Generate Documents" onSuccess={() => { setAssessmentRecord(null); }} />
              </div>
            </div>
          </div>
        )}

        {readyRecord && (
          <div className="fixed inset-0 z-[95] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
            <div className="w-full max-w-xl rounded-lg bg-white p-6 shadow-2xl dark:bg-zinc-900">
              <div className="flex items-start justify-between"><div><p className="text-xs font-black uppercase text-emerald-700">Documents ready</p><h2 className="mt-1 text-xl font-black">Assessment saved successfully</h2><p className="mt-2 text-sm text-slate-500">Assessment PDF and response letters in Word and PDF are ready for {readyRecord.reference_number}.</p></div><button type="button" onClick={() => setReadyRecord(null)} className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"><X className="h-5 w-5" /></button></div>
              <div className="mt-5 grid gap-3 sm:grid-cols-3">
                <a href={`/requests/${readyRecord.id}/assessment-pdf?margin=18`} className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-700 px-4 py-3 text-sm font-black text-white"><Download className="h-4 w-4" />Assessment PDF</a>
                <button type="button" onClick={() => openResponseAction(readyRecord, "print")} className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-3 text-sm font-black text-white"><Printer className="h-4 w-4" />Print Response Letter</button>
                <button type="button" onClick={() => openResponseAction(readyRecord, "pdf")} className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-800 px-4 py-3 text-sm font-black text-white"><Download className="h-4 w-4" />Download Response Letter</button>
              </div>
              <div className="mt-3 grid gap-3 sm:grid-cols-2"><a target="_blank" rel="noreferrer" href={`/requests/${readyRecord.id}/assessment-pdf?margin=18&inline=1`} className="block rounded-md border px-4 py-2 text-center text-sm font-bold">Preview Assessment</a><button type="button" onClick={() => openResponseAction(readyRecord, "preview")} className="block rounded-md border px-4 py-2 text-center text-sm font-bold">Preview Response Letter</button></div>
            </div>
          </div>
        )}
        {responsePrompt && <ResponseDrnModal
          requestId={responsePrompt.request.id}
          existingDrn={responsePrompt.request.response_drn || ""}
          prefixOptions={drnPrefixes.filter((row) => row.context === "response_letter").map((row) => row.value)}
          actionLabel={responsePrompt.action === "preview" ? "Save DRN & Preview" : responsePrompt.action === "print" ? "Save DRN & Print Response Letter" : "Save DRN & Download Response Letter"}
          onClose={() => setResponsePrompt(null)}
          onSaved={() => {
            const { request, action } = responsePrompt;
            setResponsePrompt(null);
            performResponseAction(request, action);
          }}
        />}
      </div>
    </AppLayout>
  );
}

function RequestDocumentPreview({ request, tab, setTab, onClose }) {
  const source = request.source_lgu_dromic_report;
  const isLguRequest = Boolean(source);
  const photos = request.drmd_aa_photo_paths ?? [];
  const src = isLguRequest
    ? tab === "report"
      ? source.lgu_signed_report_path
        ? `/lgu/dromic-sitrep/${source.id}/signed-copy/report#toolbar=0&navpanes=0`
        : `/lgu/dromic-sitrep/${source.id}/pdf?inline=1`
      : `/lgu/dromic-sitrep/${source.id}/signed-copy/request#toolbar=0&navpanes=0`
    : `/requests/${request.id}/source-document`;
  const tabs = [
    ...(isLguRequest ? [
      { key: "request", label: "Request Letter", icon: FileText, onClick: () => setTab("request") },
      { key: "report", label: "Supporting DROMIC / SitRep", icon: FileText, onClick: () => setTab("report") },
    ] : request.source_document_url ? [
      { key: "request", label: "Uploaded Document", icon: FileText, onClick: () => setTab("request") },
    ] : []),
    ...(photos.length ? [
      { key: "photos", label: `Captured Photos (${photos.length})`, icon: Images, onClick: () => setTab("photos") },
    ] : []),
  ];

  return (
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/65 p-4 backdrop-blur-sm"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Request document preview"
        className="flex h-[92vh] w-[96vw] max-w-[1500px] flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-900"
      >
        <div className="flex items-start justify-between border-b p-4">
          <div>
            <p className="text-xs font-black uppercase tracking-wide text-emerald-700">
              Request document preview
            </p>
            <h2 className="mt-1 font-black">
              {source?.lgu_relief_request_reference || request.reference_number}
            </h2>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="dromis-tip rounded-md border p-2"
            data-tip="Close preview"
            data-tip-side="bottom"
            aria-label="Close preview"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
        {tabs.length > 1 && (
          <div className="border-b bg-white p-3 dark:bg-zinc-900">
            <SystemTabs
              active={tab}
              ariaLabel="Request documents"
              items={tabs}
            />
          </div>
        )}
        {tab === "photos" ? (
          <div className="grid min-h-0 flex-1 auto-rows-max grid-cols-1 gap-4 overflow-y-auto bg-slate-100 p-5 sm:grid-cols-2 lg:grid-cols-3">
            {photos.map((path, index) => (
              <figure key={`${path}-${index}`} className="overflow-hidden rounded-lg border bg-white shadow-sm">
                <img src={`/requests/${request.id}/drmd-aa-photo/${index}`} alt={`Captured supporting evidence ${index + 1}`} className="h-64 w-full object-contain bg-slate-900" />
                <figcaption className="px-3 py-2 text-xs font-bold text-slate-600">Supporting photo {index + 1}</figcaption>
              </figure>
            ))}
          </div>
        ) : (
          <iframe
            key={src}
            title={isLguRequest && tab === "report" ? "Supporting DROMIC report" : "Request document"}
            src={src}
            className="min-h-0 w-full flex-1 bg-slate-100"
          />
        )}
      </div>
    </div>
  );
}

function LabeledInput({
  label,
  value,
  onChange,
  type = "text",
  placeholder = "",
  min,
}) {
  return (
    <label className="block text-sm font-bold">
      {label}
      <input
        type={type}
        min={min}
        className="mt-1 w-full"
        placeholder={placeholder}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      />
    </label>
  );
}

function WorkspaceMetricCard({ icon: Icon, label, value = 0, tone = "blue", breakdown = [] }) {
  const tones = {
    blue: "border-sky-200 bg-sky-50 text-sky-800",
    amber: "border-amber-200 bg-amber-50 text-amber-800",
    indigo: "border-indigo-200 bg-indigo-50 text-indigo-800",
    emerald: "border-emerald-200 bg-emerald-50 text-emerald-800",
    rose: "border-rose-200 bg-rose-50 text-rose-800",
    violet: "border-violet-200 bg-violet-50 text-violet-800",
  };

  return (
    <div className={`relative h-[88px] overflow-hidden rounded-xl border p-3 shadow-sm ${tones[tone] || tones.blue}`}>
      <div className="absolute -right-5 -top-5 h-16 w-16 rounded-full bg-current opacity-[0.07]" />
      <div className="relative flex h-full items-center justify-between gap-2">
        <div>
          <p className="text-[10px] font-black uppercase tracking-wide">{label}</p>
          <p className="mt-1 text-2xl font-black">{Number(value || 0).toLocaleString()}</p>
          {breakdown.length > 0 && (
            <p className="mt-0.5 whitespace-nowrap text-[9px] font-bold opacity-80">
              {breakdown.map(([name, count]) => `${name}: ${Number(count || 0).toLocaleString()}`).join(" · ")}
            </p>
          )}
        </div>
        <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-white/70">
          <Icon className="h-5 w-5" />
        </span>
      </div>
    </div>
  );
}
