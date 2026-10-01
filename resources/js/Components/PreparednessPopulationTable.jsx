import { createContext, useContext } from "react";

export const PreparednessPopulationContext = createContext(null);

export function preparednessPopulationTotals(intro, lgus = []) {
  const codes = new Set(String(intro?.content?.possible_affected_lgu_codes || "").split(",").map(code => code.trim()).filter(Boolean));
  const population = lgus.filter(row => codes.has(String(row.code))).reduce((sum, row) => sum + Number(row.population || 0), 0);
  const affected = Math.round(population * 0.3);
  return { population, affected, families: Math.round(affected / 5) };
}

export default function PreparednessPopulationTable() {
  const totals = useContext(PreparednessPopulationContext);
  if (!totals) return null;
  return <div data-population-summary="true" className="w-[520px] max-w-full shrink-0 text-slate-900">
    <table className="w-full table-fixed border-collapse bg-white text-center text-xs leading-tight">
      <thead className="bg-blue-50"><tr>{["Total Population", "30% of the Population", "Number of Families"].map(label => <th key={label} className="border border-slate-300 px-2 py-2 font-black">{label}</th>)}</tr></thead>
      <tbody><tr>{[totals.population, totals.affected, totals.families].map((value, index) => <td key={index} className="border border-slate-300 px-2 py-2 text-base font-black tabular-nums">{value.toLocaleString("en-PH")}</td>)}</tr></tbody>
    </table>
    <p className="mt-1 text-right text-[10px] italic leading-tight text-slate-500">Based on LGUs selected under Possible Affected Families.</p>
  </div>;
}
