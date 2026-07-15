import SearchableSelect from "@/Components/SearchableSelect";
import LookerMultiSelect from "@/Components/LookerMultiSelect";
import { composeDocumentDrn, currentDrnParts, DocumentDrnFields } from "@/Components/DocumentDrnFields";
import axios from "axios";
import { Sparkles, Trash2 } from "lucide-react";
import { useState } from "react";

const stripNarrativeSignoff = (value = "") => String(value)
  .split(/\r?\n/)
  .filter((line) => !/^(Signature|Date)\s*:\s*_+\s*$/i.test(line.trim()))
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
  drnPrefixes = [],
}) {
  const [aiAction, setAiAction] = useState(null);
  const [aiError, setAiError] = useState("");
  const [reliefSuggestionsActive, setReliefSuggestionsActive] = useState(false);
  const meta = form.data.assessment_form_data ?? {};
  const isDisaster = meta.request_type === "Disaster" && form.data.purpose === "Relief Augmentation";
  const setMeta = (key, value) =>
    form.setData("assessment_form_data", { ...meta, [key]: value });
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
  const combinedFniLibraryItems = Object.values(fniLibraryItems.reduce((items, row) => {
    const key = normalized(row.item_name);
    if (!key) return items;
    const current = items[key];
    const rowHasNoBrand = !String(row.brand_description ?? "").trim();
    const currentHasBrand = Boolean(String(current?.brand_description ?? "").trim());
    if (!current || (rowHasNoBrand && currentHasBrand)) items[key] = row;
    return items;
  }, {}));
  const locationKey = (value) => normalized(String(value ?? "").replace(/\b(?:PLGU|PGLU|CLGU|MLGU|LGU|province of|city of|city|municipality of|municipality|ADN|ADS|SDN|SDS|PDI)\b/gi, ""));
  const totalAvailability = (itemName) => warehouseStock.filter((row) => normalized(row.item) === normalized(itemName)).reduce((total, row) => total + Number(row.available || 0), 0);
  const selectedParty = requestParties.find((row) => String(row.id) === String(form.data.request_party_id));
  const selectedPartyCode = selectedParty?.lgu_directory_entry?.psgc_code ?? "";
  const selectedPartyLevel = String(form.data.lgu_level || selectedParty?.lgu_level || "").toUpperCase();
  const partyLocationKey = locationKey(`${selectedParty?.requesting_party ?? ""} ${selectedParty?.office_agency_details ?? ""}`);
  const provinceAliases = { ADN: "Agusan del Norte", ADS: "Agusan del Sur", SDN: "Surigao del Norte", SDS: "Surigao del Sur", PDI: "Province of Dinagat Islands" };
  const partyProvinceAbbreviation = String(selectedParty?.office_agency_details ?? selectedParty?.requesting_party ?? "").match(/,\s*(ADN|ADS|SDN|SDS|PDI)\s*$/i)?.[1]?.toUpperCase();
  const expectedProvince = (psgc.provinces ?? []).find((row) => locationKey(row.name) === locationKey(provinceAliases[partyProvinceAbbreviation]));
  const partyMunicipalityKey = locationKey(String(selectedParty?.office_agency_details ?? "").split(",")[0]);
  const municipalityCandidates = (psgc.municipalities ?? []).filter((row) =>
    !expectedProvince || String(row.parent_code) === String(expectedProvince.code) || String(row.code) === String(selectedPartyCode),
  );
  const matchedMunicipality = municipalityCandidates.find((row) => partyMunicipalityKey && locationKey(row.name) === partyMunicipalityKey && expectedProvince && String(row.parent_code) === String(expectedProvince.code))
    ?? municipalityCandidates.find((row) => partyMunicipalityKey && locationKey(row.name) === partyMunicipalityKey && String(row.code) === String(selectedPartyCode))
    ?? municipalityCandidates.find((row) => String(row.code) === String(selectedPartyCode))
    ?? municipalityCandidates.find((row) => partyLocationKey && partyLocationKey.includes(locationKey(row.name)));
  const matchedProvince = (psgc.provinces ?? []).find((row) => String(row.code) === String(selectedPartyCode))
    ?? (psgc.provinces ?? []).find((row) => partyLocationKey && partyLocationKey.includes(locationKey(row.name)));
  const affectedAreaMode = ["CLGU", "MLGU", "MGLU"].includes(selectedPartyLevel)
    ? "barangay"
    : ["PLGU", "PGLU"].includes(selectedPartyLevel)
      ? "municipality"
      : null;
  const affectedAreaOptions = affectedAreaMode === "barangay" && matchedMunicipality
    ? (psgc.barangays ?? []).filter((row) => String(row.parent_code) === String(matchedMunicipality.code)).map((row) => ({ value: row.name, label: row.name }))
    : affectedAreaMode === "municipality" && matchedProvince
      ? (psgc.municipalities ?? []).filter((row) => String(row.parent_code) === String(matchedProvince.code)).map((row) => ({ value: row.name, label: row.name }))
      : [];
  const selectedAffectedAreas = Array.isArray(meta.affected_areas)
    ? meta.affected_areas
    : [affectedAreaMode === "barangay" ? form.data.barangay : form.data.municipality].filter(Boolean);
  const selectAffectedAreas = (values) => {
    const selected = Array.isArray(values) ? values : [];
    const first = selected[0] ?? "";
    if (affectedAreaMode === "barangay") {
      const province = (psgc.provinces ?? []).find((row) => String(row.code) === String(matchedMunicipality?.parent_code));
      form.setData((current) => ({ ...current, province: province?.name ?? current.province, municipality: matchedMunicipality?.name ?? current.municipality, barangay: first, assessment_form_data: { ...(current.assessment_form_data ?? {}), affected_areas: selected } }));
    }
    if (affectedAreaMode === "municipality") form.setData((current) => ({ ...current, province: matchedProvince?.name ?? current.province, municipality: first, barangay: "", assessment_form_data: { ...(current.assessment_form_data ?? {}), affected_areas: selected } }));
  };
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
      const { data: payload } = await axios.post("/requests/polish-assessment", { mode, text: form.data.recommendations, requesting_agency: form.data.requesting_agency, incident_name: form.data.incident_name, incident_details: form.data.incident_details, purpose: form.data.purpose, affected_families: form.data.affected_families, items: form.data.items.map((item) => ({ item_name: item.item_name, requested_quantity: item.requested_quantity, unit: item.unit, available_quantity: item.available_quantity })), form_context: { date_received_by_drmd: form.data.date_received_by_drmd, assessment_drn: form.data.assessment_drn, office_agency_details: form.data.office_agency_details, lgu_level: form.data.lgu_level, province: form.data.province, municipality: form.data.municipality, barangay: form.data.barangay, affected_areas: meta.affected_areas, date_requested: form.data.date_requested, incident_date: form.data.incident_date, assessment_summary: form.data.assessment_summary, requester: form.data.requester, requester_position: form.data.requester_position, contact_number: form.data.contact_number, information_source: meta.information_source, information_date: meta.information_date, families_served: meta.families_served, has_previous_augmentation: meta.has_previous_augmentation, previous_augmentations: meta.previous_augmentations, delivery_batches: meta.delivery_batches, provide_augmentation: meta.provide_augmentation, response_purpose: meta.response_purpose } }, { headers: { Accept: "application/json" }, withXSRFToken: true });
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
  const signatoryName = (value) => (value ?? "").split("|")[0].trim();
  const signatoryPosition = (value) =>
    (value ?? "").split("|").slice(1).join("|").trim();
  const preparedRole = [
    ...new Set(
      [currentUser?.position, currentUser?.designation].filter(Boolean),
    ),
  ].join(" / ");
  const submit = (e) => {
    e.preventDefault();
    form[method](action, {
      preserveScroll: true,
      onSuccess,
      onError: () =>
        document
          .getElementById("assessment-validation")
          ?.scrollIntoView({ behavior: "smooth", block: "center" }),
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
        <button
          disabled={form.processing}
          className="rounded-md bg-emerald-700 px-5 py-2 text-sm font-black text-white disabled:opacity-50"
        >
          {form.processing ? "Saving..." : submitLabel}
        </button>
      </div>
      <div className="max-h-[calc(100vh-14rem)] overflow-auto p-3">
        {Object.keys(form.errors).length > 0 && (
          <div
            id="assessment-validation"
            className="mx-auto mb-3 max-w-[1280px] rounded-md border-2 border-rose-500 bg-rose-50 p-4 font-sans text-sm text-rose-800"
          >
            <p className="font-black">
              Complete the required worksheet cells before submitting:
            </p>
            <ul className="mt-2 grid list-disc gap-x-8 pl-5 md:grid-cols-2">
              {Object.entries(form.errors).map(([field, message]) => (
                <li key={field}>{message}</li>
              ))}
            </ul>
            <p className="mt-2 text-xs font-semibold">
              RIS Number, incident specification, delivery batches, families
              served, and remarks may remain blank when not applicable. All DRN
              components are required.
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
              <div className="border-t border-black px-3 py-2 font-bold">
                <span className="mb-1 block font-serif text-xs">DRN:</span>
                <DocumentDrnFields compact parts={assessmentDrnParts} onChange={setAssessmentDrn} prefixOptions={assessmentPrefixOptions} />
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
            <label className="col-span-4 flex items-center border-b border-black p-2 font-bold">
              Date:
              <CellInput
                type="date"
                value={form.data.date_received_by_drmd}
                onChange={(v) => form.setData("date_received_by_drmd", v)}
              />
            </label>
            <div className="col-span-2 border-b border-r border-black p-2 font-bold">
              Requesting Party
            </div>
            <div className={`${affectedAreaMode && affectedAreaOptions.length > 0 ? "col-span-4" : "col-span-10"} border-b border-black p-1 print:col-span-10`}>
              <CellInput
                value={form.data.requesting_agency}
                onChange={() => {}}
                readOnly
                aria-label="Requesting Party"
                className="cursor-not-allowed bg-slate-50 focus:bg-slate-50 focus:ring-0"
              />
            </div>
            {affectedAreaMode && affectedAreaOptions.length > 0 && (
              <>
                <div className="col-span-2 border-b border-l border-r border-black p-2 font-bold print:hidden">Affected Areas</div>
                <div className="col-span-4 border-b border-black p-1 print:hidden">
                  <LookerMultiSelect label="" options={affectedAreaOptions} value={selectedAffectedAreas} onApply={selectAffectedAreas} allLabel={affectedAreaMode === "barangay" ? "Select affected barangays" : "Select affected cities / municipalities"} placeholder={affectedAreaMode === "barangay" ? "Search affected barangay..." : "Search affected city or municipality..."} className="[&>span:first-child]:hidden [&>button]:mt-0" />
                </div>
              </>
            )}
            <div className="col-span-2 border-b border-r border-black p-2 font-bold">
              Purpose
            </div>
            <div className="col-span-2 border-b border-r border-black p-2">
              <label>
                <input
                  type="radio"
                  checked={meta.request_type === "Disaster"}
                  onChange={() => setPurpose("Relief Augmentation")}
                />{" "}
                Disaster
              </label>
            </div>
            <div className="col-span-3 grid grid-cols-[minmax(0,1.6fr)_minmax(112px,1fr)] border-b border-r border-black p-1">
              <SearchableSelect disabled={!isDisaster} options={incidentOptions} value={isDisaster ? form.data.incident_name : ""} onChange={(v) => form.setData("incident_name", v)} placeholder={isDisaster ? "Type of disaster" : ""} />
              <CellInput disabled={!isDisaster} type="date" value={isDisaster ? form.data.incident_date : ""} onChange={(v) => form.setData("incident_date", v)} aria-label="Date of disaster" className="ml-1 border-l border-slate-300 print:hidden disabled:cursor-not-allowed disabled:bg-slate-100" />
            </div>
            <div className="col-span-3 border-b border-r border-black">
              <CellInput
                disabled={!isDisaster}
                value={isDisaster ? form.data.incident_details : ""}
                onChange={(v) => form.setData("incident_details", v)}
                placeholder="Specify incident, if necessary"
              />
            </div>
            <div className="col-span-2 border-b border-black p-1">
              <select className="h-full w-full border-0 bg-transparent text-xs font-bold" value={meta.response_purpose ?? form.data.purpose ?? "Relief Augmentation"} onChange={(event) => setPurpose(event.target.value)}>
                <option value="Relief Augmentation">Relief Augmentation</option>
                <option value="Preparedness for Response">Preparedness for Response</option>
              </select>
            </div>
            <div className="col-span-2 border-b border-r border-black p-2 font-bold">
              Date of Request
            </div>
            <div className="col-span-10 border-b border-black">
              <CellInput
                type="date"
                value={form.data.date_requested}
                onChange={(v) => form.setData("date_requested", v)}
              />
            </div>
          </div>
          <div className="grid grid-cols-[4fr_3.7fr_52px] border-b border-black bg-slate-200 text-center text-xs font-black">
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
              <div className="border-r border-black p-1">
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
              <div className="border-r border-black">
                <CellInput
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={item.requested_quantity}
                  onChange={(v) => setItem(index, "requested_quantity", v)}
                />
              </div>
              <div className="border-r border-black bg-slate-50 p-2 text-center">
                {item.procured_quantity ?? "—"}
              </div>
              <div className="border-r border-black bg-slate-50 p-2 text-center">
                {item.donated_quantity ?? "—"}
              </div>
              <div className={`border-r border-black p-2 text-center font-black print:bg-white print:text-black ${item.fni_library_item_id ? (Number(item.requested_quantity || 0) > Number(item.available_quantity || 0) ? "bg-rose-200 text-rose-900" : "bg-emerald-200 text-emerald-900") : "bg-slate-50"}`}>
                {item.fni_library_item_id ? Number(item.available_quantity || 0).toLocaleString() : "—"}
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
          <Header>ASSESSMENT AND VALIDATION</Header>
          <div className="grid grid-cols-2 text-xs">
            <label className="col-span-2 flex border-b border-black px-2 font-bold">
              Date of Disaster Occurrence:
              <CellInput
                type="date"
                value={form.data.incident_date}
                onChange={(v) => form.setData("incident_date", v)}
              />
            </label>
            <label className="flex border-b border-r border-black px-2 font-bold">
              Actual Affected Families:
              <CellInput
                type="number"
                min="1"
                step="1"
                value={form.data.affected_families}
                onChange={setAffectedFamilies}
              />
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
            <label className="flex border-b border-r border-black px-2 font-bold">
              Source of Information:
              <CellInput
                value={meta.information_source}
                onChange={(v) => setMeta("information_source", v)}
                placeholder="e.g. CSWDO / DROMIC"
              />
            </label>
            <label className="flex border-b border-black px-2 font-bold">
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
          </div>
          <div className="grid grid-cols-[1.2fr_2.2fr_.9fr_3.6fr] border-b border-black bg-slate-50 text-center text-xs font-black">
            <div className="border-r border-black py-1">UNIT</div>
            <div className="border-r border-black py-1">DESCRIPTION</div>
            <div className="border-r border-black py-1">QUANTITY</div>
            <div className="py-1">REMARKS</div>
          </div>
          {previousAugmentations.map((row, index) => (
            <div
              key={index}
              className="grid grid-cols-[1.2fr_2.2fr_.9fr_3.6fr] border-b border-black text-xs"
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
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={row.quantity}
                  onChange={(v) =>
                    setPreviousAugmentation(index, "quantity", v)
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
              className="grid grid-cols-[.65fr_.9fr_1.6fr_.5fr_.5fr_4.5fr] border-b border-black text-xs"
            >
              <div className="border-r border-black p-2 font-bold">
                Batch {index + 1}
              </div>
              <div className="border-r border-black">
                <CellInput
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={row.quantity}
                  onChange={(v) => setBatch(index, "quantity", v)}
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
          <div className="grid grid-cols-[1.2fr_2.2fr_.9fr_3.6fr] border-b border-black bg-slate-50 text-center text-xs font-black">
            <div className="border-r border-black py-1">UNIT</div>
            <div className="border-r border-black py-1">DESCRIPTION</div>
            <div className="border-r border-black py-1">QUANTITY</div>
            <div className="py-1">REMARKS</div>
          </div>
          <div className="grid min-h-64 grid-cols-[4.3fr_3.6fr] border-b border-black text-xs">
            <div className="border-r border-black">
              {form.data.items.map((item, index) => (
                <div
                  key={index}
                  className="grid grid-cols-[1.2fr_2.2fr_.9fr] border-b border-black"
                >
                  <div className="border-r border-black px-2 py-1 text-center font-bold uppercase">
                    {item.unit || "-"}
                  </div>
                  <div className="border-r border-black px-2 py-1 text-center font-bold">
                    {item.item_name || "Select an FNI above"}
                  </div>
                  <div className="px-2 py-1 text-center font-bold">
                    {item.requested_quantity}
                  </div>
                </div>
              ))}
            </div>
            <div className="flex min-h-[34rem] max-h-[48rem] flex-col bg-white print:min-h-0 print:max-h-none">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 p-2 print:hidden">
                <span className="font-sans text-[10px] font-bold uppercase text-slate-500">Assessment narrative</span>
                <div className="flex flex-wrap gap-2">
                  <button type="button" onClick={() => runAssessmentAi("generate")} disabled={Boolean(aiAction)} className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 font-sans text-[11px] font-black text-emerald-700 hover:bg-emerald-100 disabled:opacity-50"><Sparkles className="h-3 w-3" />{aiAction === "generate" ? "Generating..." : "Auto-generate Assessment"}</button>
                  <button type="button" onClick={() => runAssessmentAi("polish")} disabled={Boolean(aiAction) || !form.data.recommendations?.trim()} className="inline-flex items-center gap-1 rounded-full border border-violet-200 bg-violet-50 px-3 py-1 font-sans text-[11px] font-black text-violet-700 hover:bg-violet-100 disabled:opacity-50"><Sparkles className="h-3 w-3" />{aiAction === "polish" ? "Polishing..." : "Polish Assessment"}</button>
                </div>
              </div>
              {aiError && <p className="px-2 pt-1 font-sans text-[10px] font-bold text-rose-600 print:hidden">{aiError}</p>}
              <textarea className="h-[34rem] min-h-[30rem] max-h-[44rem] w-full flex-1 resize-y overflow-y-auto border-0 p-3 leading-relaxed outline-none focus:bg-amber-50 print:hidden" value={form.data.recommendations} onChange={(e) => form.setData("recommendations", stripNarrativeSignoff(e.target.value))} onInput={(event) => { const editor = event.currentTarget; editor.style.height = "auto"; editor.style.height = `${Math.min(editor.scrollHeight, 704)}px`; }} placeholder="Assessment findings, validation, justification and recommendation narrative" />
              <div className="hidden whitespace-pre-wrap break-words p-3 text-[9px] leading-tight print:block">
                {stripNarrativeSignoff(form.data.recommendations)}
              </div>
            </div>
          </div>
          <div className="grid grid-cols-2 text-xs">
            <div className="flex min-h-40 flex-col border-r border-black p-3">
              <b>Prepared by:</b>
              <div className="mt-3 border-b border-black px-2 py-2 text-center">
                <p className="font-black uppercase">
                  {currentUser?.name || "User name not configured"}
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
                className="mt-3 w-full border-0 border-b border-black bg-transparent p-2 text-center font-black"
                value={meta.reviewed_by ?? ""}
                onChange={(e) => setMeta("reviewed_by", e.target.value)}
              >
                <option value="">Select reviewed by signatory</option>
                {signatoryOptions("reviewed_by").map((row) => (
                  <option key={row.id} value={row.value}>
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
              className="mx-auto mt-3 block w-2/3 border-0 border-b border-black bg-transparent p-2 text-center font-black"
              value={meta.approved_by ?? ""}
              onChange={(e) => setMeta("approved_by", e.target.value)}
            >
              <option value="">Select approved by signatory</option>
              {signatoryOptions("approved_by").map((row) => (
                <option key={row.id} value={row.value}>
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
    </form>
  );
}
