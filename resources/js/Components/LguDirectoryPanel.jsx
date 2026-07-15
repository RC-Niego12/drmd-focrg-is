import { router, useForm } from "@inertiajs/react";
import { Edit3, RefreshCw, X } from "lucide-react";
import { useMemo, useState } from "react";
import { Card, TableActionButton } from "@/Layouts/AppLayout";

const provinces = {
  ADN: "Agusan del Norte",
  ADS: "Agusan del Sur",
  SDN: "Surigao del Norte",
  SDS: "Surigao del Sur",
  PDI: "Province of Dinagat Islands",
};
const official = (row, role) => {
  const item = row.officials.find((value) => value.role === role) ?? {};
  return {
    ...item,
    name: item.override_name || item.name,
    position_designation:
      item.override_position_designation || item.position_designation,
  };
};
const contact = (row, owner, type) => {
  const item = row.contacts.find(
    (value) => value.owner_role === owner && value.contact_type === type,
  );
  return item?.override_value ?? item?.value ?? "";
};
const includes = (value, needle) =>
  String(value ?? "")
    .toLowerCase()
    .includes(needle.toLowerCase());
const hasOverride = (row) =>
  Boolean(
    row.override_lgu_name ||
    row.override_congressional_district ||
    row.override_office_address ||
    row.officials.some(
      (item) => item.override_name || item.override_position_designation,
    ) ||
    row.contacts.some((item) => item.override_value),
  );
const displayDateTime = (value) =>
  value
    ? new Intl.DateTimeFormat("en-PH", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(value))
    : "—";

export default function LguDirectoryPanel({
  entries = [],
  preview = null,
  syncRuns = [],
}) {
  const [filters, setFilters] = useState({
    province: "",
    lgu: "",
    lce: "",
    lswd: "",
    contact: "",
    psgc: "",
  });
  const [editing, setEditing] = useState(null);
  const set = (key, value) =>
    setFilters((current) => ({ ...current, [key]: value }));
  const rows = useMemo(
    () =>
      entries.filter((row) => {
        const lce = official(row, "lce"),
          lswd = official(row, "lswd_officer"),
          contacts = row.contacts
            .map((item) => item.override_value ?? item.value)
            .join(" ");
        return (
          (!filters.province || row.source_sheet === filters.province) &&
          includes(
            `${row.override_lgu_name || row.lgu_name} ${row.override_congressional_district || row.congressional_district}`,
            filters.lgu,
          ) &&
          includes(`${lce.name} ${lce.position_designation}`, filters.lce) &&
          includes(`${lswd.name} ${lswd.position_designation}`, filters.lswd) &&
          includes(contacts, filters.contact) &&
          (!filters.psgc ||
            (filters.psgc === "linked"
              ? !!row.psgc_code
              : filters.psgc === "unmatched"
                ? !row.psgc_code
                : includes(row.psgc_code, filters.psgc)))
        );
      }),
    [entries, filters],
  );
  return (
    <Card
      id="lgu-directory-library"
      className="mt-6 scroll-mt-28 overflow-hidden p-0"
    >
      <div className="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-950">
        <div>
          <h2 className="font-black uppercase">
            LGU Officials and LSWD Directory Library
          </h2>
          <p className="mt-1 text-sm text-slate-500">
            Google source values and protected local overrides are stored
            separately. Preview changes before applying synchronization.
          </p>
        </div>
        <button
          onClick={() => router.post("/lgu-library/sync-preview")}
          className="inline-flex items-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white"
        >
          <RefreshCw className="h-4 w-4" />
          Preview Sync
        </button>
      </div>
      {preview && (
        <div className="border-b border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="font-black">Synchronization preview</p>
              <p>
                {preview.new} new · {preview.changed} changed ·{" "}
                {preview.unchanged} unchanged · {preview.missing}{" "}
                missing/deactivate
              </p>
            </div>
            <button
              onClick={() =>
                confirm(
                  "Apply this synchronization? Missing source rows will be deactivated; local overrides will be preserved.",
                ) && router.post("/lgu-library/sync")
              }
              className="rounded-md bg-emerald-700 px-4 py-2 font-black text-white"
            >
              Apply Sync
            </button>
          </div>
          {preview.changes?.length > 0 && (
            <div className="mt-3 max-h-28 overflow-auto text-xs">
              {preview.changes.map((item, index) => (
                <span key={index} className="mr-3 inline-block">
                  <b className="uppercase">{item.type}</b> {item.sheet}:{" "}
                  {item.lgu}
                </span>
              ))}
            </div>
          )}
        </div>
      )}
      {syncRuns.length > 0 && (
        <div className="border-b border-slate-200 px-5 py-2 text-xs text-slate-500">
          Last sync: <b>{syncRuns[0].status}</b> ·{" "}
          {displayDateTime(syncRuns[0].started_at)}
          {syncRuns[0].summary
            ? ` · ${syncRuns[0].summary.changed} changed · ${syncRuns[0].summary.missing} deactivated`
            : ""}
        </div>
      )}
      <div className="max-h-[calc(100vh-18rem)] min-h-[480px] overflow-auto px-4 pb-4">
        <table className="w-full min-w-[1080px] table-fixed border-separate border-spacing-0 text-sm">
          <colgroup>
            <col className="w-[13%]" />
            <col className="w-[14%]" />
            <col className="w-[18%]" />
            <col className="w-[19%]" />
            <col className="w-[22%]" />
            <col className="w-[9%]" />
            <col className="w-[5%]" />
          </colgroup>
          <thead className="sticky top-0 z-20 bg-slate-100 shadow-sm dark:bg-zinc-900">
            <tr>
              {[
                "Province",
                "LGU / District",
                "LCE / Position",
                "LSWD Officer / Designation",
                "Contacts",
                "PSGC",
                "Actions",
              ].map((label) => (
                <th
                  key={label}
                  className="border-b px-3 py-2 text-left text-xs font-black uppercase"
                >
                  {label}
                </th>
              ))}
            </tr>
            <tr>
              <th className="border-b p-2">
                <select
                  className="w-full text-xs"
                  value={filters.province}
                  onChange={(e) => set("province", e.target.value)}
                >
                  <option value="">All</option>
                  {Object.entries(provinces).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
                </select>
              </th>
              <Filter
                value={filters.lgu}
                set={(v) => set("lgu", v)}
                placeholder="Search LGU..."
              />
              <Filter
                value={filters.lce}
                set={(v) => set("lce", v)}
                placeholder="Search LCE..."
              />
              <Filter
                value={filters.lswd}
                set={(v) => set("lswd", v)}
                placeholder="Search LSWD..."
              />
              <Filter
                value={filters.contact}
                set={(v) => set("contact", v)}
                placeholder="Email / phone..."
              />
              <th className="border-b p-2">
                <select
                  className="w-full text-xs"
                  value={filters.psgc}
                  onChange={(e) => set("psgc", e.target.value)}
                >
                  <option value="">All</option>
                  <option value="linked">PSGC Linked</option>
                  <option value="unmatched">Unmatched</option>
                </select>
              </th>
              <th className="border-b p-2">
                <button
                  onClick={() =>
                    setFilters({
                      province: "",
                      lgu: "",
                      lce: "",
                      lswd: "",
                      contact: "",
                      psgc: "",
                    })
                  }
                  className="text-xs font-black text-brand-700"
                >
                  Clear
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={row.id}
                className="border-b hover:bg-emerald-50/50 dark:hover:bg-emerald-950/20"
              >
                <td className="border-b px-3 py-3">
                  {provinces[row.source_sheet]}
                </td>
                <td className="border-b px-3 py-3 font-black">
                  {row.override_lgu_name || row.lgu_name}
                  <span className="block text-xs font-normal text-slate-500">
                    {row.override_congressional_district ||
                      row.congressional_district ||
                      "-"}
                  </span>
                  {hasOverride(row) && (
                    <span className="mt-1 inline-flex rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-black text-violet-700">
                      Local override
                    </span>
                  )}
                </td>
                <td className="border-b px-3 py-3">
                  {official(row, "lce").name || "-"}
                  <span className="block text-xs text-slate-500">
                    {official(row, "lce").position_designation}
                  </span>
                </td>
                <td className="border-b px-3 py-3">
                  {official(row, "lswd_officer").name || "-"}
                  <span className="block text-xs text-slate-500">
                    {official(row, "lswd_officer").position_designation}
                  </span>
                </td>
                <td className="break-words border-b px-3 py-3 text-xs">
                  {contact(row, "lswd_officer", "email") || "-"}
                  <span className="block">
                    {contact(row, "lswd_officer", "phone")}
                  </span>
                </td>
                <td className="overflow-hidden border-b px-2 py-3">
                  {row.psgc_code ? (
                    <span className="inline-flex max-w-full rounded-full bg-emerald-50 px-1.5 py-1 text-[10px] font-black text-emerald-700">
                      {row.psgc_code}
                    </span>
                  ) : (
                    <span className="text-xs font-black text-amber-700">
                      Unmatched
                    </span>
                  )}
                </td>
                <td className="border-b px-3 py-3">
                  <TableActionButton
                    icon={Edit3}
                    label="Edit"
                    onClick={() => setEditing(row)}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {editing && <EditModal row={editing} onClose={() => setEditing(null)} />}
    </Card>
  );
}
function Filter({ value, set, placeholder }) {
  return (
    <th className="border-b p-2">
      <input
        className="w-full text-xs"
        value={value}
        onChange={(e) => set(e.target.value)}
        placeholder={placeholder}
      />
    </th>
  );
}
function EditModal({ row, onClose }) {
  const lce = official(row, "lce"),
    lswd = official(row, "lswd_officer");
  const form = useForm({
    lgu_name: row.override_lgu_name || row.lgu_name,
    congressional_district:
      row.override_congressional_district || row.congressional_district || "",
    office_address: row.override_office_address || row.office_address || "",
    lce_name: lce.name || "",
    lce_position: lce.position_designation || "",
    lce_email: contact(row, "lce", "email"),
    lswd_name: lswd.name || "",
    lswd_position: lswd.position_designation || "",
    lswd_email: contact(row, "lswd_officer", "email"),
    lswd_phone: contact(row, "lswd_officer", "phone"),
  });
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4">
      <form
        onSubmit={(e) => {
          e.preventDefault();
          form.patch(`/lgu-library/${row.id}`, {
            preserveScroll: true,
            onSuccess: onClose,
          });
        }}
        className="max-h-[90vh] w-full max-w-3xl overflow-auto rounded-lg bg-white shadow-2xl dark:bg-zinc-950"
      >
        <div className="sticky top-0 flex justify-between border-b bg-slate-50 p-5 dark:bg-zinc-900">
          <h2 className="text-xl font-black">
            Edit {row.override_lgu_name || row.lgu_name}
          </h2>
          <button type="button" onClick={onClose}>
            <X />
          </button>
        </div>
        <div className="grid gap-4 p-5 sm:grid-cols-2">
          <Field
            label="LGU Name"
            value={form.data.lgu_name}
            set={(v) => form.setData("lgu_name", v)}
          />
          <Field
            label="Congressional District"
            value={form.data.congressional_district}
            set={(v) => form.setData("congressional_district", v)}
          />
          <Field
            label="LCE Name"
            value={form.data.lce_name}
            set={(v) => form.setData("lce_name", v)}
          />
          <Field
            label="LCE Position"
            value={form.data.lce_position}
            set={(v) => form.setData("lce_position", v)}
          />
          <Field
            label="LCE Email"
            value={form.data.lce_email}
            set={(v) => form.setData("lce_email", v)}
          />
          <div />
          <Field
            label="LSWD Officer"
            value={form.data.lswd_name}
            set={(v) => form.setData("lswd_name", v)}
          />
          <Field
            label="Position / Designation"
            value={form.data.lswd_position}
            set={(v) => form.setData("lswd_position", v)}
          />
          <Field
            label="LSWD Email"
            value={form.data.lswd_email}
            set={(v) => form.setData("lswd_email", v)}
          />
          <Field
            label="Contact Number / Hotline"
            value={form.data.lswd_phone}
            set={(v) => form.setData("lswd_phone", v)}
          />
          <label className="sm:col-span-2 text-sm font-bold">
            Office Address
            <textarea
              rows="3"
              className="mt-1 w-full"
              value={form.data.office_address}
              onChange={(e) => form.setData("office_address", e.target.value)}
            />
          </label>
        </div>
        <div className="flex justify-end gap-2 border-t p-5">
          <button
            type="button"
            onClick={onClose}
            className="rounded-md border px-4 py-2 font-bold"
          >
            Cancel
          </button>
          <button
            disabled={form.processing}
            className="rounded-md bg-brand-700 px-4 py-2 font-black text-white"
          >
            Save LGU
          </button>
        </div>
      </form>
    </div>
  );
}
function Field({ label, value, set }) {
  return (
    <label className="text-sm font-bold">
      {label}
      <input
        className="mt-1 w-full"
        value={value}
        onChange={(e) => set(e.target.value)}
      />
    </label>
  );
}
