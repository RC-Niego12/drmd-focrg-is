import { Head, Link, router, useForm, usePage } from "@inertiajs/react";
import {
  CheckCircle2,
  ClipboardList,
  FileSpreadsheet,
  FileText,
  Mail,
  Download,
  Printer,
  XCircle,
  X,
} from "lucide-react";
import { useMemo, useState } from "react";
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
}) {
  const currentUser = usePage().props.auth.user;
  const permissions = currentUser?.permissions ?? [];
  const canEncode = permissions.includes("encode requests");
  const canProcess = permissions.includes("process requests");
  const assessmentDrnDefaults = currentDrnParts(drnPrefixes.find((row) => row.context === "assessment")?.value);
  const [provinceCode, setProvinceCode] = useState("");
  const [municipalityCode, setMunicipalityCode] = useState("");
  const [activeTab, setActiveTab] = useState(
    usePage().props.defaultTab || "tracker",
  );
  const [assessmentRecord, setAssessmentRecord] = useState(null);
  const [readyRecord, setReadyRecord] = useState(null);
  const [responsePrompt, setResponsePrompt] = useState(null);
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
    assigned_social_worker: currentUser?.name ?? "",
    assessment_form_data: {
      request_type: "Disaster",
      response_purpose: "Relief Augmentation",
      has_previous_augmentation: false,
      provide_augmentation: true,
      prepared_by: currentUser?.name ?? "",
      prepared_by_position: currentUser?.position ?? "",
      prepared_by_designation: currentUser?.designation ?? "",
      prepared_at: localDateTimeValue(),
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

  const openEndorsedAssessment = (request) => {
    const party = requestParties.find((row) => String(row.id) === String(request.request_party_id));
    const existingMeta = request.assessment_form_data ?? {};
    const existingPurpose = allowedPurposes.includes(existingMeta.response_purpose || request.purpose) ? (existingMeta.response_purpose || request.purpose) : "Relief Augmentation";
    const isExistingAssessment = Boolean(request.assessment_status);
    const existingItems = (request.items ?? []).map((item) => ({
      inventory_item_id: item.inventory_item_id ?? "",
      fni_library_item_id: String(item.fni_library_item_id ?? ""),
      source_warehouse_id: item.source_warehouse_id ?? "",
      source_warehouse_name: item.source_warehouse_name ?? "",
      available_quantity: item.available_quantity ?? "",
      item_name: item.item_name ?? "",
      requested_quantity: item.requested_quantity ?? 1,
      unit: item.unit ?? "",
      priority: item.priority ?? "normal",
      remarks: item.remarks ?? "",
    }));
    form.clearErrors();
    form.setData({
      ...form.data,
      request_party_id: String(request.request_party_id ?? ""),
      requesting_agency: request.requesting_agency ?? party?.requesting_party ?? "",
      lgu: request.office_agency_details ?? "",
      lgu_level: request.lgu_level ?? "",
      province: request.province ?? "",
      municipality: request.municipality ?? "",
      barangay: request.barangay ?? "",
      requester: request.requester ?? party?.office_head ?? "",
      date_requested: (request.date_requested ?? request.date_received_by_drmd ?? new Date().toISOString()).slice(0, 10),
      incident_name: existingPurpose === "Relief Augmentation" ? (request.incident?.name ?? "") : "",
      incident_date: existingPurpose === "Relief Augmentation" ? (request.incident?.incident_date?.slice(0, 10) ?? "") : "",
      purpose: existingPurpose,
      assessment_summary: request.assessment_summary ?? "",
      recommendations: request.recommendations ?? "",
      remarks: request.remarks ?? "",
      date_received_by_drmd: (request.date_received_by_drmd ?? new Date().toISOString()).slice(0, 10),
      request_drn: request.request_drn ?? "",
      office_agency_details: request.office_agency_details ?? "",
      endorsed_to_drrs: true,
      date_endorsed_to_drrs: request.date_endorsed_to_drrs?.slice(0, 10) ?? "",
      incident_details: existingPurpose === "Relief Augmentation" ? (request.incident_details ?? "") : "",
      incident_count: request.incident_count ?? 1,
      response_drn: request.response_drn ?? "",
      assessment_drn: request.assessment_drn ?? "",
      source_document_url: request.source_document_url ?? "",
      response_letter_url: "",
      coordinated_with_rros: false,
      date_coordinated_with_rros: "",
      requester_position: request.requester_position ?? "",
      requester_address: request.requester_address ?? "",
      contact_number: request.contact_number ?? "",
      affected_families: request.affected_families ?? "",
      assigned_social_worker: currentUser?.name ?? "",
      assessment_form_data: {
        ...existingMeta,
        request_type: existingPurpose === "Relief Augmentation" ? "Disaster" : null,
        response_purpose: existingPurpose,
        original_request_purpose: request.purpose ?? "",
        has_previous_augmentation: existingMeta.has_previous_augmentation ?? false,
        provide_augmentation: existingMeta.provide_augmentation ?? (existingPurpose === "Relief Augmentation"),
        prepared_by: currentUser?.name ?? "",
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
      items: isExistingAssessment && existingItems.length ? existingItems : [{ inventory_item_id: "", fni_library_item_id: "", source_warehouse_id: "", source_warehouse_name: "", available_quantity: "", item_name: "", requested_quantity: 1, unit: "", priority: "normal", remarks: "" }],
    });
    setAssessmentRecord(request);
  };

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
      <div className="mb-6 rounded-md border border-slate-200 bg-white p-1 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div className="grid gap-1 sm:grid-cols-3">
          {[
            {
              key: "tracker",
              label: "Requests & Action Status",
              icon: ClipboardList,
            },
            { key: "assessments", label: "Created Assessments", icon: FileSpreadsheet },
          ].map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              type="button"
              onClick={() => setActiveTab(key)}
              className={`inline-flex items-center justify-center gap-2 rounded-md px-4 py-3 text-sm font-black transition ${activeTab === key ? "bg-brand-600 text-white shadow-sm" : "text-slate-600 hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800"}`}
            >
              <Icon className="h-4 w-4" />
              {label}
            </button>
          ))}
        </div>
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
            className="scroll-mt-28"
            showExportButtons={false}
          >
            <DataTable
              columns={[
                "#",
                "Reference",
                "Submission Type",
                "Date Received",
                "Request DRN",
                "Proposing Party",
                "Office / Agency Details",
                "Purpose",
                "Incident",
                "Items",
                "Remarks",
                "Source Document",
                "Status",
                { label: "Actions", align: "right", actionColumn: true },
              ]}
              numbered={false}
              rows={requests.data.map((request, index) => (
                <tr key={request.id}>
                  <td className="px-4 py-3 text-center text-xs font-black text-slate-500">{index + 1}</td>
                  <td className="px-4 py-3 font-medium">
                    {request.reference_number}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 font-bold">{request.submission_type === "proposal" ? `Proposal - ${request.proposal_type || "Unspecified"}` : "FNI Request"}</td>
                  <td className="whitespace-nowrap px-4 py-3">{formatDate(request.date_received_by_drmd ?? request.date_requested)}</td>
                  <td className="whitespace-nowrap px-4 py-3">{request.request_drn || "-"}</td>
                  <td className="px-4 py-3">{request.requesting_agency}</td>
                  <td className="px-4 py-3">{request.office_agency_details || "-"}</td>
                  <td className="whitespace-nowrap px-4 py-3 font-medium">{requestPurpose(request)}</td>
                  <td className="px-4 py-3">{requestIncident(request)}</td>
                  <td className="px-4 py-3">{request.items.length}</td>
                  <td className="max-w-xs px-4 py-3">{request.remarks || "-"}</td>
                  <td className="px-4 py-3">{request.source_document_url ? <a href={`/requests/${request.id}/source-document`} target="_blank" rel="noreferrer" className="font-bold text-brand-700 underline">View</a> : "-"}</td>
                  <td className="px-4 py-3 font-bold capitalize">{request.assessment_status === "final" ? "Acted" : request.assessment_status === "draft" ? "Under Review" : request.status === "endorsed" ? "Endorsed" : request.status?.replaceAll("_", " ")}</td>
                  <td className="px-4 py-3 text-right">
                    <div className="inline-flex items-center justify-end gap-2">
                      {request.endorsed_to_drrs && <TableActionButton icon={FileSpreadsheet} label={request.assessment_status ? "Assessment already created" : "Create Assessment"} onClick={() => !request.assessment_status && openEndorsedAssessment(request)} disabled={Boolean(request.assessment_status)} tone="emerald" />}
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
          <ExportableCard id="created-assessments" title="Created Assessments" showExportButtons={false}>
            <DataTable columns={["#", "Reference", "Proposing Party", "Incident", "Assessment Status", "Updated", { label: "Actions", align: "right", actionColumn: true }]} numbered={false} rows={(assessments.data ?? []).map((request, index) => (
              <tr key={request.id}>
                <td className="px-4 py-3 text-center text-xs font-black text-slate-500">{index + 1}</td>
                <td className="px-4 py-3 font-black">{request.reference_number}</td>
                <td className="px-4 py-3">{request.requesting_agency}</td>
                <td className="px-4 py-3">{request.incident?.name || "-"}</td>
                <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs font-black uppercase ${request.assessment_status === "submitted" ? "bg-emerald-100 text-emerald-700" : request.assessment_status === "final" ? "bg-blue-100 text-blue-700" : "bg-amber-100 text-amber-700"}`}>{request.assessment_status}</span></td>
                <td className="whitespace-nowrap px-4 py-3">{formatDateTime(request.updated_at)}</td>
                <td className="px-4 py-3 text-right"><div className="inline-flex flex-wrap items-center justify-end gap-2">
                  <Link href={`/requests/${request.id}/assessment-form`} className="rounded-md border px-3 py-1.5 text-xs font-bold">View Documents</Link>
                  {request.assessment_status === "draft" && <button type="button" onClick={() => openEndorsedAssessment(request)} className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">Edit Draft</button>}
                  {request.assessment_status === "draft" && <button type="button" onClick={() => router.post(`/requests/${request.id}/epirma/sign`)} disabled={request.epirma_status === "pending"} title={request.epirma_status === "pending" ? "Awaiting e-PIRMA signing confirmation" : "Send this draft assessment to e-PIRMA for signing"} className="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-black text-white disabled:cursor-wait disabled:opacity-60">{request.epirma_status === "pending" ? "Awaiting e-PIRMA" : "Sign with e-PIRMA"}</button>}
                  {request.assessment_status === "final" && <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: "draft" })} className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">Reopen Draft</button>}
                  {request.assessment_status === "final" && <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: "submitted" })} className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-black text-white">Submit</button>}
                  {request.status === "submitted" && request.assessment_status === "submitted" && <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: "draft" })} className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">Recall to Draft</button>}
                  {request.status === "rejected" && request.assessment_status === "submitted" && <button type="button" onClick={() => router.patch(`/requests/${request.id}/assessment-status`, { assessment_status: "draft" })} className="rounded-md bg-rose-600 px-3 py-1.5 text-xs font-black text-white">Revise Disapproved Assessment</button>}
                </div></td>
              </tr>
            ))} />
          </ExportableCard>
        )}

        {assessmentRecord && (
          <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm">
            <div className="max-h-[96vh] w-[96vw] overflow-hidden rounded-lg bg-slate-100 shadow-2xl dark:bg-zinc-950">
              <div className="flex items-center justify-between border-b border-slate-300 bg-white px-5 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div><p className="text-xs font-black uppercase text-emerald-700">DRMD AA Endorsement · {assessmentRecord.reference_number}</p><h2 className="text-lg font-black">Create Assessment</h2></div>
                <button type="button" onClick={() => setAssessmentRecord(null)} className="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-zinc-800"><X className="h-5 w-5" /></button>
              </div>
              <div className="max-h-[calc(96vh-64px)] overflow-auto p-3">
                <AssessmentExcelForm form={form} currentUser={currentUser} partyOptions={partyOptions} selectRequestParty={selectRequestParty} requestParties={requestParties} psgc={psgc} inventoryItems={inventoryItems} fniLibraryItems={fniLibraryItems} incidentOptions={incidentOptions} drrsSignatories={drrsSignatories} warehouseStock={warehouseStock} drnPrefixes={drnPrefixes} action={`/requests/${assessmentRecord.id}/complete-assessment`} method="patch" submitLabel="Save Draft & Generate Documents" onSuccess={() => { setReadyRecord(assessmentRecord); setAssessmentRecord(null); }} />
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
