import { useEffect, useRef, useState } from "react";

const figure = (value) =>
  value == null || value === "" ? "—" : Number(value).toLocaleString("en-PH");

export default function DromicSourceTable({ reports, selectedIds, onToggle, onToggleAll }) {
  const [expanded, setExpanded] = useState({});
  const selectAllRef = useRef(null);
  const selectedVisibleCount = reports.filter((report) => selectedIds.includes(report.id)).length;
  const allVisibleSelected = reports.length > 0 && selectedVisibleCount === reports.length;
  const someVisibleSelected = selectedVisibleCount > 0 && !allVisibleSelected;

  useEffect(() => {
    if (selectAllRef.current) selectAllRef.current.indeterminate = someVisibleSelected;
  }, [someVisibleSelected]);

  return (
    <div className="flex h-[clamp(28rem,calc(100dvh-20rem),52rem)] min-h-0 flex-col overflow-hidden rounded-lg border border-slate-200">
      <div className="min-h-0 flex-1 overflow-auto">
        <table className="w-full min-w-[860px] border-collapse text-xs [&_td]:border [&_td]:border-slate-200 [&_th]:border [&_th]:border-slate-300">
          <thead className="sticky top-0 z-10 bg-slate-100 text-slate-600">
            <tr>
              <th className="w-9 px-3 py-2 text-center">
                <input
                  ref={selectAllRef}
                  type="checkbox"
                  aria-label="Select all filtered LGU reports"
                  title="Select all filtered LGU reports"
                  checked={allVisibleSelected}
                  disabled={!reports.length}
                  onChange={(event) => onToggleAll(reports, event.target.checked)}
                  className="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                />
              </th>
              <th className="px-2 py-2 text-left">Latest report / incident</th>
              <th className="px-2 py-2 text-left">City / municipality</th>
              <th className="px-2 py-2 text-right">
                Affected
                <br />
                barangays
              </th>
              <th className="px-3 py-2 text-right">
                Affected
                <br />
                <span className="font-normal">Families / Persons</span>
              </th>
              <th className="px-3 py-2 text-right">
                Displaced · NOW
                <br />
                <span className="font-normal">Families / Persons</span>
              </th>
              <th className="px-3 py-2 text-right">
                Damaged houses
                <br />
                <span className="font-normal">Total</span>
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {reports.map((report) => {
              const { affected, displaced, houses } = report.metrics;
              return (
                <tr
                  key={report.id}
                  className={
                    selectedIds.includes(report.id)
                      ? "bg-emerald-50"
                      : "bg-white hover:bg-slate-50"
                  }
                >
                  <td className="px-3 py-3 align-top">
                    <input
                      id={`source-select-${report.id}`}
                      aria-label={`Select ${report.reference}`}
                      type="checkbox"
                      className="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                      checked={selectedIds.includes(report.id)}
                      onChange={() => onToggle(report)}
                    />
                  </td>
                  <td className="max-w-72 px-2 py-2.5 align-top">
                    <label
                      htmlFor={`source-select-${report.id}`}
                      className="block font-semibold text-slate-900"
                    >
                      {report.incident}
                    </label>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[10px] text-slate-500">
                      <span>
                        Report {report.report_number ?? "—"} · Rev{" "}
                        {report.revision || 0}
                      </span>
                      <span
                        className={`rounded px-1.5 py-0.5 font-medium ${report.validation === "validated_no_findings" ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"}`}
                      >
                        {report.validation === "validated_no_findings"
                          ? "Validated"
                          : "Awaiting validation"}
                      </span>
                    </div>
                    <details className="mt-1 text-[10px] text-slate-500">
                      <summary
                        id={`source-info-${report.id}`}
                        className="cursor-pointer break-all"
                      >
                        {report.reference}
                      </summary>
                      <p className="mt-1">
                        Received{" "}
                        {new Date(report.received_at).toLocaleString("en-PH")}
                      </p>
                    </details>
                  </td>
                  <td className="max-w-44 px-2 py-2.5 align-top">
                    <p className="font-medium text-slate-800">
                      {report.municipality || "Not reported"}
                    </p>
                    <p className="mt-1 text-[11px] text-slate-500">
                      {report.province || "Province not reported"}
                    </p>
                  </td>
                  <td className="px-2 py-2.5 align-top text-right">
                    <div className="flex min-w-32 flex-col items-end gap-1">
                      <span className="text-sm font-semibold tabular-nums">
                        {figure(affected.barangays)}
                      </span>
                      {report.barangays?.length ? (
                        <>
                          <span
                            className="max-w-40 truncate text-[11px] font-normal text-slate-700"
                            title={report.barangays.join(", ")}
                          >
                            {expanded[report.id]
                              ? report.barangays.join(", ")
                              : report.barangays[0]}
                          </span>
                          {report.barangays.length > 1 && (
                            <button
                              type="button"
                              className="text-[10px] font-semibold text-emerald-700 underline"
                              onClick={() =>
                                setExpanded((current) => ({
                                  ...current,
                                  [report.id]: !current[report.id],
                                }))
                              }
                            >
                              {expanded[report.id]
                                ? "Show less"
                                : `Show more (${report.barangays.length - 1})`}
                            </button>
                          )}
                        </>
                      ) : (
                        <span className="text-[11px] font-normal text-slate-400">
                          Not reported
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-3 py-2.5 text-right align-top text-sm font-semibold tabular-nums">
                    {figure(affected.families)}{" "}
                    <span className="font-normal text-slate-400">/</span>{" "}
                    {figure(affected.persons)}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2.5 text-right align-top tabular-nums">
                    <p className="text-sm font-semibold">
                      {figure(displaced.families_now)}{" "}
                      <span className="font-normal text-slate-400">/</span>{" "}
                      {figure(displaced.persons_now)}
                    </p>
                    <p className="mt-1 text-[10px] text-slate-500">
                      CUM {figure(displaced.families_cum)} /{" "}
                      {figure(displaced.persons_cum)}
                    </p>
                  </td>
                  <td className="whitespace-nowrap px-3 py-2.5 text-right align-top tabular-nums">
                    <p className="text-sm font-semibold">
                      {figure(houses.total)}
                    </p>
                    <p className="mt-1 text-[10px] text-slate-500">
                      {figure(houses.totally)} totally ·{" "}
                      {figure(houses.partially)} partially
                    </p>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="border-t bg-slate-50 px-3 py-2 text-[10px] text-slate-500">
        Latest submitted figures only. NOW = currently displaced; CUM =
        cumulative. — = not reported.{" "}
        <span className="md:hidden">Swipe horizontally for all figures.</span>
      </p>
    </div>
  );
}
