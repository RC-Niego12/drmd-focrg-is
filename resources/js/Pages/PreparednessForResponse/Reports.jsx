import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";
import {
  Download,
  Eye,
  FileText,
  History,
  Pencil,
  Plus,
  Trash2,
  X,
} from "lucide-react";
import AppLayout from "@/Layouts/AppLayout";

const localInput = (date) =>
  new Date(date.getTime() - date.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
const readable = (value) =>
  value
    ? new Date(value).toLocaleString("en-PH", {
        month: "long",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
      })
    : "—";
const statusClass = (status) =>
  status === "finalized"
    ? "bg-emerald-100 text-emerald-800"
    : status === "revised"
      ? "bg-violet-100 text-violet-800"
      : "bg-amber-100 text-amber-800";

function ActionButton({ href, onClick, icon: Icon, children, tone = "blue" }) {
  const style = {
    blue: "border-blue-200 bg-white text-blue-900 hover:bg-blue-50",
    solid: "border-blue-900 bg-blue-900 text-white hover:bg-blue-800",
    amber: "border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100",
    green: "border-emerald-700 bg-emerald-700 text-white hover:bg-emerald-800",
  }[tone];
  const className = `dromis-tip relative inline-flex h-9 w-9 items-center justify-center rounded-lg border ${style}`;
  const props = {
    className,
    "aria-label": children,
    "data-tip": children,
    "data-tip-side": "top",
  };
  return href ? (
    <Link href={href} {...props}>
      <Icon className="h-4 w-4" />
    </Link>
  ) : (
    <button type="button" onClick={onClick} {...props}>
      <Icon className="h-4 w-4" />
    </button>
  );
}

export default function PreparednessReports({ reports = [] }) {
  const [tab, setTab] = useState("reports");
  const [creating, setCreating] = useState(false);
  const [schedule, setSchedule] = useState("");
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState({});
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [reviseTarget, setReviseTarget] = useState(null);
  const drafts = reports.filter((report) =>
    ["draft", "revised"].includes(report.status),
  ).length;
  const history = reports.filter((report) => report.status === "finalized");
  const beforeCutoff = (report) =>
    new Date(report.revision_deadline) >= new Date();
  const canContinue = (report) =>
    ["draft", "revised"].includes(report.status) && beforeCutoff(report);

  const create = (event) => {
    event.preventDefault();
    setProcessing(true);
    setErrors({});
    router.post(
      "/preparedness-for-response/reports",
      { reporting_schedule: schedule },
      { onError: setErrors, onFinish: () => setProcessing(false) },
    );
  };
  const remove = () => {
    if (!deleteTarget) return;
    setProcessing(true);
    router.delete(`/preparedness-for-response/reports/${deleteTarget.id}`, {
      preserveScroll: true,
      onSuccess: () => setDeleteTarget(null),
      onFinish: () => setProcessing(false),
    });
  };
  const revise = () => {
    if (!reviseTarget) return;
    setProcessing(true);
    router.patch(
      `/preparedness-for-response/reports/${reviseTarget.id}/revise`,
      {},
      {
        onSuccess: () => setReviseTarget(null),
        onFinish: () => setProcessing(false),
      },
    );
  };

  return (
    <AppLayout title="Preparedness for Response Reports">
      <Head title="Preparedness for Response Reports" />
      <div className="space-y-5 pb-8">
        <header className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="h-2 bg-gradient-to-r from-blue-900 via-blue-700 to-red-500" />
          <div className="flex flex-col gap-5 p-7 md:flex-row md:items-center md:justify-between">
            <div>
              <p className="text-[10px] font-black uppercase tracking-[.22em] text-red-600">
                DROMIS reporting workspace
              </p>
              <h1 className="mt-2 text-3xl font-black text-blue-950">
                Preparedness for Response Reports
              </h1>
              <p className="mt-1 text-sm text-slate-500">
                Schedule reports, continue drafts, and manage finalized report
                history.
              </p>
            </div>
            <button
              type="button"
              onClick={() => setCreating(true)}
              className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-900 px-5 py-3 text-xs font-black uppercase text-white"
            >
              <Plus className="h-4 w-4" />
              Create report
            </button>
          </div>
        </header>
        <div className="grid gap-3 sm:grid-cols-3">
          {[
            ["All reports", reports.length, "text-blue-950"],
            ["Drafts", drafts, "text-amber-700"],
            ["Finalized", history.length, "text-emerald-700"],
          ].map(([label, value, tone]) => (
            <div
              key={label}
              className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
              <p className="text-[10px] font-black uppercase text-slate-500">
                {label}
              </p>
              <p className={`mt-2 text-3xl font-black ${tone}`}>{value}</p>
            </div>
          ))}
        </div>
        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="flex gap-1 border-b bg-slate-50 px-5 pt-3">
            <button
              onClick={() => setTab("reports")}
              className={`inline-flex items-center gap-2 border-b-2 px-5 py-3 text-xs font-black uppercase ${tab === "reports" ? "border-blue-700 bg-white text-blue-900" : "border-transparent text-slate-500"}`}
            >
              <FileText className="h-4 w-4" />
              Reports
            </button>
            <button
              onClick={() => setTab("history")}
              className={`inline-flex items-center gap-2 border-b-2 px-5 py-3 text-xs font-black uppercase ${tab === "history" ? "border-blue-700 bg-white text-blue-900" : "border-transparent text-slate-500"}`}
            >
              <History className="h-4 w-4" />
              Report history
            </button>
          </div>
          {tab === "reports" ? (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[1280px] table-fixed text-left text-xs">
                <thead className="bg-gradient-to-r from-blue-950 to-blue-800 text-white">
                  <tr>
                    <th className="w-16 px-4 py-4 text-center">No.</th>
                    <th className="w-[23%] px-4 py-4">Report Title</th>
                    <th className="w-[10%] px-4 py-4">Status</th>
                    <th className="w-[16%] px-4 py-4">Reporting Schedule</th>
                    <th className="w-[13%] px-4 py-4">Created By</th>
                    <th className="w-[16%] px-4 py-4">Date and Time Created</th>
                    <th className="px-4 py-4 text-center">Action Buttons</th>
                  </tr>
                </thead>
                <tbody>
                  {reports.length ? (
                    reports.map((report, index) => (
                      <tr
                        key={report.id}
                        className="border-b odd:bg-white even:bg-slate-50/70 hover:bg-blue-50/50"
                      >
                        <td className="px-4 py-4 text-center font-black text-blue-900">
                          {index + 1}
                        </td>
                        <td className="px-4 py-4 font-black text-slate-900">
                          {report.title}
                        </td>
                        <td className="px-4 py-4">
                          <span
                            className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${statusClass(report.status)}`}
                          >
                            {report.status}
                          </span>
                        </td>
                        <td className="px-4 py-4 font-semibold text-slate-700">
                          {readable(report.reporting_as_of)}
                        </td>
                        <td className="px-4 py-4 text-slate-700">
                          {report.creator?.name ?? "Unknown"}
                        </td>
                        <td className="px-4 py-4 text-slate-700">
                          {readable(report.created_at)}
                        </td>
                        <td className="px-4 py-4">
                          <div className="flex flex-wrap justify-center gap-1.5">
                            <ActionButton
                              href={`/preparedness-for-response/reports/${report.id}`}
                              icon={Eye}
                            >
                              View
                            </ActionButton>
                            {report.status === "finalized" && (
                              <ActionButton
                                href={`/preparedness-for-response/reports/${report.id}?download=ppt`}
                                icon={Download}
                                tone="solid"
                              >
                                Download
                              </ActionButton>
                            )}
                            {report.status === "finalized" &&
                              beforeCutoff(report) && (
                                <ActionButton
                                  onClick={() => setReviseTarget(report)}
                                  icon={Pencil}
                                  tone="amber"
                                >
                                  Revise
                                </ActionButton>
                              )}
                            {canContinue(report) && (
                              <ActionButton
                                href={`/preparedness-for-response/reports/${report.id}`}
                                icon={Pencil}
                                tone="green"
                              >
                                Continue editing
                              </ActionButton>
                            )}
                            <button
                              type="button"
                              onClick={() => setDeleteTarget(report)}
                              className="dromis-tip relative inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-200 text-red-600 hover:bg-red-50"
                              aria-label={`Delete ${report.title}`}
                              data-tip="Delete"
                              data-tip-side="top"
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td
                        colSpan="7"
                        className="p-16 text-center text-slate-500"
                      >
                        No reports have been created.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[950px] text-left text-xs">
                <thead className="bg-slate-100 text-blue-950">
                  <tr>
                    <th className="w-16 px-5 py-4 text-center">No.</th>
                    <th className="px-5 py-4">Report Title</th>
                    <th className="px-5 py-4">Reporting Schedule</th>
                    <th className="px-5 py-4">Finalized By</th>
                    <th className="px-5 py-4">Date and Time Finalized</th>
                    <th className="px-5 py-4 text-center">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {history.length ? (
                    history.map((report, index) => (
                      <tr key={report.id} className="border-t">
                        <td className="px-5 py-4 text-center font-black text-blue-900">
                          {index + 1}
                        </td>
                        <td className="px-5 py-4 font-black">{report.title}</td>
                        <td className="px-5 py-4">
                          {readable(report.reporting_as_of)}
                        </td>
                        <td className="px-5 py-4">
                          {report.finalizer?.name ?? "Unknown"}
                        </td>
                        <td className="px-5 py-4">
                          {readable(report.finalized_at)}
                        </td>
                        <td className="px-5 py-4 text-center">
                          <ActionButton
                            href={`/preparedness-for-response/reports/${report.id}`}
                            icon={Eye}
                          >
                            View
                          </ActionButton>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td
                        colSpan="6"
                        className="p-16 text-center text-slate-500"
                      >
                        No finalized report history is available.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
      {creating && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 p-5">
          <form
            onSubmit={create}
            className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"
          >
            <div className="flex justify-between">
              <div>
                <p className="text-[10px] font-black uppercase text-blue-600">
                  New report
                </p>
                <h2 className="mt-1 text-xl font-black text-blue-950">
                  Create Preparedness Report
                </h2>
              </div>
              <button
                type="button"
                onClick={() => setCreating(false)}
                className="rounded-lg border p-2"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            <p className="mt-4 text-sm text-slate-500">
              The Opening Page event title will be used as the report title.
              Revisions lock after the reporting schedule.
            </p>
            <label className="mt-5 block text-xs font-black uppercase">
              Reporting schedule
              <input
                autoFocus
                required
                type="datetime-local"
                min={localInput(new Date(Date.now() + 60000))}
                value={schedule}
                onChange={(event) => setSchedule(event.target.value)}
                className="mt-2 w-full rounded-xl border px-4 py-3 text-sm normal-case"
              />
              {errors.reporting_schedule && (
                <span className="mt-2 block text-xs normal-case text-red-600">
                  {errors.reporting_schedule}
                </span>
              )}
            </label>
            <div className="mt-6 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setCreating(false)}
                className="rounded-xl border px-4 py-2.5 text-xs font-black uppercase"
              >
                Cancel
              </button>
              <button
                disabled={processing}
                className="rounded-xl bg-blue-900 px-5 py-2.5 text-xs font-black uppercase text-white"
              >
                {processing ? "Creating…" : "Create draft"}
              </button>
            </div>
          </form>
        </div>
      )}
      {reviseTarget && (
        <ConfirmModal
          tone="amber"
          icon={Pencil}
          title="Revise this report?"
          message={`${reviseTarget.title} will return to Draft status. Download will be disabled until it is finalized again.`}
          cancel={() => setReviseTarget(null)}
          confirm={revise}
          processing={processing}
          confirmLabel="Revise report"
        />
      )}
      {deleteTarget && (
        <ConfirmModal
          tone="red"
          icon={Trash2}
          title="Delete this report?"
          message={`${deleteTarget.title} and all its encoded pages will be permanently removed. This cannot be undone.`}
          cancel={() => setDeleteTarget(null)}
          confirm={remove}
          processing={processing}
          confirmLabel="Delete report"
        />
      )}
    </AppLayout>
  );
}

function ConfirmModal({
  tone,
  icon: Icon,
  title,
  message,
  cancel,
  confirm,
  processing,
  confirmLabel,
}) {
  const color =
    tone === "red" ? "bg-red-100 text-red-700" : "bg-amber-100 text-amber-700";
  const button = tone === "red" ? "bg-red-600" : "bg-amber-600";
  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 p-5">
      <div
        role="dialog"
        aria-modal="true"
        className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"
      >
        <div
          className={`flex h-12 w-12 items-center justify-center rounded-full ${color}`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <h2 className="mt-4 text-xl font-black">{title}</h2>
        <p className="mt-2 text-sm leading-relaxed text-slate-600">{message}</p>
        <div className="mt-6 flex justify-end gap-2">
          <button
            onClick={cancel}
            className="rounded-xl border px-4 py-2.5 text-xs font-black uppercase"
          >
            Cancel
          </button>
          <button
            onClick={confirm}
            disabled={processing}
            className={`rounded-xl px-5 py-2.5 text-xs font-black uppercase text-white ${button}`}
          >
            {processing ? "Processing…" : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
