import { Head } from "@inertiajs/react";
import { AlertTriangle, Camera, ChevronLeft, ChevronRight, Clock3, Download, Eye, MapPin, PackageCheck, Search, Truck, X } from "lucide-react";
import * as maplibregl from "maplibre-gl";
import "maplibre-gl/dist/maplibre-gl.css";
import { useEffect, useMemo, useRef, useState } from "react";
import AppLayout from "@/Layouts/AppLayout";
import { formatDateTime } from "@/Utils/dateFormat";

const stageLabel = (value) => String(value || "No field update").replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
const statusLabel = (value) => stageLabel(value);
const stageTone = (stage) => ({ delay: "bg-amber-100 text-amber-900", incident: "bg-rose-100 text-rose-800", unloading_completed: "bg-emerald-100 text-emerald-800", arrived: "bg-teal-100 text-teal-800", departed: "bg-blue-100 text-blue-800" }[stage] || "bg-indigo-100 text-indigo-800");

function LocationMap({ update }) {
  const lat = Number(update?.latitude);
  const lon = Number(update?.longitude);
  const containerRef = useRef(null);
  const [mapFailed, setMapFailed] = useState(false);
  const hasCoordinates = Number.isFinite(lat) && Number.isFinite(lon);

  useEffect(() => {
    if (!hasCoordinates || !containerRef.current) return undefined;
    setMapFailed(false);
    const geoapifyKey = String(import.meta.env.VITE_GEOAPIFY_API_KEY || "").trim();
    const style = geoapifyKey
      ? `https://maps.geoapify.com/v1/styles/osm-bright/style.json?apiKey=${encodeURIComponent(geoapifyKey)}`
      : { version: 8, sources: { osm: { type: "raster", tiles: ["https://tile.openstreetmap.org/{z}/{x}/{y}.png"], tileSize: 256, attribution: "© OpenStreetMap contributors" } }, layers: [{ id: "osm", type: "raster", source: "osm" }] };
    const map = new maplibregl.Map({ container: containerRef.current, style, center: [lon, lat], zoom: 16 });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), "top-right");
    new maplibregl.Marker({ color: "#e11d48" }).setLngLat([lon, lat]).addTo(map);
    map.once("load", () => window.setTimeout(() => map.resize(), 0));
    map.on("error", (event) => { if (event?.error) setMapFailed(true); });
    return () => map.remove();
  }, [hasCoordinates, lat, lon, update?.id]);

  if (!hasCoordinates) return <div className="flex min-h-52 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm font-semibold text-slate-500"><MapPin className="mr-2 h-5 w-5" /> No GPS coordinates were recorded.</div>;
  const fullMapUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=17/${lat}/${lon}`;
  return <div className="overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
    <div className="relative h-64 w-full">
      <div ref={containerRef} className="absolute inset-0" aria-label="Recorded delivery location map" />
      {mapFailed && <div className="absolute inset-x-3 bottom-3 z-10 rounded-lg bg-slate-950/90 p-3 text-xs font-semibold text-white shadow-lg">Map tiles are temporarily unavailable. The recorded coordinates remain available below. <a href={fullMapUrl} target="_blank" rel="noreferrer" className="ml-1 font-black text-cyan-300 underline">Open full map</a></div>}
    </div>
    <div className="grid grid-cols-2 gap-2 border-t border-slate-200 bg-white p-3 text-xs">
      <p><span className="block font-black uppercase text-slate-400">Latitude</span>{lat.toFixed(7)}</p>
      <p><span className="block font-black uppercase text-slate-400">Longitude</span>{lon.toFixed(7)}</p>
      {update.accuracy_meters != null && <p className="font-semibold text-amber-700">GPS accuracy: ±{Number(update.accuracy_meters).toFixed(0)} meters</p>}
      <a href={fullMapUrl} target="_blank" rel="noreferrer" className="text-right font-black text-blue-700 underline">Open full map</a>
    </div>
  </div>;
}

function DispatchViewer({ dispatch, initialUpdateId, initialOperation, initialStage, onClose }) {
  const allUpdates = dispatch?.updates || [];
  const hasLocalHandover = Boolean(dispatch?.local_handover?.source_warehouse_name);
  const hasTransport = Boolean(dispatch?.transport_required);
  const mixedOperation = hasLocalHandover && hasTransport;
  const focusedUpdate = allUpdates.find((row) => Number(row.id) === Number(initialUpdateId));
  const [vehicleView, setVehicleView] = useState(focusedUpdate ? focusedUpdate.vehicle_index : "all");
  const updates = vehicleView === "all" ? allUpdates : allUpdates.filter((row) => Number(row.vehicle_index) === Number(vehicleView));
  const initialIndex = Math.max(0, updates.findIndex((row) => Number(row.id) === Number(initialUpdateId)));
  const [updateIndex, setUpdateIndex] = useState(initialIndex);
  const [photoIndex, setPhotoIndex] = useState(0);
  const [expandedPhotoIndex, setExpandedPhotoIndex] = useState(null);
  const [operationView, setOperationView] = useState(initialOperation === "local" && hasLocalHandover ? "local" : (hasTransport ? "transport" : "local"));
  const vehicleViewInitialized = useRef(false);
  const showingLocal = operationView === "local";
  const update = updates[updateIndex] || null;
  const photos = update?.photos || [];
  const localWarehouseId = Number(dispatch?.local_handover?.source_warehouse_id);
  const hasLocalWarehouseId = Number.isFinite(localWarehouseId) && localWarehouseId > 0;
  const localWarehouseName = String(dispatch?.local_handover?.source_warehouse_name || "").trim().toLowerCase();
  const operationItems = showingLocal
    ? (dispatch.items || []).filter((item) => (hasLocalWarehouseId && Number(item.warehouse_id) === localWarehouseId) || String(item.warehouse || "").trim().toLowerCase() === localWarehouseName)
    : (mixedOperation ? (dispatch.items || []).filter((item) => !((hasLocalWarehouseId && Number(item.warehouse_id) === localWarehouseId) || String(item.warehouse || "").trim().toLowerCase() === localWarehouseName)) : dispatch.items || []);
  const selectedVehicle = vehicleView === "all" ? null : dispatch.vehicles.find((vehicle) => Number(vehicle.index) === Number(vehicleView));
  const visibleVehicles = selectedVehicle ? [selectedVehicle] : dispatch.vehicles;
  const selectedLoadedItems = selectedVehicle?.loaded_items || [];
  const visibleItems = !showingLocal && selectedVehicle && selectedLoadedItems.length
    ? operationItems.filter((item) => selectedLoadedItems.some((loaded) => String(loaded.requisition_issuance_item_id) === String(item.requisition_issuance_item_id)))
    : operationItems;
  const displayedQuantity = (item) => {
    if (showingLocal) return item.allocated;
    if (!selectedVehicle) return item.loaded;
    return selectedLoadedItems.find((loaded) => String(loaded.requisition_issuance_item_id) === String(item.requisition_issuance_item_id))?.quantity ?? 0;
  };
  useEffect(() => setPhotoIndex(0), [update?.id]);
  useEffect(() => setExpandedPhotoIndex(null), [update?.id]);
  useEffect(() => {
    if (expandedPhotoIndex === null) return undefined;
    const onKeyDown = (event) => {
      if (event.key === "Escape") setExpandedPhotoIndex(null);
      if (event.key === "ArrowLeft") setExpandedPhotoIndex((value) => Math.max(0, value - 1));
      if (event.key === "ArrowRight") setExpandedPhotoIndex((value) => Math.min(photos.length - 1, value + 1));
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [expandedPhotoIndex, photos.length]);
  useEffect(() => {
    // Preserve the exact update selected by a notification on first mount.
    // Only reset to the latest update after the user manually changes vehicle.
    if (!vehicleViewInitialized.current) {
      vehicleViewInitialized.current = true;
      return;
    }
    setUpdateIndex(0);
    setPhotoIndex(0);
  }, [vehicleView]);

  return <div className="fixed inset-0 z-[260] overflow-y-auto bg-slate-950/80 p-0 backdrop-blur-sm sm:p-4" role="dialog" aria-modal="true" aria-label="Delivery update details">
    <div className="mx-auto flex min-h-[100dvh] w-full max-w-7xl flex-col overflow-hidden bg-white shadow-2xl sm:min-h-0 sm:max-h-[calc(100dvh-2rem)] sm:rounded-2xl">
      <header className="sticky top-0 z-20 flex items-start justify-between gap-3 border-b border-slate-200 bg-slate-950 px-4 py-4 text-white sm:px-6">
        <div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">Read-only delivery situation</p><h2 className="truncate text-lg font-black sm:text-2xl">{dispatch.dispatch_number}</h2><p className="truncate text-xs text-slate-300">{dispatch.request?.reference_number} · {dispatch.destination}</p></div>
        <button type="button" onClick={onClose} className="flex min-h-11 min-w-11 items-center justify-center rounded-full border border-white/20 bg-white/10 hover:bg-white/20" aria-label="Close viewer"><X className="h-5 w-5" /></button>
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto p-3 sm:p-5">
        <div className="mb-4 grid gap-3 sm:grid-cols-4">
          <div className="rounded-xl bg-emerald-50 p-3"><p className="text-[10px] font-black uppercase text-emerald-700">Current status</p><p className="mt-1 font-black text-emerald-950">{statusLabel(dispatch.status)}</p></div>
          <div className="rounded-xl bg-blue-50 p-3"><p className="text-[10px] font-black uppercase text-blue-700">Operation</p><p className="mt-1 font-black text-blue-950">{showingLocal ? "No transport" : `${dispatch.vehicles.length} vehicle${dispatch.vehicles.length === 1 ? "" : "s"}`}</p></div>
          <div className="rounded-xl bg-violet-50 p-3"><p className="text-[10px] font-black uppercase text-violet-700">Field updates</p><p className="mt-1 font-black text-violet-950">{showingLocal ? 0 : updates.length}</p></div>
          <div className="rounded-xl bg-cyan-50 p-3"><p className="text-[10px] font-black uppercase text-cyan-700">Evidence photos</p><p className="mt-1 font-black text-cyan-950">{showingLocal ? 0 : updates.reduce((total, row) => total + (row.photos?.length || 0), 0)}</p></div>
        </div>

        {mixedOperation && <div className="mb-4 grid grid-cols-2 gap-1 rounded-xl border border-slate-200 bg-slate-100 p-1" role="tablist" aria-label="Dispatch operations">
          <button type="button" role="tab" aria-selected={!showingLocal} onClick={() => setOperationView("transport")} className={`min-h-11 rounded-lg px-3 py-2 text-xs font-black ${!showingLocal ? "bg-slate-950 text-white shadow" : "text-slate-600 hover:bg-white"}`}><Truck className="mr-2 inline h-4 w-4" />Transport delivery ({dispatch.vehicles.length})</button>
          <button type="button" role="tab" aria-selected={showingLocal} onClick={() => setOperationView("local")} className={`min-h-11 rounded-lg px-3 py-2 text-xs font-black ${showingLocal ? "bg-emerald-700 text-white shadow" : "text-slate-600 hover:bg-white"}`}><PackageCheck className="mr-2 inline h-4 w-4" />No-transport release</button>
        </div>}

        {showingLocal && initialStage && <div className="mb-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3"><span className="rounded-full bg-emerald-700 px-3 py-1 text-[10px] font-black uppercase text-white">{initialStage === "received" ? "Receipt confirmed" : "Items released"}</span><p className="text-xs font-semibold text-emerald-900">Opened from the selected local handover notification.</p></div>}

        {!showingLocal && dispatch.vehicles.length > 1 && <div className="mb-4">
          <p className="mb-2 text-[10px] font-black uppercase tracking-wider text-slate-500">Choose a vehicle</p>
          <div className="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Transport vehicles">
            <button type="button" role="tab" aria-selected={vehicleView === "all"} onClick={() => setVehicleView("all")} className={`min-h-11 shrink-0 rounded-lg border px-4 py-2 text-xs font-black ${vehicleView === "all" ? "border-blue-700 bg-blue-700 text-white" : "border-slate-200 bg-white text-slate-600"}`}>All vehicles</button>
            {dispatch.vehicles.map((vehicle) => <button key={vehicle.index} type="button" role="tab" aria-selected={Number(vehicleView) === Number(vehicle.index)} onClick={() => setVehicleView(vehicle.index)} className={`min-h-11 shrink-0 rounded-lg border px-4 py-2 text-left text-xs ${Number(vehicleView) === Number(vehicle.index) ? "border-slate-950 bg-slate-950 text-white" : "border-slate-200 bg-white text-slate-700"}`}><span className="block font-black">Vehicle {vehicle.index + 1} · {vehicle.plate || "No plate"}</span><span className="block opacity-70">{vehicle.type || "Vehicle type not recorded"}</span></button>)}
          </div>
        </div>}

        {!showingLocal && vehicleView === "all" && dispatch.vehicles.length > 1 && <div className="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {dispatch.vehicles.map((vehicle) => {
            const vehicleUpdates = allUpdates.filter((row) => Number(row.vehicle_index) === Number(vehicle.index));
            const latest = vehicleUpdates[0];
            const photoCount = vehicleUpdates.reduce((total, row) => total + (row.photos?.length || 0), 0);
            return <button key={vehicle.index} type="button" onClick={() => setVehicleView(vehicle.index)} className="rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:border-blue-300 hover:shadow-md"><div className="flex items-start justify-between gap-2"><div><p className="font-black text-slate-950">Vehicle {vehicle.index + 1} · {vehicle.plate || "No plate"}</p><p className="text-xs text-slate-500">{vehicle.type || "Vehicle type not recorded"}</p></div><span className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${stageTone(latest?.stage)}`}>{stageLabel(latest?.stage)}</span></div><p className="mt-3 truncate text-xs font-semibold text-slate-600"><MapPin className="mr-1 inline h-3.5 w-3.5 text-rose-500" />{latest?.location || vehicle.source || "No location update"}</p><div className="mt-3 flex gap-3 text-[10px] font-bold text-slate-500"><span>{vehicleUpdates.length} updates</span><span>{photoCount} photos</span></div></button>;
          })}
        </div>}

        {!showingLocal && update ? <>
          <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div className="flex min-w-0 items-center gap-2"><span className={`shrink-0 rounded-full px-3 py-1 text-[10px] font-black uppercase ${stageTone(update.stage)}`}>{stageLabel(update.stage)}</span><span className="truncate text-xs font-semibold text-slate-500">{formatDateTime(update.occurred_at, "")}</span></div>
            <div className="flex items-center gap-2"><button type="button" disabled={updateIndex === 0} onClick={() => setUpdateIndex((value) => value - 1)} className="rounded-lg border border-slate-300 bg-white p-2 disabled:opacity-30" aria-label="Newer update"><ChevronLeft className="h-4 w-4" /></button><span className="text-xs font-black">{updateIndex + 1} / {updates.length}</span><button type="button" disabled={updateIndex >= updates.length - 1} onClick={() => setUpdateIndex((value) => value + 1)} className="rounded-lg border border-slate-300 bg-white p-2 disabled:opacity-30" aria-label="Older update"><ChevronRight className="h-4 w-4" /></button></div>
          </div>
          <div className="grid gap-4 lg:grid-cols-3">
            <section className="rounded-xl border border-slate-200 p-4"><p className="text-[10px] font-black uppercase tracking-wide text-slate-400">Update message</p><p className="mt-3 whitespace-pre-line text-sm leading-6 text-slate-800">{update.message}</p><div className="mt-4 space-y-3 border-t border-slate-100 pt-4 text-sm"><p><span className="font-black">Location:</span> {update.location}</p><p><span className="font-black">Reported by:</span> {update.reporter_name || "—"} {update.reporter_role ? `(${update.reporter_role})` : ""}</p>{dispatch.transport_required && <><p><span className="font-black">Vehicle:</span> {[update.vehicle?.type, update.vehicle?.plate].filter(Boolean).join(" · ") || "—"}</p><p><span className="font-black">Driver:</span> {update.vehicle?.driver || "—"}</p><p><span className="font-black">Escort:</span> {update.vehicle?.escort || "—"}</p></>}</div></section>
            <section><p className="mb-2 text-[10px] font-black uppercase tracking-wide text-slate-400">Recorded location</p><LocationMap update={update} /></section>
            <section className="rounded-xl border border-slate-200 p-3"><div className="mb-2 flex items-center justify-between"><p className="flex items-center gap-2 text-[10px] font-black uppercase tracking-wide text-slate-400"><Camera className="h-4 w-4" /> Photo evidence</p><span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-black">{photos.length}</span></div>{photos.length ? <><button type="button" onClick={() => setExpandedPhotoIndex(photoIndex)} className="group relative flex min-h-64 w-full cursor-zoom-in items-center justify-center overflow-hidden rounded-lg bg-slate-100" aria-label={`Enlarge ${photos[photoIndex]?.label || "evidence photo"}`}><img src={photos[photoIndex]?.url} alt={photos[photoIndex]?.label} className="max-h-[28rem] w-full object-contain transition group-hover:scale-[1.015]" /><span className="absolute bottom-3 right-3 rounded-full bg-slate-950/85 px-3 py-1.5 text-[10px] font-black uppercase text-white shadow-lg">Click to enlarge</span></button><div className="mt-3 flex items-center justify-between gap-2"><button type="button" disabled={photoIndex === 0} onClick={() => setPhotoIndex((value) => value - 1)} className="rounded-lg border p-2 disabled:opacity-30"><ChevronLeft className="h-4 w-4" /></button><span className="text-xs font-black">Photo {photoIndex + 1} of {photos.length}</span><button type="button" disabled={photoIndex >= photos.length - 1} onClick={() => setPhotoIndex((value) => value + 1)} className="rounded-lg border p-2 disabled:opacity-30"><ChevronRight className="h-4 w-4" /></button></div><a href={photos[photoIndex]?.download_url} className="mt-3 flex min-h-11 items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-xs font-black text-white"><Download className="h-4 w-4" /> Download this photo</a></> : <div className="flex min-h-64 items-center justify-center rounded-lg border border-dashed text-sm font-semibold text-slate-400">No photos for this update</div>}</section>
          </div>
        </> : showingLocal ? <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-center"><PackageCheck className="mx-auto h-8 w-8 text-emerald-700" /><p className="mt-2 font-black text-emerald-950">Direct local warehouse release and receipt</p><p className="mt-1 text-sm text-emerald-800">This operation does not use transport field updates, GPS tracking, vehicles, drivers, or escorts.</p></div> : <div className="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500">No field update has been posted yet.</div>}

        <div className="mt-5 grid gap-4 lg:grid-cols-2">
          <section className="overflow-hidden rounded-xl border border-slate-200"><div className="bg-slate-50 px-4 py-3"><h3 className="font-black">{showingLocal ? "Local release and receipt" : selectedVehicle ? `Vehicle ${selectedVehicle.index + 1} release and movement` : "Pickup, release and vehicles"}</h3></div><div className="divide-y">{!showingLocal && visibleVehicles.map((vehicle) => <div key={vehicle.index} className="p-4 text-sm"><p className="font-black">{vehicle.type || `Vehicle ${vehicle.index + 1}`} · {vehicle.plate || "No plate"}</p><p className="mt-1 text-slate-600">From {vehicle.source || "—"}</p><p className="mt-1 text-xs text-slate-500">Released: {formatDateTime(vehicle.released_at, "Not yet")} · Departed: {formatDateTime(vehicle.departed_at, "Not yet")} · Received: {formatDateTime(vehicle.received_at, "Not yet")}</p></div>)}{showingLocal && dispatch.local_handover?.source_warehouse_name && <div className="p-4 text-sm"><p className="font-black">No-transport warehouse handover</p><p className="text-slate-600">{dispatch.local_handover.source_warehouse_name}</p><p className="mt-1 text-xs font-semibold text-emerald-700">Vehicle, driver, and escort details are not required.</p><p className="mt-2 text-xs text-slate-500">Released: {formatDateTime(dispatch.local_handover.released_at, "Not yet")} · Received: {formatDateTime(dispatch.local_handover.received_at, "Not yet")}</p></div>}</div></section>
          <section className="overflow-hidden rounded-xl border border-slate-200"><div className="bg-slate-50 px-4 py-3"><h3 className="font-black">Items and receipt outcome</h3></div><div className="overflow-x-auto"><table className="min-w-full text-sm"><thead className="bg-slate-100 text-[10px] uppercase text-slate-500"><tr><th className="px-3 py-2 text-left">Item</th><th className="px-3 py-2">{showingLocal ? "Released" : "Loaded"}</th><th className="px-3 py-2">Received</th><th className="px-3 py-2">Balance</th></tr></thead><tbody>{visibleItems.map((item, index) => <tr key={`${item.name}-${index}`} className="border-t"><td className="px-3 py-2"><span className="font-bold">{item.name}</span><span className="block text-xs text-slate-400">{item.warehouse}</span></td><td className="px-3 py-2 text-center">{displayedQuantity(item)} {item.unit}</td><td className="px-3 py-2 text-center">{item.received ?? "—"}</td><td className="px-3 py-2 text-center">{item.variance || 0}</td></tr>)}</tbody></table>{visibleItems.length === 0 && <p className="p-5 text-center text-sm font-semibold text-slate-400">No items are assigned to this operation.</p>}</div>{dispatch.returned_quantity > 0 && <div className="border-t border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><p className="font-black">Returned/cancelled: {dispatch.returned_quantity}</p><p>{dispatch.returned_particulars}</p><p className="mt-1 text-xs">{dispatch.returned_reason}</p></div>}</section>
        </div>
      </div>
    </div>
    {expandedPhotoIndex !== null && photos[expandedPhotoIndex] && <div className="fixed inset-0 z-[320] flex flex-col bg-slate-950/95 p-2 backdrop-blur-sm sm:p-5" role="dialog" aria-modal="true" aria-label="Enlarged evidence photo">
      <div className="flex shrink-0 items-center justify-between gap-3 pb-3 text-white"><div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-wider text-cyan-300">{stageLabel(update?.stage)} · Evidence photo</p><p className="truncate text-sm font-bold">{dispatch.dispatch_number} · Photo {expandedPhotoIndex + 1} of {photos.length}</p></div><button type="button" onClick={() => setExpandedPhotoIndex(null)} className="flex min-h-11 min-w-11 items-center justify-center rounded-full border border-white/30 bg-white/10 hover:bg-white/20" aria-label="Close enlarged photo"><X className="h-5 w-5" /></button></div>
      <div className="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-xl bg-black"><img src={photos[expandedPhotoIndex].url} alt={photos[expandedPhotoIndex].label} className="h-full w-full object-contain" />{photos.length > 1 && <><button type="button" disabled={expandedPhotoIndex === 0} onClick={() => setExpandedPhotoIndex((value) => value - 1)} className="absolute left-2 flex h-12 w-12 items-center justify-center rounded-full bg-black/70 text-white shadow-lg disabled:opacity-25 sm:left-4" aria-label="Previous photo"><ChevronLeft className="h-7 w-7" /></button><button type="button" disabled={expandedPhotoIndex >= photos.length - 1} onClick={() => setExpandedPhotoIndex((value) => value + 1)} className="absolute right-2 flex h-12 w-12 items-center justify-center rounded-full bg-black/70 text-white shadow-lg disabled:opacity-25 sm:right-4" aria-label="Next photo"><ChevronRight className="h-7 w-7" /></button></>}</div>
      <div className="flex shrink-0 items-center justify-center pt-3"><a href={photos[expandedPhotoIndex].download_url} className="flex min-h-11 items-center justify-center gap-2 rounded-lg bg-white px-5 py-2 text-xs font-black text-slate-950"><Download className="h-4 w-4" /> Download photo</a></div>
    </div>}
  </div>;
}

export default function DeliveryMonitoring({ dispatches = [], focusDispatchId = null, focusUpdateId = null, focusOperation = null, focusStage = null }) {
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("active");
  const [viewer, setViewer] = useState(null);
  useEffect(() => { if (focusDispatchId) { const found = dispatches.find((row) => Number(row.id) === Number(focusDispatchId)); if (found) setViewer({ dispatch: found, updateId: focusUpdateId, operation: focusOperation, stage: focusStage }); } }, [focusDispatchId, focusUpdateId, focusOperation, focusStage, dispatches]);
  const rows = useMemo(() => dispatches.filter((row) => {
    if (status === "active" && row.status === "received") return false;
    if (status === "completed" && row.status !== "received") return false;
    const haystack = [row.dispatch_number, row.destination, row.request?.reference_number, row.request?.requesting_agency, row.latest_update?.location].join(" ").toLowerCase();
    return haystack.includes(query.toLowerCase());
  }), [dispatches, query, status]);

  return <AppLayout title="Delivery Monitoring"><Head title="Delivery Monitoring" />
    <div className="border-b border-slate-200 bg-gradient-to-r from-slate-950 via-blue-950 to-emerald-950 p-5 text-white sm:p-7"><p className="text-[10px] font-black uppercase tracking-[.2em] text-cyan-300">Regional operational visibility</p><h1 className="mt-1 text-2xl font-black sm:text-3xl">Delivery Situation Monitoring</h1><p className="mt-2 max-w-3xl text-sm text-slate-300">Read-only pickup, release, transport, GPS, photo evidence, receipt, and exception monitoring for DRMD, QRT, and authorized officials.</p></div>
    <div className="p-3 sm:p-5"><div className="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:flex-row"><label className="relative flex-1"><Search className="absolute left-3 top-3 h-4 w-4 text-slate-400" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search dispatch, request, destination or location" className="form-input w-full pl-9" /></label><div className="grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1">{[["active","Active"],["completed","Completed"],["all","All"]].map(([value,label]) => <button key={value} type="button" onClick={() => setStatus(value)} className={`rounded-md px-3 py-2 text-xs font-black ${status === value ? "bg-white text-emerald-800 shadow-sm" : "text-slate-500"}`}>{label}</button>)}</div></div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{rows.map((dispatch) => { const update = dispatch.latest_update; return <article key={dispatch.id} className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg"><div className="border-b border-slate-100 p-4"><div className="flex items-start justify-between gap-2"><div className="min-w-0"><p className="truncate text-base font-black text-slate-950">{dispatch.dispatch_number}</p><p className="truncate text-xs font-semibold text-slate-500">{dispatch.request?.reference_number}</p></div><span className="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-black uppercase text-emerald-800">{statusLabel(dispatch.status)}</span></div><p className="mt-3 flex items-start gap-2 text-sm font-semibold text-slate-700"><MapPin className="mt-0.5 h-4 w-4 shrink-0 text-rose-500" />{update?.location || dispatch.destination || "No location update"}</p></div><div className="flex-1 p-4">{update && <div className="flex items-center justify-between gap-2"><span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${stageTone(update.stage)}`}>{stageLabel(update.stage)}</span><span className="text-[10px] font-semibold text-slate-400">{formatDateTime(update.occurred_at, "")}</span></div>}<p className="mt-3 line-clamp-3 text-sm leading-5 text-slate-600">{update?.message || "Waiting for the first field update."}</p><div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs"><div className="rounded-lg bg-blue-50 p-2"><Truck className="mx-auto h-4 w-4 text-blue-600" /><b>{dispatch.vehicles.length}</b><span className="block text-[9px] text-slate-500">Vehicles</span></div><div className="rounded-lg bg-cyan-50 p-2"><Camera className="mx-auto h-4 w-4 text-cyan-600" /><b>{dispatch.photo_count}</b><span className="block text-[9px] text-slate-500">Photos</span></div><div className="rounded-lg bg-violet-50 p-2"><Clock3 className="mx-auto h-4 w-4 text-violet-600" /><b>{dispatch.updates.length}</b><span className="block text-[9px] text-slate-500">Updates</span></div></div></div><button type="button" onClick={() => setViewer({ dispatch })} className="flex min-h-12 items-center justify-center gap-2 bg-slate-950 px-4 py-3 text-xs font-black uppercase tracking-wide text-white hover:bg-emerald-800"><Eye className="h-4 w-4" /> View delivery details</button></article>; })}</div>{rows.length === 0 && <div className="rounded-xl border border-dashed border-slate-300 p-12 text-center"><PackageCheck className="mx-auto h-10 w-10 text-slate-300" /><p className="mt-3 font-black text-slate-700">No deliveries match these filters.</p></div>}</div>
    {viewer && <DispatchViewer dispatch={viewer.dispatch} initialUpdateId={viewer.updateId} initialOperation={viewer.operation} initialStage={viewer.stage} onClose={() => setViewer(null)} />}
  </AppLayout>;
}
