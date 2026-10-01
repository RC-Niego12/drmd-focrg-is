import { Head, Link } from "@inertiajs/react";
import { ArrowLeft, CalendarClock, MapPin, Package, Truck } from "lucide-react";
import AppLayout, { Card } from "@/Layouts/AppLayout";
import { formatDateTime } from "@/Utils/dateFormat";
import { formatWholeQuantity } from "@/Utils/wholeQuantity";

const statusLabel = (value) => String(value || "")
  .replaceAll("_", " ")
  .replace(/\b\w/g, (letter) => letter.toUpperCase());

function DetailBlock({ icon: Icon, title, children }) {
  return (
    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3">
        <Icon className="h-4 w-4 text-emerald-700" />
        <h2 className="text-sm font-black uppercase tracking-wide text-slate-800">{title}</h2>
      </div>
      <div className="space-y-3 p-4 text-sm text-slate-700">{children}</div>
    </section>
  );
}

function Fact({ label, value }) {
  return (
    <div>
      <p className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-0.5 font-semibold text-slate-900">{value || "—"}</p>
    </div>
  );
}

export default function Show({ plan }) {
  const vehicles = plan?.vehicles || [];
  const local = plan?.local_handover || null;
  const items = plan?.items || [];
  const request = plan?.request || {};

  return (
    <AppLayout
      title="Dispatch plan"
      breadcrumbs={[
        { label: "DROMIC / SitRep", href: "/lgu/dromic-sitrep?tab=requests" },
        { label: plan?.dispatch_number || "Plan details" },
      ]}
    >
      <Head title={`${plan?.dispatch_number || "Dispatch plan"} · Planned release`} />

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-[11px] font-black uppercase tracking-[.16em] text-emerald-700">Planned for release / delivery</p>
          <h1 className="mt-1 text-2xl font-black text-slate-950">{plan?.dispatch_number}</h1>
          <p className="mt-1 text-sm font-semibold text-slate-500">
            {request.reference_number || "Request"}
            {request.relief_request_reference ? ` · ${request.relief_request_reference}` : ""}
            {plan?.ris?.ris_number ? ` · ${plan.ris.ris_number}` : ""}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className="rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
            {statusLabel(plan?.status)}
          </span>
          <Link
            href="/lgu/dromic-sitrep?tab=requests"
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase text-slate-700 hover:bg-slate-50"
          >
            <ArrowLeft className="h-3.5 w-3.5" /> Back to requests
          </Link>
        </div>
      </div>

      <Card className="mb-4 border-emerald-200 bg-emerald-50/60 p-4 text-sm font-semibold text-emerald-950">
        DSWD has completed the dispatch/delivery plan for your request. Review what will be released, when it is scheduled, where it will move from/to, and how delivery or pickup is arranged.
      </Card>

      <div className="grid gap-4 xl:grid-cols-2">
        <DetailBlock icon={Package} title="What — items planned">
          {items.length === 0 ? (
            <p className="text-slate-500">No allocated items were found on this plan.</p>
          ) : (
            <div className="overflow-hidden rounded-xl border border-slate-200">
              <table className="min-w-full text-left text-xs">
                <thead className="bg-slate-50 text-[10px] font-black uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Item</th>
                    <th className="px-3 py-2">Source warehouse</th>
                    <th className="px-3 py-2 text-right">Allocated</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item, index) => (
                    <tr key={`${item.name}-${index}`} className="border-t border-slate-100">
                      <td className="px-3 py-2 font-bold text-slate-900">
                        {item.name}
                        {item.unit ? <span className="mt-0.5 block font-semibold text-slate-500">{item.unit}</span> : null}
                      </td>
                      <td className="px-3 py-2">{item.warehouse || "—"}</td>
                      <td className="px-3 py-2 text-right tabular-nums font-black">{formatWholeQuantity(item.allocated, "0")}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </DetailBlock>

        <DetailBlock icon={MapPin} title="Where — destination & sources">
          <div className="grid gap-3 sm:grid-cols-2">
            <Fact label="Delivery site / destination" value={plan?.destination} />
            <Fact label="Receiving agency / LGU" value={plan?.receiving_agency_lgu || request.requesting_agency} />
            <Fact label="Municipality" value={request.municipality} />
            <Fact label="Province" value={request.province} />
            <Fact label="Purpose" value={plan?.purpose || request.purpose} />
          </div>
          {local ? (
            <div className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2">
              <p className="text-[10px] font-black uppercase tracking-wide text-sky-700">No-transport source</p>
              <p className="mt-1 font-bold text-sky-950">{local.source_warehouse_name || "Local warehouse"}</p>
              <p className="mt-1 text-xs text-sky-800">Items already at recipient custody — no vehicle trip required.</p>
            </div>
          ) : null}
        </DetailBlock>

        <DetailBlock icon={CalendarClock} title="When — planned schedule">
          {local ? (
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <p className="text-[10px] font-black uppercase tracking-wide text-slate-500">Local / no-transport plan</p>
              <div className="mt-2 grid gap-3 sm:grid-cols-2">
                <Fact label="Expected release" value={formatDateTime(local.expected_release_at, "Not set")} />
                <Fact label="Plan confirmed" value={formatDateTime(local.plan_confirmed_at, "Not yet")} />
              </div>
              {local.planning_remarks ? <p className="mt-2 text-xs text-slate-600">{local.planning_remarks}</p> : null}
            </div>
          ) : null}
          {vehicles.length === 0 && !local ? (
            <p className="text-slate-500">No schedule details are available yet.</p>
          ) : null}
          {vehicles.map((vehicle) => (
            <div key={vehicle.index} className="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <p className="font-black text-slate-900">{vehicle.label}{vehicle.plate ? ` · ${vehicle.plate}` : ""}</p>
              <div className="mt-2 grid gap-3 sm:grid-cols-2">
                <Fact label="Estimated departure" value={formatDateTime(vehicle.estimated_departure, "Not set")} />
                <Fact label="Estimated arrival" value={formatDateTime(vehicle.estimated_arrival, "Not set")} />
                <Fact label="Plan confirmed" value={formatDateTime(vehicle.plan_confirmed_at, "Not yet")} />
              </div>
              {vehicle.planning_remarks ? <p className="mt-2 text-xs text-slate-600">{vehicle.planning_remarks}</p> : null}
            </div>
          ))}
        </DetailBlock>

        <DetailBlock icon={Truck} title="How — delivery / pickup arrangement">
          {local ? (
            <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
              <p className="font-black text-emerald-950">Direct release at source warehouse</p>
              <p className="mt-1 text-xs text-emerald-800">No transport vehicle. Recipient takes custody at {local.source_warehouse_name || "the local warehouse"}.</p>
              {local.dr_number ? <p className="mt-2 text-xs font-bold text-emerald-900">DR: {local.dr_number}</p> : null}
            </div>
          ) : null}
          {vehicles.length === 0 && !local ? (
            <p className="text-slate-500">No vehicle or pickup arrangement has been encoded yet.</p>
          ) : null}
          {vehicles.map((vehicle) => (
            <div key={`how-${vehicle.index}`} className="rounded-xl border border-slate-200 p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-black text-slate-900">{vehicle.label}</p>
                <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wide text-amber-900 ring-1 ring-amber-200">
                  {vehicle.arrangement}
                </span>
              </div>
              <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <Fact label="Source warehouse" value={vehicle.source_warehouse_name} />
                <Fact label="Mode of transportation" value={vehicle.mode_of_transportation} />
                <Fact label="Vehicle type" value={vehicle.vehicle_type} />
                <Fact label="Plate number" value={vehicle.plate} />
                <Fact label="Driver / transported by" value={vehicle.driver} />
                <Fact label="Driver contact" value={vehicle.driver_contact} />
                <Fact label="Delivery receipt" value={vehicle.dr_number} />
              </div>
              {vehicle.loaded_items?.length > 0 ? (
                <div className="mt-3 overflow-hidden rounded-lg border border-slate-200">
                  <table className="min-w-full text-left text-xs">
                    <thead className="bg-slate-50 text-[10px] font-black uppercase tracking-wide text-slate-500">
                      <tr>
                        <th className="px-3 py-2">Load on this vehicle</th>
                        <th className="px-3 py-2 text-right">Planned qty</th>
                      </tr>
                    </thead>
                    <tbody>
                      {vehicle.loaded_items.map((line, lineIndex) => (
                        <tr key={`${vehicle.index}-${lineIndex}`} className="border-t border-slate-100">
                          <td className="px-3 py-2 font-semibold">{line.item_name}</td>
                          <td className="px-3 py-2 text-right tabular-nums font-black">{formatWholeQuantity(line.planned_quantity, "0")}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}
            </div>
          ))}
        </DetailBlock>
      </div>
    </AppLayout>
  );
}
