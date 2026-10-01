import { Head, Link, useForm } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import {
  ArrowRight,
  ChevronLeft,
  Download,
  FileText,
  Eye,
  Pencil,
  History,
  Layers3,
  MapPin,
  Search,
  Table2,
  Users,
  X,
} from "lucide-react";
import AppLayout from "@/Layouts/AppLayout";
import DromicSourceTable from "@/Components/DromicSourceTable";
import LookerMultiSelect from "@/Components/LookerMultiSelect";

const number = (v, money = false) =>
  v == null || v === ""
    ? "-"
    : Number(v).toLocaleString("en-PH", {
        minimumFractionDigits: money ? 2 : 0,
        maximumFractionDigits: money ? 2 : 0,
      });
const types = {
  initial: "Initial Report",
  progress: "Progress Report",
  terminal: "Terminal Report",
  first_final: "First and Final Report",
};
const input =
  "w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-600 focus:ring-emerald-600";
const primary =
  "inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40";
const secondary =
  "inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50";
const localNow = () => {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
};
const readableDate = (value) => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "—";
const pluralizeItemName = (name, quantity) => {
  if (Number(quantity) === 1) return name;
  const words = String(name || "").split(/\s+/);
  const last = words.pop() || "item";
  if (/^(rice|water|food|milk|soap|equipment|clothing)$/i.test(last)) return [...words, last].join(" ");
  const plural = /[^aeiou]y$/i.test(last) ? `${last.slice(0, -1)}ies`
    : /(s|x|z|ch|sh)$/i.test(last) ? `${last}es`
    : /s$/i.test(last) ? last : `${last}s`;
  return [...words, plural].join(" ");
};
const annexTitleCase = (value) => {
  const minorWords = new Set(["a", "an", "and", "as", "at", "but", "by", "for", "from", "in", "of", "on", "or", "the", "to", "with"]);
  const acronyms = { idps: "IDPs", ecs: "ECs", dswd: "DSWD", fni: "FNI", fnis: "FNIs", lgu: "LGU", ngos: "NGOs", csos: "CSOs", php: "PHP" };
  const words = String(value || "").split(/\s+/);
  return words.map((word, index) => {
    const plain = word.replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, "");
    const lower = plain.toLowerCase();
    if (!plain) return word;
    const replacement = acronyms[lower] || ((index > 0 && index < words.length - 1 && minorWords.has(lower)) ? lower : `${lower.charAt(0).toUpperCase()}${lower.slice(1)}`);
    return word.replace(plain, replacement);
  }).join(" ");
};
const annexAgeGroups = [
  ["infant", "INFANT", "0–6 months old"], ["toddler", "TODDLERS", "7 months–2 y/o"],
  ["pre_school", "PRESCHOOLERS", "3–5 y/o"], ["school_age", "SCHOOL AGE", "6–12 y/o"],
  ["teenage", "TEENAGE", "13–17 y/o"], ["adult", "ADULT", "18–59 years old"],
  ["elderly", "ELDERLY", "60 years old and above"],
];
const annexSectorGroups = [
  ["pregnant_women", "PREGNANT", false], ["lactating_mothers", "LACTATING MOTHERS", false],
  ["child_headed_family", "CHILD-HEADED FAMILY", true], ["single_headed_family", "SINGLE-HEADED FAMILY", true],
  ["solo_parent", "SOLO PARENT", true], ["pwds", "PERSON WITH DISABILITY (PWDs)", true],
  ["indigenous_people", "INDIGENOUS PEOPLE (IPs)", true], ["four_ps", "4Ps BENEFICIARY", true],
];
const fniReportType = (release) => {
  const category = String(release.category || "").toLowerCase().replace(/[-_]/g, " ").replace(/\s+/g, " ").trim();
  if (category.includes("family food") || category === "food" || category === "food items" || category === "food item" || (category.includes("other food") && !category.includes("non food"))) return "Family Food Packs / Food Items";
  if (category.includes("other nfi") || category.includes("other non food")) return "Other Non-Food Items";
  if (category.includes("raw material") || category.includes("indirect material")) return "Raw Materials";
  if (category.includes("non food")) return "Non-Food Items";
  return release.category || "Other Items";
};
const dynamicFniGroups = (releases = [], byLocation = true) => Object.entries(releases.reduce((groups, release) => {
  const type = fniReportType(release);
  const key = [...(byLocation ? [release.province, release.municipality] : []), release.item, release.unit].map((value) => String(value || "").trim().toLowerCase()).join("|");
  groups[type] ||= {};
  groups[type][key] ||= { province: release.province || "Province not reported", municipality: release.municipality || "City / municipality not reported", item: release.item || "Unspecified item", unit: release.unit || "", quantity: 0, cost: 0 };
  groups[type][key].quantity += Number(release.quantity || 0);
  groups[type][key].cost += Number(release.cost || 0);
  return groups;
}, {})).map(([type, items]) => ({ type, items: Object.values(items).sort((a, b) => a.item.localeCompare(b.item)) })).sort((a, b) => {
  const order = ["Family Food Packs / Food Items", "Non-Food Items", "Other Non-Food Items", "Raw Materials"];
  return (order.indexOf(a.type) < 0 ? 99 : order.indexOf(a.type)) - (order.indexOf(b.type) < 0 ? 99 : order.indexOf(b.type)) || a.type.localeCompare(b.type);
});
const fniItemColumns = (items) => Object.values(items.reduce((columns, item) => {
  const key = [item.item, item.unit].map((value) => String(value || "").trim().toLowerCase()).join("|");
  columns[key] ||= { key, item: item.item, unit: item.unit };
  return columns;
}, {})).sort((a, b) => a.item.localeCompare(b.item));
const fniQuantityFor = (items, column) => items
  .filter((item) => [item.item, item.unit].map((value) => String(value || "").trim().toLowerCase()).join("|") === column.key)
  .reduce((total, item) => total + Number(item.quantity || 0), 0);
const fniHierarchyRows = (items) => {
  const rows = [{ label: "CARAGA", level: "region", items }];
  [...new Set(items.map((item) => item.province))].sort().forEach((province) => {
    const provinceItems = items.filter((item) => item.province === province);
    rows.push({ label: province, level: "province", items: provinceItems });
    [...new Set(provinceItems.map((item) => item.municipality))].sort().forEach((municipality) => {
      rows.push({ label: municipality, level: "municipality", items: provinceItems.filter((item) => item.municipality === municipality) });
    });
  });
  return rows;
};
const sum = (values) =>
  values.some((v) => v == null) || !values.length
    ? null
    : values.reduce((a, b) => a + Number(b), 0);
const cleanOverview = (text, sources) => {
  const lines = String(text || "").split(/\r?\n/);
  const sourceLocations = new Set(
    sources.map((source) => `${source.municipality}, ${source.province}`.trim().toLowerCase()),
  );
  if (sourceLocations.has((lines[0] || "").trim().toLowerCase())) lines.shift();
  text = lines.join("\n").trim();
  if (!text) return text;
  return text.split(/\n\s*\n/).map((paragraph) => paragraph
    .split(/(?<=[.!?])\s+/)
    .filter((sentence) => !/\b(?:the\s+)?LGU reaffirms its commitment\b/i.test(sentence))
    .join(" ")
    .trim())
    .filter(Boolean)
    .join("\n\n");
};
const normalizeReportTitle = (value) => String(value || "").trim()
  .replace(/^(?:the\s+)?effects?\s+of\s+(?:the\s+)?effects?\s+of\s+/i, "the effects of ")
  .replace(/\s+/g, " ");

const suggestReportTitle = (sources) => {
  if (!sources.length) return "";
  const incident = String(sources[0].incident || "Disaster incident").trim();
  const effectsMatch = incident.match(/^(?:the\s+)?effects?\s+of\s+(.+)$/i);
  const incidentPhrase = effectsMatch ? `the effects of ${effectsMatch[1]}` : (/^the\b/i.test(incident) ? incident : `the ${incident}`);
  const sameIncident = sources.every(
    (source) => source.incident_key === sources[0].incident_key,
  );
  const locations = [
    ...new Map(
      sources.map((source) => [
        `${source.municipality}|${source.province}`,
        source,
      ]),
    ).values(),
  ];
  if (sources.length === 1) {
    const source = sources[0];
    const barangays = source.barangays || [];
    if (barangays.length === 1)
      return normalizeReportTitle(`${incidentPhrase} in Brgy. ${barangays[0]}, ${source.municipality}, ${source.province}`);
    if (barangays.length > 1)
      return normalizeReportTitle(`${incidentPhrase} in ${barangays.length} barangays, ${source.municipality}, ${source.province}`);
    return normalizeReportTitle(`${incidentPhrase} in ${source.municipality}, ${source.province}`);
  }
  if (sameIncident && locations.length === 1)
    return normalizeReportTitle(`${incidentPhrase} in ${locations[0].municipality}, ${locations[0].province}`);
  if (sameIncident) return normalizeReportTitle(`${incidentPhrase} in Caraga Region`);
  return `Consolidated disaster incidents in Caraga Region`;
};

function aggregate(sources, definitions) {
  return Object.fromEntries(
    Object.entries(definitions).map(([key, def]) => {
      if (key === "age_sex" || key === "sectoral") {
        const labels =
          key === "age_sex"
            ? {
                infant: "Infant (0–6 months)",
                toddler: "Toddler (7 months–2 years)",
                pre_school: "Pre-School (3–5 years)",
                school_age: "School Age (6–12 years)",
                teenage: "Teenage (13–17 years)",
                adult: "Adult (18–59 years)",
                elderly: "Elderly (60 years and above)",
              }
            : {
                pwds: "Persons with Disabilities (PWDs)",
                child_headed_family: "Child-Headed Family",
                single_headed_family: "Single-Headed Family",
                solo_parent: "Solo Parent",
                pregnant_women: "Pregnant Women",
                lactating_mothers: "Lactating Mothers",
                four_ps: "4Ps Beneficiaries (4Ps)",
                indigenous_people: "Indigenous People (IP)",
              };
        return [
          key,
          {
            ...def,
            rows: Object.entries(labels).map(([rowKey, label]) => ({
              label,
              level: "category",
              province: "",
              values: Object.fromEntries(
                Object.keys(def.columns).map((column) => [
                  column,
                  sum(
                    sources.map(
                      (source) => source.metrics[key]?.[rowKey]?.[column],
                    ),
                  ),
                ]),
              ),
            })),
          },
        ];
      }
      const row = (group, label, level, province = "") => ({
        label,
        level,
        province,
        values: Object.fromEntries(
          Object.keys(def.columns).map((col) => [
            col,
            col === "barangays"
              ? new Set(
                  group.flatMap((s) =>
                    s.barangays.map(
                      (b) =>
                        `${s.province}|${s.municipality}|${b.trim().toLowerCase()}`,
                    ),
                  ),
                ).size
              : sum(group.map((s) => s.metrics[key][col])),
          ]),
        ),
      });
      const rows = [row(sources, "CARAGA", "region")];
      [...new Set(sources.map((s) => s.province))]
        .sort()
        .forEach((province) => {
          const group = sources.filter((s) => s.province === province);
          rows.push(
            row(
              group,
              province || "Province not reported",
              "province",
              province,
            ),
          );
          [...new Set(group.map((s) => s.municipality))]
            .sort()
            .forEach((city) => {
              const local = group.filter((s) => s.municipality === city);
              rows.push(
                row(
                  local,
                  city || "City / municipality not reported",
                  "municipality",
                  province,
                ),
              );
              [...new Set(local.flatMap((source) => Object.keys(source.barangay_metrics || {})))]
                .sort((a, b) => a.localeCompare(b))
                .forEach((barangay) => {
                  const values = Object.fromEntries(Object.keys(def.columns).map((column) => [
                    column,
                    sum(local.map((source) => source.barangay_metrics?.[barangay]?.[key]?.[column]).filter((value) => value !== undefined)),
                  ]));
                  rows.push({ label: barangay, level: "barangay", province, municipality: city, values });
                });
            });
        });
      return [key, { ...def, rows }];
    }),
  );
}

function DromicTable({ table, money = false, editing = false, section = "", onCellChange = null, includeBarangays = true }) {
  return (
    <div className="max-h-[calc(100vh-15rem)] overflow-auto rounded-xl border border-slate-200">
      <table className="w-full border-collapse text-sm">
        <thead className="sticky top-0 z-20 bg-[#0a2f6b] text-xs uppercase text-white shadow-[0_1px_0_0_rgb(148,163,184)]">
          {section && section !== "cccm" ? <OfficialAnnexHeader type={section} /> : <tr>
            <th className="min-w-56 border border-slate-300 px-4 py-3 text-left">Region / Province / City / Municipality / Barangay</th>
            {Object.values(table.columns).map((c) => <th className="min-w-28 border border-slate-300 px-3 py-3 text-right" key={c}>{c}</th>)}
          </tr>}
        </thead>
        <tbody>
          {table.rows.filter((row) => includeBarangays || row.level !== "barangay").map((r, i) => (
            <tr
              key={i}
              className={`border-t border-slate-200 text-slate-900 ${r.level === "region" ? "bg-blue-200 font-bold text-blue-950" : r.level === "province" ? "bg-blue-100 font-semibold text-blue-950" : r.level === "municipality" ? "bg-blue-50" : "bg-white"} ${r.level === "barangay" ? "text-slate-700" : ""}`}
            >
              <td
                className={`border border-slate-200 px-4 py-3 ${r.level === "municipality" ? "pl-8 font-medium" : r.level === "barangay" ? "pl-14" : ""}`}
              >
                {r.level === "barangay" && <span className="mr-2 text-slate-400">↳</span>}{r.label}
              </td>
              {Object.entries(r.values).map(([column, v], n) => (
                <td
                  key={column}
                  className="whitespace-nowrap border border-slate-200 px-3 py-3 text-right tabular-nums"
                >
                  {editing && ["municipality", "category"].includes(r.level) && !(section === "assistance" && ["dswd", "total"].includes(column)) && !(section === "houses" && column === "total") ? <input aria-label={`${r.label} ${table.columns[column]}`} type="number" min="0" step={section === "assistance" ? "0.01" : "1"} className="w-24 rounded border border-emerald-400 bg-white px-2 py-1 text-right text-slate-950 outline-none focus:ring-2 focus:ring-emerald-500" value={v ?? ""} onChange={(event) => onCellChange?.(i, column, event.target.value === "" ? null : event.target.value)} /> : number(v, money)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function AssistanceItemTables({ groups, types, emptyMessage }) {
  const visibleGroups = groups.filter((group) => types.includes(group.type));

  if (!visibleGroups.length) {
    return <div className="rounded-xl border border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-600">{emptyMessage} Confirmed releases will appear automatically after the linked RIS/DR dispatch is released.</div>;
  }

  return <div className="space-y-4">{visibleGroups.map((group) => {
    const itemColumns = Object.values(group.items.reduce((columns, item) => {
      const key = [item.item, item.unit].map((value) => String(value || "").trim().toLowerCase()).join("|");
      columns[key] ||= { key, item: item.item, unit: item.unit };
      return columns;
    }, {})).sort((a, b) => a.item.localeCompare(b.item));
    const quantityFor = (items, column) => items
      .filter((item) => [item.item, item.unit].map((value) => String(value || "").trim().toLowerCase()).join("|") === column.key)
      .reduce((total, item) => total + Number(item.quantity || 0), 0);
    const hierarchyRows = [{ label: "CARAGA", level: "region", items: group.items }];
    [...new Set(group.items.map((item) => item.province))].sort().forEach((province) => {
      const provinceItems = group.items.filter((item) => item.province === province);
      hierarchyRows.push({ label: province, level: "province", items: provinceItems });
      [...new Set(provinceItems.map((item) => item.municipality))].sort().forEach((municipality) => {
        hierarchyRows.push({ label: municipality, level: "municipality", items: provinceItems.filter((item) => item.municipality === municipality) });
      });
    });
    return <section key={group.type}>
      <h3 className="mb-2 font-semibold text-slate-900">{group.type}</h3>
      <div className="max-h-[calc(100vh-18rem)] overflow-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full min-w-[720px] border-collapse text-sm">
          <thead className="sticky top-0 z-10 bg-[#0a2f6b] text-xs uppercase text-white">
            <tr><th className="min-w-64 border border-slate-300 px-4 py-3 text-left">Province/City/Municipality</th>{itemColumns.map((column) => <th key={column.key} className="min-w-32 border border-slate-300 px-3 py-3 text-center"><span className="block">{column.item}</span>{column.unit && <span className="mt-1 block text-[10px] font-normal normal-case text-blue-100">({column.unit})</span>}</th>)}</tr>
          </thead>
          <tbody>{hierarchyRows.map((row) => <tr key={`${group.type}|${row.level}|${row.label}`} className={row.level === "region" ? "bg-blue-200 font-bold text-blue-950" : row.level === "province" ? "bg-blue-100 font-semibold text-blue-950" : "bg-blue-50 text-slate-900"}><td className={`border border-slate-200 px-4 py-3 ${row.level === "municipality" ? "pl-8 font-medium" : ""}`}>{row.label}</td>{itemColumns.map((column) => <td key={column.key} className="border border-slate-200 px-3 py-3 text-right tabular-nums">{number(quantityFor(row.items, column))}</td>)}</tr>)}</tbody>
        </table>
      </div>
    </section>;
  })}</div>;
}

const financialPrograms = [
  { key: "aics", label: "AICS", unitCost: true, matches: [/\baics\b/i, /assistance to individuals in crisis/i] },
  { key: "akap", label: "AKAP", unitCost: true, matches: [/\bakap\b/i, /ayuda sa kapos ang kita/i] },
  { key: "ect", label: "ECT", unitCost: false, matches: [/\bect\b/i, /emergency cash transfer/i] },
  { key: "cfw", label: "CFW", unitCost: false, matches: [/\bcfw\b/i, /cash[ -]for[ -]work/i] },
  { key: "slp", label: "SLP", unitCost: false, matches: [/\bslp\b/i, /sustainable livelihood/i] },
];
const financialProgramFor = (row) => {
  const value = [row.particular, row.item, row.program, row.source_details].filter(Boolean).join(" ");
  return financialPrograms.find((program) => program.matches.some((pattern) => pattern.test(value)))?.key || null;
};

function FinancialAssistanceTable({ sources, fniReleases = [] }) {
  const assistanceRecords = sources.flatMap((source) => (source.details?.assistance || []).map((row) => ({ ...row, province: source.province || "Province not reported", municipality: source.municipality || "City / municipality not reported", cost: Number(row.quantity || 0) * Number(row.cost_per_unit || 0) })));
  const dswdFinancialRecords = assistanceRecords.filter((row) => String(row.item_type || "").toLowerCase().includes("financial") && String(row.source || "").toLowerCase().includes("dswd"));
  const records = dswdFinancialRecords
    .map((row) => ({ ...row, program: financialProgramFor(row), beneficiaries: Number(row.quantity || 0) }))
    .filter((row) => row.program);
  const unclassifiedCount = dswdFinancialRecords.length - records.length;
  const places = [...new Map(sources.map((source) => [`${source.province}|${source.municipality}`, { province: source.province || "Province not reported", municipality: source.municipality || "City / municipality not reported" }])).values()];
  const rowFor = (label, level, predicate) => {
    const matching = records.filter(predicate);
    const values = Object.fromEntries(financialPrograms.map((program) => {
      const programRows = matching.filter((row) => row.program === program.key);
      const beneficiaries = programRows.reduce((total, row) => total + row.beneficiaries, 0);
      const cost = programRows.reduce((total, row) => total + row.cost, 0);
      return [program.key, { beneficiaries, unitCost: beneficiaries > 0 ? cost / beneficiaries : 0, cost }];
    }));
    const financialTotal = Object.values(values).reduce((total, value) => total + value.cost, 0);
    const fniTotal = fniReleases.filter(predicate).reduce((total, release) => total + Number(release.cost || 0), 0);
    const stakeholderTotal = (matcher) => assistanceRecords.filter((row) => predicate(row) && matcher(String(row.source || "").toLowerCase())).reduce((total, row) => total + row.cost, 0);
    const lgu = stakeholderTotal((source) => source.includes("lgu"));
    const ngo = stakeholderTotal((source) => source.includes("ngo") || source.includes("cso"));
    const others = stakeholderTotal((source) => !source.includes("dswd") && !source.includes("lgu") && !source.includes("ngo") && !source.includes("cso"));
    return { label, level, values, fniTotal, financialTotal, dswdTotal: fniTotal + financialTotal, lgu, ngo, others, fniGrandTotal: fniTotal + lgu + ngo + others, grandTotal: fniTotal + financialTotal + lgu + ngo + others };
  };
  const rows = [rowFor("CARAGA", "region", () => true)];
  [...new Set(places.map((place) => place.province))].sort().forEach((province) => {
    rows.push(rowFor(province, "province", (record) => record.province === province));
    places.filter((place) => place.province === province).sort((a, b) => a.municipality.localeCompare(b.municipality)).forEach((place) => rows.push(rowFor(place.municipality, "municipality", (record) => record.province === province && record.municipality === place.municipality)));
  });

  return <section><h3 className="mb-2 font-semibold text-slate-900">Financial Assistance</h3>
    <div className="max-h-[calc(100vh-18rem)] overflow-auto rounded-xl border border-slate-200 bg-white"><table className="w-full min-w-[2300px] border-collapse text-sm">
      <thead className="sticky top-0 z-10 bg-[#0a2f6b] text-xs uppercase text-white"><tr><th rowSpan="2" className="min-w-64 border border-slate-300 px-4 py-3 text-left">Province/City/Municipality</th><th rowSpan="2" className="min-w-28 border border-slate-300 px-3 py-3 text-center">FNI Total</th>{financialPrograms.map((program) => <th key={program.key} colSpan={program.unitCost ? 3 : 2} className="border border-slate-300 px-3 py-3 text-center">{program.label}</th>)}<th rowSpan="2" className="min-w-40 border border-slate-300 px-3 py-3 text-center">DSWD Financial Assistance Total</th><th rowSpan="2" className="min-w-40 border border-slate-300 px-3 py-3 text-center">DSWD Total (FNI + Financial Assistance)</th><th rowSpan="2" className="min-w-28 border border-slate-300 px-3 py-3 text-center">LGU</th><th rowSpan="2" className="min-w-28 border border-slate-300 px-3 py-3 text-center">NGOs</th><th rowSpan="2" className="min-w-28 border border-slate-300 px-3 py-3 text-center">Others</th><th rowSpan="2" className="min-w-44 border border-slate-300 px-3 py-3 text-center">Grand Total (DSWD FNI + Other Stakeholders)</th><th rowSpan="2" className="min-w-48 border border-slate-300 px-3 py-3 text-center">Grand Total (DSWD FNI &amp; Financial Assistance + Other Stakeholders)</th></tr>
        <tr>{financialPrograms.flatMap((program) => [<th key={`${program.key}-beneficiaries`} className="min-w-32 border border-slate-300 px-3 py-3 text-center">Number of Beneficiaries</th>, ...(program.unitCost ? [<th key={`${program.key}-unit-cost`} className="min-w-28 border border-slate-300 px-3 py-3 text-center">Unit Cost</th>] : []), <th key={`${program.key}-cost`} className="min-w-28 border border-slate-300 px-3 py-3 text-center">Cost</th>])}</tr></thead>
      <tbody>{rows.map((row) => <tr key={`${row.level}|${row.label}`} className={row.level === "region" ? "bg-blue-200 font-bold text-blue-950" : row.level === "province" ? "bg-blue-100 font-semibold text-blue-950" : "bg-blue-50 text-slate-900"}><td className={`border border-slate-200 px-4 py-3 ${row.level === "municipality" ? "pl-8 font-medium" : ""}`}>{row.label}</td><td className="border border-slate-200 px-3 py-3 text-right tabular-nums">{number(row.fniTotal, true)}</td>{financialPrograms.flatMap((program) => { const value = row.values[program.key]; return [<td key={`${program.key}-beneficiaries`} className="border border-slate-200 px-3 py-3 text-right tabular-nums">{number(value.beneficiaries)}</td>, ...(program.unitCost ? [<td key={`${program.key}-unit-cost`} className="border border-slate-200 px-3 py-3 text-right tabular-nums">{number(value.unitCost, true)}</td>] : []), <td key={`${program.key}-cost`} className="border border-slate-200 px-3 py-3 text-right tabular-nums">{number(value.cost, true)}</td>]; })}<td className="border border-slate-200 px-3 py-3 text-right font-bold tabular-nums">{number(row.financialTotal, true)}</td><td className="border border-slate-200 px-3 py-3 text-right font-bold tabular-nums">{number(row.dswdTotal, true)}</td><td className="border border-slate-200 px-3 py-3 text-right tabular-nums">{number(row.lgu, true)}</td><td className="border border-slate-200 px-3 py-3 text-right tabular-nums">{number(row.ngo, true)}</td><td className="border border-slate-200 px-3 py-3 text-right tabular-nums">{number(row.others, true)}</td><td className="border border-slate-200 px-3 py-3 text-right font-bold tabular-nums">{number(row.fniGrandTotal, true)}</td><td className="border border-slate-200 px-3 py-3 text-right font-bold tabular-nums">{number(row.grandTotal, true)}</td></tr>)}</tbody>
    </table></div>{!records.length && <p className="mt-2 text-sm text-slate-500">No AICS, AKAP, ECT, CFW, or SLP financial assistance is reported for the selected reports.</p>}{unclassifiedCount > 0 && <p className="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-900">{unclassifiedCount} DSWD financial assistance entr{unclassifiedCount === 1 ? "y has" : "ies have"} no recognized program. Update the LGU report and select AICS, AKAP, ECT, CFW, or SLP so the amount can be placed in the correct column.</p>}</section>;
}

function applyReviewEdits(baseTables, edits) {
  const tables = structuredClone(baseTables);
  Object.entries(edits).forEach(([section, edit]) => {
    const table = tables[section];
    if (!table) return;
    edit.rows.forEach(({ index, values }) => {
      if (["municipality", "category"].includes(table.rows[index]?.level)) {
        table.rows[index].values = { ...table.rows[index].values, ...values };
        if (["houses", "assistance"].includes(section)) {
          const components = Object.entries(table.rows[index].values).filter(([column]) => column !== "total").map(([, value]) => value);
          table.rows[index].values.total = components.some((value) => value === null || value === "") ? null : components.reduce((sum, value) => sum + Number(value), 0);
        }
      }
    });
    if (["age_sex", "sectoral"].includes(section)) return;
    const cityRows = table.rows.filter((row) => row.level === "municipality");
    table.rows.forEach((row) => {
      if (row.level === "municipality") return;
      const group = row.level === "region" ? cityRows : cityRows.filter((city) => city.province === row.province);
      Object.keys(row.values).forEach((column) => {
        const values = group.map((city) => city.values[column]);
        row.values[column] = values.some((value) => value === null || value === "") ? null : values.reduce((sum, value) => sum + Number(value), 0);
      });
    });
  });
  if (edits.inside || edits.outside) {
    tables.displaced.rows.forEach((row, index) => Object.keys(row.values).forEach((column) => {
      const values = [tables.inside.rows[index].values[column], tables.outside.rows[index].values[column]];
      row.values[column] = values.some((value) => value === null || value === "") ? null : values.reduce((sum, value) => sum + Number(value), 0);
    }));
  }
  return tables;
}

function applyDswdAssistance(baseTables, selections, releases, sources) {
  const tables = structuredClone(baseTables);
  const selectedRows = releases.filter((release) => selections[release.id]);
  tables.assistance.rows.forEach((row) => {
    if (row.level !== "municipality") return;
    row.values.dswd = selectedRows.filter((release) => {
      const source = sources.find((item) => item.id === Number(selections[release.id]));
      return source?.province === row.province && source?.municipality === row.label;
    }).reduce((sum, release) => sum + Number(release.cost || 0), 0);
    const components = Object.entries(row.values).filter(([column]) => column !== "total").map(([, value]) => value);
    row.values.total = components.some((value) => value === null || value === "") ? null : components.reduce((sum, value) => sum + Number(value), 0);
  });
  const municipalities = tables.assistance.rows.filter((row) => row.level === "municipality");
  tables.assistance.rows.forEach((row) => {
    if (!["region", "province"].includes(row.level)) return;
    const group = row.level === "region" ? municipalities : municipalities.filter((city) => city.province === row.province);
    Object.keys(row.values).forEach((column) => {
      const values = group.map((city) => city.values[column]);
      row.values[column] = values.some((value) => value === null || value === "") ? null : values.reduce((sum, value) => sum + Number(value), 0);
    });
  });
  return tables;
}

function annexGeographyRows(sources, columns, valueForSource) {
  const makeRow = (group, label, level, province = "") => ({
    label, level, province,
    values: Object.fromEntries(columns.map(([column]) => [column, sum(group.map((source) => valueForSource(source, column)))])),
  });
  const rows = [makeRow(sources, "CARAGA", "region")];
  [...new Set(sources.map((source) => source.province))].sort().forEach((province) => {
    const provinceSources = sources.filter((source) => source.province === province);
    rows.push(makeRow(provinceSources, province, "province", province));
    [...new Set(provinceSources.map((source) => source.municipality))].sort().forEach((municipality) => {
      const local = provinceSources.filter((source) => source.municipality === municipality);
      rows.push(makeRow(local, municipality, "municipality", province));
    });
  });
  return rows;
}

function demographicReviewTable(sources, type) {
  const groups = type === "age_sex" ? annexAgeGroups : annexSectorGroups;
  const columns = groups.flatMap(([key, , bothSexes = true]) => (bothSexes ? ["male_cum", "male_now", "female_cum", "female_now"] : ["female_cum", "female_now"]).map((field) => [`${key}_${field}`, field]));
  const fromMetric = (source, column) => {
    const match = column.match(/^(.*)_(male|female)_(cum|now)$/);
    return match ? source.metrics?.[type]?.[match[1]]?.[`${match[2]}_${match[3]}`] : null;
  };
  const fromCenters = (centers, column) => {
    const match = column.match(/^(.*)_(male|female)_(cum|now)$/);
    return match ? sum(centers.map((center) => center.disaggregation?.[type]?.[match[1]]?.[`${match[2]}_${match[3]}`])) : null;
  };
  const rows = annexGeographyRows(sources, columns, fromMetric);
  const expanded = [];
  rows.forEach((row) => {
    expanded.push(row);
    if (row.level !== "municipality") return;
    const localSources = sources.filter((source) => source.province === row.province && source.municipality === row.label);
    const centers = localSources.flatMap((source) => (source.details?.centers || []).filter((center) => center.disaggregation_completed));
    const barangays = [...new Set(centers.map((center) => center.barangay_address || center.barangay_origin || "Barangay not reported"))].sort((a, b) => a.localeCompare(b));
    barangays.forEach((barangay) => {
      const barangayCenters = centers.filter((center) => (center.barangay_address || center.barangay_origin || "Barangay not reported") === barangay);
      expanded.push({ label: barangay, level: "barangay", province: row.province, municipality: row.label, values: Object.fromEntries(columns.map(([column]) => [column, fromCenters(barangayCenters, column)])) });
      [...new Set(barangayCenters.map((center) => center.evacuation_center || "Evacuation center not reported"))].sort((a, b) => a.localeCompare(b)).forEach((centerName) => {
        const ecRows = barangayCenters.filter((center) => (center.evacuation_center || "Evacuation center not reported") === centerName);
        expanded.push({ label: centerName, level: "evacuation_center", province: row.province, municipality: row.label, barangay, values: Object.fromEntries(columns.map(([column]) => [column, fromCenters(ecRows, column)])) });
      });
    });
  });
  return { title: type === "age_sex" ? "Sex and Age Distribution of IDPs Inside ECs" : "Sectoral Distribution of IDPs Inside ECs", columns: Object.fromEntries(columns), rows: expanded };
}

function DemographicMatrix({ table, type, includeEcRows = false }) {
  const minimumWidth = type === "age_sex" ? 2700 : 2450;
  return <div className="max-h-[calc(100vh-15rem)] overflow-auto rounded-xl border border-slate-200 bg-white"><table className="table-fixed border-collapse text-xs leading-normal" style={{ minWidth: `${minimumWidth}px`, width: `${minimumWidth}px` }}><colgroup><col style={{ width: "240px" }} />{Object.keys(table.columns).map((column) => <col key={column} style={{ width: "86px" }} />)}</colgroup><thead className="sticky top-0 z-20 shadow-[0_1px_0_0_rgb(203,213,225)]"><OfficialAnnexHeader type={type} /></thead><tbody>{table.rows.filter((row) => includeEcRows || !["barangay", "evacuation_center"].includes(row.level)).map((row, index) => <tr key={`${row.level}-${row.province}-${row.municipality}-${row.barangay || ""}-${row.label}-${index}`} className={`${row.level === "region" ? "bg-blue-200 font-bold text-blue-950" : row.level === "province" ? "bg-blue-100 font-semibold text-blue-950" : row.level === "municipality" ? "bg-blue-50 text-slate-900" : "bg-white text-slate-900"} ${row.level === "barangay" ? "font-semibold" : row.level === "evacuation_center" ? "italic" : ""}`}><td className={`border border-slate-200 px-3 py-2.5 whitespace-normal ${row.level === "municipality" ? "pl-5 font-medium" : row.level === "barangay" ? "pl-8" : row.level === "evacuation_center" ? "pl-11" : ""}`}>{row.level === "evacuation_center" ? `EC: ${row.label}` : row.label}</td>{Object.values(row.values).map((value, valueIndex) => <td key={valueIndex} className="border border-slate-200 px-2 py-2.5 text-center tabular-nums">{number(value)}</td>)}</tr>)}</tbody></table></div>;
}

function buildOfficialAnnexTables(snapshot) {
  const sources = snapshot.sources || [];
  const tables = snapshot.tables || {};
  const ageColumns = annexAgeGroups.flatMap(([key]) => ["male_cum", "male_now", "female_cum", "female_now"].map((field) => [`${key}_${field}`, field]));
  const sectorColumns = annexSectorGroups.flatMap(([key, , bothSexes]) => (bothSexes ? ["male_cum", "male_now", "female_cum", "female_now"] : ["female_cum", "female_now"]).map((field) => [`${key}_${field}`, field]));
  return {
    affected: tables.affected,
    inside: tables.inside,
    age_sex: { title: "Sex and Age Distribution of IDPs Inside ECs", columns: Object.fromEntries(ageColumns), rows: annexGeographyRows(sources, ageColumns, (source, column) => { const split = column.lastIndexOf("_"); const field = column.slice(split + 1); const prefix = column.slice(0, split); const second = prefix.lastIndexOf("_"); return source.metrics?.age_sex?.[prefix.slice(0, second)]?.[`${prefix.slice(second + 1)}_${field}`]; }) },
    sectoral: { title: "Sectoral Distribution of IDPs Inside ECs", columns: Object.fromEntries(sectorColumns), rows: annexGeographyRows(sources, sectorColumns, (source, column) => { const field = column.match(/(male|female)_(cum|now)$/)?.[0]; const key = field ? column.slice(0, -(field.length + 1)) : column; return source.metrics?.sectoral?.[key]?.[field]; }) },
    outside: tables.outside, displaced: tables.displaced, houses: tables.houses, assistance: tables.assistance,
  };
}

function OfficialAnnexHeader({ type, compact = false }) {
  const th = `overflow-hidden whitespace-normal break-words border border-slate-500 bg-[#c6e6ed] text-center align-middle font-bold text-slate-950 ${compact ? "px-0.5 py-1.5 leading-tight" : "px-1.5 py-1.5"}`;
  if (type === "affected") return <><tr><th className={th} rowSpan={2}>PROVINCE/CITY/MUNICIPALITY</th><th className={th} colSpan={3}>NUMBER OF AFFECTED</th></tr><tr>{["BRGYS.", "FAMILIES", "INDIVIDUALS"].map((label) => <th className={th} key={label}>{label}</th>)}</tr></>;
  if (type === "inside") return <><tr><th className={th} rowSpan={3}>PROVINCE/CITY/MUNICIPALITY</th><th className={th} colSpan={2} rowSpan={2}>NUMBER OF EVACUATION CENTER (ECs)</th><th className={th} colSpan={4}>NUMBER OF DISPLACED (INSIDE ECs)</th></tr><tr><th className={th} colSpan={2}>FAMILIES</th><th className={th} colSpan={2}>PERSONS</th></tr><tr>{["CUM", "NOW", "CUM", "NOW", "CUM", "NOW"].map((label, index) => <th className={th} key={`${label}-${index}`}>{label}</th>)}</tr></>;
  if (type === "age_sex") return <><tr><th className={th} rowSpan={4}>PROVINCE/CITY/MUNICIPALITY</th>{annexAgeGroups.map(([key, label]) => <th className={th} colSpan={4} key={key}>{label}</th>)}</tr><tr>{annexAgeGroups.map(([key, , age]) => <th className={th} colSpan={4} key={key}>{age}</th>)}</tr><tr>{annexAgeGroups.flatMap(([key]) => [<th className={th} colSpan={2} key={`${key}-m`}>MALE</th>, <th className={th} colSpan={2} key={`${key}-f`}>FEMALE</th>])}</tr><tr>{annexAgeGroups.flatMap(([key]) => ["CUM", "NOW", "CUM", "NOW"].map((label, index) => <th className={th} key={`${key}-${index}`}>{label}</th>))}</tr></>;
  if (type === "sectoral") return <><tr><th className={th} rowSpan={3}>PROVINCE/CITY/MUNICIPALITY</th>{annexSectorGroups.map(([key, label, both]) => <th className={th} colSpan={both ? 4 : 2} rowSpan={both ? 1 : 2} key={key}>{label}</th>)}</tr><tr>{annexSectorGroups.flatMap(([key, , both]) => both ? [<th className={th} colSpan={2} key={`${key}-m`}>MALE</th>, <th className={th} colSpan={2} key={`${key}-f`}>FEMALE</th>] : [])}</tr><tr>{annexSectorGroups.flatMap(([key, , both]) => Array.from({ length: both ? 4 : 2 }, (_, index) => <th className={th} key={`${key}-${index}`}>{index % 2 ? "NOW" : "CUM"}</th>))}</tr></>;
  if (["outside", "displaced"].includes(type)) return <><tr><th className={th} rowSpan={3}>PROVINCE/CITY/MUNICIPALITY</th><th className={th} colSpan={4}>{type === "outside" ? "NUMBER OF DISPLACED (OUTSIDE ECs)" : "NUMBER OF DISPLACED (INSIDE + OUTSIDE ECs)"}</th></tr><tr><th className={th} colSpan={2}>FAMILIES</th><th className={th} colSpan={2}>PERSONS</th></tr><tr>{["CUM", "NOW", "CUM", "NOW"].map((label, index) => <th className={th} key={`${label}-${index}`}>{label}</th>)}</tr></>;
  if (type === "houses") return <><tr><th className={th} rowSpan={2}>PROVINCE/CITY/MUNICIPALITY</th><th className={th} colSpan={3}>NUMBER OF DAMAGED HOUSES</th></tr><tr>{["TOTAL", "TOTALLY", "PARTIALLY"].map((label) => <th className={th} key={label}>{label}</th>)}</tr></>;
  const columns = ["DSWD", "LGU", "NGOs", "OTHER GOs", "GRAND TOTAL"];
  return <><tr><th className={th} rowSpan={2}>PROVINCE/CITY/MUNICIPALITY</th><th className={th} colSpan={columns.length}>TOTAL COST OF ASSISTANCE</th></tr><tr>{columns.map((label) => <th className={th} key={label}>{label}</th>)}</tr></>;
}

function ResponseActivityTable({ date, children }) {
  return <table className="mt-1 w-full table-fixed break-inside-avoid border-collapse text-[14px] leading-normal">
    <colgroup><col className="w-[22%]" /><col /></colgroup>
    <thead><tr className="bg-[#c6e6ed] text-center font-bold"><th className="border border-slate-500 px-2 py-1">Date</th><th className="border border-slate-500 px-2 py-1">Activities</th></tr></thead>
    <tbody><tr><td className="border border-slate-500 px-2 py-2 text-center align-middle">{date}</td><td className="border border-slate-500 px-3 py-2 align-top">{children}</td></tr></tbody>
  </table>;
}

function InlineNarrativeText({ value, onSave, className = "", multiline = true }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  useEffect(() => { if (!editing) setDraft(value); }, [value, editing]);
  if (!onSave) return <span className={className}>{value}</span>;
  if (!editing) return <span className={`group relative inline-block w-full rounded pr-7 ${className}`}><span>{value}</span><button type="button" aria-label="Edit narrative text" onClick={() => setEditing(true)} className="absolute right-0 top-0 rounded border border-blue-200 bg-white p-1 text-blue-900 opacity-0 shadow transition-opacity group-hover:opacity-100 focus:opacity-100"><Pencil className="h-3 w-3" /></button></span>;
  const Input = multiline ? "textarea" : "input";
  return <span className="relative z-40 block rounded outline outline-2 outline-blue-400"><Input autoFocus rows={multiline ? 3 : undefined} value={draft} onChange={(event) => setDraft(event.target.value)} className="block w-full resize-y rounded border-0 bg-white p-2 text-inherit leading-inherit text-slate-950" /><span className="absolute right-1 top-full mt-1 flex gap-1 rounded border border-blue-200 bg-white p-1 shadow-lg"><button type="button" onClick={() => { setDraft(value); setEditing(false); }} className="rounded border px-2 py-1 text-[10px] font-bold text-slate-600">Cancel</button><button type="button" onClick={() => { if (draft.trim()) { onSave(draft.trim()); setEditing(false); } }} className="rounded bg-blue-900 px-2 py-1 text-[10px] font-bold text-white">Save</button></span></span>;
}

function InlineLockedNarrative({ template, values, onSave }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(template);
  const [error, setError] = useState("");
  useEffect(() => { if (!editing) setDraft(template); }, [template, editing]);
  const render = (text) => String(text).split(/(\{\{[a-z0-9_]+\}\})/gi).map((part, index) => {
    const key = part.match(/^\{\{([a-z0-9_]+)\}\}$/i)?.[1];
    return key ? <strong key={`${key}-${index}`} className="text-[#0070c0]">{values[key]}</strong> : <span key={index}>{part}</span>;
  });
  if (!onSave) return <>{render(template)}</>;
  if (!editing) return <span className="group relative inline"><span>{render(template)}</span><button type="button" aria-label="Edit narrative sentence" onClick={() => setEditing(true)} className="ml-2 inline-flex rounded border border-blue-200 bg-white p-1 text-blue-900 opacity-0 shadow transition-opacity group-hover:opacity-100 focus:opacity-100"><Pencil className="h-3 w-3" /></button></span>;
  return <span className="relative z-40 block rounded outline outline-2 outline-blue-400"><textarea autoFocus rows={3} value={draft} onChange={(event) => { setDraft(event.target.value); setError(""); }} className="block w-full resize-y rounded border-0 bg-white p-2 text-inherit leading-inherit text-slate-950" />{error && <span className="block px-2 pb-1 text-left text-[10px] font-semibold text-red-700">{error}</span>}<span className="absolute right-1 top-full mt-1 flex gap-1 rounded border border-blue-200 bg-white p-1 shadow-lg"><button type="button" onClick={() => { setDraft(template); setError(""); setEditing(false); }} className="rounded border px-2 py-1 text-[10px] font-bold text-slate-600">Cancel</button><button type="button" onClick={() => { const missing = Object.keys(values).filter((key) => !draft.includes(`{{${key}}}`)); if (missing.length) { setError("Keep every locked data placeholder in the narrative."); return; } onSave(draft.trim()); setEditing(false); }} className="rounded bg-blue-900 px-2 py-1 text-[10px] font-bold text-white">Save</button></span></span>;
}

function Narrative({ snapshot, signatories = {}, onNarrativeEdit = null, onOverviewEdit = null, onResponseSectionsEdit = null, onPhotoChange = null }) {
  const t = Object.fromEntries(
    Object.entries(snapshot.tables).map(([key, table]) => [
      key,
      table.rows[0].values,
    ]),
  );
  const m = snapshot.metadata;
  const sectionPhotos = m.section_photos || {};
  const reportTitle = normalizeReportTitle(m.title) || "the incident";
  const stockpile = snapshot.standby_stockpile_summary || {};
  const ffpBreakdown = stockpile.ffp_breakdown || [];
  const otherBreakdown = stockpile.other_breakdown || [];
  const reportFniGroups = dynamicFniGroups(snapshot.dswd_assistance_releases || []);
  const ffpTotal = ffpBreakdown.reduce((sum, row) => sum + Number(row.current || 0), 0);
  const regionalAndSatelliteFfps = ffpBreakdown.filter((row) => /regional|satellite/i.test(row.warehouse_type || "")).reduce((sum, row) => sum + Number(row.current || 0), 0);
  const prepositionedFfps = ffpBreakdown.filter((row) => /preposition/i.test(row.warehouse_type || "")).reduce((sum, row) => sum + Number(row.current || 0), 0);
  const sourceLabel = snapshot.sources
    .map((source) => `${source.municipality}, ${source.province} LGU`)
    .filter(Boolean)
    .filter((name, index, names) => names.indexOf(name) === index)
    .join("; ");
  const sourceNames = sourceLabel ? sourceLabel.split("; ") : [];
  const sourceNote = (extra = "") => {
    const labels = [...(sourceNames.length > 3 ? ["Affected LGUs"] : sourceNames), ...(extra ? [extra] : [])];
    return `${labels.length === 1 ? "Source" : "Sources"}: ${labels.join("; ")}`;
  };
  const reportHeading = `${m.type_label || types[m.report_type]}${!m.type_label && m.report_type === "progress" ? ` No. ${m.progress_number}` : ""} on ${reportTitle}`;
  const narrativeEdits = m.narrative_edits || {};
  const responseSectionRows = m.response_sections || {};
  // Section VI is the DSWD response section. LGU actions remain source
  // material for the situation report and must never be prefilled here.
  const actionGroups = { fni: [], idpp: [], cccm: [], ect: [], other: [] };
  const reportDates = snapshot.sources.map((source) => source.incident_date || source.received_at).filter(Boolean).map((value) => new Date(value));
  const activityStart = reportDates.length ? new Date(Math.min(...reportDates)) : new Date(m.as_of);
  const activityEnd = new Date(m.as_of);
  const activityDate = (date) => date.toLocaleDateString("en-PH", { day: "2-digit", month: "short", year: "numeric" });
  const activityDateLabel = activityDate(activityStart) === activityDate(activityEnd) ? activityDate(activityEnd) : `${activityDate(activityStart)} – ${activityDate(activityEnd)}`;
  const fniReleaseDates = snapshot.dswd_assistance_releases || [];
  const fniStartValues = fniReleaseDates.map((release) => release.response_letter_date).filter(Boolean).sort();
  const fniEndValues = fniReleaseDates.map((release) => release.delivery_or_receipt_date || release.date).filter(Boolean).sort();
  const fniActivityStart = fniStartValues[0] ? new Date(`${fniStartValues[0]}T00:00:00`) : activityStart;
  const fniActivityEnd = fniEndValues.at(-1) ? new Date(`${fniEndValues.at(-1)}T00:00:00`) : activityEnd;
  const fniActivityDateLabel = activityDate(fniActivityStart) === activityDate(fniActivityEnd) ? activityDate(fniActivityEnd) : `${activityDate(fniActivityStart)} – ${activityDate(fniActivityEnd)}`;
  const affectedAreaCount = new Set(snapshot.sources.flatMap((source) => (source.barangays?.length ? source.barangays.map((barangay) => `${source.province}|${source.municipality}|${barangay}`) : [`${source.province}|${source.municipality}`]))).size;
  const affectedLguCount = new Set(snapshot.sources.map((source) => `${source.province}|${source.municipality}`)).size;
  const hasFoodItems = reportFniGroups.some((group) => group.type === "Family Food Packs / Food Items");
  const hasNonFoodItems = reportFniGroups.some((group) => group.type !== "Family Food Packs / Food Items");
  const providedItemLabel = hasFoodItems && hasNonFoodItems ? "food and non-food items" : hasFoodItems ? "food items" : "non-food items";
  const fniActivitySentence = (type) => {
    const itemType = type === "Family Food Packs / Food Items" ? "food items" : type === "Non-Food Items" ? "non-food items" : type.toLowerCase();
    return `Facilitated the delivery and distribution of the following ${itemType} to affected families in the affected ${affectedAreaCount === 1 ? "area" : "areas"}, in coordination with the concerned ${affectedLguCount === 1 ? "LGU" : "LGUs"}:`;
  };
  const ActivityBullets = ({ rows, empty, emptyKey = "" }) => rows.length
    ? <ul className="list-disc space-y-1 pl-4">{rows.map((row, index) => <li key={row.narrativeKey || `${row.source?.id || "activity"}-${index}`}><InlineNarrativeText value={narrativeEdits[row.narrativeKey] || row.action} onSave={onNarrativeEdit ? (value) => onNarrativeEdit(row.narrativeKey, value) : null} />{row.office ? <> <span className="italic">({row.office})</span></> : null}</li>)}</ul>
    : <p><InlineNarrativeText value={narrativeEdits[emptyKey] || empty} onSave={onNarrativeEdit ? (value) => onNarrativeEdit(emptyKey, value) : null} /></p>;
  const annexLetters = {
    affected: "A",
    inside: "B",
    age_sex: "C",
    sectoral: "D",
    outside: "E",
    displaced: "F",
    houses: "G",
    assistance: "H",
    photo: "I",
  };
  const officialAnnexTables = buildOfficialAnnexTables(snapshot);
  const annexOrder = ["affected", "inside", "age_sex", "sectoral", "outside", "displaced", "houses", "assistance"];
  const previewAnnexes = [
    ...annexOrder.filter((key) => officialAnnexTables[key]).map((key) => [key, officialAnnexTables[key]]),
    ["photo", { title: "Photo Documentation", columns: {}, rows: [] }],
  ];
  const isOngoingReport = ["initial", "progress"].includes(m.report_type);
  // Usable A4 body area after the document header, page footer, and margins.
  // Keep a safety allowance so borders and the final row never cross the page edge.
  const annexPageCapacity = 720;
  const annexPages = [];
  const nextAnnexPage = () => {
    const page = { items: [], height: 0 };
    annexPages.push(page);
    return page;
  };
  previewAnnexes.forEach(([key, table]) => {
    if (key === "photo") {
      let page = annexPages.at(-1) || nextAnnexPage();
      if (page.height + 90 > annexPageCapacity && page.items.length) page = nextAnnexPage();
      page.items.push([key, table, false, true]);
      page.height += 90;
      return;
    }
    const rows = (table.rows || []).filter((row) => row.level !== "barangay");
    const wide = ["age_sex", "sectoral"].includes(key);
    const headerHeight = wide ? 112 : 110;
    const rowHeight = wide ? 18 : 31;
    const noteHeight = isOngoingReport ? 24 : 0;
    const completeTableHeight = headerHeight + Math.max(rows.length, 1) * rowHeight + noteHeight;
    let offset = 0;
    let continued = false;
    while (offset < Math.max(rows.length, 1)) {
      let page = annexPages.at(-1) || nextAnnexPage();
      const remainingPageHeight = annexPageCapacity - page.height;
      if (offset === 0 && completeTableHeight <= annexPageCapacity && page.items.length && remainingPageHeight < completeTableHeight) {
        page = nextAnnexPage();
      }
      const minimumStartHeight = headerHeight + rowHeight * Math.min(2, Math.max(rows.length - offset, 1));
      if (page.items.length && remainingPageHeight < minimumStartHeight) page = nextAnnexPage();
      const availableForRows = annexPageCapacity - page.height - headerHeight;
      const remainingRows = Math.max(rows.length - offset, 1);
      let rowLimit = Math.max(1, Math.floor(availableForRows / rowHeight));
      let take = Math.min(remainingRows, rowLimit);
      if (take === remainingRows && page.height + headerHeight + take * rowHeight + noteHeight > annexPageCapacity) {
        take = Math.max(1, take - Math.ceil(noteHeight / rowHeight));
      }
      const finalChunk = offset + take >= rows.length;
      const chunkRows = rows.length ? rows.slice(offset, offset + take) : [];
      page.items.push([key, { ...table, rows: chunkRows }, continued, finalChunk]);
      page.height += headerHeight + take * rowHeight + (finalChunk ? noteHeight : 0);
      offset += take;
      continued = true;
      if (!finalChunk) nextAnnexPage();
    }
  });
  const signatureHeight = 175;
  if (!annexPages.length || annexPages.at(-1).height + signatureHeight > annexPageCapacity) {
    annexPages.push({ items: [], height: 0 });
  }
  const responseSections = [
    ["c. Internally Displaced Person Protection (IDPP)", responseSectionRows.idpp || [], "No DSWD IDPP activity was reported for the period.", "idpp"],
    ["d. Camp Coordination and Camp Management (CCCM)", responseSectionRows.cccm || [], "No DSWD camp coordination and camp management activity was reported for the period.", "cccm"],
    ["e. Emergency Cash Transfer (ECT)", responseSectionRows.ect || [], "No DSWD Emergency Cash Transfer activity was reported for the period.", "ect"],
    ["f. Other Activities", responseSectionRows.other || [], "No other DSWD activity was reported for the period.", "other"],
  ];
  const activityRowHeight = (row) => Math.max(38, 24 + (row.bullets || []).reduce((height, bullet) => height + Math.max(22, Math.ceil(String(bullet).length / 75) * 20), 0));
  // Treat every response subsection as one pagination unit. This keeps its
  // heading, activity table, and source note together and moves the complete
  // subsection to the next page when it cannot fit in the remaining space.
  const responseBlocks = responseSections.map(([heading, rows, empty, emptyKey]) => ({
    key: heading,
    heading,
    rows,
    empty,
    emptyKey,
    showSource: emptyKey === "other",
  }));
  const responseSectionHeight = (block) => 66 + (block.heading ? 26 : 0) + Math.max(22, block.rows.reduce((height, row) => height + activityRowHeight(row), 0));
  const fniTableHeight = reportFniGroups.reduce((height, group) => height + 64 + fniHierarchyRows(group.items).length * 30, 0);
  let pageTwoSpace = Math.max(0, 700 - 535 - Math.max(70, fniTableHeight));
  const pageTwoSections = [];
  const remainingResponseSections = [...responseBlocks];
  while (remainingResponseSections.length && responseSectionHeight(remainingResponseSections[0]) <= pageTwoSpace) {
    const block = remainingResponseSections.shift();
    pageTwoSections.push(block);
    pageTwoSpace -= responseSectionHeight(block);
  }
  const responseContinuationPages = [];
  remainingResponseSections.forEach((section) => {
    let page = responseContinuationPages.at(-1);
    const height = responseSectionHeight(section);
    if (!page || page.height + height > 700) {
      page = { sections: [], height: 0 };
      responseContinuationPages.push(page);
    }
    page.sections.push(section);
    page.height += height;
  });
  const totalPreviewPages = 2 + responseContinuationPages.length + annexPages.length;
  const signaturePeople = {
    prepared: snapshot.metadata.prepared_by || signatories.prepared || {},
    recommended: signatories.recommended || {},
    approved: signatories.approved || {},
  };
  const hasReported = (values) =>
    Object.values(values || {}).some((value) => value !== null && value !== "");
  const countNoun = (value, singular, plural = `${singular}s`) => Number(value) === 1 ? singular : plural;
  const countVerb = (value) => Number(value) === 1 ? "was" : "were";
  const narrativeDefaults = {
    affected: `A total of {{families}} ${countNoun(t.affected.families, "family", "families")} ${countVerb(t.affected.families)} affected, comprising {{persons}} ${countNoun(t.affected.persons, "individual")} across {{barangays}} ${countNoun(t.affected.barangays, "barangay", "barangays")} in Caraga Region (see Annex A).`,
    inside: `A total of {{families}} ${countNoun(t.inside.families_cum, "family", "families")} ${countVerb(t.inside.families_cum)} temporarily sheltered inside {{ecs}} ${countNoun(t.inside.ecs_cum, "evacuation center")}, comprising {{persons}} ${countNoun(t.inside.persons_cum, "individual")} (see Annex B).`,
    outside: `A total of {{families}} ${countNoun(t.outside.families_cum, "family", "families")}, comprising {{persons}} ${countNoun(t.outside.persons_cum, "individual")}, temporarily stayed with relatives or friends (see Annex E).`,
    displaced: `A total of {{families}} ${countNoun(t.displaced.families_cum, "family", "families")} ${countVerb(t.displaced.families_cum)} displaced in Caraga Region, comprising {{persons}} ${countNoun(t.displaced.persons_cum, "individual")} (see Annex F).`,
    houses: `A total of {{total}} ${countNoun(t.houses.total, "house", "houses")} ${countVerb(t.houses.total)} damaged in Caraga Region, of which {{totally}} ${countVerb(t.houses.totally)} totally damaged and {{partially}} ${countVerb(t.houses.partially)} partially damaged (see Annex G).`,
  };
  const formatDateTime = (value) =>
    new Date(value).toLocaleString("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit",
    });
  return (
    <div className="space-y-6">
    <article className="mx-auto flex h-[297mm] max-h-[297mm] w-full max-w-[210mm] flex-col overflow-hidden border border-slate-300 bg-white px-9 pb-6 pt-7 text-justify font-sans text-[14px] leading-[1.55] text-slate-950 shadow-sm sm:px-14">
      <header className="border-b-2 border-slate-500 pb-2">
        <div className="flex h-20 items-center justify-start gap-3 overflow-hidden">
          <img src="/images/dromic-header-dswd.png" alt="DSWD Field Office Caraga" className="h-16 w-auto object-contain" />
          <img src="/images/dromic-logo.png" alt="DROMIC" className="h-12 w-auto object-contain" />
          <img src="/images/dromic-header-bagong.png" alt="Bagong Pilipinas" className="h-16 w-auto object-contain" />
        </div>
      </header>
      <p className="hidden">
        DSWD Field Office Caraga · Draft
      </p>
      <p className="mt-3 text-right text-xs font-semibold tracking-wide text-slate-900">
        DRN: CARAGA-FO-DRMD-DRIMS-SS-DS-26-___-_____-S
      </p>
      <h2 className="mx-auto mt-4 max-w-3xl text-center text-[24px] font-bold leading-tight text-black">
        DSWD Field Office Caraga {m.type_label || types[m.report_type]}
        {!m.type_label && m.report_type === "progress"
          ? ` No. ${m.progress_number}`
          : ""}
        {" "}on {reportTitle}
      </h2>
      <p className="mb-8 mt-1 text-center text-[20px] text-black">
        As of {formatDateTime(m.as_of)}
      </p>
      <h3 className="font-bold text-[#0a2a66]">I. Situation Overview</h3>
      <EditableSectionPhoto label="Section I photo" photo={sectionPhotos.section_i} onChange={onPhotoChange ? (photo) => onPhotoChange("section_i", photo) : null} />
      <p className="mt-2 whitespace-pre-line"><InlineNarrativeText value={cleanOverview(m.overview, snapshot.sources) || "Add the situation overview."} onSave={onOverviewEdit} /></p>
      <p className="clear-both mt-1 text-right text-xs italic text-[#0070c0]">{sourceNote()}</p>
      {false && <h4 className="mt-4 font-semibold">LGU response actions and interventions</h4>}
      {false && ((snapshot.metadata.response_actions || "").trim()
        ? snapshot.metadata.response_actions
            .split(/\n\s*\n/)
            .map((action, i) => (
              <p key={i} className="mt-2 whitespace-pre-line">
                {action}
              </p>
            ))
        : snapshot.sources.flatMap((s) =>
            s.actions.map((a, i) => (
              <p key={`${s.id}-${i}`} className="mt-2">
                <strong>
                  {s.municipality}, {s.province} — {a.office}:
                </strong>{" "}
                {a.action}
              </p>
            )),
          ))}
      <h3 className="mt-4 font-bold text-[#0a2a66]">
        II. Status of Affected Areas and Population
      </h3>
      <EditableSectionPhoto label="Section II photo" photo={sectionPhotos.section_ii} onChange={onPhotoChange ? (photo) => onPhotoChange("section_ii", photo) : null} />
      <p><InlineLockedNarrative template={narrativeEdits["section:affected"] || narrativeDefaults.affected} values={{ families: number(t.affected.families), persons: number(t.affected.persons), barangays: number(t.affected.barangays) }} onSave={onNarrativeEdit ? (value) => onNarrativeEdit("section:affected", value) : null} /></p>
      <p className="clear-both mt-1 text-right text-xs italic text-[#0070c0]">{sourceNote()}</p>
      <h3 className="mt-4 font-bold text-[#0a2a66]">
        III. Status of Displaced Population
      </h3>
      {["inside", "outside", "displaced"].filter((key) => hasReported(t[key])).map((key, i) => (
        <div key={key} className="mt-3">
          <h4 className="font-semibold">
            {
              [
                "a. Inside evacuation centers",
                "b. Outside evacuation centers",
                "c. Total displaced population",
              ][i]
            }
          </h4>
          <p><InlineLockedNarrative template={narrativeEdits[`section:${key}`] || narrativeDefaults[key]} values={{ families: number(t[key].families_cum), persons: number(t[key].persons_cum), ...(key === "inside" ? { ecs: number(t[key].ecs_cum) } : {}) }} onSave={onNarrativeEdit ? (value) => onNarrativeEdit(`section:${key}`, value) : null} /></p>
        </div>
      ))}
      <p className="mt-1 text-right text-xs italic text-[#0070c0]">{sourceNote()}</p>
      <p className="hidden">
        Sources:{" "}
        {snapshot.sources
          .map(
            (s) =>
              `${s.reference} (${s.municipality}, ${s.province}; ${s.validation.replaceAll("_", " ")})`,
          )
          .join("; ")}
        .
      </p>
      <footer className="mt-auto border-t border-slate-500 pt-2 text-[11px] leading-tight text-slate-500">
        <div className="text-right">
          <span>
            DSWD Field Office Caraga {reportHeading}
            <br />As of {formatDateTime(m.as_of)}
          </span>
          <br /><span>Page 1 of {totalPreviewPages}</span>
        </div>
      </footer>
    </article>
    <article className="mx-auto flex h-[297mm] max-h-[297mm] w-full max-w-[210mm] flex-col overflow-hidden border border-slate-300 bg-white px-9 pb-6 pt-7 text-justify font-sans text-[14px] leading-[1.55] text-slate-950 shadow-sm sm:px-14">
      <header className="border-b-2 border-slate-500 pb-2">
        <div className="flex h-20 items-center justify-start gap-3 overflow-hidden">
          <img src="/images/dromic-header-dswd.png" alt="DSWD Field Office Caraga" className="h-16 w-auto object-contain" />
          <img src="/images/dromic-logo.png" alt="DROMIC" className="h-12 w-auto object-contain" />
          <img src="/images/dromic-header-bagong.png" alt="Bagong Pilipinas" className="h-16 w-auto object-contain" />
        </div>
      </header>
      <h3 className="text-base font-bold text-[#0a2a66]">IV. Damaged Houses</h3>
      <p><InlineLockedNarrative template={narrativeEdits["section:houses"] || narrativeDefaults.houses} values={{ total: number(t.houses.total), totally: number(t.houses.totally), partially: number(t.houses.partially) }} onSave={onNarrativeEdit ? (value) => onNarrativeEdit("section:houses", value) : null} /></p>
      <p className="mt-1 text-right text-xs italic text-[#0070c0]">{sourceNote()}</p>
      <h3 className="mt-3 text-base font-bold text-[#0a2a66]">V. Cost of Assistance Provided</h3>
      <p><InlineLockedNarrative template={narrativeEdits["section:assistance"] || "A total of PHP {{total}} worth of relief assistance was provided to affected families: DSWD, PHP {{dswd}}; LGUs, PHP {{lgu}}; NGOs / CSOs, PHP {{ngo}}; and others, PHP {{others}} (see Annex H)."} values={{ total: number(t.assistance.total, true), dswd: number(t.assistance.dswd, true), lgu: number(t.assistance.lgu, true), ngo: number(t.assistance.ngo, true), others: number(t.assistance.others, true) }} onSave={onNarrativeEdit ? (value) => onNarrativeEdit("section:assistance", value) : null} /></p>
      <p className="mt-1 text-right text-xs italic text-[#0070c0]">{sourceNote(snapshot.dswd_assistance_releases?.length ? "DROMIS FNI Releases" : "")}</p>
      <h3 className="mt-3 text-base font-bold text-[#0a2a66]">VI. Response Actions and Interventions</h3>
      <section className="mt-4">
        <h4 className="font-bold">a. Standby Funds and Prepositioned Relief Stockpile</h4>
        <div className="mt-2 overflow-x-auto">
          <table className="w-full table-fixed border-collapse text-[11px] leading-tight">
            <colgroup><col className="w-[19%]" /><col className="w-[14.5%]" /><col className="w-[15%]" /><col className="w-[16%]" /><col className="w-[17%]" /><col className="w-[18.5%]" /></colgroup>
            <thead className="bg-[#0a2f6b] text-center font-black uppercase text-white">
              <tr><th rowSpan={3} className="border border-slate-300 px-1.5 py-2 align-middle">Office</th><th rowSpan={3} className="border border-slate-300 px-1.5 py-2 align-middle">Standby<br />Funds</th><th colSpan={3} className="border border-slate-300 px-1.5 py-1.5 text-center">Stockpile</th><th rowSpan={3} className="border border-slate-300 px-1.5 py-2 align-middle">Total<br />Standby<br />Funds &amp;<br />Stockpile</th></tr>
              <tr><th colSpan={2} className="border border-slate-300 px-2 py-2">Family Food Packs</th><th rowSpan={2} className="border border-slate-300 px-2 py-2 align-middle">Other Food<br />and Non-Food<br />Items (FNIs)</th></tr>
              <tr><th className="border border-slate-300 px-2 py-2">Quantity</th><th className="border border-slate-300 px-2 py-2">Total Cost</th></tr>
            </thead>
            <tbody><tr className="font-bold"><td className="border border-slate-300 px-1.5 py-3 text-center">{stockpile.office || "DSWD Field Office Caraga"}</td><td className="border border-slate-300 px-1.5 py-3 text-center">₱{number(stockpile.standby_funds, true)}</td><td className="border border-slate-300 px-1.5 py-3 text-center">{number(stockpile.ffp_quantity)}</td><td className="border border-slate-300 px-1.5 py-3 text-center">₱{number(stockpile.ffp_cost, true)}</td><td className="border border-slate-300 px-1.5 py-3 text-center">₱{number(stockpile.other_food_non_food_cost, true)}</td><td className="border border-slate-300 px-1.5 py-3 text-center">₱{number(stockpile.total_standby_funds_stockpile, true)}</td></tr></tbody>
          </table>
        </div>
        <ul className="mt-3 list-disc space-y-2 pl-5">
          <li className="font-bold">Prepositioned FFPs and Other Relief Items
            <ul className="mt-2 list-disc space-y-2 pl-5 font-normal">
              <li><strong>{number(ffpTotal)} {ffpTotal === 1 ? "FFP" : "FFPs"}</strong> {ffpTotal === 1 ? "is" : "are"} available in the region; of these, <strong>{number(regionalAndSatelliteFfps)} {regionalAndSatelliteFfps === 1 ? "FFP is" : "FFPs are"}</strong> at the DSWD Regional and Satellite Warehouses, and <strong>{number(prepositionedFfps)} {prepositionedFfps === 1 ? "FFP is" : "FFPs are"}</strong> prepositioned at LGU warehouses.</li>
              <li><strong>₱{number(stockpile.other_food_non_food_cost, true)}</strong> available other food and non-food items, of which {otherBreakdown.map((row, index) => <span key={row.label}>{index > 0 ? ", " : ""}<strong>₱{number(row.cost, true)}</strong> for {String(row.label || "").toLowerCase()}</span>)}.</li>
            </ul>
          </li>
        </ul>
      </section>
      <section className="mt-4">
        <h4 className="font-bold">b. Food and Non-Food Items (FNIs)</h4>
        <ResponseActivityTable date={fniActivityDateLabel}>
          {reportFniGroups.length ? (affectedLguCount === 1 ? <ul className="mt-1 list-disc pl-5"><li className="pl-1 text-left align-top">Facilitated the delivery and distribution of {providedItemLabel} to the affected {Number(t.affected.families) === 1 ? "family" : "families"}, as follows:<ul className="ml-5 mt-1 list-[circle] space-y-0.5">{reportFniGroups.flatMap((group) => group.items).map((item) => <li className="pl-1 text-left align-top" key={`${item.item}|${item.unit}`}>{number(item.quantity)} {pluralizeItemName(item.item, item.quantity)}{item.unit ? ` (${item.unit})` : ""}</li>)}</ul></li></ul> : <div className="mt-2 space-y-3">{reportFniGroups.map((group) => { const columns = fniItemColumns(group.items); const rows = fniHierarchyRows(group.items); return <div key={group.type} className="overflow-hidden"><ul className="mb-2 list-disc pl-5"><li className="pl-1 text-left align-top">{fniActivitySentence(group.type)}</li></ul><table className="w-full table-fixed border-collapse text-[14px] leading-normal"><thead><tr><th className="w-28 border border-slate-400 bg-[#0a2f6b] px-1 py-1 text-center font-bold text-white">PROVINCE/CITY/MUNICIPALITY</th>{columns.map((column) => <th key={column.key} className="border border-slate-400 bg-[#0a2f6b] px-1 py-1 text-center font-bold text-white"><span>{column.item}</span><span className="block font-normal">({column.unit || "unit not specified"})</span></th>)}</tr></thead><tbody>{rows.map((row) => <tr key={`${group.type}|${row.level}|${row.label}`} className={row.level === "region" ? "bg-blue-200 font-bold" : row.level === "province" ? "bg-blue-100 font-bold" : "bg-blue-50"}><td className={`border border-slate-400 px-1 py-1 ${row.level === "municipality" ? "pl-3" : ""}`}>{row.label}</td>{columns.map((column) => <td key={column.key} className="border border-slate-400 px-1 py-1 text-center">{number(fniQuantityFor(row.items, column))}</td>)}</tr>)}</tbody></table></div>; })}</div>) : <p>No DSWD food or non-food item activity was reported for the period.</p>}
        </ResponseActivityTable>
      </section>
      {pageTwoSections.map(({ key, heading, rows, empty, emptyKey, showSource }) => <section key={key} className="mt-2 break-inside-avoid">{heading && <h4 className="font-bold">{heading}</h4>}<EditableResponseTable rows={rows} empty={empty} onChange={onResponseSectionsEdit ? (value) => onResponseSectionsEdit(emptyKey, value) : null} />{showSource && <p className="mt-1 text-right text-xs italic text-[#0070c0]">Source: DROMIS</p>}</section>)}
      <footer className="mt-auto border-t border-slate-500 pt-2 text-[11px] leading-tight text-slate-500"><div className="text-right">DSWD Field Office Caraga {reportHeading}<br />As of {formatDateTime(m.as_of)}<br />Page 2 of {totalPreviewPages}</div></footer>
    </article>
    {responseContinuationPages.map(({ sections }, pageIndex) => { const pageNumber = pageIndex + 3; return <article key={`response-${pageNumber}`} className="mx-auto flex h-[297mm] max-h-[297mm] w-full max-w-[210mm] flex-col overflow-hidden border border-slate-300 bg-white px-9 pb-6 pt-7 text-justify font-sans text-[14px] leading-[1.55] text-slate-950 shadow-sm sm:px-14">
      <header className="border-b-2 border-slate-500 pb-2"><div className="flex h-20 items-center justify-start gap-3 overflow-hidden"><img src="/images/dromic-header-dswd.png" alt="DSWD Field Office Caraga" className="h-16 w-auto object-contain" /><img src="/images/dromic-logo.png" alt="DROMIC" className="h-12 w-auto object-contain" /><img src="/images/dromic-header-bagong.png" alt="Bagong Pilipinas" className="h-16 w-auto object-contain" /></div></header>
      {sections.map(({ key, heading, rows, empty, emptyKey, showSource }) => <section key={key} className="mt-2 break-inside-avoid">{heading && <h4 className="font-bold">{heading}</h4>}<EditableResponseTable rows={rows} empty={empty} onChange={onResponseSectionsEdit ? (value) => onResponseSectionsEdit(emptyKey, value) : null} />{showSource && <p className="mt-1 text-right text-xs italic text-[#0070c0]">Source: DROMIS</p>}</section>)}
      <footer className="mt-auto border-t border-slate-500 pt-2 text-[11px] leading-tight text-slate-500"><div className="text-right">DSWD Field Office Caraga {reportHeading}<br />As of {formatDateTime(m.as_of)}<br />Page {pageNumber} of {totalPreviewPages}</div></footer>
    </article>})}    {annexPages.map((annexPage, annexPageIndex) => <article key={annexPageIndex} className="mx-auto flex h-[297mm] max-h-[297mm] w-full max-w-[210mm] flex-col overflow-hidden border border-slate-300 bg-white px-9 pb-6 pt-7 text-justify font-sans text-[12px] leading-[1.45] text-slate-950 shadow-sm sm:px-14">
      <header className="border-b-2 border-slate-500 pb-2">
            <div className="flex h-20 items-center justify-start gap-3 overflow-hidden">
          <img src="/images/dromic-header-dswd.png" alt="DSWD Field Office Caraga" className="h-16 w-auto object-contain" />
          <img src="/images/dromic-logo.png" alt="DROMIC" className="h-12 w-auto object-contain" />
          <img src="/images/dromic-header-bagong.png" alt="Bagong Pilipinas" className="h-16 w-auto object-contain" />
        </div>
      </header>
      {annexPageIndex === 0 && <h2 className="text-center text-lg font-bold text-[#0a2a66]">Annexes</h2>}
    {annexPage.items.map(([key, table, continued, finalChunk], index) => {
      const letter = annexLetters[key] || String.fromCharCode(65 + index);
      return (
        <section key={`${key}-${annexPageIndex}-${index}`} className="mt-4">
          <h3 className="text-base font-bold text-[#0a2a66]">Annex {letter}. {annexTitleCase(table.title)}{continued ? " (Continued)" : ""}</h3>
          {key === "photo" ? (
            <p className="mt-2">Photo documentation is included when attached to the selected validated LGU reports.</p>
          ) : (
            <>
            <div className="mt-2 overflow-hidden">
              <table className={`w-full border-collapse ${["age_sex", "sectoral"].includes(key) ? "table-fixed text-[5px] leading-tight" : "text-[10px]"}`}>
                {["age_sex", "sectoral"].includes(key) && <colgroup><col style={{ width: "14%" }} />{Object.keys(table.columns).map((column) => <col key={column} />)}</colgroup>}
                <thead><OfficialAnnexHeader type={key} compact={["age_sex", "sectoral"].includes(key)} /></thead>
                <tbody>{table.rows.filter((row) => row.level !== "barangay").map((row, rowIndex) => <tr key={rowIndex} className={row.level === "region" || row.level === "province" ? "bg-blue-50 font-bold" : ""}><td className={`overflow-hidden whitespace-normal break-words border border-slate-300 ${["age_sex", "sectoral"].includes(key) ? "px-1 py-1" : "p-2"}`}>{row.label}</td>{Object.values(row.values).map((value, valueIndex) => <td key={valueIndex} className={`overflow-hidden border border-slate-300 text-right ${["age_sex", "sectoral"].includes(key) ? "px-0.5 py-1 text-center" : "p-2"}`}>{key === "assistance" && value != null ? `₱${number(value, true)}` : number(value)}</td>)}</tr>)}</tbody>
              </table>
            </div>
            {isOngoingReport && finalChunk && <p className="mt-1 text-right text-xs italic text-slate-600">Note: Ongoing assessment and validation</p>}
            </>
          )}
        </section>
      );
    })}
      {annexPageIndex === annexPages.length - 1 && <><p className="mt-4 text-right text-xs italic text-[#0070c0]">{sourceNote()}</p>
      <section className="mt-4 grid grid-cols-1 gap-8 sm:grid-cols-3 sm:gap-7">
        {[
          ["Prepared by:", signaturePeople.prepared],
          ["Recommended for Approval:", signaturePeople.recommended],
          ["Approved by:", signaturePeople.approved],
        ].map(([label, person]) => (
          <div key={label}>
            <p className="text-[14px]">{label}</p>
            <div className="h-12" />
            <p className="font-bold uppercase leading-tight">{person.name || "—"}</p>
            <p className="leading-tight">{person.position || ""}</p>
          </div>
        ))}
      </section></>}
      <footer className="mt-auto border-t border-slate-500 pt-2 text-[11px] leading-tight text-slate-500"><div className="text-right">DSWD Field Office Caraga {reportHeading}<br />As of {formatDateTime(m.as_of)}<br />Page {annexPageIndex + responseContinuationPages.length + 3} of {totalPreviewPages}</div></footer>
    </article>)}
    </div>
  );
}

export default function Dromic({
  reports,
  eligibleReports = [],
  tableDefinitions,
  cccmColumns,
  canEditReports = false,
  signatories = {},
  standbyStockpileSummary = {},
  fniReleases = [],
}) {
  const [step, setStep] = useState(0);
  const [search, setSearch] = useState("");
  const [validation, setValidation] = useState("all");
  const [tab, setTab] = useState("affected");
  const [insideTab, setInsideTab] = useState("main");
  const [assistanceTab, setAssistanceTab] = useState("main");
  const [opened, setOpened] = useState(null);
  const [editSection, setEditSection] = useState(null);
  const [editValues, setEditValues] = useState(null);
  const [editReason, setEditReason] = useState("");
  const [editVersion, setEditVersion] = useState("");
  const [editHistory, setEditHistory] = useState([]);
  const [editError, setEditError] = useState("");
  const [savingEdit, setSavingEdit] = useState(false);
  const [reviewEdits, setReviewEdits] = useState({});
  const [reviewEditor, setReviewEditor] = useState(null);
  const [fniReleaseSearch, setFniReleaseSearch] = useState("");
  const [fniReleasePage, setFniReleasePage] = useState(1);
  const [fniReleaseDetail, setFniReleaseDetail] = useState(null);
  const [showFniSelectionSummary, setShowFniSelectionSummary] = useState(false);
  const [fniRecipientFilter, setFniRecipientFilter] = useState([]);
  const [fniPurposeFilter, setFniPurposeFilter] = useState([]);
  const [fniDateFrom, setFniDateFrom] = useState("");
  const [fniDateTo, setFniDateTo] = useState("");
  const [autoTitle, setAutoTitle] = useState("");
  const dialogRef = useRef(null);
  useEffect(() => {
    if (opened && dialogRef.current && !dialogRef.current.open) {
      dialogRef.current.showModal();
    }
  }, [opened]);
  const form = useForm({
    source_ids: [],
    title: "",
    report_type: "initial",
    progress_number: 1,
    as_of: localNow(),
    relationship: "",
    overview: "",
    narrative_edits: {},
    section_photos: { section_i: null, section_ii: null },
    response_sections: { idpp: [], cccm: [], ect: [], other: [] },
    review_edits: {},
    overlap_reviewed: false,
  });
  const selected = eligibleReports.filter((s) =>
    form.data.source_ids.includes(s.id),
  );
  const baseTables = aggregate(selected, tableDefinitions);
  const reviewAgeSexTable = demographicReviewTable(selected, "age_sex");
  const reviewSectoralTable = demographicReviewTable(selected, "sectoral");
  const barangaysByLocation = selected.reduce((locations, source) => {
    const key = `${source.province}|${source.municipality}`;
    locations[key] = [...new Set([...(locations[key] || []), ...(source.barangays || [])])];
    return locations;
  }, {});
  useEffect(() => {
    const suggestion = suggestReportTitle(selected);
    if (!form.data.title || form.data.title === autoTitle) {
      form.setData("title", suggestion);
    }
    setAutoTitle(suggestion);
  }, [form.data.source_ids.join(",")]);
  const filtered = eligibleReports.filter(
    (s) =>
      (validation === "all" ||
        (validation === "validated"
          ? s.validation === "validated_no_findings"
          : s.validation !== "validated_no_findings")) &&
      `${s.reference} ${s.incident} ${s.province} ${s.municipality}`
        .toLowerCase()
        .includes(search.toLowerCase()),
  );
  const incidentCount = new Set(selected.map((s) => s.incident_key)).size;
  const contextualFniReleases = fniReleases.flatMap((release) => {
    const allocations = Array.isArray(release.incident_allocations) ? release.incident_allocations : [];
    if (!allocations.length && (release.linked_incident_references || []).length > 1) return [];
    const allocationTotal = allocations.reduce((total, allocation) => total + Number(allocation.quantity || 0), 0);
    const matchedAllocations = allocations.flatMap((allocation) => {
      const source = selected.find((candidate) => allocation.source_reference && String(candidate.reference).toLowerCase() === String(allocation.source_reference).toLowerCase())
        || selected.find((candidate) => allocation.series_key && (String(candidate.series).toLowerCase() === String(allocation.series_key).toLowerCase() || String(candidate.series).toLowerCase().endsWith(String(allocation.series_key).toLowerCase())));
      if (!source || allocationTotal <= 0) return [];
      return [{ ...release, id: `${release.id}-${source.id}`, transaction_id: release.id, quantity: Number(allocation.released_quantity || 0), cost: Number(allocation.released_cost || 0), source_id: source.id, province: source.province, municipality: source.municipality }];
    });
    if (matchedAllocations.length) return matchedAllocations;
    const linkedSource = selected.find((source) => (release.source_report_ids || []).map(Number).includes(Number(source.id)))
      || selected.find((source) => release.incident_id && Number(source.incident_id) === Number(release.incident_id));
    return linkedSource ? [{ ...release, source_id: linkedSource.id, province: linkedSource.province, municipality: linkedSource.municipality }] : [];
  });
  const detectedFniMatches = new Map(contextualFniReleases.flatMap((release) => {
    const withinCutoff = !release.date || release.date <= String(form.data.as_of || "").slice(0, 10);
    return release.source_id && withinCutoff ? [[release.id, { sourceId: release.source_id }]] : [];
  }));
  const detectedFniReleases = contextualFniReleases.filter((release) => detectedFniMatches.has(release.id));
  const unallocatedMultiIncidentReleases = fniReleases.filter((release) => {
    if ((release.incident_allocations || []).length) return false;
    const references = (release.linked_incident_references || []).map((value) => String(value).toLowerCase());
    return selected.some((source) => references.includes(String(source.reference).toLowerCase()) || references.includes(String(source.series).toLowerCase()));
  });
  const confirmedFniReleaseMap = Object.fromEntries([...detectedFniMatches].map(([releaseId, match]) => [releaseId, match.sourceId]));
  const baseCccmTable = { title: "DSWD CCCM & IDPP interventions", columns: cccmColumns, rows: baseTables.affected.rows.map((row) => ({ ...row, values: Object.fromEntries(Object.keys(cccmColumns).map((column) => [column, null])) })) };
  const reviewedTables = applyReviewEdits({ ...baseTables, cccm: baseCccmTable }, reviewEdits);
  const tables = applyDswdAssistance(reviewedTables, confirmedFniReleaseMap, contextualFniReleases, selected);
  const { cccm: cccmReviewTable, ...reportTables } = tables;
  const snapshot = { sources: selected, tables: reportTables, cccm: cccmReviewTable, metadata: { ...form.data, title: normalizeReportTitle(form.data.title) }, dswd_assistance_releases: detectedFniReleases.map((release) => ({ ...release, source_id: Number(confirmedFniReleaseMap[release.id]) })) };
  const matchesFniContext = (release) => {
    const needle = fniReleaseSearch.trim().toLowerCase();
    const haystack = `${release.reference} ${release.item} ${release.recipient} ${release.delivery_site} ${release.purpose} ${release.record_source}`.toLowerCase();
    return detectedFniMatches.has(release.id) && (!needle || haystack.includes(needle));
  };
  const matchingFniReleases = contextualFniReleases.filter((release) => {
    const recipient = String(release.recipient || release.delivery_site || "");
    return matchesFniContext(release) && (!fniRecipientFilter.length || fniRecipientFilter.includes(recipient)) && (!fniPurposeFilter.length || fniPurposeFilter.includes(String(release.purpose || ""))) && (!fniDateFrom || release.date >= fniDateFrom) && (!fniDateTo || release.date <= fniDateTo);
  });
  const recipientFacetRows = contextualFniReleases.filter((release) => matchesFniContext(release) && (!fniPurposeFilter.length || fniPurposeFilter.includes(String(release.purpose || ""))) && (!fniDateFrom || release.date >= fniDateFrom) && (!fniDateTo || release.date <= fniDateTo));
  const purposeFacetRows = contextualFniReleases.filter((release) => matchesFniContext(release) && (!fniRecipientFilter.length || fniRecipientFilter.includes(String(release.recipient || release.delivery_site || ""))) && (!fniDateFrom || release.date >= fniDateFrom) && (!fniDateTo || release.date <= fniDateTo));
  const fniRecipientOptions = [...new Set([...recipientFacetRows.map((release) => release.recipient || release.delivery_site).filter(Boolean), ...fniRecipientFilter])].sort((a, b) => a.localeCompare(b));
  const fniPurposeOptions = [...new Set([...purposeFacetRows.map((release) => release.purpose).filter(Boolean), ...fniPurposeFilter])].sort((a, b) => a.localeCompare(b));
  const dateFacetRows = contextualFniReleases.filter((release) => matchesFniContext(release) && (!fniRecipientFilter.length || fniRecipientFilter.includes(String(release.recipient || release.delivery_site || ""))) && (!fniPurposeFilter.length || fniPurposeFilter.includes(String(release.purpose || ""))));
  const availableFniDates = dateFacetRows.map((release) => release.date).filter(Boolean).sort();
  const fniDateMin = availableFniDates[0];
  const fniDateMax = availableFniDates.at(-1);
  const selectedFniReleases = detectedFniReleases;
  const selectedFniGroups = dynamicFniGroups(selectedFniReleases);
  const selectedFniSummary = Object.values(selectedFniReleases.reduce((groups, release) => {
    const key = [release.item, release.brand, release.unit].map((value) => String(value || "").trim().toLowerCase()).join("|");
    if (!groups[key]) groups[key] = { item: release.item || "Unspecified item", brand: release.brand || "", unit: release.unit || "", quantity: 0, cost: 0 };
    groups[key].quantity += Number(release.quantity || 0);
    groups[key].cost += Number(release.cost || 0);
    return groups;
  }, {})).sort((a, b) => a.item.localeCompare(b.item) || a.brand.localeCompare(b.brand));
  const selectedFniQuantity = selectedFniSummary.reduce((total, item) => total + item.quantity, 0);
  const selectedFniCost = selectedFniSummary.reduce((total, item) => total + item.cost, 0);
  const fniReleasePageSize = 8;
  const fniReleasePageCount = Math.max(1, Math.ceil(matchingFniReleases.length / fniReleasePageSize));
  const visibleFniReleases = matchingFniReleases.slice((fniReleasePage - 1) * fniReleasePageSize, fniReleasePage * fniReleasePageSize);
  const overlapping = selected.some((s, i) =>
    selected.some(
      (other, j) =>
        i !== j &&
        s.province === other.province &&
        s.municipality === other.municipality,
    ),
  );
  const toggle = (s) => {
    const ids = form.data.source_ids.includes(s.id)
      ? form.data.source_ids.filter((id) => id !== s.id)
      : [
          ...form.data.source_ids.filter(
            (id) =>
              eligibleReports.find((r) => r.id === id)?.series !== s.series,
          ),
          s.id,
        ];
    form.setData({ ...form.data, source_ids: ids, overlap_reviewed: false, review_edits: {} });
    setReviewEdits({});
    setReviewEditor(null);
  };
  const toggleFilteredSources = (reportsToToggle, checked) => {
    const visibleIds = new Set(reportsToToggle.map((report) => report.id));
    let ids = form.data.source_ids.filter((id) => !visibleIds.has(id));
    if (checked) {
      const visibleSeries = new Set(reportsToToggle.map((report) => report.series));
      ids = ids.filter((id) => !visibleSeries.has(eligibleReports.find((report) => report.id === id)?.series));
      ids = [...ids, ...reportsToToggle.map((report) => report.id)];
    }
    form.setData({ ...form.data, source_ids: [...new Set(ids)], overlap_reviewed: false, review_edits: {} });
    setReviewEdits({});
    setReviewEditor(null);
  };
  const beginReviewEdit = (section) => {
    const table = tables[section];
    setReviewEditor({ section, reason: reviewEdits[section]?.reason || "", error: "", rows: table.rows.map((row, index) => ({ index, values: { ...row.values } })).filter(({ index }) => ["municipality", "category"].includes(table.rows[index].level)) });
  };
  const saveReviewEdit = () => {
    if (!reviewEditor.reason.trim()) return setReviewEditor({ ...reviewEditor, error: "Enter the reason for this adjustment." });
    const nextEdits = { ...reviewEdits, [reviewEditor.section]: { rows: reviewEditor.rows, reason: reviewEditor.reason.trim() } };
    setReviewEdits(nextEdits);
    form.setData("review_edits", nextEdits);
    setReviewEditor(null);
  };
  const changeReviewCell = (rowIndex, column, value) => setReviewEditor((editor) => ({
    ...editor,
    error: "",
    rows: editor.rows.map((row) => row.index === rowIndex ? { ...row, values: { ...row.values, [column]: value } } : row),
  }));
  const reviewControls = (section) => reviewEditor?.section === section && <div className="mt-3 flex flex-col gap-3 rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 sm:flex-row sm:items-end">
    <label className="flex-1 text-sm font-medium">Reason for adjustment (required)<textarea rows={2} className={`${input} mt-1`} placeholder="State the validation finding or source correction" value={reviewEditor.reason} onChange={(event) => setReviewEditor({ ...reviewEditor, reason: event.target.value, error: "" })} /></label>
    <div className="flex shrink-0 gap-2"><button type="button" className={secondary} onClick={() => setReviewEditor(null)}>Cancel</button><button type="button" className={primary} onClick={saveReviewEdit}>Apply adjustment</button></div>
    {reviewEditor.error && <p className="text-sm font-semibold text-red-700 sm:basis-full">{reviewEditor.error}</p>}
  </div>;
  const startNarrative = () => {
    if (!form.data.overview)
      form.setData(
        "overview",
        cleanOverview(selected
          .filter((s) => s.narrative)
          .map((s) => s.narrative)
          .join("\n\n"), selected),
      );
    setStep(2);
  };
  const totals = tables.affected.rows[0].values;
  const openSavedReport = async (report) => {
    setOpened(report);
    setEditSection(null);
    setEditError("");
    try {
      const response = await fetch(`/dromic/reports/${report.id}`, {
        headers: { Accept: "application/json" },
      });
      if (response.ok) {
        const payload = await response.json();
        setOpened({
          ...payload.report,
          response_actions: payload.response_actions,
        });
        setEditVersion(payload.version);
        setEditHistory(payload.history || []);
      }
    } catch (error) {
      setEditError(
        "Unable to load the report editing controls. The saved report remains available.",
      );
    }
  };
  const beginSectionEdit = (section) => {
    const table =
      section === "overview"
        ? null
        : opened.consolidation?.[section === "cccm" ? "cccm" : "tables"]?.[
            section
          ];
    setEditSection(section);
    setEditReason("");
    setEditError("");
    setEditValues(
      section === "overview"
        ? {
            overview: opened.consolidation.metadata.overview || "",
            response_actions:
              opened.response_actions ||
              opened.consolidation.metadata.response_actions ||
              "",
          }
        : {
            rows: (table?.rows || [])
              .map((row, index) => ({ index, values: { ...row.values } }))
              .filter((row) => table.rows[row.index]?.level === "municipality"),
          },
    );
  };
  const saveSectionEdit = async () => {
    if (!editReason.trim()) {
      setEditError("Enter a reason for this edit before saving.");
      return;
    }
    setSavingEdit(true);
    setEditError("");
    try {
      const response = await fetch(`/dromic/reports/${opened.id}/sections`, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-CSRF-TOKEN":
            document.querySelector('meta[name="csrf-token"]')?.content || "",
        },
        body: JSON.stringify({
          section: editSection,
          reason: editReason,
          version: editVersion,
          values: editValues,
        }),
      });
      const payload = await response.json();
      if (!response.ok) {
        setEditError(
          Object.values(payload.errors || {})
            .flat()
            .join(" ") || "The edit could not be saved.",
        );
        return;
      }
      setOpened({
        ...payload.report,
        response_actions: payload.response_actions,
      });
      setEditVersion(payload.version);
      setEditHistory(payload.history || []);
      setEditSection(null);
      setEditReason("");
    } catch (error) {
      setEditError(
        "The edit could not be saved. Check your connection and try again.",
      );
    } finally {
      setSavingEdit(false);
    }
  };
  return (
    <AppLayout title="DSWD DROMIC Reporting">
      <Head title="DSWD DROMIC Reporting" />
      <div className="space-y-6 text-slate-800">
        <section className="rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-950 via-slate-900 to-teal-950 p-6 text-white shadow-lg sm:p-8">
          <div className="flex flex-wrap items-start justify-between gap-5">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-200">
                Regional disaster reporting
              </p>
              <h1 className="mt-2 text-2xl font-bold sm:text-3xl">
                DSWD DROMIC Reporting
              </h1>
              <p className="mt-2 max-w-2xl text-sm text-cyan-100">
                Bring LGU situation reports together into one regional picture.
                Review the figures, prepare the narrative, and keep a record of
                every source.
              </p>
            </div>
            <a
              href="#dromic-list"
              className="rounded-lg border border-white/30 px-4 py-2 text-sm font-semibold"
            >
              View saved reports
            </a>
          </div>
          <div className="mt-6 flex flex-wrap gap-x-8 gap-y-3 text-sm">
            <span>
              <strong className="text-xl">{eligibleReports.length}</strong>{" "}
              received reports available
            </span>
            <span>
              <strong className="text-xl">
                {
                  eligibleReports.filter(
                    (s) => s.validation === "validated_no_findings",
                  ).length
                }
              </strong>{" "}
              validated
            </span>
            <span>
              <strong className="text-xl">{reports.total}</strong> saved DSWD
              reports
            </span>
          </div>
        </section>
        <section
          id="dromic-create"
          className="scroll-mt-28 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
        >
          <div className="grid grid-cols-3 border-b bg-slate-50">
            {[
              "Select LGU reports",
              "Review DROMIC tables",
              "Prepare narrative",
            ].map((label, i) => (
              <button
                key={label}
                type="button"
                disabled={i > 0 && !selected.length}
                onClick={() => (i === 2 ? startNarrative() : setStep(i))}
                className={`flex items-center gap-2 px-3 py-4 text-left text-xs font-semibold sm:px-6 sm:text-sm ${step === i ? "border-b-2 border-emerald-700 bg-emerald-50 text-emerald-800" : "text-slate-500"}`}
              >
                <span
                  className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${step === i ? "bg-emerald-700 text-white" : "bg-slate-200"}`}
                >
                  {i + 1}
                </span>
                {label}
              </button>
            ))}
          </div>
          <div className="p-4 sm:p-6">
            {Object.keys(form.errors).length > 0 && (
              <div
                role="alert"
                className="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"
              >
                {Object.entries(form.errors).map(([k, v]) => (
                  <p key={k}>{v}</p>
                ))}
              </div>
            )}
            {step === 0 && (
              <div className="space-y-3">
                <div>
                  <h2 className="text-lg font-bold">
                    Select your source reports
                  </h2>
                  <p className="mt-1 text-sm text-slate-500">
                    Latest submitted report per LGU incident. Earlier reports
                    are superseded.
                  </p>
                  <div className="my-3 flex flex-wrap gap-2">
                    <label className="relative min-w-56 flex-1">
                      <Search
                        size={17}
                        className="absolute left-3 top-3 text-slate-400"
                      />
                      <input
                        aria-label="Search LGU reports"
                        className={`${input} pl-9`}
                        placeholder="Search incident, LGU, province or report…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                      />
                    </label>
                    <select
                      aria-label="Filter validation status"
                      className={`${input} sm:w-48`}
                      value={validation}
                      onChange={(e) => setValidation(e.target.value)}
                    >
                      <option value="all">All received reports</option>
                      <option value="validated">Validated reports</option>
                      <option value="received">Awaiting validation</option>
                    </select>
                  </div>
                  <div>
                    {!!filtered.length && (
                      <DromicSourceTable
                        reports={filtered}
                        selectedIds={form.data.source_ids}
                        onToggle={toggle}
                        onToggleAll={toggleFilteredSources}
                      />
                    )}
                    {!filtered.length && (
                      <div className="rounded-xl border border-dashed p-10 text-center">
                        <FileText className="mx-auto mb-3 text-slate-400" />
                        <p className="font-semibold">No matching LGU reports</p>
                        <p className="mt-2 text-sm text-slate-500">
                          Received reports appear here after submission. Reports
                          requiring corrections are excluded.
                        </p>
                        <Link
                          href="/dromic/lgu-reports"
                          className="mt-4 inline-block text-sm font-semibold text-emerald-700"
                        >
                          Open LGU report intake →
                        </Link>
                      </div>
                    )}
                  </div>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-100 bg-emerald-50/50 px-3 py-2">
                  <div className="flex flex-wrap items-center gap-3 text-xs">
                    <span className="font-semibold text-emerald-900">
                      {selected.length} selected &middot; {incidentCount}{" "}
                      incident series
                    </span>
                    {!!selected.length && (
                      <button
                        className="text-slate-500 underline hover:text-slate-800"
                        onClick={() =>
                          form.setData({
                            ...form.data,
                            source_ids: [],
                            overlap_reviewed: false,
                          })
                        }
                      >
                        Clear selection
                      </button>
                    )}
                    <span className="text-slate-500">
                      {filtered.length} latest reports shown
                    </span>
                  </div>
                  <button
                    disabled={!selected.length}
                    className={primary}
                    onClick={() => setStep(1)}
                  >
                    Create DSWD DROMIC Report <ArrowRight size={16} />
                  </button>
                </div>
              </div>
            )}
            {step === 1 && (
              <div className="space-y-6">
                <div>
                  <h2 className="text-lg font-bold">
                    Define the report and review consolidated data
                  </h2>
                  <p className="mt-1 text-sm text-slate-500">
                    Geographic totals follow the DROMIC annex structure. CUM is
                    cumulative; NOW is current.
                  </p>
                </div>
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                  <label className="text-sm font-medium md:col-span-2">
                    Incident / consolidated report title
                    <input
                      className={`${input} mt-1`}
                      placeholder="e.g. Effects of Shearline in Caraga Region"
                      value={form.data.title}
                      onChange={(e) => form.setData("title", e.target.value)}
                    />
                    {autoTitle && form.data.title === autoTitle && (
                      <span className="mt-1 block text-xs font-normal text-emerald-700">
                        Suggested from the latest selected LGU report. You can
                        edit it before continuing.
                      </span>
                    )}
                  </label>
                  <label className="text-sm font-medium">
                    Reporting type
                    <select
                      className={`${input} mt-1`}
                      value={form.data.report_type}
                      onChange={(e) =>
                        form.setData("report_type", e.target.value)
                      }
                    >
                      {Object.entries(types).map(([k, v]) => (
                        <option key={k} value={k}>
                          {v}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="text-sm font-medium">
                    Reporting cutoff
                    <input
                      type="datetime-local"
                      className={`${input} mt-1`}
                      value={form.data.as_of}
                      onChange={(e) => form.setData("as_of", e.target.value)}
                    />
                  </label>
                  {form.data.report_type === "progress" && (
                    <label className="text-sm font-medium">
                      Progress report number
                      <input
                        type="number"
                        min="1"
                        className={`${input} mt-1`}
                        value={form.data.progress_number}
                        onChange={(e) =>
                          form.setData("progress_number", e.target.value)
                        }
                      />
                    </label>
                  )}
                  {incidentCount > 1 && (
                    <label className="text-sm font-medium md:col-span-2">
                      How are these incidents related?
                      <input
                        className={`${input} mt-1`}
                        placeholder="Common weather system, disaster event or reporting scope"
                        value={form.data.relationship}
                        onChange={(e) =>
                          form.setData("relationship", e.target.value)
                        }
                      />
                    </label>
                  )}
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                  {[
                    [Users, "Affected families", number(totals.families)],
                    [MapPin, "Affected barangays", number(totals.barangays)],
                    [Layers3, "Source reports", selected.length],
                  ].map(([Icon, label, value]) => (
                    <div
                      key={label}
                      className="flex items-center gap-4 rounded-xl bg-slate-50 p-4"
                    >
                      <Icon size={22} className="text-emerald-700" />
                      <div>
                        <p className="text-xs text-slate-500">{label}</p>
                        <p className="text-xl font-bold">{value}</p>
                      </div>
                    </div>
                  ))}
                </div>
                <div
                  className="flex flex-wrap gap-2"
                  role="tablist"
                  aria-label="DROMIC tables"
                >
                  {Object.entries({
                    affected: "Affected",
                    inside: "Inside ECs",
                    outside: "Outside ECs",
                    displaced: "Total displaced",
                    houses: "Damaged houses",
                    assistance: "Assistance",
                    cccm: "CCCM & IDPP",
                  }).map(([key, label]) => (
                    <button
                      role="tab"
                      aria-selected={tab === key}
                      key={key}
                      onClick={() => {
                        setTab(key);
                        if (key === "inside") setInsideTab("main");
                        if (key === "assistance") setAssistanceTab("main");
                      }}
                      className={`rounded-lg px-3 py-2 text-xs font-semibold ${tab === key ? "bg-emerald-700 text-white" : "bg-slate-100 text-slate-600"}`}
                    >
                      {label}
                    </button>
                  ))}
                </div>
                {tab === "inside" && <div className="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2" role="tablist" aria-label="Inside evacuation centers tables">
                  {[
                    ["main", "Main Inside ECs"],
                    ["age_sex", "Sex and Age Distribution of IDPs Inside ECs"],
                    ["sectoral", "Sectoral Distribution of IDPs Inside ECs"],
                  ].map(([key, label]) => <button key={key} type="button" role="tab" aria-selected={insideTab === key} onClick={() => setInsideTab(key)} className={`rounded-lg px-3 py-2 text-xs font-semibold transition ${insideTab === key ? "bg-[#0a2f6b] text-white shadow-sm" : "bg-white text-slate-700 hover:bg-slate-100"}`}>{label}</button>)}
                </div>}
                {tab === "assistance" && <div className="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2" role="tablist" aria-label="Assistance tables">
                  {[
                    ["main", "Main Assistance View"],
                    ["food", "Family Food Packs and Food Items"],
                    ["non_food", "Non-Food Items"],
                    ["other_non_food", "Other Non-Food Items and Raw Materials"],
                    ["financial", "Financial Assistance"],
                  ].map(([key, label]) => <button key={key} type="button" role="tab" aria-selected={assistanceTab === key} onClick={() => setAssistanceTab(key)} className={`rounded-lg px-3 py-2 text-xs font-semibold transition ${assistanceTab === key ? "bg-[#0a2f6b] text-white shadow-sm" : "bg-white text-slate-700 hover:bg-slate-100"}`}>{label}</button>)}
                </div>}
                {tables[tab] && (tab !== "inside" || insideTab === "main") && (tab !== "assistance" || assistanceTab === "main") && (
                  <p className="text-xs text-slate-500 sm:hidden">
                    Swipe the table horizontally to see all figures.
                  </p>
                )}
                {tables[tab] && (tab !== "inside" || insideTab === "main") && (tab !== "assistance" || assistanceTab === "main") && (
                  <div>
                    {tab === "assistance" && <div className="mb-2 flex justify-end"><button type="button" className={secondary} onClick={() => beginReviewEdit("assistance")}><Pencil size={15} /> Edit assistance data</button></div>}
                    <DromicTable table={tables[tab]} money={tab === "assistance"} section={tab} includeBarangays={tab !== "assistance"} editing={reviewEditor?.section === tab} onCellChange={changeReviewCell} />
                    {reviewControls(tab)}
                  </div>
                )}
                {tab === "assistance" && assistanceTab === "main" && <section className="rounded-xl border border-sky-200 bg-sky-50/50 p-4">
                  <div><h3 className="font-bold text-slate-950">Confirmed DSWD assistance from RROS releases</h3><p className="mt-1 text-xs text-slate-600">The system automatically displays confirmed releases linked through the selected LGU report, DSWD assessment, approved RIS/DR, and dispatch record.</p></div>
                  <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3"><p className="text-sm font-bold text-emerald-950">{detectedFniReleases.length} confirmed release record{detectedFniReleases.length === 1 ? "" : "s"} linked automatically</p><p className="mt-0.5 text-xs text-emerald-800">Only releases with an exact system workflow link to the selected reports or incidents and dated on or before the report cutoff are included.</p></div>
                  {unallocatedMultiIncidentReleases.length > 0 && <div className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3"><p className="text-sm font-bold text-amber-950">{unallocatedMultiIncidentReleases.length} combined release record{unallocatedMultiIncidentReleases.length === 1 ? " needs" : "s need"} an incident breakdown</p><p className="mt-0.5 text-xs text-amber-900">The originating DRRS assessment combined multiple incidents without allocating each FNI. Return that assessment to draft, enter the FNI Breakdown per Separate Incident, and complete the workflow again. These ambiguous quantities are excluded until corrected.</p></div>}
                  <input className={`${input} mt-3`} value={fniReleaseSearch} onChange={(event) => { setFniReleaseSearch(event.target.value); setFniReleasePage(1); }} placeholder="Search reference, incident purpose, item, recipient or delivery site" />
                  <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <LookerMultiSelect label="Recipient" allLabel="All recipients" options={fniRecipientOptions.map((recipient) => ({ value: recipient, label: recipient }))} value={fniRecipientFilter} onApply={(values) => { setFniRecipientFilter(values); setFniReleasePage(1); }} placeholder="Search recipients..." />
                    <LookerMultiSelect label="Purpose" allLabel="All purposes" options={fniPurposeOptions.map((purpose) => ({ value: purpose, label: purpose }))} value={fniPurposeFilter} onApply={(values) => { setFniPurposeFilter(values); setFniReleasePage(1); }} placeholder="Search purposes..." />
                    <label className="text-xs font-semibold">From date<input type="date" min={fniDateMin} max={fniDateTo || fniDateMax} className={`${input} mt-1`} value={fniDateFrom} onChange={(event) => { setFniDateFrom(event.target.value); setFniReleasePage(1); }} /></label>
                    <label className="text-xs font-semibold">To date<input type="date" min={fniDateFrom || fniDateMin} max={fniDateMax} className={`${input} mt-1`} value={fniDateTo} onChange={(event) => { setFniDateTo(event.target.value); setFniReleasePage(1); }} /></label>
                  </div>
                  <div className="mt-3 overflow-x-auto rounded-lg border border-slate-200 bg-white">
                    <table className="w-full min-w-[850px] border-collapse text-sm [&_td]:border [&_td]:border-slate-200"><thead className="bg-[#0a2f6b] text-xs uppercase text-white"><tr><th className="border border-slate-300 px-3 py-3 text-center">#</th><th className="border border-slate-300 px-3 py-3 text-center">Action</th><th className="border border-slate-300 px-3 py-3 text-left">Transaction Date</th><th className="border border-slate-300 px-3 py-3 text-left">Source Warehouse</th><th className="border border-slate-300 px-3 py-3 text-left">Recipient</th><th className="border border-slate-300 px-3 py-3 text-left">Purpose</th><th className="border border-slate-300 px-3 py-3 text-left">Item</th><th className="border border-slate-300 px-3 py-3 text-right">Issuance</th></tr></thead><tbody>
                    {matchingFniReleases.length === 0 && <tr><td colSpan={8} className="p-4 text-center text-sm text-slate-500">No confirmed releases are linked to the selected reports under the current filters.</td></tr>}
                    {visibleFniReleases.map((release, index) => <tr key={release.id} className="border-t bg-emerald-50"><td className="px-3 py-3 text-center font-semibold">{(fniReleasePage - 1) * fniReleasePageSize + index + 1}</td><td className="px-3 py-3 text-center"><button type="button" aria-label={`View ${release.reference}`} className="rounded-md border border-emerald-200 bg-emerald-50 p-2 text-emerald-800" onClick={() => setFniReleaseDetail(release)}><Eye size={15} /></button></td><td className="px-3 py-3 font-semibold">{readableDate(release.date)}</td><td className="px-3 py-3"><span className="font-semibold">{release.warehouse || "—"}</span><span className="block text-xs text-slate-500">{release.warehouse_partnership || ""}</span></td><td className="px-3 py-3">{release.recipient || release.delivery_site || "—"}</td><td className="px-3 py-3">{release.purpose || "—"}<span className="mt-1 block w-fit rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-800">Confirmed workflow match</span></td><td className="px-3 py-3"><span className="font-semibold">{release.item}</span><span className="block text-xs text-slate-500">{release.brand || ""}</span></td><td className="px-3 py-3 text-right font-bold">{number(release.quantity)}</td></tr>)}
                    </tbody></table>
                  </div>
                  <div className="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs"><span>{matchingFniReleases.length} release{matchingFniReleases.length === 1 ? "" : "s"} found</span><div className="flex items-center gap-2"><button type="button" className={secondary} disabled={fniReleasePage <= 1} onClick={() => setFniReleasePage((page) => page - 1)}>Previous</button><span>Page {fniReleasePage} of {fniReleasePageCount}</span><button type="button" className={secondary} disabled={fniReleasePage >= fniReleasePageCount} onClick={() => setFniReleasePage((page) => page + 1)}>Next</button></div></div>
                  <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-sky-900"><p className="font-semibold">Confirmed DSWD assistance: ₱{number(selectedFniCost, true)}</p><button type="button" className={secondary} disabled={!selectedFniReleases.length} onClick={() => setShowFniSelectionSummary(true)}>Show summary</button></div>
                </section>}
                {tab === "assistance" && assistanceTab === "food" && <AssistanceItemTables groups={selectedFniGroups} types={["Family Food Packs / Food Items"]} emptyMessage="No confirmed Family Food Packs, Food Items, or Other Food Items are available." />}
                {tab === "assistance" && assistanceTab === "non_food" && <AssistanceItemTables groups={selectedFniGroups} types={["Non-Food Items"]} emptyMessage="No confirmed Non-Food Items are available." />}
                {tab === "assistance" && assistanceTab === "other_non_food" && <AssistanceItemTables groups={selectedFniGroups} types={["Other Non-Food Items", "Raw Materials"]} emptyMessage="No confirmed Other Non-Food Items or Raw Materials are available." />}
                {tab === "assistance" && assistanceTab === "financial" && <FinancialAssistanceTable sources={selected} fniReleases={selectedFniReleases} />}
                {showFniSelectionSummary && <div className="fixed inset-0 z-[260] flex items-center justify-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-label="Confirmed DSWD assistance summary"><div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white shadow-2xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b bg-slate-50 px-5 py-4"><div><p className="text-xs font-black uppercase text-emerald-700">Confirmed FNI releases</p><h3 className="text-xl font-bold">DSWD assistance summary</h3></div><button type="button" aria-label="Close summary" className="rounded-md p-2 hover:bg-slate-200" onClick={() => setShowFniSelectionSummary(false)}><X size={20} /></button></div><div className="p-5"><div className="grid gap-3 sm:grid-cols-4"><div className="rounded-lg bg-slate-100 p-3"><p className="text-xs font-bold uppercase text-slate-500">Confirmed release records</p><p className="mt-1 text-xl font-black">{number(selectedFniReleases.length)}</p></div><div className="rounded-lg bg-slate-100 p-3"><p className="text-xs font-bold uppercase text-slate-500">Distinct items</p><p className="mt-1 text-xl font-black">{number(selectedFniSummary.length)}</p></div><div className="rounded-lg bg-slate-100 p-3"><p className="text-xs font-bold uppercase text-slate-500">Total quantity issued</p><p className="mt-1 text-xl font-black">{number(selectedFniQuantity)}</p></div><div className="rounded-lg bg-emerald-50 p-3"><p className="text-xs font-bold uppercase text-emerald-700">Total assistance cost</p><p className="mt-1 text-xl font-black text-emerald-900">₱{number(selectedFniCost, true)}</p></div></div><div className="mt-5 overflow-x-auto rounded-lg border border-slate-200"><table className="w-full min-w-[600px] border-collapse text-sm [&_td]:border [&_td]:border-slate-200 [&_tfoot_td]:border-slate-300"><thead className="bg-[#0a2f6b] text-xs uppercase text-white"><tr><th className="border border-slate-300 px-4 py-3 text-left">Item</th><th className="border border-slate-300 px-4 py-3 text-left">Unit</th><th className="border border-slate-300 px-4 py-3 text-right">Quantity issued</th><th className="border border-slate-300 px-4 py-3 text-right">Assistance cost</th></tr></thead><tbody>{selectedFniSummary.map((item) => <tr key={`${item.item}|${item.brand}|${item.unit}`} className="border-t"><td className="px-4 py-3"><span className="font-semibold">{item.item}</span>{item.brand && <span className="block text-xs text-slate-500">{item.brand}</span>}</td><td className="px-4 py-3">{item.unit || "—"}</td><td className="px-4 py-3 text-right font-semibold">{number(item.quantity)}</td><td className="px-4 py-3 text-right font-bold">₱{number(item.cost, true)}</td></tr>)}</tbody><tfoot className="border-t-2 border-slate-300 bg-slate-50 font-black"><tr><td className="px-4 py-3" colSpan={2}>Grand total</td><td className="px-4 py-3 text-right">{number(selectedFniQuantity)}</td><td className="px-4 py-3 text-right">₱{number(selectedFniCost, true)}</td></tr></tfoot></table></div></div></div></div>}
                {fniReleaseDetail && <div className="fixed inset-0 z-[260] flex items-center justify-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-label="FNI release details"><div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl bg-white shadow-2xl"><div className="sticky top-0 flex items-center justify-between border-b bg-slate-50 px-5 py-4"><div><p className="text-xs font-black uppercase text-emerald-700">FNI Issuance</p><h3 className="text-xl font-bold">{fniReleaseDetail.reference}</h3></div><button type="button" className="rounded-md p-2 hover:bg-slate-200" onClick={() => setFniReleaseDetail(null)}><X size={20} /></button></div><div className="p-5"><dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2">{[
                  ["Transaction Date", readableDate(fniReleaseDetail.date)], ["Record Source", fniReleaseDetail.record_source], ["Reconciliation Status", fniReleaseDetail.reconciliation_status], ["Source of Goods", fniReleaseDetail.source_of_goods], ["Warehouse", fniReleaseDetail.warehouse], ["Warehouse Type", fniReleaseDetail.warehouse_type], ["Warehouse Partnership", fniReleaseDetail.warehouse_partnership], ["DR Number", fniReleaseDetail.dr_number], ["RIS / TF / STF", fniReleaseDetail.ris_if_stf], ["Call-Off Number", fniReleaseDetail.call_off_number], ["Purpose", fniReleaseDetail.purpose], ["Recipient", fniReleaseDetail.recipient], ["Delivery Site", fniReleaseDetail.delivery_site], ["Expected Delivery Date", readableDate(fniReleaseDetail.expected_delivery_date)], ["Category", fniReleaseDetail.category], ["Item", fniReleaseDetail.item], ["Brand / Specification", fniReleaseDetail.brand], ["Unit", fniReleaseDetail.unit], ["Quantity", number(fniReleaseDetail.quantity)], ["Unit Cost", `₱${number(fniReleaseDetail.unit_cost, true)}`], ["Total Cost", `₱${number(fniReleaseDetail.cost, true)}`], ["Encoded At", fniReleaseDetail.encoded_at], ["Edited At", fniReleaseDetail.edited_at], ["Remarks", fniReleaseDetail.remarks],
                ].map(([label, value]) => <div key={label} className={label === "Remarks" ? "sm:col-span-2" : ""}><dt className="text-xs font-bold uppercase text-slate-500">{label}</dt><dd className="mt-1 text-sm font-semibold text-slate-900">{value || "—"}</dd></div>)}</dl><div className="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900">Automatically included from the confirmed DSWD assessment, RIS/DR, dispatch, and inventory-release workflow.</div></div></div></div>}
                {tab === "inside" && insideTab !== "main" && (
                  <div className="space-y-4">
                    {insideTab === "age_sex" && <section>
                      <div className="mb-2 flex items-center justify-between">
                        <div>
                          <h3 className="font-semibold text-slate-900">
                            Sex and Age Distribution of IDPs Inside ECs
                          </h3>
                          <p className="text-xs text-slate-500">
                            Aggregated from completed evacuation-center
                            disaggregation entries.
                          </p>
                        </div>
                      </div>
                      <DemographicMatrix table={reviewAgeSexTable} type="age_sex" includeEcRows />
                    </section>}
                    {insideTab === "sectoral" && <section>
                      <div className="mb-2 flex items-center justify-between">
                        <div>
                          <h3 className="font-semibold text-slate-900">
                            Sectoral Distribution of IDPs Inside ECs
                          </h3>
                          <p className="text-xs text-slate-500">
                            Includes PWDs, child-headed families, solo parents,
                            4Ps, IPs, pregnant women, and lactating mothers.
                          </p>
                        </div>
                      </div>
                      <DemographicMatrix table={reviewSectoralTable} type="sectoral" includeEcRows />
                    </section>}
                  </div>
                )}
                {tab === "cccm" && (
                  <div className="rounded-xl border border-sky-200 bg-sky-50 p-5">
                    <h3 className="font-semibold">
                      DSWD protection interventions
                    </h3>
                    <p className="mt-2 text-sm">
                      The CCCM & IDPP template records DSWD child/women-friendly
                      spaces, psychological first aid, referrals and protection
                      vehicles. These numeric DSWD service counts are not
                      present in the selected LGU report fields and remain
                      unreported. LGU interventions appear with their source in
                      the situation overview.
                    </p>
                    <a
                      href="https://docs.google.com/spreadsheets/d/1CPVQtc0k_g3muoV0sWI9KyekzBnJwjJTs_ZrkoucXFo/edit#gid=445283078"
                      target="_blank"
                      rel="noreferrer"
                      className="mt-3 inline-block text-sm font-semibold text-sky-800"
                    >
                      View CCCM & IDPP reference ↗
                    </a>
                    <div className="mt-4">
                      <div className="mb-2 flex justify-end"><button type="button" className={secondary} onClick={() => beginReviewEdit("cccm")}><Pencil size={15} /> Edit CCCM &amp; IDPP data</button></div>
                      <DromicTable table={tables.cccm} section="cccm" editing={reviewEditor?.section === "cccm"} onCellChange={changeReviewCell} barangaysByLocation={barangaysByLocation} />
                      {reviewControls("cccm")}
                    </div>
                  </div>
                )}
                {false && reviewEditor && (
                  <section className="rounded-xl border border-emerald-300 bg-emerald-50/40 p-4">
                    <div className="flex items-center justify-between"><div><h3 className="font-bold">Review adjustment: {tables[reviewEditor.section]?.title}</h3><p className="text-xs text-slate-500">Update the LGU figures before preparing the narrative. Regional and provincial totals are recalculated automatically.</p></div><button type="button" className="text-sm text-slate-500 underline" onClick={() => setReviewEditor(null)}>Cancel</button></div>
                    <div className="mt-4 overflow-x-auto"><table className="min-w-[760px] border-collapse text-xs [&_td]:border [&_td]:border-slate-200 [&_th]:border [&_th]:border-slate-300"><thead><tr><th className="p-2 text-left">City / municipality</th>{Object.entries(tables[reviewEditor.section].columns).filter(([column]) => !(["houses", "assistance"].includes(reviewEditor.section) && column === "total")).map(([column, label]) => <th key={column} className="p-2 text-right">{label}</th>)}</tr></thead><tbody>{reviewEditor.rows.map((row, rowIndex) => <tr key={row.index} className="border-t"><td className="p-2 font-medium">{tables[reviewEditor.section].rows[row.index].label}<span className="block text-[10px] text-slate-500">{tables[reviewEditor.section].rows[row.index].province}</span></td>{Object.keys(tables[reviewEditor.section].columns).filter((column) => !(["houses", "assistance"].includes(reviewEditor.section) && column === "total")).map((column) => <td key={column} className="p-2"><input type="number" min="0" step={reviewEditor.section === "assistance" ? "0.01" : "1"} className="w-28 rounded border border-slate-300 px-2 py-1 text-right" value={row.values[column] ?? ""} onChange={(event) => { const rows = [...reviewEditor.rows]; rows[rowIndex] = { ...row, values: { ...row.values, [column]: event.target.value === "" ? null : event.target.value } }; setReviewEditor({ ...reviewEditor, rows, error: "" }); }} /></td>)}</tr>)}</tbody></table></div>
                    <label className="mt-4 block text-sm font-medium">Reason for adjustment (required)<textarea rows={2} className={`${input} mt-1`} placeholder="State the validation finding or source correction" value={reviewEditor.reason} onChange={(event) => setReviewEditor({ ...reviewEditor, reason: event.target.value, error: "" })} /></label>
                    {reviewEditor.error && <p className="mt-2 text-sm font-semibold text-red-700">{reviewEditor.error}</p>}
                    <button type="button" className={`${primary} mt-3`} onClick={saveReviewEdit}>Apply reviewed adjustment</button>
                  </section>
                )}
                <p className="text-xs text-slate-500">
                  - = incomplete source figures. Explicitly
                  not-applicable sections contribute zero. Assistance includes
                  provided items only.
                </p>
                {overlapping && (
                  <p className="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                    Multiple incident series cover the same city / municipality.
                    Check that affected households, evacuation centers and
                    assistance entries do not overlap before combining their
                    totals.
                  </p>
                )}
                <label className="flex items-start gap-3 rounded-lg border p-4 text-sm">
                  <input
                    type="checkbox"
                    className="mt-1 rounded text-emerald-700"
                    checked={form.data.overlap_reviewed}
                    onChange={(e) =>
                      form.setData("overlap_reviewed", e.target.checked)
                    }
                  />
                  I reviewed the reporting periods, related incidents and
                  possible overlap. The selected figures can be combined without
                  counting the same population or assistance twice.
                </label>
                <div className="flex justify-between">
                  <button className={secondary} onClick={() => setStep(0)}>
                    <ChevronLeft size={16} />
                    Sources
                  </button>
                  <button
                    className={primary}
                    disabled={
                      !form.data.title ||
                      !form.data.as_of ||
                      !form.data.overlap_reviewed ||
                      (incidentCount > 1 && !form.data.relationship)
                    }
                    onClick={startNarrative}
                  >
                    Prepare narrative <ArrowRight size={16} />
                  </button>
                </div>
              </div>
            )}
            {step === 2 && (
              <div className="space-y-6">
                <Narrative
                  snapshot={{ ...snapshot, standby_stockpile_summary: snapshot.standby_stockpile_summary || standbyStockpileSummary }}
                  signatories={signatories}
                  onOverviewEdit={(value) => form.setData("overview", value)}
                  onNarrativeEdit={(key, value) => form.setData("narrative_edits", {
                    ...(form.data.narrative_edits || {}),
                    [key]: value,
                  })}
                  onResponseSectionsEdit={(section, rows) => form.setData("response_sections", {
                    ...(form.data.response_sections || {}),
                    [section]: rows,
                  })}
                  onPhotoChange={(section, photo) => form.setData("section_photos", {
                    ...(form.data.section_photos || {}),
                    [section]: photo,
                  })}
                />
                <div className="flex flex-wrap justify-between gap-3">
                  <button className={secondary} onClick={() => setStep(1)}>
                    <ChevronLeft size={16} />
                    Review tables
                  </button>
                  <button
                    className={primary}
                    disabled={
                      form.processing ||
                      !form.data.overview.trim() ||
                      !form.data.overlap_reviewed
                    }
                    onClick={() => {
                      form.transform((data) => ({
                        ...data,
                        title: normalizeReportTitle(data.title),
                        source_versions: Object.fromEntries(
                          selected.map((s) => [s.id, s.version]),
                        ),
                      }));
                      form.post("/dromic", {
                        preserveScroll: true,
                        onSuccess: () => {
                          form.reset();
                          setReviewEdits({});
                          setReviewEditor(null);
                          setStep(0);
                        },
                        onFinish: () => form.transform((data) => data),
                      });
                    }}
                  >
                    {form.processing ? "Saving…" : "Save DSWD DROMIC draft"}
                  </button>
                </div>
              </div>
            )}
          </div>
        </section>
        <section
          id="dromic-list"
          className="scroll-mt-28 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
        >
          <div className="mb-5 flex items-center gap-3">
            <FileText className="text-emerald-700" />
            <div>
              <h2 className="text-lg font-bold">Saved DSWD reports</h2>
              <p className="text-sm text-slate-500">
                Saved figures stay tied to the source reports used at
                preparation.
              </p>
            </div>
          </div>
          <div className="space-y-3">
            {reports.data.map((r) => (
              <div
                key={r.id}
                className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 p-4"
              >
                <div>
                  <p className="text-xs font-medium text-slate-500">
                    {r.report_number} · {r.status}
                  </p>
                  <h3 className="mt-1 font-semibold">
                    {r.consolidation?.metadata.title ||
                      r.purpose ||
                      r.affected_lgu ||
                      "Legacy DROMIC report"}
                  </h3>
                  <p className="mt-1 text-xs text-slate-500">
                    {r.consolidation
                      ? `${r.consolidation.metadata.type_label} · ${r.consolidation.sources.length} LGU reports`
                      : "Legacy report"}{" "}
                    · {new Date(r.created_at).toLocaleDateString("en-PH")}
                  </p>
                </div>
                {r.consolidation && (
                  <div className="flex flex-wrap gap-2">
                    <button
                      className={secondary}
                      onClick={() => openSavedReport(r)}
                    >
                      View report
                    </button>
                    <a
                      className={secondary}
                      href={`/dromic/reports/${r.id}/xlsx`}
                    >
                      <Table2 size={16} />
                      Excel
                    </a>
                    <a
                      className={secondary}
                      href={`/dromic/reports/${r.id}/pdf`}
                    >
                      <Download size={16} />
                      PDF
                    </a>
                  </div>
                )}
              </div>
            ))}
            {!reports.data.length && (
              <div className="rounded-xl border border-dashed p-8 text-center text-sm text-slate-500">
                Your saved DSWD DROMIC reports will appear here.
              </div>
            )}
          </div>
          <div className="mt-4 flex gap-3">
            {reports.prev_page_url && (
              <Link
                className={secondary}
                href={reports.prev_page_url}
                preserveState
                preserveScroll
              >
                Previous
              </Link>
            )}
            {reports.next_page_url && (
              <Link
                className={secondary}
                href={reports.next_page_url}
                preserveState
                preserveScroll
              >
                Next
              </Link>
            )}
          </div>
        </section>
        <p className="text-xs text-slate-500">
          Reference layouts:{" "}
          <a
            className="underline"
            href="https://docs.google.com/spreadsheets/d/1CPVQtc0k_g3muoV0sWI9KyekzBnJwjJTs_ZrkoucXFo/edit#gid=2118223329"
            target="_blank"
            rel="noreferrer"
          >
            CARAGA master table
          </a>{" "}
          ·{" "}
          <a
            className="underline"
            href="https://docs.google.com/spreadsheets/d/1SHRlk6zvd6Iykw4tDnpgNWAB2xp-_MT9h1x-qxptVEU/edit#gid=1607148519"
            target="_blank"
            rel="noreferrer"
          >
            DROMIC annexes
          </a>
          . Exports contain mapped report tables; Google templates are not
          modified.
        </p>
      </div>
      {opened && (
        <dialog
          ref={dialogRef}
          className="fixed inset-0 m-0 h-full max-h-none w-full max-w-none overflow-y-auto bg-slate-950/60 p-4 sm:p-8"
          aria-modal="true"
          aria-label="Saved DSWD DROMIC report"
          onCancel={() => setOpened(null)}
        >
          <div className="mx-auto max-w-5xl rounded-2xl bg-slate-50 p-4 sm:p-6">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="font-bold">{opened.report_number}</h2>
              <button
                autoFocus
                className={secondary}
                onClick={() => setOpened(null)}
              >
                <X size={16} />
                Close
              </button>
            </div>
            {false && canEditReports && (
              <div className="mb-5 flex flex-wrap items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-900">
                <Pencil size={15} />
                <span>
                  DRIMS editing is enabled. Every section edit requires a reason
                  and is logged.
                </span>
                <button
                  className="ml-auto text-emerald-800 underline"
                  onClick={() => beginSectionEdit("overview")}
                >
                  Edit overview
                </button>
                <button
                  className="text-emerald-800 underline"
                  onClick={() =>
                    document
                      .getElementById("dromic-edit-history")
                      ?.scrollIntoView({ behavior: "smooth" })
                  }
                >
                  <History size={14} className="inline" /> History
                </button>
              </div>
            )}
            {false && editSection && (
              <div className="mb-6 rounded-xl border border-emerald-300 bg-white p-4 shadow-sm">
                <div className="flex items-center justify-between gap-3">
                  <h3 className="font-bold">
                    Edit{" "}
                    {editSection === "overview"
                      ? "situation overview"
                      : opened.consolidation.tables[editSection]?.title ||
                        opened.consolidation.cccm?.title ||
                        editSection}
                  </h3>
                  <button
                    className="text-sm text-slate-500 underline"
                    onClick={() => setEditSection(null)}
                  >
                    Cancel
                  </button>
                </div>
                {editSection === "overview" ? (
                  <div className="mt-4 grid gap-3">
                    <label className="text-sm font-medium">
                      Situation overview
                      <textarea
                        className={`${input} mt-1`}
                        rows={6}
                        value={editValues?.overview || ""}
                        onChange={(e) =>
                          setEditValues({
                            ...editValues,
                            overview: e.target.value,
                          })
                        }
                      />
                    </label>
                    <label className="text-sm font-medium">
                      Response actions / interventions
                      <textarea
                        className={`${input} mt-1`}
                        rows={5}
                        value={editValues?.response_actions || ""}
                        onChange={(e) =>
                          setEditValues({
                            ...editValues,
                            response_actions: e.target.value,
                          })
                        }
                      />
                    </label>
                  </div>
                ) : (
                  <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[760px] text-xs">
                      <thead>
                        <tr>
                          <th className="p-2 text-left">City / municipality</th>
                          {Object.values(
                            (
                              opened.consolidation.tables[editSection] ||
                              opened.consolidation.cccm
                            ).columns,
                          ).map((label) => (
                            <th className="p-2 text-right" key={label}>
                              {label}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {editValues?.rows?.map((row, rowIndex) => {
                          const table =
                            opened.consolidation.tables[editSection] ||
                            opened.consolidation.cccm;
                          const sourceRow = table.rows[row.index];
                          return (
                            <tr className="border-t" key={row.index}>
                              <td className="p-2 font-medium">
                                {sourceRow.label}
                                <span className="block text-[10px] text-slate-500">
                                  {sourceRow.province}
                                </span>
                              </td>
                              {Object.keys(table.columns)
                                .filter(
                                  (column) =>
                                    !(
                                      editSection === "houses" ||
                                      editSection === "assistance"
                                    ) || column !== "total",
                                )
                                .map((column) => (
                                  <td className="p-2" key={column}>
                                    <input
                                      type="number"
                                      min="0"
                                      step={
                                        editSection === "assistance"
                                          ? "0.01"
                                          : "1"
                                      }
                                      className="w-28 rounded border border-slate-300 px-2 py-1 text-right"
                                      value={row.values[column] ?? ""}
                                      onChange={(e) => {
                                        const rows = [...editValues.rows];
                                        rows[rowIndex] = {
                                          ...rows[rowIndex],
                                          values: {
                                            ...rows[rowIndex].values,
                                            [column]:
                                              e.target.value === ""
                                                ? null
                                                : e.target.value,
                                          },
                                        };
                                        setEditValues({ ...editValues, rows });
                                      }}
                                    />
                                  </td>
                                ))}
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
                <label className="mt-4 block text-sm font-medium">
                  Reason for edit (required)
                  <textarea
                    className={`${input} mt-1`}
                    rows={2}
                    placeholder="Explain the source correction or review decision"
                    value={editReason}
                    onChange={(e) => setEditReason(e.target.value)}
                  />
                </label>
                {editError && (
                  <p className="mt-3 rounded bg-red-50 p-2 text-sm text-red-700">
                    {editError}
                  </p>
                )}
                <button
                  className={`${primary} mt-4`}
                  disabled={savingEdit}
                  onClick={saveSectionEdit}
                >
                  {savingEdit ? "Saving…" : "Save section edit"}
                </button>
              </div>
            )}
            <Narrative
              snapshot={{ ...opened.consolidation, standby_stockpile_summary: opened.consolidation.standby_stockpile_summary || standbyStockpileSummary }}
              signatories={{
                ...signatories,
                prepared: opened.creator
                  ? {
                      name: opened.creator.name,
                      position:
                        opened.creator.designation ||
                        opened.creator.position ||
                        "DRIMS",
                    }
                  : signatories.prepared,
              }}
            />
            {false && <div id="dromic-edit-history" className="mt-7 border-t pt-5">
              <h3 className="flex items-center gap-2 font-semibold">
                <History size={16} /> Edit history
              </h3>
              {editHistory.length ? (
                <div className="mt-3 space-y-3">
                  {editHistory.map((entry) => (
                    <details
                      key={entry.id}
                      className="rounded-lg border p-3 text-xs"
                    >
                      <summary className="cursor-pointer font-semibold">
                        {entry.section} · {entry.editor} ·{" "}
                        {new Date(entry.at).toLocaleString("en-PH")}
                      </summary>
                      <p className="mt-2 text-slate-600">
                        Reason: {entry.reason}
                      </p>
                      <ul className="mt-2 space-y-1">
                        {entry.changes?.map((change, index) => (
                          <li key={index}>
                            {change.location} · {change.field}:{" "}
                            {String(change.before ?? "Not reported")} →{" "}
                            {String(change.after ?? "Not reported")}
                          </li>
                        ))}
                      </ul>
                    </details>
                  ))}
                </div>
              ) : (
                <p className="mt-2 text-xs text-slate-500">
                  No section edits have been recorded.
                </p>
              )}
            </div>}
          </div>
        </dialog>
      )}
    </AppLayout>
  );
}

function EditableResponseTable({ rows = [], empty, onChange = null }) {
  const updateRow = (index, changes) => onChange?.(rows.map((row, rowIndex) => rowIndex === index ? { ...row, ...changes } : row));
  const dateLabel = (row) => {
    if (!row.date_from) return "Date not set";
    const from = readableDate(row.date_from);
    const to = row.date_to ? readableDate(row.date_to) : from;
    return from === to ? from : `${from} – ${to}`;
  };
  return <div><table className="mt-1 w-full table-fixed break-inside-avoid border-collapse text-[14px] leading-normal"><colgroup><col className="w-[22%]" /><col /></colgroup><thead><tr className="bg-[#c6e6ed] text-center font-bold"><th className="border border-slate-500 px-2 py-1">Date</th><th className="border border-slate-500 px-2 py-1">Activities</th></tr></thead><tbody>{rows.length ? rows.map((row, rowIndex) => <tr key={row.id || rowIndex}><td className="border border-slate-500 px-2 py-2 text-center align-top">{onChange ? <div className="space-y-1"><input aria-label="Response start date" type="date" value={row.date_from || ""} onChange={(event) => updateRow(rowIndex, { date_from: event.target.value })} className="w-full rounded border border-slate-300 p-1 text-[13px]" /><input aria-label="Response end date" type="date" min={row.date_from || undefined} value={row.date_to || ""} onChange={(event) => updateRow(rowIndex, { date_to: event.target.value })} className="w-full rounded border border-slate-300 p-1 text-[13px]" /></div> : dateLabel(row)}</td><td className="border border-slate-500 px-3 py-2 align-top">{onChange ? <div className="space-y-2">{(row.bullets || []).map((bullet, bulletIndex) => <div className="flex items-start gap-2" key={bulletIndex}><span className="pt-2">•</span><textarea rows={2} value={bullet} onChange={(event) => updateRow(rowIndex, { bullets: row.bullets.map((item, index) => index === bulletIndex ? event.target.value : item) })} className="min-h-10 flex-1 rounded border border-slate-300 p-2 text-[14px]" /><button type="button" aria-label="Remove bullet" onClick={() => updateRow(rowIndex, { bullets: row.bullets.filter((_, index) => index !== bulletIndex) })} className="mt-1 rounded p-1 text-red-700"><X size={15} /></button></div>)}<div className="flex gap-2"><button type="button" onClick={() => updateRow(rowIndex, { bullets: [...(row.bullets || []), ""] })} className="rounded border px-2 py-1 text-xs font-semibold">Add bullet</button><button type="button" onClick={() => onChange(rows.filter((_, index) => index !== rowIndex))} className="rounded border border-red-200 px-2 py-1 text-xs font-semibold text-red-700">Remove row</button></div></div> : <ul className="list-disc space-y-1 pl-5">{row.bullets.filter(Boolean).map((bullet, index) => <li className="pl-1 text-left align-top" key={index}>{bullet}</li>)}</ul>}</td></tr>) : <tr><td className="border border-slate-500 px-2 py-2 text-center align-top">—</td><td className="border border-slate-500 px-3 py-2 align-top">{empty}</td></tr>}</tbody></table>{onChange && <button type="button" onClick={() => onChange([...rows, { id: `${Date.now()}`, date_from: "", date_to: "", bullets: [""] }])} className="mt-2 rounded border border-blue-300 bg-white px-3 py-1.5 text-xs font-bold text-blue-900">Add response row</button>}</div>;
}

function EditableSectionPhoto({ label, photo, onChange = null }) {
  const drag = useRef(null);
  const figure = useRef(null);
  const settings = { side: "right", width: 46, offset_y: 0, aspect_ratio: 1.5, ...(photo || {}) };
  const update = (changes) => onChange?.({ ...settings, ...changes });
  const loadPhoto = (file) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const image = new Image();
      image.onload = () => {
        const limit = 1800;
        const ratio = Math.min(1, limit / Math.max(image.width, image.height));
        const canvas = document.createElement("canvas");
        canvas.width = Math.round(image.width * ratio);
        canvas.height = Math.round(image.height * ratio);
        canvas.getContext("2d").drawImage(image, 0, 0, canvas.width, canvas.height);
        update({ data_url: canvas.toDataURL("image/jpeg", 0.86), side: "right", width: 46, offset_y: 0, aspect_ratio: image.width / image.height, alt: file.name.replace(/\.[^.]+$/, "") });
      };
      image.src = reader.result;
    };
    reader.readAsDataURL(file);
  };
  if (!photo?.data_url) return onChange ? <label className="my-2 inline-flex cursor-pointer items-center rounded border border-blue-300 bg-white px-3 py-1.5 text-xs font-bold text-blue-900">Upload {label}<input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(event) => loadPhoto(event.target.files?.[0])} /></label> : null;
  const begin = (event, mode, corner = "se") => {
    if (!onChange) return;
    event.preventDefault();
    event.stopPropagation();
    event.currentTarget.setPointerCapture(event.pointerId);
    const bounds = figure.current.parentElement.getBoundingClientRect();
    drag.current = { mode, corner, clientX: event.clientX, clientY: event.clientY, bounds, ...settings };
  };
  const move = (event) => {
    if (!drag.current || !onChange) return;
    const start = drag.current;
    const dxPercent = (event.clientX - start.clientX) / start.bounds.width * 100;
    const dy = event.clientY - start.clientY;
    if (start.mode === "move") {
      update({ side: event.clientX < start.bounds.left + start.bounds.width / 2 ? "left" : "right", offset_y: Math.max(0, Math.min(180, start.offset_y + dy)) });
      return;
    }
    const fromWest = start.corner.includes("w");
    update({ width: Math.max(25, Math.min(75, start.width + (fromWest ? -dxPercent : dxPercent))) });
  };
  const handle = (corner, position, cursor) => onChange && <button type="button" aria-label={`Resize photo from ${corner}`} onPointerDown={(event) => begin(event, "resize", corner)} className={`absolute z-20 h-3 w-3 rounded-sm border border-white bg-blue-700 shadow ${position} ${cursor}`} />;
  return <figure ref={figure} className={`relative z-10 mb-2 break-inside-avoid ${settings.side === "left" ? "float-left mr-4" : "float-right ml-4"}`} style={{ width: `${settings.width}%`, marginTop: `${settings.offset_y}px` }} onPointerMove={move} onPointerUp={() => { drag.current = null; }} onPointerCancel={() => { drag.current = null; }}>
      <div className={`relative touch-none ${onChange ? "cursor-move border-2 border-blue-600" : ""}`} onPointerDown={(event) => begin(event, "move")}>
        <img src={settings.data_url} alt={settings.alt || label} className="pointer-events-none block h-auto w-full select-none" draggable={false} />
        {handle("nw", "-left-1.5 -top-1.5", "cursor-nwse-resize")}{handle("ne", "-right-1.5 -top-1.5", "cursor-nesw-resize")}{handle("sw", "-bottom-1.5 -left-1.5", "cursor-nesw-resize")}{handle("se", "-bottom-1.5 -right-1.5", "cursor-nwse-resize")}
      </div>
    {onChange && <figcaption className="mt-1 flex flex-wrap items-center justify-between gap-2 rounded border border-slate-200 bg-white p-1.5 text-[10px]"><span className="text-slate-600">Drag to move left/right or lower. Drag a corner to resize proportionally.</span><span className="flex gap-2"><button type="button" onClick={() => update({ side: settings.side === "right" ? "left" : "right" })} className="font-bold text-blue-900">Move {settings.side === "right" ? "left" : "right"}</button><label className="cursor-pointer font-bold text-blue-900">Replace<input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(event) => loadPhoto(event.target.files?.[0])} /></label><button type="button" onClick={() => onChange(null)} className="font-bold text-red-700">Remove</button></span></figcaption>}
  </figure>;
}
