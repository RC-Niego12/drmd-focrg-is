import { router } from "@inertiajs/react";
import {
  Building2,
  Database,
  RefreshCw,
  UserRound,
  UsersRound,
  X,
} from "lucide-react";
import { useMemo, useState } from "react";
import LookerMultiSelect from "@/Components/LookerMultiSelect";
import { Card } from "@/Layouts/AppLayout";

const provinces = {
  ADN: "Agusan del Norte",
  ADS: "Agusan del Sur",
  SDN: "Surigao del Norte",
  SDS: "Surigao del Sur",
  PDI: "Province of Dinagat Islands",
};

const official = (row, role) => {
  const item = row.officials?.find((value) => value.role === role) ?? {};

  return {
    ...item,
    name: item.override_name || item.name,
    position_designation:
      item.override_position_designation || item.position_designation,
  };
};

const contact = (row, owner, type) => {
  const item = row.contacts?.find(
    (value) => value.owner_role === owner && value.contact_type === type,
  );

  return item?.override_value ?? item?.value ?? "";
};

const valueOrDash = (value) =>
  value === null || value === undefined || String(value).trim() === ""
    ? "-"
    : value;

const hasOverride = (row) =>
  Boolean(
    row.override_lgu_name ||
      row.override_office_address ||
      row.officials?.some(
        (item) => item.override_name || item.override_position_designation,
      ) ||
      row.contacts?.some((item) => item.override_value),
  );

const primaryLdrrmoFor = (row) => {
  const officers = row.ldrrmo_officers?.length
    ? row.ldrrmo_officers
    : [
        {
          name: row.ldrrmo_name,
          designation: row.ldrrmo_position,
          mobile_number: row.ldrrmo_contact,
          email_address: row.ldrrmo_email,
          is_primary: true,
        },
      ];

  return (
    officers.find((officer) => officer.is_primary) ?? officers[0] ?? {}
  );
};

const rowFilterValue = (row) =>
  String(row.id ?? `${row.source_sheet}:${row.lgu_name}`);

const filterOptionLabel = (name, designation, lgu) =>
  [name, designation, lgu].filter(Boolean).join(" — ");

const displayDateTime = (value) =>
  value
    ? new Intl.DateTimeFormat("en-PH", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(value))
    : "-";

const levelStyles = {
  PLGU:
    "border border-violet-300 bg-violet-100 text-violet-800 ring-violet-300",
  CLGU: "border border-sky-300 bg-sky-100 text-sky-800 ring-sky-300",
  MLGU:
    "border border-emerald-300 bg-emerald-100 text-emerald-800 ring-emerald-300",
};

function LevelBadge({ level }) {
  return (
    <span
      className={`inline-flex rounded-full px-2 py-1 text-xs font-black ring-1 ${
        levelStyles[level] || "bg-slate-50 text-slate-600 ring-slate-200"
      }`}
    >
      {level || "-"}
    </span>
  );
}

function OfficialPhoto({ src, label, size = "row", logo = false }) {
  const dimensions =
    size === "profile"
      ? "h-28 w-28"
      : logo
        ? "h-20 w-24"
        : "h-20 w-20";
  const Icon = logo ? Building2 : UserRound;

  return (
    <span
      className={`relative z-0 flex ${dimensions} shrink-0 origin-center items-center justify-center overflow-hidden transition duration-300 ease-out hover:z-[30] hover:scale-[1.6] hover:shadow-2xl ${
        logo ? "rounded-xl" : "rounded-full"
      } border border-slate-200 bg-white text-brand-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-brand-100`}
    >
      {src ? (
        <img
          src={src}
          alt={label}
          className={`h-full w-full ${logo ? "object-contain p-2" : "object-cover"}`}
          loading="lazy"
        />
      ) : (
        <Icon className={size === "profile" ? "h-10 w-10" : "h-7 w-7"} />
      )}
    </span>
  );
}

function OfficialSummary({ photo, name, designation, role }) {
  return (
    <div className="flex min-w-0 items-center gap-4 py-1">
      <OfficialPhoto src={photo} label={`${name || role} photo`} />
      <div className="min-w-0">
        <p className="text-[10px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">
          {role}
        </p>
        <p className="mt-1 whitespace-normal break-words text-sm font-black leading-5 text-slate-900 dark:text-white">
          {valueOrDash(name)}
        </p>
        <p className="mt-1 whitespace-normal break-words text-xs font-bold leading-5 text-slate-500 dark:text-zinc-400">
          {valueOrDash(designation)}
        </p>
      </div>
    </div>
  );
}

export default function LguDirectoryPanel({
  entries = [],
  syncRuns = [],
  unmatched = [],
  isSuperAdmin = false,
}) {
  const [filters, setFilters] = useState({
    province: [],
    level: [],
    lgu: [],
    lce: [],
    lswd: [],
    ldrrmo: [],
  });
  const [viewing, setViewing] = useState(null);
  const set = (key, value) =>
    setFilters((current) => ({ ...current, [key]: value }));

  const directoryFilterOptions = useMemo(
    () => ({
      lgu: entries.map((row) => {
        const lgu = row.override_lgu_name || row.lgu_name;

        return {
          value: rowFilterValue(row),
          label: filterOptionLabel(
            lgu,
            row.lgu_level,
            provinces[row.source_sheet] || row.source_sheet,
          ),
        };
      }),
      lce: entries
        .map((row) => {
          const person = official(row, "lce");

          return person.name
            ? {
                value: rowFilterValue(row),
                label: filterOptionLabel(
                  person.name,
                  person.position_designation,
                  row.override_lgu_name || row.lgu_name,
                ),
              }
            : null;
        })
        .filter(Boolean),
      lswd: entries
        .map((row) => {
          const person = official(row, "lswd_officer");

          return person.name
            ? {
                value: rowFilterValue(row),
                label: filterOptionLabel(
                  person.name,
                  person.position_designation,
                  row.override_lgu_name || row.lgu_name,
                ),
              }
            : null;
        })
        .filter(Boolean),
      ldrrmo: entries
        .map((row) => {
          const person = primaryLdrrmoFor(row);

          return person.name
            ? {
                value: rowFilterValue(row),
                label: filterOptionLabel(
                  person.name,
                  person.designation,
                  row.override_lgu_name || row.lgu_name,
                ),
              }
            : null;
        })
        .filter(Boolean),
    }),
    [entries],
  );

  const rows = useMemo(
    () =>
      entries.filter((row) => {
        const filterValue = rowFilterValue(row);

        return (
          (filters.province.length === 0 ||
            filters.province.includes(row.source_sheet)) &&
          (filters.level.length === 0 ||
            filters.level.includes(row.lgu_level)) &&
          (filters.lgu.length === 0 || filters.lgu.includes(filterValue)) &&
          (filters.lce.length === 0 || filters.lce.includes(filterValue)) &&
          (filters.lswd.length === 0 || filters.lswd.includes(filterValue)) &&
          (filters.ldrrmo.length === 0 ||
            filters.ldrrmo.includes(filterValue))
        );
      }),
    [entries, filters],
  );

  const resetFilters = () =>
    setFilters({
      province: [],
      level: [],
      lgu: [],
      lce: [],
      lswd: [],
      ldrrmo: [],
    });

  return (
    <Card
      id="lgu-directory-library"
      className="mt-6 scroll-mt-28 overflow-hidden p-0"
    >
      <div className="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-950">
        <div>
          <h2 className="font-black uppercase text-brand-700">
            LGU Directory
          </h2>
          <p className="mt-1 text-sm text-slate-500">
            Select any LGU row to view its complete directory profile.
          </p>
        </div>
        {isSuperAdmin && (
          <button
            onClick={() =>
              confirm(
                "Sync the LGU directory from the live DSWD Caraga Regional Directory and the dedicated LDRRMO source?",
              ) && router.post("/lgu-library/sync", {}, { preserveScroll: true })
            }
            className="inline-flex items-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-black text-white"
          >
            <RefreshCw className="h-4 w-4" />
            Sync LGU Directory
          </button>
        )}
      </div>

      {unmatched.length > 0 && (
        <div className="border-b border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
          <p className="font-black">
            {unmatched.length} LDRRMO row(s) still need matching.
          </p>
        </div>
      )}

      {syncRuns.length > 0 && (
        <div className="border-b border-slate-200 px-5 py-2 text-xs text-slate-500">
          Last sync: <b>{syncRuns[0].status}</b> ·{" "}
          {displayDateTime(syncRuns[0].started_at)}
          {syncRuns[0].summary
            ? ` · ${syncRuns[0].summary.changed} changed · ${syncRuns[0].summary.missing} deactivated · ${syncRuns[0].summary.ldrrmo?.matched ?? 0} LDRRMO matched`
            : ""}
        </div>
      )}

      <div className="grid gap-3 border-b border-slate-200 bg-white p-4 md:grid-cols-2 xl:grid-cols-6 dark:border-zinc-800 dark:bg-zinc-950">
        <LookerMultiSelect
          label="Province"
          allLabel="All provinces"
          placeholder="Search province..."
          options={Object.entries(provinces).map(([value, label]) => ({
            value,
            label,
          }))}
          value={filters.province}
          onApply={(value) => set("province", value)}
          className="min-w-0"
        />
        <LookerMultiSelect
          label="Level"
          allLabel="All levels"
          placeholder="Search level..."
          options={["PLGU", "CLGU", "MLGU"].map((value) => ({
            value,
            label: value,
          }))}
          value={filters.level}
          onApply={(value) => set("level", value)}
          className="min-w-0"
        />
        {[
          ["lgu", "LGU", "All LGUs", "Search LGU..."],
          ["lce", "LCE", "All LCEs", "Search LCE..."],
          ["lswd", "LSWDO", "All LSWDOs", "Search LSWDO..."],
          ["ldrrmo", "LDRRMO", "All LDRRMOs", "Search LDRRMO..."],
        ].map(([key, label, allLabel, placeholder]) => (
          <LookerMultiSelect
            key={key}
            label={label}
            allLabel={allLabel}
            placeholder={placeholder}
            options={directoryFilterOptions[key]}
            value={filters[key]}
            onApply={(value) => set(key, value)}
            className="min-w-0"
          />
        ))}
        <div className="flex items-center justify-between text-xs text-slate-500 xl:col-span-6">
          <span>{rows.length} LGU record(s)</span>
          <button
            type="button"
            onClick={resetFilters}
            className="font-black text-brand-700"
          >
            Clear filters
          </button>
        </div>
      </div>

      <div className="max-h-[calc(100vh-18rem)] min-h-[480px] overflow-auto px-4 pb-4">
        <table className="w-full min-w-[1280px] table-fixed border-separate border-spacing-0 text-sm">
          <colgroup>
            <col className="w-[4%]" />
            <col className="w-[20%]" />
            <col className="w-[25.33%]" />
            <col className="w-[25.33%]" />
            <col className="w-[25.33%]" />
          </colgroup>
          <thead className="sticky top-0 z-20 bg-slate-100 shadow-sm dark:bg-zinc-900">
            <tr>
              {["#", "LGU", "Local Chief Executive", "LSWDO", "LDRRMO"].map(
                (label) => (
                  <th
                    key={label}
                    className="border-b px-4 py-3 text-left text-xs font-black uppercase"
                  >
                    {label}
                  </th>
                ),
              )}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <DirectoryRow
                key={row.id}
                row={row}
                index={index + 1}
                onView={() => setViewing(row)}
              />
            ))}
          </tbody>
        </table>
      </div>

      {viewing && (
        <DirectoryProfileModal
          row={viewing}
          isSuperAdmin={isSuperAdmin}
          onClose={() => setViewing(null)}
        />
      )}
    </Card>
  );
}

function DirectoryRow({ row, index, onView }) {
  const lce = official(row, "lce");
  const lswd = official(row, "lswd_officer");
  const ldrrmo = primaryLdrrmoFor(row);

  const openFromKeyboard = (event) => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      onView();
    }
  };

  return (
    <tr
      tabIndex={0}
      role="button"
      aria-label={`View ${row.override_lgu_name || row.lgu_name} profile`}
      onClick={onView}
      onKeyDown={openFromKeyboard}
      className="cursor-pointer border-b outline-none transition hover:bg-emerald-50/70 focus:bg-emerald-50 focus:ring-2 focus:ring-inset focus:ring-brand-500 dark:hover:bg-emerald-950/20 dark:focus:bg-emerald-950/30"
    >
      <td className="border-b px-4 py-4 text-center text-xs font-black">
        {index}
      </td>
      <td className="border-b px-4 py-4">
        <p className="whitespace-normal break-words text-base font-black leading-6 text-slate-900 dark:text-white">
          {row.override_lgu_name || row.lgu_name}
        </p>
        <p className="mt-1 text-xs font-bold text-slate-500">
          {provinces[row.source_sheet] || "-"}
        </p>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <LevelBadge level={row.lgu_level} />
          {hasOverride(row) && (
            <span className="rounded-full bg-violet-50 px-2 py-1 text-[10px] font-black text-violet-700">
              Local override
            </span>
          )}
        </div>
      </td>
      <td className="border-b px-4 py-4 align-middle">
        <OfficialSummary
          role="LCE"
          photo={row.lce_photo_url}
          name={lce.name}
          designation={lce.position_designation}
        />
      </td>
      <td className="border-b px-4 py-4 align-middle">
        <OfficialSummary
          role="LSWDO"
          photo={row.lswd_photo_url}
          name={lswd.name}
          designation={lswd.position_designation}
        />
      </td>
      <td className="border-b px-4 py-4 align-middle">
        <OfficialSummary
          role="LDRRMO"
          photo={row.ldrrmo_photo_url}
          name={ldrrmo.name}
          designation={ldrrmo.designation}
        />
      </td>
    </tr>
  );
}

function ProfileField({ label, value }) {
  return (
    <div className="min-w-0 rounded-lg bg-white p-3 dark:bg-zinc-950">
      <dt className="text-[10px] font-black uppercase tracking-wide text-slate-400">
        {label}
      </dt>
      <dd className="mt-1 whitespace-pre-line break-words text-sm font-bold leading-6 text-slate-800 dark:text-zinc-100">
        {valueOrDash(value)}
      </dd>
    </div>
  );
}

function ProfileSection({ title, photo, name, fields, children }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
        <OfficialPhoto
          src={photo}
          label={`${name || title} photo`}
          size="profile"
        />
        <div className="min-w-0">
          <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">
            {title}
          </p>
          <h4 className="mt-1 whitespace-normal break-words text-xl font-black leading-7 text-slate-950 dark:text-white">
            {valueOrDash(name)}
          </h4>
        </div>
      </div>
      <dl className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {fields.map(([label, value]) => (
          <ProfileField key={label} label={label} value={value} />
        ))}
      </dl>
      {children}
    </section>
  );
}

function DirectoryProfileModal({ row, isSuperAdmin = false, onClose }) {
  const lce = official(row, "lce");
  const lswd = official(row, "lswd_officer");
  const legacyLswdAlternate = official(row, "lswd_officer_alternate");
  const lswdoAlternates = row.lswdo_alternates?.length
    ? row.lswdo_alternates
    : [
        {
          name: legacyLswdAlternate.name || row.lswd_alternate_name,
          position:
            legacyLswdAlternate.position_designation ||
            row.lswd_alternate_position,
          contact_number:
            contact(row, "lswd_officer_alternate", "phone") ||
            row.lswd_alternate_contact_number,
        },
      ];
  const ldrrmoOfficers = row.ldrrmo_officers?.length
    ? [...row.ldrrmo_officers].sort(
        (left, right) =>
          Number(Boolean(right.is_primary)) -
          Number(Boolean(left.is_primary)),
      )
    : [primaryLdrrmoFor(row)];
  const primaryLdrrmo = ldrrmoOfficers[0] || {};

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/55 p-4 py-6 backdrop-blur-sm sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-labelledby="directory-profile-title"
    >
      <div className="flex max-h-[calc(100vh-3rem)] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
        <div className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
          <div className="flex min-w-0 items-center gap-4">
            <OfficialPhoto
              logo
              src={row.logo_url}
              label={`${row.override_lgu_name || row.lgu_name} logo`}
            />
            <div className="min-w-0">
              <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">
                LGU Profile
              </p>
              <h2
                id="directory-profile-title"
                className="mt-1 whitespace-normal break-words text-2xl font-black text-slate-950 dark:text-white"
              >
                {row.override_lgu_name || row.lgu_name}
              </h2>
              <p className="mt-1 text-sm font-bold text-slate-500">
                {provinces[row.source_sheet] || "-"}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close LGU profile"
            className="rounded-md p-2 text-slate-400 transition hover:bg-slate-200 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-white"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="min-h-0 space-y-5 overflow-y-auto p-5">
          <section className="rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
            <div className="mb-4 flex items-center gap-2">
              <Database className="h-5 w-5 text-brand-700" />
              <h3 className="text-sm font-black uppercase tracking-wide">
                LGU Information
              </h3>
            </div>
            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <ProfileField
                label="LGU Name"
                value={row.override_lgu_name || row.lgu_name}
              />
              <ProfileField
                label="Province"
                value={provinces[row.source_sheet]}
              />
              <ProfileField label="LGU Level" value={row.lgu_level} />
              <ProfileField label="PSGC Code" value={row.psgc_code} />
              <ProfileField
                label="Congressional District"
                value={
                  row.override_congressional_district ||
                  row.congressional_district
                }
              />
              <ProfileField
                label="Managed District"
                value={row.managed_district_name}
              />
              <ProfileField
                label="Office Address"
                value={row.override_office_address || row.office_address}
              />
              <ProfileField
                label="Source Updated"
                value={row.source_updated_label}
              />
            </dl>
          </section>

          <section className="rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
            <div className="mb-4 flex items-center gap-2">
              <UsersRound className="h-5 w-5 text-brand-700" />
              <div>
                <h3 className="text-sm font-black uppercase tracking-wide">
                  LGU Officials
                </h3>
                <p className="mt-1 text-xs text-slate-500">
                  Complete read-only directory information.
                </p>
              </div>
            </div>

            <div className="space-y-5">
              <ProfileSection
                title="Local Chief Executive"
                photo={row.lce_photo_url}
                name={lce.name}
                fields={[
                  ["Designation", lce.position_designation],
                  [
                    "LGU/LCE Office Email Address",
                    contact(row, "lce", "email"),
                  ],
                ]}
              />

              <ProfileSection
                title="LSWDO"
                photo={row.lswd_photo_url}
                name={lswd.name}
                fields={[
                  ["Position", lswd.position_designation],
                  [
                    "LSWD Office Email Address",
                    contact(row, "lswd_officer", "email") || row.lswd_email,
                  ],
                  [
                    "Alternate Email / Copy Furnish",
                    contact(row, "lswd_officer", "alternate_email") ||
                      row.lswd_alternate_email,
                  ],
                  [
                    "Contact Number",
                    contact(row, "lswd_officer", "phone") ||
                      row.lswd_contact_number,
                  ],
                  [
                    "Facebook Link",
                    contact(row, "lswd_officer", "facebook") ||
                      row.lswd_facebook,
                  ],
                ]}
              >
                <AlternateList
                  title="Alternate LSWDO Personnel"
                  rows={lswdoAlternates}
                  fields={[
                    ["Full Name", "name"],
                    ["Position", "position"],
                    ["Contact Number", "contact_number"],
                  ]}
                />
              </ProfileSection>

              <ProfileSection
                title="LDRRMO"
                photo={row.ldrrmo_photo_url}
                name={primaryLdrrmo.name}
                fields={[
                  ["Office", primaryLdrrmo.office],
                  ["Designation", primaryLdrrmo.designation],
                  ["Mobile Number", primaryLdrrmo.mobile_number],
                  ["Hotline Number", primaryLdrrmo.hotline_number],
                  ["Landline Number", primaryLdrrmo.landline_number],
                  ["Email Address", primaryLdrrmo.email_address],
                  [
                    "Alternate Email Address",
                    primaryLdrrmo.alternate_email_address,
                  ],
                  ["VHF Radio Frequency", primaryLdrrmo.vhf_radio_frequency],
                  ["Facebook", primaryLdrrmo.facebook],
                ]}
              >
                {ldrrmoOfficers.length > 1 && (
                  <AlternateList
                    title="Alternate LDRRMO Personnel"
                    rows={ldrrmoOfficers.slice(1)}
                    fields={[
                      ["Full Name", "name"],
                      ["Designation", "designation"],
                      ["Mobile Number", "mobile_number"],
                      ["Hotline Number", "hotline_number"],
                      ["Landline Number", "landline_number"],
                      ["Email Address", "email_address"],
                      ["Alternate Email", "alternate_email_address"],
                      ["Facebook", "facebook"],
                    ]}
                  />
                )}
              </ProfileSection>
            </div>
          </section>
        </div>
      </div>
    </div>
  );
}

function AlternateList({ title, rows, fields }) {
  const meaningfulRows = rows.filter((row) =>
    fields.some(([, key]) => String(row?.[key] ?? "").trim() !== ""),
  );

  return (
    <div className="mt-5 border-t border-slate-200 pt-4 dark:border-zinc-700">
      <div className="flex items-center justify-between gap-3">
        <p className="text-[10px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">
          {title}
        </p>
        <span className="rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-black text-brand-700 dark:bg-brand-950 dark:text-brand-100">
          {meaningfulRows.length}
        </span>
      </div>
      {meaningfulRows.length ? (
        <div className="mt-2 divide-y divide-slate-200 dark:divide-zinc-700">
          {meaningfulRows.map((row, index) => (
            <div
              key={row.id || index}
              className="grid gap-3 py-4 sm:grid-cols-2 lg:grid-cols-4"
            >
              <div className="flex items-center gap-3 lg:col-span-4">
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-xs font-black text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                  {index + 1}
                </span>
                <p className="font-black">
                  {valueOrDash(row.name)}
                </p>
              </div>
              {fields
                .filter(([, key]) => key !== "name")
                .map(([label, key]) => (
                  <div key={key}>
                    <p className="text-[10px] font-black uppercase text-slate-400">
                      {label}
                    </p>
                    <p className="mt-1 whitespace-pre-line break-words text-sm font-bold leading-6">
                      {valueOrDash(row[key])}
                    </p>
                  </div>
                ))}
            </div>
          ))}
        </div>
      ) : (
        <p className="mt-3 text-sm font-bold text-slate-500">-</p>
      )}
    </div>
  );
}
