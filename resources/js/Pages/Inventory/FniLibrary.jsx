import { Head, router, useForm } from "@inertiajs/react";
import {
  Activity,
  Boxes,
  Building2,
  ClipboardCheck,
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
import { useMemo, useState } from "react";
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
  transportation_source: [Building2, "RROS References", "Transport & Delivery"],
  program_activity_type: [Activity, "RROS References", "Programs & Documents"],
  incident_type: [Flame, "DRIMS References", "Incidents"],
  drrs_signatory: [ClipboardCheck, "DRRS References", "Assessment & Correspondence"],
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
  "Directories",
  "Incidents",
  "Assessment & Correspondence",
  "System Identity",
  "Other References",
];

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
  lguSyncRuns = [],
  isSuperAdmin = false,
}) {
  const [activeLibrary, setActiveLibrary] = useState(
    initialLibrary ||
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
    value: "",
    short_name: "",
    context: "all",
    is_active: true,
  });
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
            label: "LGU Officials and LSWD Directory",
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
    ...Object.entries(operationalLibraryTypes).map(([key, label]) => ({
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
  const isOperational = activeLibrary.startsWith("operational:");
  const activeType = isOperational
    ? activeLibrary.split(":")[1]
    : activeLibrary;
  const activeRows =
    activeLibrary === "fni"
      ? items
      : activeLibrary === "lgu_directory"
        ? lguDirectoryEntries
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

  const openAdd = () => {
    if (activeLibrary === "fni")
      fniForm.setData({
        item_category: "",
        item_name: "",
        brand_description: "",
        unit_of_measure: "",
      });
    else if (isOperational)
      operationalForm.setData({
        library_type: activeType,
        value: "",
        short_name: "",
        context: activeType === "drrs_signatory" ? "prepared_by" : activeType === "drn_prefix" ? "assessment" : activeType === "response_letter_initials" ? "response_letter" : "all",
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
        value: row.value,
        short_name: row.metadata?.short_name || "",
        context: row.context,
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
  const submit = (event) => {
    event.preventDefault();
    const close = { preserveScroll: true, onSuccess: () => setModal(null) };
    if (modal.kind === "fni")
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
          className="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm sm:p-6"
          onMouseDown={(event) => event.target === event.currentTarget && setLibraryModalOpen(false)}
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
              <button type="button" onClick={() => setLibraryModalOpen(false)} className="rounded-md border border-slate-200 p-2 hover:bg-slate-100 dark:border-zinc-700 dark:hover:bg-zinc-800" aria-label="Close library">
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
      {activeLibrary === "lgu_directory" ? (
        <LguDirectoryPanel
          entries={lguDirectoryEntries}
          preview={lguSyncPreview}
          syncRuns={lguSyncRuns}
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
  onClose,
  onSubmit,
}) {
  const form =
    modal.kind === "fni"
      ? fniForm
      : modal.kind === "operational"
        ? operationalForm
        : warehouseForm;
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <form
        onSubmit={onSubmit}
        className="w-full max-w-xl overflow-hidden rounded-lg bg-white shadow-2xl dark:bg-zinc-950"
      >
        <div className="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-900">
          <div>
            <p className="text-xs font-black uppercase text-brand-700 dark:text-brand-100">
              {definition.label}
            </p>
            <h2 className="text-xl font-black">
              {modal.row ? "Edit Library Value" : "Add Library Value"}
            </h2>
          </div>
          <button type="button" onClick={onClose}>
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="space-y-4 p-5">
          {modal.kind === "fni" ? (
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
              </> : <Input
                label="Reference Value"
                value={form.data.value}
                onChange={(value) => form.setData("value", value)}
              />}
              {form.data.library_type !== "system_name" && (["drrs_signatory", "drn_prefix", "response_letter_initials"].includes(form.data.library_type) ? <label className="block text-sm font-bold">
                Workflow Context
                <select className="mt-1 w-full" required value={form.data.context} onChange={(event) => form.setData("context", event.target.value)}>
                  {(form.data.library_type === "drrs_signatory" ? [
                    ["prepared_by", "Prepared By"], ["reviewed_by", "Reviewed By"], ["approved_by", "Approved By"],
                  ] : form.data.library_type === "response_letter_initials" ? [["response_letter", "Response Letter"]] : [["assessment", "Assessment"], ["response_letter", "Response Letter"]]).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
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
            disabled={form.processing}
            className="w-full rounded-md bg-brand-600 px-4 py-2.5 text-sm font-black text-white"
          >
            {form.processing ? "Saving..." : "Save Library Value"}
          </button>
        </div>
      </form>
    </div>
  );
}

function Input({ label, value, onChange, required = true }) {
  return (
    <label className="block text-sm font-bold">
      {label}
      <input
        className="mt-1 w-full"
        required={required}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      />
    </label>
  );
}
