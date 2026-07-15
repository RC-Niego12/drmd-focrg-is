import axios from "axios";
import { FileText, X } from "lucide-react";
import { useId, useMemo, useState } from "react";

export const currentDrnParts = (prefix = "CARAGA-FO-DRMD-DRRMS-SS-REP") => {
  const now = new Date();
  return {
    prefix,
    year: String(now.getFullYear()).slice(-2),
    month: String(now.getMonth() + 1).padStart(2, "0"),
    specified: "",
  };
};

export const composeDocumentDrn = ({ prefix = "", year = "", month = "", specified = "" }) =>
  [prefix.replace(/-+$/, ""), year, month, specified.replace(/^-+/, "")].join("-");

export const parseDocumentDrn = (value, prefixOptions = []) => {
  const full = String(value || "").trim();
  const knownPrefix = [...prefixOptions].sort((a, b) => b.length - a.length).find((prefix) => full.startsWith(`${prefix}-`));
  const match = full.match(/^(.*)-(\d{2})-(\d{2})-(.+)$/);
  if (!match) return currentDrnParts(knownPrefix || prefixOptions[0]);
  return { prefix: knownPrefix || match[1], year: match[2], month: match[3], specified: match[4] };
};

export function DocumentDrnFields({ parts, onChange, prefixOptions = [], compact = false, errors = {} }) {
  const listId = useId();
  const update = (key, value) => {
    const next = { ...parts, [key]: value };
    onChange(next, composeDocumentDrn(next));
  };
  const fields = [
    ["prefix", "Prefix", compact ? "min-w-[330px] flex-[4]" : ""],
    ["year", "Year", compact ? "w-20" : ""],
    ["month", "Month", compact ? "w-20" : ""],
    ["specified", "Specified DRN", compact ? "min-w-[145px] flex-[2]" : ""],
  ];
  return <div className={compact ? "w-full" : "space-y-3"}>
    <div className={compact ? "flex flex-wrap items-end gap-1.5" : "grid items-start gap-3 md:grid-cols-[minmax(0,4fr)_6rem_6rem_minmax(12rem,2fr)]"}>
      {fields.map(([key, label, sizing]) => <label key={key} className={`${sizing} block min-w-0 text-left font-sans text-[10px] font-black uppercase tracking-wide text-slate-600`}>
        {!compact && <span className="block h-4 leading-4">{label} *</span>}
        <input
          required
          aria-label={label}
          title={label}
          list={key === "prefix" ? listId : undefined}
          inputMode={key === "year" || key === "month" ? "numeric" : undefined}
          maxLength={key === "year" || key === "month" ? 2 : undefined}
          value={parts[key] ?? ""}
          placeholder={compact ? label : undefined}
          onChange={(event) => update(key, event.target.value)}
          className={`mt-1 w-full rounded border border-slate-300 bg-white px-2 py-2 text-xs font-bold normal-case tracking-normal text-slate-950 focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 ${compact ? "h-9" : "h-10"}`}
        />
        {errors[key] && <span className="mt-1 block normal-case text-rose-600">{errors[key]}</span>}
      </label>)}
      <datalist id={listId}>{prefixOptions.map((prefix) => <option key={prefix} value={prefix} />)}</datalist>
    </div>
    {!compact && <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 font-mono text-sm font-bold text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-100">
      {composeDocumentDrn(parts)}
    </div>}
  </div>;
}

export function ResponseDrnModal({ requestId, existingDrn = "", prefixOptions = [], onClose, onSaved, actionLabel = "Continue" }) {
  const initial = useMemo(() => parseDocumentDrn(existingDrn, prefixOptions), [existingDrn, prefixOptions]);
  const [parts, setParts] = useState(initial);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const submit = async (event) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      const { data } = await axios.patch(`/requests/${requestId}/response-drn`, parts, { headers: { Accept: "application/json" }, withXSRFToken: true });
      onSaved(data.drn, parts);
    } catch (error) {
      const validation = error.response?.data?.errors ?? {};
      setErrors(Object.fromEntries(Object.entries(validation).map(([key, messages]) => [key, Array.isArray(messages) ? messages[0] : messages])));
    } finally {
      setSaving(false);
    }
  };

  return <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
    <form onSubmit={submit} className="w-full max-w-3xl overflow-hidden rounded-lg bg-white shadow-2xl dark:bg-zinc-950">
      <div className="flex items-start justify-between border-b border-slate-200 bg-slate-50 px-6 py-5 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="flex gap-3"><span className="rounded-md bg-emerald-100 p-2 text-emerald-700"><FileText className="h-5 w-5" /></span><div><p className="text-xs font-black uppercase tracking-wide text-emerald-700">Required document reference</p><h2 className="text-xl font-black">Response Letter DRN</h2><p className="mt-1 text-sm text-slate-500">Confirm or edit every component before generating the response letter.</p></div></div>
        <button type="button" onClick={onClose} className="rounded-md p-2 hover:bg-slate-200 dark:hover:bg-zinc-800"><X className="h-5 w-5" /></button>
      </div>
      <div className="p-6"><DocumentDrnFields parts={parts} onChange={setParts} prefixOptions={prefixOptions} errors={errors} /></div>
      <div className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-900"><button type="button" onClick={onClose} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button><button disabled={saving} className="rounded-md bg-emerald-700 px-5 py-2 text-sm font-black text-white disabled:opacity-60">{saving ? "Saving DRN..." : actionLabel}</button></div>
    </form>
  </div>;
}
