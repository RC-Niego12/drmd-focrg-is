import { Head, router, useForm } from "@inertiajs/react";
import {
  Activity,
  Boxes,
  Building2,
  ClipboardCheck,
  ChevronDown,
  Edit3,
  FileText,
  Flame,
  HandHeart,
  Hash,
  MapPin,
  Network,
  PackageCheck,
  Plus,
  Route,
  Send,
  Tags,
  Trash2,
  Truck,
  Warehouse,
  Waves,
  X,
} from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import AppLayout, {
  Card,
  DataTable,
  ExportableCard,
  TableActionButton,
} from "@/Layouts/AppLayout";
import LguDirectoryPanel from "@/Components/LguDirectoryPanel";

const scopeLabels = {
  all: "All Warehouse Types",
  prepositioning: "Prepositioning Area",
  other: "Regional / Satellite",
};
const cardThemes = [
  "hover:border-emerald-300 hover:bg-emerald-50/70 dark:hover:border-emerald-800 dark:hover:bg-emerald-950/30",
  "hover:border-sky-300 hover:bg-sky-50/70 dark:hover:border-sky-800 dark:hover:bg-sky-950/30",
  "hover:border-amber-300 hover:bg-amber-50/70 dark:hover:border-amber-800 dark:hover:bg-amber-950/30",
  "hover:border-violet-300 hover:bg-violet-50/70 dark:hover:border-violet-800 dark:hover:bg-violet-950/30",
  "hover:border-rose-300 hover:bg-rose-50/70 dark:hover:border-rose-800 dark:hover:bg-rose-950/30",
];
const operationalMeta = {
  system_name: [Tags, "System Configuration", "System Identity"],
  source_of_goods: [PackageCheck, "RROS References", "Inventory & Stock"],
  transaction_purpose: [ClipboardCheck, "RROS References", "Inventory Transactions"],
  supplier_sender: [Send, "RROS References", "Parties & Locations"],
  recipient_requesting_party: [HandHeart, "RROS References", "Parties & Locations"],
  delivery_site: [MapPin, "RROS References", "Parties & Locations"],
  transportation_mode: [Route, "RROS References", "Transport & Delivery"],
  vehicle_type: [Truck, "RROS References", "Transport & Delivery"],
  dispatch_driver: [Truck, "RROS References", "Transport & Delivery"],
  dispatch_received_by: [HandHeart, "RROS References", "Transport & Delivery"],
  transportation_source: [Building2, "RROS References", "Transport & Delivery"],
  program_activity_type: [Activity, "RROS References", "Programs & Documents"],
  incident_type: [Flame, "DRIMS References", "Incidents"],
  drrs_signatory: [ClipboardCheck, "DRRS References", "Assessment & Correspondence"],
  drims_signatory: [ClipboardCheck, "DRIMS References", "DROMIC Report Signatories"],
  rros_ris_signatory: [ClipboardCheck, "RROS References", "RIS Signatories"],
  rros_dr_signatory: [Truck, "RROS References", "DR Signatories"],
  rros_stf_signatory: [FileText, "RROS References", "STF Signatories"],
  drn_prefix: [Hash, "DRRS References", "Assessment & Correspondence"],
  response_letter_initials: [FileText, "DRRS References", "Assessment & Correspondence"],
  document_reference_type: [FileText, "RROS References", "Programs & Documents"],
  stock_status: [Boxes, "RROS References", "Inventory & Stock"],
  transaction_status: [ClipboardCheck, "RROS References", "Inventory Transactions"],
};
const warehouseIcons = {
  distribution_network: Network,
  warehouse_type: Warehouse,
  warehouse_category: Tags,
  partnership: HandHeart,
  ownership: Building2,
};
const groupSectionIds = {
  "RROS References": "library-group-rros-references",
  "DRIMS References": "library-group-drims-references",
  "DRRS References": "library-group-drrs-references",
  "System Configuration": "library-group-system-configuration",
  "Other References": "library-group-other-references",
};
const referenceGroupOrder = [
  "RROS References",
  "DRIMS References",
  "DRRS References",
  "System Configuration",
  "Other References",
];
const subgroupOrder = [
  "Inventory & Stock",
  "Parties & Locations",
  "Warehouse Classification",
  "Inventory Transactions",
  "Transport & Delivery",
  "Programs & Documents",
  "RIS Signatories",
  "DR Signatories",
  "STF Signatories",
  "Directories",
  "Incidents",
  "DROMIC Report Signatories",
  "Assessment & Correspondence",
  "System Identity",
  "Other References",
];
const rrosSignatoryTypes = ["rros_ris_signatory", "rros_dr_signatory", "rros_stf_signatory"];
const allDocumentSignatoryTypes = [...rrosSignatoryTypes, "drims_signatory"];
const rrosDocumentLabels = {
  rros_ris_signatory: "RIS / DR",
  rros_dr_signatory: "Delivery Receipt",
  rros_stf_signatory: "STF",
};
const defaultSignatoryContext = {
  rros_ris_signatory: "requested_by",
  rros_dr_signatory: "issuance_approved_by",
  rros_stf_signatory: "requested_by",
  drims_signatory: "recommended_by",
};
const rrosSignatoryRoles = {
  rros_ris_signatory: [["requested_by", "Requested By"], ["approved_by", "Approved By"], ["issued_by", "Issued By"]],
  rros_dr_signatory: [["issuance_approved_by", "Issuance Approved By"], ["released_by", "Released By"]],
  rros_stf_signatory: [["requested_by", "Requested By"], ["approved_by", "Approved By"], ["issued_by", "Issued By"]],
  drims_signatory: [["recommended_by", "DRMD Chief (DC)"], ["approved_by", "Regional Director (RD)"]],
};
const drrsSignatoryRoles = {
  assessment: [["reviewed_by", "Reviewed By"], ["approved_by", "Approved By"]],
  response_letter: [["approved_by", "Approved By"]],
};
const drrsRolesFor = (documentType) => drrsSignatoryRoles[documentType] || [];
const drrsDocumentLabels = { assessment: "Assessment", response_letter: "Response Letter" };
const signatoryContextOptions = (libraryType, documentType = "assessment") => {
  if (libraryType === "drrs_signatory") return drrsRolesFor(documentType);
  if (rrosSignatoryRoles[libraryType]) return rrosSignatoryRoles[libraryType];
  if (libraryType === "response_letter_initials") return [["response_letter", "Response Letter"]];
  if (libraryType === "drn_prefix") return [["assessment", "Assessment"], ["response_letter", "Response Letter"]];
  return [];
};
const defaultContextFor = (libraryType, documentType = "assessment") =>
  signatoryContextOptions(libraryType, documentType)[0]?.[0] || "all";
const emptySignatorySet = (libraryType) => Object.fromEntries((rrosSignatoryRoles[libraryType] || []).map(([context]) => [context, { name: "", position: "", suffix: "", designation: "", office: "" }]));
const signatorySetFromRows = (libraryType, rows) => Object.fromEntries((rrosSignatoryRoles[libraryType] || []).map(([context]) => {
  const row = rows.find((entry) => entry.library_type === libraryType && entry.context === context);
  return [context, {
    name: row?.metadata?.employee_name || String(row?.value || "").split("|")[0].split(",")[0].trim(),
    position: row?.metadata?.position || "",
    suffix: row?.metadata?.suffix || "",
    designation: row?.metadata?.designation || String(row?.value || "").split("|").slice(1).join("|").trim(),
    office: row?.metadata?.office || "",
  }];
}));
const existingSignatoriesFor = (employee, rows = []) => {
  const name = String(employee?.value || "").trim().toLowerCase();
  return rows.filter((row) => ["drrs_signatory", ...allDocumentSignatoryTypes].includes(row.library_type)
    && (String(row.metadata?.employee_name || "").trim().toLowerCase() === name
      || String(row.value || "").split("|")[0].trim().toLowerCase().startsWith(name)));
};
const existingSignatoryFor = (employee, rows = []) => existingSignatoriesFor(employee, rows)[0];
const savedSignatoryDetail = (employee, rows, field) => existingSignatoriesFor(employee, rows)
  .map((row) => row.metadata?.[field])
  .find((value) => String(value || "").trim()) || "";
const credentialsFromSavedName = (employee, rows = []) => {
  const employeeName = String(employee?.value || "").trim();
  return existingSignatoriesFor(employee, rows)
    .map((row) => String(row.value || "").split("|")[0].trim())
    .map((savedName) => savedName.toLowerCase().startsWith(employeeName.toLowerCase())
      ? savedName.slice(employeeName.length).replace(/^\s*,\s*/, "").trim()
      : "")
    .find(Boolean) || "";
};
const generatedEmployeeInitials = (employee) => {
  const explicitParts = [employee?.first_name, employee?.middle_name, employee?.last_name]
    .map((part) => String(part || "").trim())
    .filter(Boolean);
  if (explicitParts.length >= 2) return explicitParts.map((part) => part[0]).join("").toUpperCase();

  const parts = String(employee?.value || "")
    .split(",")[0]
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (parts.length >= 3) return `${parts[0][0]}${parts[parts.length - 2][0]}${parts[parts.length - 1][0]}`.toUpperCase();
  return parts.map((part) => part[0]).join("").toUpperCase();
};
const suggestedDesignation = (employee, rows = []) => {
  const existing = existingSignatoryFor(employee, rows);
  return savedSignatoryDetail(employee, rows, "designation")
    || String(existing?.value || "").split("|").slice(1).join("|").trim()
    || employee?.designation
    || "";
};
const suggestedSuffix = (employee, rows = []) => savedSignatoryDetail(employee, rows, "suffix") || credentialsFromSavedName(employee, rows);
const suggestedOffice = (employee, rows = []) => employee?.section_unit_program
  || employee?.office
  || savedSignatoryDetail(employee, rows, "office")
  || "";
const suggestedSignatoryDetails = (employee, rows = []) => {
  const existing = existingSignatoryFor(employee, rows);
  return {
    name: employee?.value || "",
    position: savedSignatoryDetail(employee, rows, "position") || employee?.position || "",
    suffix: savedSignatoryDetail(employee, rows, "suffix") || credentialsFromSavedName(employee, rows),
    designation: savedSignatoryDetail(employee, rows, "designation")
      || String(existing?.value || "").split("|").slice(1).join("|").trim()
      || employee?.designation
      || "",
    office: suggestedOffice(employee, rows),
    initials: savedSignatoryDetail(employee, rows, "initials") || generatedEmployeeInitials(employee),
  };
};
const hydratedDrrsEntry = (row, rows = []) => {
  const name = row?.metadata?.employee_name || String(row?.value || "").split("|")[0].split(",")[0].trim();
  const suggested = suggestedSignatoryDetails({
    value: name,
    position: row?.metadata?.position || "",
    section_unit_program: row?.metadata?.office || "",
  }, rows);
  return {
    ...suggested,
    position: row?.metadata?.position || suggested.position,
    suffix: row?.metadata?.suffix || suggested.suffix,
    designation: row?.metadata?.designation || String(row?.value || "").split("|").slice(1).join("|").trim() || suggested.designation,
    office: row?.metadata?.office || suggested.office,
    initials: row?.metadata?.initials || suggested.initials,
  };
};

export default function FniLibrary({
  items = [],
  warehouseLibraries = [],
  warehouseLibraryTypes = {},
  operationalLibraries = [],
  operationalLibraryTypes = {},
  libraryScope = "RROS",
  lguDirectoryEntries = [],
  canManageLguDirectory = false,
  initialLibrary = "",
  lguSyncPreview = null,
  lguSyncUnmatched = [],
  lguSyncRuns = [],
  isSuperAdmin = false,
}) {
  const [activeLibrary, setActiveLibrary] = useState(
    (rrosSignatoryTypes.some((type) => initialLibrary === `operational:${type}`) ? "rros_signatories" : initialLibrary) ||
      (libraryScope === "DRRS" ? "operational:drrs_signatory" : "fni"),
  );
  const [search, setSearch] = useState("");
  const [librarySearch, setLibrarySearch] = useState("");
  const [modal, setModal] = useState(null);
  const [libraryModalOpen, setLibraryModalOpen] = useState(Boolean(initialLibrary));
  const fniForm = useForm({
    item_category: "",
    item_name: "",
    brand_description: "",
    unit_of_measure: "",
  });
  const warehouseForm = useForm({
    library_type: "",
    value: "",
    applicability: "all",
  });
  const operationalForm = useForm({
    library_type: "",
    document_type: "",
    value: "",
    position: "",
    designation: "",
    office: "",
    contact_number: "",
    id_number: "",
    suffix: "",
    initials: "",
    short_name: "",
    context: "all",
    is_active: true,
  });
  const rrosSignatoryForm = useForm({
    library_type: "",
    signatories: {},
  });
  const drrsSignatoryForm = useForm({ document_type: "", signatories: {} });
  const definitions = [
    {
      key: "fni",
      label: "Food and Non-Food Items & UOM",
      icon: Tags,
      group: "RROS References",
      subgroup: "Inventory & Stock",
      count: items.length,
    },
    ...(canManageLguDirectory
      ? [
          {
            key: "lgu_directory",
            label: "LGU Directory",
            icon: MapPin,
            group: "DRIMS References",
            subgroup: "Directories",
            count: lguDirectoryEntries.length,
          },
        ]
      : []),
    ...Object.entries(warehouseLibraryTypes).map(([key, label]) => ({
      key,
      label,
      icon: warehouseIcons[key] ?? Warehouse,
      group: "RROS References",
      subgroup: "Warehouse Classification",
      count: warehouseLibraries.filter((row) => row.library_type === key)
        .length,
    })),
    ...(rrosSignatoryTypes.some((type) => operationalLibraryTypes[type]) ? [{
      key: "rros_signatories",
      label: "RROS Signatories",
      icon: ClipboardCheck,
      group: "RROS References",
      subgroup: "Programs & Documents",
      count: operationalLibraries.filter((row) => rrosSignatoryTypes.includes(row.library_type)).length,
    }] : []),
    ...Object.entries(operationalLibraryTypes).filter(([key]) => !rrosSignatoryTypes.includes(key)).map(([key, label]) => ({
      key: `operational:${key}`,
      type: key,
      label,
      icon: operationalMeta[key]?.[0] ?? Boxes,
      group: operationalMeta[key]?.[1] ?? "Other References",
      subgroup: operationalMeta[key]?.[2] ?? "Other References",
      count: operationalLibraries.filter((row) => row.library_type === key)
        .length,
    })),
  ];
  const libraryNeedle = librarySearch.trim().toLowerCase();
  const visibleDefinitions = libraryNeedle
    ? definitions.filter((entry) =>
        `${entry.label} ${entry.group}`.toLowerCase().includes(libraryNeedle),
      )
    : definitions;
  const groupedDefinitions = Object.entries(
    visibleDefinitions.reduce((groups, entry) => ({
      ...groups,
      [entry.group]: {
        ...(groups[entry.group] ?? {}),
        [entry.subgroup]: [
          ...(groups[entry.group]?.[entry.subgroup] ?? []),
          entry,
        ],
      },
    }), {}),
  ).sort(
    ([left], [right]) =>
      referenceGroupOrder.indexOf(left) - referenceGroupOrder.indexOf(right),
  );
  const activeDefinition =
    definitions.find((entry) => entry.key === activeLibrary) ?? definitions[0];
  const ActiveDefinitionIcon = activeDefinition?.icon ?? Boxes;
  const isRrosSignatoryLibrary = activeLibrary === "rros_signatories";
  const isOperational = activeLibrary.startsWith("operational:") || isRrosSignatoryLibrary;
  const activeType = isOperational
    ? (isRrosSignatoryLibrary ? "rros_signatories" : activeLibrary.split(":")[1])
    : activeLibrary;
  const activeRows =
    activeLibrary === "fni"
      ? items
      : activeLibrary === "lgu_directory"
        ? lguDirectoryEntries
        : isRrosSignatoryLibrary
          ? operationalLibraries.filter((row) => rrosSignatoryTypes.includes(row.library_type))
        : isOperational
          ? operationalLibraries.filter(
              (row) => row.library_type === activeType,
            )
          : warehouseLibraries.filter(
              (row) => row.library_type === activeLibrary,
            );
  const filteredRows = useMemo(() => {
    const needle = search.trim().toLowerCase();
    if (!needle) return activeRows;
    return activeRows.filter((row) =>
      Object.values(row).some((value) =>
        String(value ?? "")
          .toLowerCase()
          .includes(needle),
      ),
    );
  }, [activeRows, search]);
  const documentSignatoryRows = useMemo(() => {
    const documents = isRrosSignatoryLibrary
      ? Object.entries(rrosDocumentLabels).map(([type, label]) => ({ type, label, roles: rrosSignatoryRoles[type] || [] }))
      : activeType === "drrs_signatory"
        ? Object.entries(drrsDocumentLabels).map(([type, label]) => ({ type, label, roles: drrsRolesFor(type) }))
        : activeType === "drims_signatory"
          ? [{ type: "drims_signatory", label: "DROMIC Report", roles: rrosSignatoryRoles.drims_signatory }]
        : [];
    const needle = search.trim().toLowerCase();
    return documents.map((document) => {
      const rows = operationalLibraries.filter((row) => isRrosSignatoryLibrary || activeType === "drims_signatory"
        ? row.library_type === document.type
        : row.library_type === "drrs_signatory" && (row.metadata?.document_type || "assessment") === document.type);
      return { ...document, rows, configured: document.roles.filter(([context]) => rows.some((row) => row.context === context)).length };
    }).filter((document) => !needle || `${document.label} ${document.rows.map((row) => row.value).join(" ")}`.toLowerCase().includes(needle));
  }, [activeType, isRrosSignatoryLibrary, operationalLibraries, search]);

  const openAdd = () => {
    if (activeLibrary === "fni")
      fniForm.setData({
        item_category: "",
        item_name: "",
        brand_description: "",
        unit_of_measure: "",
      });
    else if (activeType === "drrs_signatory") {
      drrsSignatoryForm.setData({ document_type: "", signatories: {} });
      operationalForm.setData({ library_type: "drrs_signatory", document_type: "assessment", value: "", position: "", suffix: "", designation: "", office: "", contact_number: "", id_number: "", initials: "", short_name: "", context: defaultContextFor("drrs_signatory", "assessment"), is_active: true });
    } else if (isRrosSignatoryLibrary || activeType === "drims_signatory") {
      const libraryType = activeType === "drims_signatory" ? "drims_signatory" : "";
      rrosSignatoryForm.setData({ library_type: libraryType, signatories: libraryType ? signatorySetFromRows(libraryType, operationalLibraries) : {} });
      operationalForm.setData({ library_type: libraryType || "rros_ris_signatory", value: "", position: "", suffix: "", designation: "", office: "", contact_number: "", id_number: "", initials: "", short_name: "", context: defaultContextFor(libraryType || "rros_ris_signatory"), is_active: true });
    } else if (isOperational)
      operationalForm.setData({
        library_type: isRrosSignatoryLibrary ? "rros_ris_signatory" : activeType,
        value: "",
        position: "",
        designation: "",
        office: "",
        contact_number: "",
        id_number: "",
        suffix: "",
        initials: "",
        short_name: "",
        context: ["drrs_signatory", ...allDocumentSignatoryTypes, "drn_prefix", "response_letter_initials"].includes(activeType)
          ? defaultContextFor(activeType)
          : "all",
        is_active: true,
      });
    else
      warehouseForm.setData({
        library_type: activeLibrary,
        value: "",
        applicability: "all",
      });
    setModal({
      kind:
        activeLibrary === "fni"
          ? "fni"
          : isOperational
            ? "operational"
            : "warehouse",
      row: null,
    });
  };
  const openEdit = (row) => {
    if (activeLibrary === "fni")
      fniForm.setData({
        item_category: row.item_category,
        item_name: row.item_name,
        brand_description: row.brand_description || "",
        unit_of_measure: row.unit_of_measure || "unit",
      });
    else if (isOperational)
      operationalForm.setData({
        library_type: row.library_type,
        document_type: row.library_type === "drrs_signatory" ? (row.metadata?.document_type || "assessment") : "",
        value: ["drrs_signatory", ...allDocumentSignatoryTypes].includes(row.library_type) ? String(row.value || "").split("|")[0].trim() : row.value,
        position: row.metadata?.position || "",
        suffix: row.metadata?.suffix || "",
        designation: row.metadata?.designation || String(row.value || "").split("|").slice(1).join("|").trim(),
        office: row.metadata?.office || "",
        contact_number: row.metadata?.contact_number || "",
        id_number: row.metadata?.id_number || "",
        initials: row.metadata?.initials || "",
        short_name: row.metadata?.short_name || "",
        context: (() => {
          const documentType = row.library_type === "drrs_signatory" ? (row.metadata?.document_type || "assessment") : undefined;
          const allowed = signatoryContextOptions(row.library_type, documentType).map(([context]) => context);
          if (allowed.length && !allowed.includes(row.context)) {
            return defaultContextFor(row.library_type, documentType);
          }
          return row.context;
        })(),
        is_active: row.is_active,
      });
    else
      warehouseForm.setData({
        library_type: row.library_type,
        value: row.value,
        applicability: row.applicability,
      });
    setModal({
      kind:
        activeLibrary === "fni"
          ? "fni"
          : isOperational
            ? "operational"
            : "warehouse",
      row,
    });
  };
  const openDocumentSignatories = (documentType) => {
    if (isRrosSignatoryLibrary || activeType === "drims_signatory") {
      rrosSignatoryForm.setData({ library_type: documentType, signatories: signatorySetFromRows(documentType, operationalLibraries) });
    } else {
      drrsSignatoryForm.setData({
        document_type: documentType,
        signatories: Object.fromEntries(drrsRolesFor(documentType).map(([context]) => {
          const row = operationalLibraries.find((entry) => entry.library_type === "drrs_signatory" && entry.context === context && (entry.metadata?.document_type || "assessment") === documentType);
          return [context, hydratedDrrsEntry(row, operationalLibraries)];
        })),
      });
    }
    setModal({ kind: "operational", row: null, editingDocument: true });
  };
  const submit = (event) => {
    event.preventDefault();
    const close = { preserveScroll: true, onSuccess: () => setModal(null) };
    if (activeType === "drrs_signatory" && !modal.row) {
      drrsSignatoryForm.post("/operational-library/drrs-signatories", close);
    } else if ((isRrosSignatoryLibrary || activeType === "drims_signatory") && !modal.row) {
      rrosSignatoryForm.post("/operational-library/rros-signatories", close);
    } else if (modal.kind === "fni")
      modal.row
        ? fniForm.put(`/fni-library/${modal.row.id}`, close)
        : fniForm.post("/fni-library", close);
    else if (modal.kind === "operational")
      modal.row
        ? operationalForm.put(`/operational-library/${modal.row.id}`, close)
        : operationalForm.post("/operational-library", close);
    else
      modal.row
        ? warehouseForm.put(`/warehouse-library/${modal.row.id}`, close)
        : warehouseForm.post("/warehouse-library", close);
  };
  const remove = (row) => {
    const url =
      activeLibrary === "fni"
        ? `/fni-library/${row.id}`
        : isOperational
          ? `/operational-library/${row.id}`
          : `/warehouse-library/${row.id}`;
    if (confirm(`Delete ${row.item_name || row.value}?`))
      router.delete(url, { preserveScroll: true });
  };

  return (
    <AppLayout title="Libraries">
      <Head title="Libraries" />
      <ExportableCard
        id="fni-library-overview"
        title="Libraries"
        className="overflow-hidden"
        showExportButtons={false}
      >
        <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">
          System Reference Data
        </p>
        <h2 className="mt-1 text-2xl font-black">Libraries</h2>
        <p className="mt-2 text-sm font-semibold text-slate-500 dark:text-zinc-400">
          Normalized reference values organized by operational ownership and
          shared across authorized system workflows.
        </p>
      </ExportableCard>

      {isSuperAdmin && (
        <div className="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
          <label className="block text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">
            Find a Library
            <div className="relative mt-2">
              <input
                type="search"
                className="w-full pr-10"
                placeholder="Search by library or group name..."
                value={librarySearch}
                onChange={(event) => setLibrarySearch(event.target.value)}
              />
              {librarySearch && (
                <button type="button" onClick={() => setLibrarySearch("")} className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800" aria-label="Clear library search">
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
          </label>
          <p className="mt-2 text-xs font-semibold text-slate-500 dark:text-zinc-400">
            {visibleDefinitions.length} {visibleDefinitions.length === 1 ? "library" : "libraries"} found
          </p>
        </div>
      )}

      <div className="mt-6 space-y-6">
        {groupedDefinitions.map(([group, subgroups]) => (
          <section
            key={group}
            id={groupSectionIds[group]}
            className="scroll-mt-28"
          >
            <div className="mb-3 flex items-center gap-3">
              <h3 className="text-xs font-black uppercase tracking-[0.14em] text-slate-500 dark:text-zinc-400">
                {group}
              </h3>
              <div className="h-px flex-1 bg-slate-200 dark:bg-zinc-800" />
            </div>
            <div className="space-y-5">
              {Object.entries(subgroups)
                .sort(([left], [right]) => subgroupOrder.indexOf(left) - subgroupOrder.indexOf(right))
                .map(([subgroup, entries]) => (
                <div key={subgroup}>
                  <div className="mb-2 flex items-center gap-2">
                    <span className="h-1.5 w-1.5 rounded-full bg-brand-600 dark:bg-brand-300" />
                    <h4 className="text-xs font-extrabold text-slate-600 dark:text-zinc-300">{subgroup}</h4>
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
              {entries.map(({ key, label, icon: Icon, count }, index) => (
                <button
                  key={key}
                  id={
                    key === "lgu_directory" ? "lgu-directory-card" : undefined
                  }
                  type="button"
                  onClick={() => {
                    setActiveLibrary(key);
                    setSearch("");
                    setLibraryModalOpen(true);
                  }}
                  className={`group relative overflow-hidden rounded-md border p-4 text-left shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg ${activeLibrary === key ? "border-brand-300 bg-brand-50 ring-2 ring-brand-100 dark:border-brand-700 dark:bg-brand-950/30 dark:ring-brand-900" : `border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 ${cardThemes[index % cardThemes.length]}`}`}
                >
                  <span className="absolute inset-x-0 top-0 h-1 origin-left scale-x-0 bg-gradient-to-r from-brand-600 via-sky-400 to-emerald-400 transition-transform duration-200 group-hover:scale-x-100" />
                  <div className="flex items-start justify-between gap-3">
                    <Icon className="h-5 w-5 text-brand-700 transition-transform duration-200 group-hover:scale-110 group-hover:rotate-3 dark:text-brand-100" />
                    <span className="rounded-full bg-white px-2 py-0.5 text-xs font-black shadow-sm transition group-hover:shadow-md dark:bg-zinc-950">
                      {count}
                    </span>
                  </div>
                  <p className="mt-3 text-sm font-black leading-tight">
                    {label}
                  </p>
                </button>
              ))}
                  </div>
                </div>
              ))}
            </div>
          </section>
        ))}
        {groupedDefinitions.length === 0 && (
          <div className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm font-semibold text-slate-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
            No library matches “{librarySearch}”.
          </div>
        )}
      </div>

      {libraryModalOpen && (
        <div
          className="fixed inset-0 z-[220] flex items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm sm:p-6"
          role="dialog"
          aria-modal="true"
          aria-label={`${activeDefinition?.label || "Library"} workspace`}
        >
          <div className="flex h-[min(92vh,980px)] w-full max-w-[96rem] flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-50 shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
            <div className="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4 dark:border-zinc-800 dark:bg-zinc-900">
              <div className="flex items-center gap-3">
                <ActiveDefinitionIcon className="h-5 w-5 text-brand-700 dark:text-brand-100" />
                <div>
                  <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">{activeDefinition?.group} · {activeDefinition?.subgroup}</p>
                  <h2 className="text-lg font-black">{activeDefinition?.label}</h2>
                </div>
              </div>
              <button type="button" onClick={() => setLibraryModalOpen(false)} className="dromis-tip rounded-md border border-slate-200 p-2 hover:bg-slate-100 dark:border-zinc-700 dark:hover:bg-zinc-800" aria-label="Close library" data-tip="Close library workspace" data-tip-side="bottom" data-tip-preferred-side="bottom" data-tip-locked="true">
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
      {activeLibrary === "lgu_directory" ? (
        <LguDirectoryPanel
          entries={lguDirectoryEntries}
          preview={lguSyncPreview}
          unmatched={lguSyncUnmatched}
          syncRuns={lguSyncRuns}
          isSuperAdmin={isSuperAdmin}
        />
      ) : (
        <Card
          id="fni-library-list"
          className="overflow-hidden p-0"
        >
          <div className="flex flex-col gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-950">
            <div>
              <h2 className="font-black uppercase tracking-wide">
                {activeDefinition.label} Library
              </h2>
              <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                {activeLibrary === "fni"
                  ? "Combined Item Category, Item Name, Brand / Description, and Unit of Measurement values selected in the WIT Data Entry sheet, including zero-balance items."
                  : isOperational
                    ? "Reference values used by the corresponding system workflows and forms."
                    : "Values are scoped to the warehouse types where they are valid and feed the Add Warehouse modal."}
              </p>
            </div>
            <button
              type="button"
              onClick={openAdd}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-600 px-4 py-2 text-sm font-black text-white"
            >
              <Plus className="h-4 w-4" />
              Add
            </button>
          </div>
          <div className="p-5">
            <input
              type="search"
              className="mb-4 w-full max-w-md"
              placeholder={`Search ${activeDefinition.label.toLowerCase()}...`}
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
            {activeLibrary === "fni" ? (
              <DataTable
                numbered={false}
                stickyHeader
                className="h-[calc(100vh-30rem)] min-h-[320px] max-h-[720px] overflow-auto"
                columns={[
                  "Item Category",
                  "Item Name",
                  "Brand / Description",
                  "Unit of Measurement",
                  { label: "Actions", align: "right", actionColumn: true },
                ]}
                rows={filteredRows.map((row) => (
                  <LibraryRow
                    key={row.id}
                    cells={[
                      row.item_category,
                      row.item_name,
                      row.brand_description || "-",
                      row.unit_of_measure,
                    ]}
                    row={row}
                    onEdit={openEdit}
                    onDelete={remove}
                  />
                ))}
              />
            ) : (isRrosSignatoryLibrary || activeType === "drrs_signatory" || activeType === "drims_signatory") ? (
              <DataTable
                numbered={false}
                stickyHeader
                className="h-[calc(100vh-30rem)] min-h-[320px] max-h-[720px] overflow-auto"
                columns={["Document Type", "Configured Signatories", "Assignments", "Status", { label: "Actions", align: "right", actionColumn: true }]}
                rows={documentSignatoryRows.map((document) => (
                  <DocumentSignatoryRow key={document.type} document={document} onEdit={() => openDocumentSignatories(document.type)} />
                ))}
              />
            ) : isOperational ? (
              <DataTable
                numbered={false}
                stickyHeader
                className="h-[calc(100vh-30rem)] min-h-[320px] max-h-[720px] overflow-auto"
                columns={activeType === "system_name" ? [
                  "Long Name",
                  "Short Name",
                  "Status",
                  { label: "Actions", align: "right", actionColumn: true },
                ] : isRrosSignatoryLibrary ? [
                  "Employee / Signatory",
                  "Document",
                  "Signatory Role",
                  "Status",
                  { label: "Actions", align: "right", actionColumn: true },
                ] : activeType === "drrs_signatory" ? [
                  "Employee / Signatory",
                  "Document",
                  "Signatory Role",
                  "Status",
                  { label: "Actions", align: "right", actionColumn: true },
                ] : activeType === "dispatch_driver" ? [
                  "Name",
                  "ID Number",
                  "Contact No.",
                  "Position",
                  "Office",
                  "Status",
                  { label: "Actions", align: "right", actionColumn: true },
                ] : activeType === "dispatch_received_by" ? [
                  "Name",
                  "ID Number",
                  "Position",
                  "Office",
                  "Status",
                  { label: "Actions", align: "right", actionColumn: true },
                ] : [
                  "Reference Value",
                  "Context",
                  "Status",
                  { label: "Actions", align: "right", actionColumn: true },
                ]}
                rows={filteredRows.map((row) => (
                  <LibraryRow
                    key={row.id}
                    cells={activeType === "system_name" ? [
                      row.value,
                      row.metadata?.short_name || row.value,
                      row.is_active ? "Active" : "Inactive",
                    ] : isRrosSignatoryLibrary ? [
                      row.value,
                      rrosDocumentLabels[row.library_type] || row.library_type,
                      row.context.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()),
                      row.is_active ? "Active" : "Inactive",
                    ] : activeType === "drrs_signatory" ? [
                      row.value,
                      drrsDocumentLabels[row.metadata?.document_type || "assessment"],
                      row.context.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()),
                      row.is_active ? "Active" : "Inactive",
                    ] : activeType === "dispatch_driver" ? [
                      row.value,
                      row.metadata?.id_number || "—",
                      row.metadata?.contact_number || "—",
                      row.metadata?.position || "—",
                      row.metadata?.office || "—",
                      row.is_active ? "Active" : "Inactive",
                    ] : activeType === "dispatch_received_by" ? [
                      row.value,
                      row.metadata?.id_number || "—",
                      row.metadata?.position || "—",
                      row.metadata?.office || "—",
                      row.is_active ? "Active" : "Inactive",
                    ] : [
                      row.value,
                      row.context === "all" ? "All Workflows" : row.context,
                      row.is_active ? "Active" : "Inactive",
                    ]}
                    row={row}
                    onEdit={openEdit}
                    onDelete={remove}
                  />
                ))}
              />
            ) : (
              <DataTable
                numbered={false}
                stickyHeader
                className="h-[calc(100vh-30rem)] min-h-[320px] max-h-[720px] overflow-auto"
                columns={[
                  "Reference Value",
                  "Applies To",
                  "Conditional Effect",
                  { label: "Actions", align: "right", actionColumn: true },
                ]}
                rows={filteredRows.map((row) => (
                  <LibraryRow
                    key={row.id}
                    cells={[
                      row.value,
                      scopeLabels[row.applicability],
                      conditionText(row),
                    ]}
                    row={row}
                    onEdit={openEdit}
                    onDelete={remove}
                  />
                ))}
              />
            )}
          </div>
        </Card>
      )}
            </div>
          </div>
        </div>
      )}

      {modal && (
        <LibraryModal
          modal={modal}
          definition={activeDefinition}
          fniForm={fniForm}
          warehouseForm={warehouseForm}
          operationalForm={operationalForm}
          rrosSignatoryForm={rrosSignatoryForm}
          drrsSignatoryForm={drrsSignatoryForm}
          operationalLibraries={operationalLibraries}
          onClose={() => setModal(null)}
          onSubmit={submit}
        />
      )}
    </AppLayout>
  );
}

function LibraryRow({ cells, row, onEdit, onDelete }) {
  return (
    <tr>
      {cells.map((cell, index) => (
        <td
          key={index}
          className={`px-4 py-3 ${index === 0 ? "font-black" : ""}`}
        >
          {cell}
        </td>
      ))}
      <td className="px-4 py-3 text-right">
        <span className="inline-flex gap-2">
          <TableActionButton
            icon={Edit3}
            label="Edit"
            tone="brand"
            onClick={() => onEdit(row)}
          />
          <TableActionButton
            icon={Trash2}
            label="Delete"
            tone="rose"
            onClick={() => onDelete(row)}
          />
        </span>
      </td>
    </tr>
  );
}

function DocumentSignatoryRow({ document, onEdit }) {
  const complete = document.configured === document.roles.length;
  return <tr>
    <td className="px-4 py-3 font-black">{document.label}</td>
    <td className="px-4 py-3"><span className="font-black text-emerald-700">{document.configured}</span> / {document.roles.length}</td>
    <td className="px-4 py-3">
      <div className="flex flex-wrap gap-1.5">
        {document.roles.map(([context, label]) => {
          const row = document.rows.find((entry) => entry.context === context);
          return <span key={context} title={row?.value || `${label} is not configured`} className={`rounded-full px-2 py-1 text-[10px] font-bold ${row ? "bg-emerald-50 text-emerald-800" : "bg-slate-100 text-slate-400"}`}>{label}</span>;
        })}
      </div>
    </td>
    <td className="px-4 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-black ${complete ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"}`}>{complete ? "Complete" : "Needs setup"}</span></td>
    <td className="px-4 py-3 text-right"><TableActionButton icon={Edit3} label={`Edit ${document.label} signatories`} tone="brand" onClick={onEdit} /></td>
  </tr>;
}

function conditionText(row) {
  if (row.library_type === "distribution_network")
    return `Auto-filled for ${scopeLabels[row.applicability]}`;
  if (row.library_type === "warehouse_type")
    return `Activates ${scopeLabels[row.applicability]} rules`;
  if (
    ["warehouse_category", "ownership", "partnership"].includes(
      row.library_type,
    )
  )
    return `Selectable only for ${scopeLabels[row.applicability]}`;
  return "Reference option";
}

function LibraryModal({
  modal,
  definition,
  fniForm,
  warehouseForm,
  operationalForm,
  rrosSignatoryForm,
  drrsSignatoryForm,
  operationalLibraries,
  onClose,
  onSubmit,
}) {
  const bulkRrosSignatories = definition.key === "rros_signatories" && !modal.row;
  const bulkDrrsSignatories = definition.key === "operational:drrs_signatory" && !modal.row;
  const bulkDrimsSignatories = definition.key === "operational:drims_signatory" && !modal.row;
  const form = bulkRrosSignatories || bulkDrimsSignatories
    ? rrosSignatoryForm
    : bulkDrrsSignatories ? drrsSignatoryForm
    : modal.kind === "fni"
      ? fniForm
      : modal.kind === "operational"
        ? operationalForm
        : warehouseForm;
  return (
    <div
      className="fixed inset-0 z-[240] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
    >
      <form
        onSubmit={onSubmit}
        className={`w-full overflow-hidden rounded-lg bg-white shadow-2xl dark:bg-zinc-950 ${bulkRrosSignatories || bulkDrrsSignatories || bulkDrimsSignatories ? "max-w-5xl" : "max-w-xl"}`}
      >
        <div className="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-900">
          <div>
            <p className="text-xs font-black uppercase text-brand-700 dark:text-brand-100">
              {definition.label}
            </p>
            <h2 className="text-xl font-black">
              {bulkDrimsSignatories ? "Configure DRIMS Signatories" : bulkRrosSignatories ? "Configure Document Signatories" : bulkDrrsSignatories ? "Configure DRRS Signatories" : modal.row ? "Edit Library Value" : "Add Library Value"}
            </h2>
          </div>
          <button type="button" onClick={onClose} aria-label="Close modal" data-tip="Close modal" data-tip-side="bottom" data-tip-preferred-side="bottom" data-tip-locked="true" className="dromis-tip">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="space-y-4 p-5">
          {bulkRrosSignatories || bulkDrimsSignatories ? (
            <RrosSignatorySetFields
              form={rrosSignatoryForm}
              operationalLibraries={operationalLibraries}
              fixedLibraryType={bulkDrimsSignatories ? "drims_signatory" : null}
            />
          ) : bulkDrrsSignatories ? (
            <DrrsSignatorySetFields form={drrsSignatoryForm} operationalLibraries={operationalLibraries} />
          ) : modal.kind === "fni" ? (
            <>
              <Input
                label="Item Category"
                value={form.data.item_category}
                onChange={(value) => form.setData("item_category", value)}
              />
              <Input
                label="Item Name"
                value={form.data.item_name}
                onChange={(value) => form.setData("item_name", value)}
              />
              <Input
                label="Brand / Description"
                value={form.data.brand_description}
                onChange={(value) => form.setData("brand_description", value)}
                required={false}
              />
              <Input
                label="Unit of Measurement"
                value={form.data.unit_of_measure}
                onChange={(value) => form.setData("unit_of_measure", value)}
              />
            </>
          ) : modal.kind === "operational" ? (
            <>
              {rrosSignatoryTypes.includes(form.data.library_type) && <div className="rounded-xl border border-emerald-200 bg-emerald-50/70 p-3">
                <label className="block text-sm font-black text-emerald-950">Document
                  <select className="mt-2 w-full bg-white" value={form.data.library_type} onChange={(event) => {
                    const libraryType = event.target.value;
                    form.setData((data) => ({ ...data, library_type: libraryType, context: defaultSignatoryContext[libraryType] }));
                  }}>
                    {rrosSignatoryTypes.map((type) => <option key={type} value={type}>{rrosDocumentLabels[type]}</option>)}
                  </select>
                </label>
                <p className="mt-2 text-xs font-semibold text-emerald-800">One directory manages all RROS signatories while keeping each assignment specific to its official document.</p>
              </div>}
              {form.data.library_type === "system_name" ? <>
                <Input
                  label="Long Name"
                  value={form.data.value}
                  onChange={(value) => form.setData("value", value)}
                />
                <Input
                  label="Short Name"
                  value={form.data.short_name}
                  onChange={(value) => form.setData("short_name", value)}
                />
                <p className="rounded-md border border-sky-100 bg-sky-50 p-3 text-xs font-semibold text-sky-800 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
                  The long name appears on public and sign-in screens. The short name appears below the agency name in the authenticated sidebar.
                </p>
              </> : ["drrs_signatory", ...allDocumentSignatoryTypes].includes(form.data.library_type) ? <div className="space-y-4">
                {form.data.library_type === "drrs_signatory" && <label className="block text-sm font-bold">Document Type<select className="mt-1 w-full" value={form.data.document_type} onChange={(event) => {
                  const documentType = event.target.value;
                  const roles = drrsRolesFor(documentType);
                  const nextContext = roles.some(([context]) => context === form.data.context) ? form.data.context : defaultContextFor("drrs_signatory", documentType);
                  form.setData((data) => ({ ...data, document_type: documentType, context: nextContext }));
                }}><option value="assessment">Assessment</option><option value="response_letter">Response Letter</option></select></label>}
                <MyPortalSignatoryInput
                  value={form.data.value}
                  onChange={(value) => form.setData("value", value)}
                  onSelect={(employee) => form.setData((data) => ({
                    ...data,
                    value: employee.value,
                    position: employee.position || "",
                    suffix: suggestedSuffix(employee, operationalLibraries),
                    designation: suggestedDesignation(employee, operationalLibraries),
                    office: suggestedOffice(employee, operationalLibraries),
                  }))}
                />
                <Input label="Position" value={form.data.position} onChange={() => {}} readOnly />
                <Input label="Office" value={form.data.office} onChange={() => {}} readOnly />
                <Input label="Name Suffix / Professional Credentials" value={form.data.suffix} onChange={(value) => form.setData("suffix", value)} required={false} />
                <Input
                  label="Designation"
                  value={form.data.designation}
                  onChange={(value) => form.setData("designation", value)}
                />
                {form.data.library_type === "drrs_signatory" && <Input label="Signatory Initials" value={form.data.initials} onChange={(value) => form.setData("initials", value)} />}
              </div> : form.data.library_type === "dispatch_driver" ? <div className="space-y-4">
                <Input
                  label="Driver Name"
                  value={form.data.value}
                  onChange={(value) => form.setData("value", value)}
                />
                <Input
                  label="ID Number"
                  value={form.data.id_number}
                  onChange={(value) => form.setData("id_number", value)}
                  required={false}
                />
                <Input
                  label="Contact No."
                  value={form.data.contact_number}
                  onChange={(value) => form.setData("contact_number", value)}
                  required
                />
                <Input
                  label="Position"
                  value={form.data.position}
                  onChange={(value) => form.setData("position", value)}
                  required={false}
                />
                <Input
                  label="Office"
                  value={form.data.office}
                  onChange={(value) => form.setData("office", value)}
                  required={false}
                />
              </div> : form.data.library_type === "dispatch_received_by" ? <div className="space-y-4">
                <Input
                  label="Name"
                  value={form.data.value}
                  onChange={(value) => form.setData("value", value)}
                />
                <Input
                  label="ID Number"
                  value={form.data.id_number}
                  onChange={(value) => form.setData("id_number", value)}
                  required={false}
                />
                <Input
                  label="Position"
                  value={form.data.position}
                  onChange={(value) => form.setData("position", value)}
                  required={false}
                />
                <Input
                  label="Office"
                  value={form.data.office}
                  onChange={(value) => form.setData("office", value)}
                  required={false}
                />
              </div> : <Input
                label="Reference Value"
                value={form.data.value}
                onChange={(value) => form.setData("value", value)}
              />}
              {form.data.library_type !== "system_name" && (["drrs_signatory", "rros_ris_signatory", "rros_dr_signatory", "rros_stf_signatory", "drn_prefix", "response_letter_initials"].includes(form.data.library_type) ? <label className="block text-sm font-bold">
                Workflow Context
                <select className="mt-1 w-full" required value={form.data.context} onChange={(event) => form.setData("context", event.target.value)}>
                  {signatoryContextOptions(form.data.library_type, form.data.document_type || "assessment").map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              </label> : <Input
                label="Workflow Context"
                value={form.data.context}
                onChange={(value) => form.setData("context", value)}
              />)}
              <label className="flex items-center gap-3 text-sm font-bold">
                <input
                  type="checkbox"
                  checked={form.data.is_active}
                  onChange={(event) =>
                    form.setData("is_active", event.target.checked)
                  }
                />
                Active reference value
              </label>
            </>
          ) : (
            <>
              <Input
                label="Reference Value"
                value={form.data.value}
                onChange={(value) => form.setData("value", value)}
              />
              <label className="block text-sm font-bold">
                Applies To
                <select
                  className="mt-1 w-full"
                  value={form.data.applicability}
                  onChange={(event) =>
                    form.setData("applicability", event.target.value)
                  }
                >
                  <option value="all">All Warehouse Types</option>
                  <option value="prepositioning">Prepositioning Area</option>
                  <option value="other">Regional / Satellite</option>
                </select>
              </label>
              <div className="rounded-md border border-brand-100 bg-brand-50 p-3 text-sm font-semibold text-brand-800 dark:border-brand-900 dark:bg-brand-950/30 dark:text-brand-100">
                {conditionText({
                  library_type: form.data.library_type,
                  applicability: form.data.applicability,
                })}
              </div>
            </>
          )}
          {Object.values(form.errors).map((error) => (
            <p key={error} className="text-xs font-bold text-rose-600">
              {error}
            </p>
          ))}
          <button
            disabled={form.processing || (bulkRrosSignatories && !form.data.library_type) || (bulkDrrsSignatories && !form.data.document_type)}
            className="w-full rounded-md bg-brand-600 px-4 py-2.5 text-sm font-black text-white"
          >
            {form.processing ? "Saving..." : bulkDrimsSignatories ? "Save DRIMS Signatories" : bulkRrosSignatories ? (form.data.library_type ? `Save ${rrosDocumentLabels[form.data.library_type]} Signatories` : "Select a Document") : bulkDrrsSignatories ? (form.data.document_type ? `Save ${drrsDocumentLabels[form.data.document_type]} Signatories` : "Select a Document") : "Save Library Value"}
          </button>
        </div>
      </form>
    </div>
  );
}

function DrrsSignatorySetFields({ form, operationalLibraries }) {
  const update = (context, fields) => form.setData("signatories", { ...form.data.signatories, [context]: { ...form.data.signatories[context], ...fields } });
  const changeDocument = (documentType) => form.setData({
    document_type: documentType,
    signatories: documentType ? Object.fromEntries(drrsRolesFor(documentType).map(([context]) => {
      const row = operationalLibraries.find((entry) => entry.library_type === "drrs_signatory" && entry.context === context
        && ((entry.metadata?.document_type || "assessment") === documentType));
      return [context, hydratedDrrsEntry(row, operationalLibraries)];
    })) : {},
  });
  return <div className="space-y-4">
    <div className="rounded-xl border border-sky-200 bg-gradient-to-r from-sky-50 to-indigo-50 p-4">
      <label className="block text-sm font-black text-sky-950">DRRS Document Type
        <select className="mt-2 w-full bg-white" value={form.data.document_type} onChange={(event) => changeDocument(event.target.value)}>
          <option value="">Select Assessment or Response Letter</option>
          <option value="assessment">Assessment</option>
          <option value="response_letter">Response Letter</option>
        </select>
      </label>
      <p className="mt-2 text-xs font-semibold text-sky-800">Configure the complete signatory set independently for each DRRS document.</p>
    </div>
    <div className="max-h-[58vh] space-y-3 overflow-y-auto pr-1">
    {drrsRolesFor(form.data.document_type).map(([context, label], index) => {
      const entry = form.data.signatories[context] || { name: "", position: "", suffix: "", designation: "", office: "", initials: "" };
      return <section key={context} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="mb-3 flex items-center gap-3"><span className="flex h-7 w-7 items-center justify-center rounded-full bg-sky-100 text-xs font-black text-sky-800">{index + 1}</span><h3 className="font-black">{label}</h3></div>
        <div className="space-y-4">
          <MyPortalSignatoryInput value={entry.name} onChange={(name) => update(context, { name })} onSelect={(employee) => update(context, suggestedSignatoryDetails(employee, operationalLibraries))} />
          <Input label="Position" value={entry.position} onChange={() => {}} readOnly />
          <Input label="Office" value={entry.office || ""} onChange={() => {}} readOnly />
          <Input label="Name Suffix / Professional Credentials" value={entry.suffix} onChange={(suffix) => update(context, { suffix })} required={false} />
          <Input label="Designation" value={entry.designation} onChange={(designation) => update(context, { designation })} />
          <Input label="Signatory Initials" value={entry.initials} onChange={(initials) => update(context, { initials })} />
        </div>
      </section>;
    })}
    </div>
  </div>;
}

function RrosSignatorySetFields({ form, operationalLibraries, fixedLibraryType = null }) {
  const setEntry = (context, field, value) => form.setData("signatories", {
    ...form.data.signatories,
    [context]: { ...form.data.signatories[context], [field]: value },
  });
  const changeDocument = (libraryType) => form.setData({
    library_type: libraryType,
    signatories: signatorySetFromRows(libraryType, operationalLibraries),
  });

  return <div className="space-y-4">
    {!fixedLibraryType && <div className="rounded-xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-sky-50 p-4">
      <label className="block text-sm font-black text-emerald-950">RROS Document Type
        <select className="mt-2 w-full bg-white" value={form.data.library_type} onChange={(event) => changeDocument(event.target.value)}>
          <option value="">Select RIS / DR, Delivery Receipt, or STF</option>
          {rrosSignatoryTypes.map((type) => <option key={type} value={type}>{rrosDocumentLabels[type]}</option>)}
        </select>
      </label>
      <p className="mt-2 text-xs font-semibold text-emerald-800">Complete all official signatory assignments for this document, then save them together.</p>
    </div>}
    {fixedLibraryType === "drims_signatory" && <div className="rounded-xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-sky-50 p-4">
      <p className="text-sm font-black text-emerald-950">DROMIC Report Signatories</p>
      <p className="mt-1 text-xs font-semibold text-emerald-800">Assign the DRMD Chief and Regional Director used in DSWD DROMIC reports.</p>
    </div>}
    <div className="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
      {(rrosSignatoryRoles[form.data.library_type] || []).map(([context, label], index) => {
        const entry = form.data.signatories[context] || { name: "", position: "", suffix: "", designation: "", office: "" };
        return <section key={context} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="mb-3 flex items-center gap-3"><span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-100 text-xs font-black text-emerald-800">{index + 1}</span><h3 className="font-black text-slate-950">{label}</h3></div>
          <div className="space-y-4">
            <MyPortalSignatoryInput value={entry.name} onChange={(value) => setEntry(context, "name", value)} onSelect={(employee) => {
              form.setData("signatories", {
                ...form.data.signatories,
                [context]: { ...entry, ...suggestedSignatoryDetails(employee, operationalLibraries) },
              });
            }} />
            <Input label="Position" value={entry.position} onChange={() => {}} readOnly />
            <Input label="Office" value={entry.office || ""} onChange={() => {}} readOnly />
            <Input label="Name Suffix / Professional Credentials" value={entry.suffix} onChange={(value) => setEntry(context, "suffix", value)} required={false} />
            <Input label="Designation" value={entry.designation} onChange={(value) => setEntry(context, "designation", value)} />
          </div>
        </section>;
      })}
    </div>
  </div>;
}

function MyPortalSignatoryInput({ value, onChange, onSelect }) {
  const [query, setQuery] = useState(value || "");
  const [options, setOptions] = useState([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const wrapper = useRef(null);

  useEffect(() => setQuery(value || ""), [value]);
  useEffect(() => {
    const close = (event) => !wrapper.current?.contains(event.target) && setOpen(false);
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);
  useEffect(() => {
    const search = query.trim();
    if (!open || search.length < 2 || search === value) return undefined;
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setLoading(true); setError("");
      try {
        const response = await fetch(`/myportal-employees?search=${encodeURIComponent(search)}`, { headers: { Accept: "application/json" }, signal: controller.signal });
        const payload = await response.json();
        setOptions(payload.employees || []);
        setError(payload.directory_error || (!response.ok ? "Employee directory search failed." : ""));
      } catch (exception) {
        if (exception.name !== "AbortError") { setOptions([]); setError("Could not connect to MyPortal."); }
      } finally { if (!controller.signal.aborted) setLoading(false); }
    }, 300);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [query, open, value]);

  return <label className="block text-sm font-bold">DSWD Caraga Employee *
    <span className="mt-1 block text-xs font-semibold text-slate-500">Enter at least 2 letters of the employee's last name or first name, then select the correct employee.</span>
    <div ref={wrapper} className="relative mt-2">
      <input required autoComplete="off" role="combobox" aria-expanded={open} value={query} onFocus={() => setOpen(true)} onChange={(event) => { setQuery(event.target.value); setOpen(true); if (value) onChange(""); }} placeholder="Search MyPortal employee directory" className="w-full pr-10" />
      <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      {open && query.trim().length >= 2 && query !== value && <div className="absolute z-[260] mt-1 max-h-80 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-2xl">
        {loading && <p className="p-3 text-sm text-slate-500">Searching MyPortal...</p>}
        {!loading && error && <p className="p-3 text-sm font-semibold text-rose-700">{error}</p>}
        {!loading && !error && options.length === 0 && <p className="p-3 text-sm text-slate-500">No active employee matched.</p>}
        {!loading && options.map((option) => <button key={`${option.id_number}-${option.value}`} type="button" className="block w-full border-b px-4 py-3 text-left hover:bg-emerald-50" onClick={() => { onSelect?.(option); setQuery(option.value); setOpen(false); }}>
          <span className="block font-black text-slate-950">{option.id_number ? `[${option.id_number}] ` : ""}{option.value}</span>
          {option.position && <span className="block text-xs font-bold text-slate-600">{option.position}</span>}
          {option.section_unit_program && <span className="mt-1 block text-xs text-emerald-700">{option.section_unit_program}</span>}
          {option.division && <span className="block text-[11px] text-slate-500">{option.division}</span>}
        </button>)}
      </div>}
    </div>
  </label>;
}

function Input({ label, value, onChange, required = true, readOnly = false }) {
  return (
    <label className="block text-sm font-bold">
      {label}
      <input
        required={required}
        readOnly={readOnly}
        value={value}
        className={`mt-1 w-full ${readOnly ? "cursor-not-allowed bg-slate-100 text-slate-600" : ""}`}
        onChange={(event) => !readOnly && onChange(event.target.value)}
      />
    </label>
  );
}
