import { Head, Link } from "@inertiajs/react";
import { Fragment, useContext, useEffect, useMemo, useRef, useState } from "react";
import PreparednessPopulationTable, { PreparednessPopulationContext, preparednessPopulationTotals } from "../../Components/PreparednessPopulationTable";
import { router } from "@inertiajs/react";
import * as htmlToImage from "html-to-image";
import PptxGenJS from "pptxgenjs";
import { challengePagesFromContent, challengePagesToContent, CHALLENGE_ROWS_PER_PAGE, CHALLENGE_PAGE_LIMIT } from "../../Utils/preparednessChallenges";
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  Boxes,
  Building2,
  CalendarDays,
  CircleDollarSign,
  Container,
  Download,
  Droplets,
  FileText,
  FileBarChart2,
  Eye,
  EyeOff,
  Forklift,
  HeartHandshake,
  Maximize2,
  Minimize2,
  Monitor,
  PackageOpen,
  PackageCheck,
  Pencil,
  Plus,
  Satellite,
  ShieldCheck,
  Signpost,
  TentTree,
  Trash2,
  Truck,
  UsersRound,
  Warehouse,
  X,
} from "lucide-react";
import AppLayout from "@/Layouts/AppLayout";
import {
  FamilyFoodPackReport,
  StandbyStockpileSummaryV2,
} from "@/Pages/Dashboard/Index";

const ASSET = "/images/preparedness";
const assetUrl = (image) => image?.startsWith("/") ? image : `${ASSET}/${image}`;
const pages = [
  ["Regional Overview", "Executive readiness picture"],
  ["Family Food Packs", "Regional and warehouse stockpile"],
  ["Ready-to-Eat Food", "Immediate food response capacity"],
  ["Non-Food Items", "Essential family support inventory"],
  ["Shelter Capacity", "Emergency shelter and sleeping resources"],
  ["Response Facilities", "Specialized facilities and service assets"],
  ["Prepositioning Network", "Warehouse distribution and operations"],
];
const number = (value) =>
  Number(value || 0).toLocaleString("en-PH", { maximumFractionDigits: 0 });
const money = (value) =>
  `\u20B1${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const displayedWholeIsNonZero = (value) => {
  const numeric = Number(value);
  return Number.isFinite(numeric) && Math.round(numeric) !== 0;
};
const displayedMoneyIsNonZero = (value) => {
  const numeric = Number(value);
  return Number.isFinite(numeric) && Math.round(numeric * 100) !== 0;
};
const title = (value) =>
  String(value || "Unspecified")
    .replaceAll("_", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
const total = (rows, key) =>
  rows.reduce((sum, row) => sum + Number(row[key] || 0), 0);
const aggregateItems = (rows) =>
  Object.values(
    rows.reduce((groups, row) => {
      const key = `${row.item}|${row.category}`;
      groups[key] ??= {
        item: row.item,
        category: row.category,
        quantity: 0,
        value: 0,
        warehouseIds: new Set(),
      };
      groups[key].quantity += Number(row.quantity || 0);
      groups[key].value += Number(row.value || 0);
      if (row.warehouse_id) groups[key].warehouseIds.add(row.warehouse_id);
      return groups;
    }, {}),
  )
    .map((row) => ({ ...row, warehouses: row.warehouseIds.size }))
    .sort((a, b) => b.quantity - a.quantity);

const waitForImages = async (container) => {
  const images = Array.from(container?.querySelectorAll("img") ?? []);
  await Promise.all(images.map((image) => {
    if (image.complete && image.naturalWidth > 0) return Promise.resolve();
    return new Promise((resolve) => {
      const finish = () => resolve();
      image.addEventListener("load", finish, { once: true });
      image.addEventListener("error", finish, { once: true });
      window.setTimeout(finish, 10000);
    });
  }));
};

const blobToDataUrl = (blob) => new Promise((resolve, reject) => {
  const reader = new FileReader();
  reader.addEventListener("load", () => resolve(reader.result), { once: true });
  reader.addEventListener("error", () => reject(reader.error ?? new Error("Unable to read exported image.")), { once: true });
  reader.readAsDataURL(blob);
});

// Make the export DOM self-contained before html-to-image clones each slide.
// Otherwise uploaded /storage images can disappear from that clone and leave
// the dashboard snapshot or action-photo boxes blank in PowerPoint.
const inlineExportImages = async (container) => {
  const images = Array.from(container?.querySelectorAll("img") ?? []);
  const originals = images.map((image) => image.getAttribute("src"));
  const failures = [];

  await Promise.all(images.map(async (image) => {
    const source = image.currentSrc || image.src;
    if (!source || source.startsWith("data:")) return;

    try {
      const response = await fetch(source, { credentials: "same-origin", cache: "no-store" });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      image.src = await blobToDataUrl(await response.blob());
      if (typeof image.decode === "function") await image.decode();
    } catch (error) {
      failures.push({ source, error });
    }
  }));

  if (failures.length) {
    const sources = failures.map(({ source }) => new URL(source, window.location.href).pathname).join(", ");
    throw new Error(`Unable to prepare image files for PowerPoint: ${sources}`);
  }

  return () => images.forEach((image, index) => {
    const original = originals[index];
    if (original === null) image.removeAttribute("src");
    else image.setAttribute("src", original);
  });
};

const pptColor = (value, fallback = "FFFFFF") => {
  const match = String(value || "").match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/i);
  if (!match || (match[4] !== undefined && Number(match[4]) === 0)) return null;
  return [match[1], match[2], match[3]]
    .map((part) => Number(part).toString(16).padStart(2, "0"))
    .join("")
    .toUpperCase() || fallback;
};

const editablePptImageBox = (image, box) => {
  const naturalWidth = Number(image.naturalWidth || image.width || 1);
  const naturalHeight = Number(image.naturalHeight || image.height || 1);
  const sourceRatio = naturalWidth / naturalHeight;
  const boxRatio = box.w / box.h;
  if (window.getComputedStyle(image).objectFit === "contain") {
    if (sourceRatio > boxRatio) {
      const height = box.w / sourceRatio;
      return { ...box, y: box.y + ((box.h - height) / 2), h: height };
    }
    const width = box.h * sourceRatio;
    return { ...box, x: box.x + ((box.w - width) / 2), w: width };
  }
  return box;
};

const addEditableDomSlide = (presentation, root) => {
  const slide = presentation.addSlide();
  slide.background = { color: "FFFFFF" };
  const rootRect = root.getBoundingClientRect();
  const xScale = 13.333 / rootRect.width;
  const yScale = 7.5 / rootRect.height;
  const ignoredTags = new Set(["BUTTON", "INPUT", "TEXTAREA", "SELECT", "OPTION", "IFRAME", "SCRIPT", "STYLE"]);
  const boxFor = (element) => {
    const rect = element.getBoundingClientRect();
    return {
      x: Math.max(0, (rect.left - rootRect.left) * xScale),
      y: Math.max(0, (rect.top - rootRect.top) * yScale),
      w: Math.max(0.01, Math.min(rect.width, rootRect.right - rect.left) * xScale),
      h: Math.max(0.01, Math.min(rect.height, rootRect.bottom - rect.top) * yScale),
    };
  };
  const render = (element) => {
    if (!(element instanceof HTMLElement || element instanceof SVGElement)) return;
    if (ignoredTags.has(element.tagName) || element.dataset?.exportIgnore === "true") return;
    const style = window.getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    if (style.display === "none" || style.visibility === "hidden" || Number(style.opacity) === 0 || rect.width < 1 || rect.height < 1) return;
    if (rect.right <= rootRect.left || rect.left >= rootRect.right || rect.bottom <= rootRect.top || rect.top >= rootRect.bottom) return;
    const box = boxFor(element);
    const fillColor = pptColor(style.backgroundColor);
    const borderColor = pptColor(style.borderTopColor);
    const borderWidth = parseFloat(style.borderTopWidth || "0");
    const radius = parseFloat(style.borderTopLeftRadius || "0");
    const shouldDrawBox = (fillColor && fillColor !== "FFFFFF") || borderWidth > 0 || ["TD", "TH"].includes(element.tagName);
    if (shouldDrawBox) {
      slide.addShape(radius > 4 ? presentation.ShapeType.roundRect : presentation.ShapeType.rect, {
        ...box,
        fill: fillColor ? { color: fillColor, transparency: Math.round((1 - Number(style.opacity || 1)) * 100) } : { color: "FFFFFF", transparency: 100 },
        line: borderWidth > 0 && borderColor ? { color: borderColor, width: Math.max(0.25, borderWidth * 0.45) } : { color: "FFFFFF", transparency: 100 },
      });
    }
    if (element instanceof HTMLImageElement && element.src) {
      slide.addImage({ data: element.src, ...editablePptImageBox(element, box) });
      return;
    }
    if (element instanceof SVGElement && element.tagName.toLowerCase() === "svg") {
      const svg = new XMLSerializer().serializeToString(element);
      slide.addImage({ data: `data:image/svg+xml;base64,${window.btoa(unescape(encodeURIComponent(svg)))}`, ...box });
      return;
    }
    Array.from(element.children).forEach(render);
    const directText = Array.from(element.childNodes)
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .map((node) => node.textContent)
      .join(" ")
      .replace(/\s+/g, " ")
      .trim();
    if (!directText) return;
    const elementScale = element instanceof HTMLElement && element.offsetWidth > 0
      ? Math.min(1, rect.width / element.offsetWidth)
      : 1;
    const fontSize = Math.max(5, parseFloat(style.fontSize || "16") * 0.6 * elementScale);
    slide.addText(directText, {
      ...box,
      margin: 0,
      fontFace: String(style.fontFamily || "Arial").split(",")[0].replace(/["']/g, "").trim(),
      fontSize,
      color: pptColor(style.color, "111827") || "111827",
      bold: Number(style.fontWeight) >= 600 || style.fontWeight === "bold",
      italic: style.fontStyle === "italic",
      underline: String(style.textDecorationLine).includes("underline"),
      align: ["center", "right", "justify"].includes(style.textAlign) ? style.textAlign : "left",
      valign: "mid",
      breakLine: false,
      fit: "shrink",
      paraSpaceAfterPt: 0,
      transparency: Math.round((1 - Number(style.opacity || 1)) * 100),
    });
  };
  render(root);
  return slide;
};

function BriefingHeader({ title: pageTitle, subtitle, asOf, onEdit = null }) {
  const population = useContext(PreparednessPopulationContext);
  return (
    <header className={`relative border-b border-slate-200 bg-white px-5 sm:px-8 ${population ? "py-3" : "py-5"}`}>
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-black uppercase tracking-[.2em] text-red-600">
            DSWD Field Office Caraga &bull; Disaster Response Management
            Division
          </p>
          <h2 className={`mt-2 font-black uppercase leading-tight text-blue-950 ${population ? "text-[28px]" : "text-2xl sm:text-4xl"}`}>
            {pageTitle}
          </h2>
          <p className={`mt-1 font-bold text-slate-500 ${population ? "text-xs" : "text-sm"}`}>{subtitle}</p>
        </div>
        <div className={`flex shrink-0 self-stretch flex-col items-end justify-between ${population ? "gap-1" : "gap-4"}`}>
          {population && <PreparednessPopulationTable />}
          {onEdit && (
            <button type="button" onClick={onEdit} className="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-white px-4 py-2.5 text-xs font-black uppercase text-blue-900 shadow-sm transition hover:border-blue-300 hover:bg-blue-50">
              <Pencil className="h-4 w-4" /> Edit data
            </button>
          )}
          <div className="flex items-center gap-2 text-xs font-black uppercase text-red-600">
            <CalendarDays className="h-4 w-4" /> As of {asOf}
          </div>
        </div>
      </div>
    </header>
  );
}

const SLIDE_WIDTH = 1600;
const SLIDE_HEIGHT = 900;
const PAGE_CONTENT_SCALE = {
  "briefing-title": 1,
  "briefing-synopsis": 0.96,
  "briefing-forecast": 0.96,
  "resource-capacity-title": 0.96,
  "thank-you": 1,
  "standby-stockpile": 0.9,
  "rros-ffp-summary": 0.9,
  "ffp-province": 0.94,
  "ffp-dswd": 0.92,
  "ffp-lgu-province-summary": 0.94,
  "rtef-capacity": 0.94,
  "bottled-water": 0.94,
  "relief-shelter-items": 0.92,
  "protection-cccm-items": 0.9,
  "production-materials": 0.94,
  "response-assets": 0.94,
  "qrt-human-resources": 0.98,
  "qrt-specializations": 0.98,
};

function SlideCanvas({ children }) {
  const frameRef = useRef(null);
  const [displayScale, setDisplayScale] = useState(1);

  useEffect(() => {
    const measure = () => {
      const frameWidth = frameRef.current?.clientWidth || SLIDE_WIDTH;
      setDisplayScale(frameWidth / SLIDE_WIDTH);
    };
    measure();
    const observer = new ResizeObserver(measure);
    if (frameRef.current) observer.observe(frameRef.current);
    window.addEventListener("resize", measure);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", measure);
    };
  }, []);
  return (
    <div
      ref={frameRef}
      className="relative w-full overflow-hidden bg-white"
      style={{ height: `${SLIDE_HEIGHT * displayScale}px` }}
      data-ppt-slide="16:9"
    >
      <div
        className="absolute left-0 top-0 overflow-hidden bg-white"
        style={{
          width: `${SLIDE_WIDTH}px`,
          height: `${SLIDE_HEIGHT}px`,
          transform: `scale(${displayScale})`,
          transformOrigin: "top left",
        }}
      >
        <div style={{ width: `${SLIDE_WIDTH}px`, height: `${SLIDE_HEIGHT}px`, display: "flex", flexDirection: "column" }}>
          {children}
        </div>
      </div>
    </div>
  );
}

function BriefingSlideContents({ page, pageNumber, pageCount }) {
  const contentScale = PAGE_CONTENT_SCALE[page.key] ?? 0.94;
  const isFirstPage = page.key === "briefing-title";
  const isThankYouPage = page.key === "thank-you";
  const isStandardPage = !isFirstPage && !isThankYouPage;

  if (isStandardPage) {
    return (
      <>
        <div className="flex h-3 w-full" aria-hidden="true">
          <div className="w-[31%] bg-blue-900" />
          <div className="-ml-1 w-8 -skew-x-[32deg] bg-yellow-300" />
          <div className="-ml-1 flex-1 bg-red-500" />
        </div>
        <div className="min-h-0 flex-1 overflow-hidden bg-white">
          <div style={{ width: `${100 / contentScale}%`, height: `${100 / contentScale}%`, transform: `scale(${contentScale})`, transformOrigin: "top left" }}>
            {page.render()}
          </div>
        </div>
        <footer className="relative mt-auto overflow-hidden border-t border-slate-200 bg-slate-50 px-8 py-4">
          <div className="relative z-10 flex items-center justify-between gap-3 text-[9px] font-black uppercase tracking-[.16em] text-slate-500">
            <span className="flex flex-col gap-0.5"><span>DSWD Field Office Caraga {"\u00B7"} Preparedness for Response</span><span className="text-[10px] font-bold normal-case leading-none tracking-normal text-slate-500">Prepared and generated through DROMIS (Developed by: Roger Ongue, Computer Programmer I / DRIMS Head)</span></span>
            <span className="text-blue-900">{page.label} {"\u00B7"} {String(pageNumber).padStart(2, "0")} / {String(pageCount).padStart(2, "0")}</span>
          </div>
          <div className="absolute inset-x-0 bottom-0 flex h-2" aria-hidden="true"><div className="w-[23%] bg-blue-900" /><div className="-ml-1 w-7 -skew-x-[32deg] bg-yellow-300" /><div className="-ml-1 flex-1 bg-red-500" /></div>
        </footer>
      </>
    );
  }

  const frameImage = isFirstPage
    ? "/images/preparedness/branding/first-page-footer.png"
    : "/images/preparedness/branding/thank-you-page.png";

  return (
    <div className="relative min-h-0 flex-1 overflow-hidden bg-white">
      <img src={frameImage} alt="" aria-hidden="true" className="pointer-events-none absolute inset-0 z-0 h-full w-full object-fill" />
      <div className="relative z-10 h-full overflow-hidden">
        <div
          style={{
            width: `${100 / contentScale}%`,
            height: `${100 / contentScale}%`,
            transform: `scale(${contentScale})`,
            transformOrigin: "top left",
          }}
        >
          {page.render()}
        </div>
      </div>
      <div className={`pointer-events-none absolute bottom-[18px] left-[42px] z-30 flex h-[76px] items-center ${isFirstPage ? "text-yellow-300" : "text-blue-900"}`}>
        <img src={isFirstPage ? "/images/preparedness/branding/dswd-fo-logo-white.png?v=20260823-2" : "/images/preparedness/branding/dswd-logo.png"} alt="DSWD Field Office Caraga" className="h-[72px] w-[180px] object-contain" />
        <img src={isFirstPage ? "/images/preparedness/branding/bagong-pilipinas-white.png?v=20260823-2" : "/images/preparedness/branding/bagong-pilipinas.png"} alt="Bagong Pilipinas" className="ml-4 h-[70px] w-[70px] object-contain" />
        <span className="ml-9 whitespace-nowrap text-[27px] font-black leading-none tracking-[-0.02em]">#BawatBuhayMahalagaSaDSWD</span>
      </div>
      <div className="pointer-events-none absolute bottom-[18px] right-[26px] z-30 flex h-[76px] w-[36%] items-center justify-center whitespace-nowrap text-center text-[24px] font-medium leading-none text-white">
        Disaster Response Management Division
      </div>
      <div className={`pointer-events-none absolute bottom-[12px] left-[23%] z-40 w-[42%] whitespace-nowrap text-center text-[10px] font-semibold leading-none ${isFirstPage ? "text-white/90" : "text-blue-950/80"}`}>
        Prepared and generated through DROMIS (Developed by: Roger Ongue, Computer Programmer I / DRIMS Head)
      </div>
    </div>
  );
}

function Metric({ icon: Icon, label, value, note, red = false, compact = false }) {
  return (
    <div className={`group relative overflow-hidden border border-blue-100 bg-gradient-to-br from-white via-white to-blue-50/80 shadow-[0_10px_30px_-18px_rgba(30,64,175,.45)] transition hover:-translate-y-0.5 hover:shadow-lg ${compact ? "rounded-xl p-3" : "rounded-2xl p-5"}`}>
      <div className={`absolute -right-8 -top-10 h-28 w-28 rounded-full ${red ? "bg-red-100/70" : "bg-blue-100/70"}`} />
      <div className={`absolute inset-x-0 top-0 h-1 ${red ? "bg-red-500" : "bg-gradient-to-r from-blue-900 via-blue-600 to-cyan-400"}`} />
      <div className="flex items-center justify-between">
        <p className="relative text-xs font-black uppercase leading-tight tracking-[.12em] text-slate-500">
          {label}
        </p>
        <span className={`relative flex items-center justify-center rounded-xl ${compact ? "h-8 w-8" : "h-9 w-9"} ${red ? "bg-red-50 text-red-600 ring-red-100" : "bg-white text-blue-900 ring-blue-100"} shadow-sm ring-1`}>
          <Icon className={compact ? "h-4 w-4" : "h-5 w-5"} />
        </span>
      </div>
      <p
        className={`relative whitespace-nowrap font-black tabular-nums tracking-tight ${compact ? "mt-2 text-[1.7rem]" : "mt-4 text-4xl"} ${red ? "text-red-600" : "text-blue-950"}`}
      >
        {value}
      </p>
      {note && <p className="mt-1 text-xs text-slate-500">{note}</p>}
    </div>
  );
}

function ResourceCard({ image, label, quantity, value, note, compact = false, expandImage = false, blendImage = false }) {
  return (
    <article className={`group grid grid-cols-[44%_56%] items-center overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-[0_14px_35px_-24px_rgba(15,23,42,.65)] transition hover:-translate-y-1 hover:shadow-xl ${compact ? "min-h-36" : "min-h-44"}`}>
      <div className={`relative flex h-full items-center justify-center overflow-hidden bg-gradient-to-br from-sky-300 via-blue-200 to-cyan-100 ${expandImage ? "p-1" : "p-4"}`}>
        <div className="absolute -left-10 -top-10 h-28 w-28 rounded-full bg-white/30" />
        <div className="absolute -bottom-12 -right-10 h-32 w-32 rounded-full bg-blue-700/10" />
        {image ? (
          expandImage ? (
            <div className="absolute inset-1 flex items-center justify-center overflow-hidden">
              <img
                src={assetUrl(image)}
                alt=""
                className={`h-full w-full object-contain drop-shadow-xl transition duration-300 group-hover:scale-105 ${blendImage ? "mix-blend-multiply" : ""}`}
              />
            </div>
          ) : (
            <img
              src={assetUrl(image)}
              alt=""
              className={`relative max-w-full object-contain drop-shadow-xl transition duration-300 group-hover:scale-105 ${compact ? "max-h-28" : "max-h-36"} ${blendImage ? "mix-blend-multiply" : ""}`}
            />
          )
        ) : (
          <div className="relative flex h-24 w-24 items-center justify-center rounded-3xl bg-white/85 text-blue-900 shadow-xl ring-1 ring-white">
            <ResourceVector label={label} />
          </div>
        )}
      </div>
      <div className={compact ? "p-4" : "p-5"}>
        <p className={`${compact ? "text-sm leading-tight" : "text-base leading-tight"} font-black uppercase text-blue-950`}>{label}</p>
        <p className={`${compact ? "mt-1" : "mt-2"} font-black ${quantity == null ? "text-lg text-amber-700" : compact ? "text-2xl tabular-nums text-red-600" : "text-3xl tabular-nums text-red-600"}`}>
          {quantity == null ? "Not yet tracked" : number(quantity)}
        </p>
        <p className="text-sm font-bold uppercase leading-tight text-slate-500">
          {quantity == null ? "Requires asset registry" : "units available"}
        </p>
        {value != null && (
          <p className="mt-2 whitespace-nowrap text-xl font-black leading-none tabular-nums text-blue-900">
            {money(value)}
          </p>
        )}
        {note && (
          <p className={note === "On Standby"
            ? `${compact ? "mt-2 px-2" : "mt-3 px-3"} inline-flex rounded-full bg-emerald-50 py-1 text-[10px] font-black uppercase tracking-wide text-emerald-700 ring-1 ring-emerald-200`
            : "mt-1 text-xs font-medium text-slate-500"}
          >
            {note}
          </p>
        )}
      </div>
    </article>
  );
}

function ResourceVector({ label }) {
  const name = String(label || "").toLowerCase();
  let Icon = PackageOpen;
  if (name.includes("forklift")) Icon = Forklift;
  else if (name.includes("starlink")) Icon = Satellite;
  else if (name.includes("vehicle") || name.includes("van")) Icon = Truck;
  else if (name.includes("signage")) Icon = Signpost;
  else if (name.includes("information board")) Icon = Monitor;
  else if (name.includes("gender") || name.includes("referral")) Icon = HeartHandshake;
  else if (name.includes("form")) Icon = FileText;
  else if (name.includes("water") || name.includes("filtration")) Icon = Droplets;
  else if (name.includes("tent") || name.includes("camp")) Icon = TentTree;
  else if (name.includes("sack") || name.includes("bag") || name.includes("carton")) Icon = Container;
  return <Icon className="h-12 w-12" strokeWidth={1.7} aria-hidden="true" />;
}

function StockHeroSummary({
  image,
  label,
  current,
  currentLabel,
  capacity,
  capacityLabel,
  totalAmount = null,
  compact = false,
  showCapacity = true,
  showVariance = true,
  varianceMode = "current-minus-capacity",
}) {
  const variance = Number(capacity || 0) > 0
    ? varianceMode === "capacity-minus-current"
      ? Number(capacity) - Number(current || 0)
      : Number(current || 0) - Number(capacity)
    : null;
  return (
    <section className="grid overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-[0_18px_45px_-28px_rgba(15,23,42,.7)] lg:grid-cols-2">
      <div className={`relative flex items-center justify-center overflow-hidden bg-gradient-to-br from-sky-300 via-blue-200 to-cyan-100 ${compact ? "min-h-44 p-4" : "min-h-72 p-7"}`}>
        <div className="absolute -left-20 -top-20 h-56 w-56 rounded-full bg-white/35" />
        <div className="absolute -bottom-24 -right-16 h-64 w-64 rounded-full bg-blue-800/10" />
        <div className="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-blue-900/10 to-transparent" />
        <img
          src={assetUrl(image)}
          alt={label}
          className="absolute inset-[6%] h-[88%] w-[88%] object-contain drop-shadow-2xl"
        />
      </div>
      <div className={`flex flex-col justify-center ${compact ? "gap-2 p-4" : "gap-4 p-5 sm:p-7"}`}>
        <div>
          <p className="text-[10px] font-black uppercase tracking-[.18em] text-blue-600">Regional Stockpile Summary</p>
          <h3 className={`mt-1 font-black text-blue-950 ${compact ? "text-xl leading-tight" : "text-2xl"}`}>{label}</h3>
        </div>
        <div className={`grid sm:grid-cols-2 ${compact ? "gap-2" : "gap-3"}`}>
          <div className={showCapacity ? "" : "sm:col-span-2"}>
            <Metric icon={PackageCheck} label={currentLabel} value={number(current)} compact={compact} />
          </div>
          {showCapacity && (
            <Metric icon={Warehouse} label={capacityLabel} value={number(capacity)} compact={compact} />
          )}
          {totalAmount != null && (
            <div className="sm:col-span-2">
              <Metric icon={CircleDollarSign} label="Total Amount" value={money(totalAmount)} compact={compact} />
            </div>
          )}
        </div>
        {showCapacity && showVariance && <div className={`rounded-xl px-4 text-sm font-black ${compact ? "py-2" : "py-3"} ${variance == null ? "bg-slate-100 text-slate-600" : variance < 0 ? "bg-red-50 text-red-700" : "bg-emerald-50 text-emerald-800"}`}>
            Capacity variance: {variance == null ? "Not encoded" : number(variance)}
          </div>}
      </div>
    </section>
  );
}

function DataTable({
  rows,
  columns,
  empty = "No matching inventory is currently recorded.",
}) {
  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <table className="w-full table-fixed text-left text-sm sm:text-base">
        <thead className="bg-gradient-to-r from-blue-950 via-blue-900 to-blue-800 text-white">
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                className={`break-words px-3 py-3 text-xs font-black uppercase leading-tight tracking-wide sm:px-4 sm:text-sm ${column.align === "right" ? "text-right" : ""}`}
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length ? (
            rows.map((row, index) => (
              <tr
                key={`${row.item || row.warehouse || row.province}-${index}`}
                className={`${index % 2 ? "bg-slate-50/80" : "bg-white"} transition hover:bg-blue-50`}
              >
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={`break-words border-b border-slate-100 px-3 py-3 sm:px-4 ${column.bold ? "font-black text-slate-950" : "text-slate-600"} ${column.align === "right" ? "whitespace-nowrap text-right tabular-nums" : ""}`}
                  >
                    {column.render ? column.render(row) : row[column.key]}
                  </td>
                ))}
              </tr>
            ))
          ) : (
            <tr>
              <td
                colSpan={columns.length}
                className="px-5 py-12 text-center text-sm text-slate-500"
              >
                {empty}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function useInlineIntroEditor(intro, enabled, reportId) {
  const [activeField, setActiveField] = useState(null);
  const [draft, setDraft] = useState(() => ({ ...(intro?.content ?? {}) }));
  const [image, setImage] = useState(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => { if (!activeField) setDraft({ ...(intro?.content ?? {}) }); }, [intro, activeField]);
  const update = (field, value) => setDraft((current) => ({ ...current, [field]: value }));
  const start = (field) => { setDraft({ ...(intro?.content ?? {}) }); setImage(null); setActiveField(field); };
  const cancel = () => { setDraft({ ...(intro?.content ?? {}) }); setImage(null); setActiveField(null); };
  const save = () => {
    setSaving(true);
    router.post(`/preparedness-for-response/reports/${reportId}/data/briefing-intro`, { _method: "patch", content: draft, synopsis_image: image }, { forceFormData: true, preserveScroll: true, onSuccess: () => router.reload({ only: ["briefingIntro", "asOf"], preserveScroll: true, onSuccess: () => setActiveField(null) }), onFinish: () => setSaving(false) });
  };
  const saveValues = (values) => {
    setSaving(true);
    router.post(`/preparedness-for-response/reports/${reportId}/data/briefing-intro`, { _method: "patch", content: { ...draft, ...values } }, { forceFormData: true, preserveScroll: true, onSuccess: () => router.reload({ only: ["briefingIntro", "asOf"], preserveScroll: true, onSuccess: () => setActiveField(null) }), onFinish: () => setSaving(false) });
  };
  return { enabled, activeField, editing: Boolean(enabled && activeField), isEditing: (field) => enabled && activeField === field, draft, update, image, setImage, saving, start, cancel, save, saveValues };
}

function InlineEditControls() {
  return null;
}

function InlineField({ editor, field, fontField, defaultSize, multiline = false, label = "", className = "", style = {}, fallbackValue = "" }) {
  const value = editor.draft[field] ?? fallbackValue;
  const fontSize = editor.draft[fontField] || defaultSize;
  if (!editor.isEditing(field)) return <div className={`group relative ${editor.enabled ? "inline-editable rounded-lg bg-blue-50/55 outline outline-1 outline-offset-4 outline-blue-300" : ""}`}><div style={{ ...style, fontSize: `${fontSize}px` }} className={className}>{value}</div>{editor.enabled && !editor.editing && <button type="button" onClick={() => editor.start(field)} className="absolute right-2 top-1/2 z-40 -translate-y-1/2 rounded-lg border border-blue-200 bg-white p-1.5 text-blue-900 shadow-md transition hover:bg-blue-50" aria-label={`Edit ${label || field}`}><Pencil className="h-3.5 w-3.5" /></button>}</div>;
  const Input = multiline ? "textarea" : "input";
  return <div className="relative z-50 w-full rounded-lg outline outline-2 outline-offset-4 outline-blue-400">{label && <span className="pointer-events-none absolute -top-5 left-0 text-[9px] font-black uppercase tracking-wide text-blue-700">{label}</span>}<Input rows={multiline ? undefined : undefined} value={value} onChange={(event) => editor.update(field, event.target.value)} className={`block w-full resize-none overflow-hidden border-0 bg-transparent p-0 outline-none ${className}`} style={{ ...style, fontSize: `${fontSize}px`, fontFamily: "inherit", fieldSizing: multiline ? "content" : undefined }} /><div className="absolute -bottom-9 right-0 z-50 flex items-center gap-1 rounded-md border border-blue-200 bg-white px-2 py-1 text-[9px] font-black uppercase text-blue-900 shadow-sm"><label>Font size <input type="number" min="8" max="180" value={fontSize} onChange={(event) => editor.update(fontField, event.target.value)} className="ml-1 w-14 rounded border px-1 py-0.5 text-center text-xs" /></label><button type="button" onClick={editor.cancel} className="rounded border px-2 py-1 text-slate-600">Cancel</button><button type="button" onClick={editor.save} disabled={editor.saving} className="rounded bg-blue-900 px-2 py-1 text-white disabled:opacity-50">{editor.saving ? "\u2026" : "Save"}</button></div></div>;
}

function InlineWeatherImage({ editor, imagePath }) {
  const [previewUrl, setPreviewUrl] = useState(null);
  useEffect(() => {
    if (!editor.image) { setPreviewUrl(null); return undefined; }
    const url = URL.createObjectURL(editor.image);
    setPreviewUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [editor.image]);
  const editing = editor.isEditing("synopsis_image");
  return <div className={`group relative h-full min-h-0 overflow-hidden rounded-lg border bg-slate-100 ${editor.enabled && !editing ? "inline-editable bg-blue-50/55 outline outline-1 outline-offset-4 outline-blue-300" : ""}`}>{previewUrl || imagePath ? <img src={previewUrl || imagePath} className="h-full w-full object-contain object-top" alt="Weather synopsis" /> : <div className="flex h-full items-start justify-center px-8 pt-8 text-center text-slate-400">Upload a PAGASA satellite or weather image</div>}{editor.enabled && !editor.editing && <button type="button" onClick={() => editor.start("synopsis_image")} className="absolute right-2 top-2 z-40 inline-flex items-center gap-1 rounded-lg border border-blue-200 bg-white px-2 py-1.5 text-xs font-black uppercase text-blue-900 shadow-md hover:bg-blue-50"><Pencil className="h-3.5 w-3.5" /> Edit image</button>}{editing && <div className="absolute inset-x-3 bottom-3 z-50 rounded-xl border border-blue-200 bg-white/95 p-3 shadow-xl"><input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => editor.setImage(event.target.files?.[0] ?? null)} className="block w-full text-xs file:mr-3 file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:font-black file:text-blue-900" /><div className="mt-2 flex justify-end gap-2"><button type="button" onClick={editor.cancel} className="rounded border px-3 py-1 text-xs font-black uppercase text-slate-600">Cancel</button><button type="button" onClick={editor.save} disabled={!editor.image || editor.saving} className="rounded bg-blue-900 px-3 py-1 text-xs font-black uppercase text-white disabled:opacity-40">{editor.saving ? "Saving\u2026" : "Save image"}</button></div></div>}</div>;
}

function BriefingTitlePage({ intro, canEdit, asOf, reportId }) {
  const editor = useInlineIntroEditor(intro, canEdit, reportId);
  return <div className="relative flex h-[900px] flex-col bg-transparent pt-10 text-center"><InlineEditControls editor={editor} enabled={canEdit} /><div className="flex justify-center gap-5"><img src="/images/preparedness/branding/dswd-logo.png" className="h-24 w-auto object-contain" alt="DSWD Field Office Caraga" /><img src="/images/preparedness/branding/bagong-pilipinas.png" className="h-24 w-24 object-contain" alt="Bagong Pilipinas" /></div><div className="mx-auto mt-14 w-[88%]"><InlineField editor={editor} field="cover_heading" fallbackValue={'FIELD OFFICE CARAGA\nPREPAREDNESS FOR RESPONSE'} fontField="cover_heading_font_size" defaultSize={68} multiline label="Cover heading" className="w-full whitespace-pre-line text-center font-black uppercase leading-tight text-blue-900" /></div><div className="mx-auto mt-8 w-[90%]"><InlineField editor={editor} field="title_event" fontField="title_event_font_size" defaultSize={48} multiline label="Report title" className="w-full text-center font-medium leading-tight text-red-600" /></div><p className="mx-auto mt-4 w-[70%] text-center text-3xl font-semibold text-slate-700">As of {asOf}</p></div>;
}

function BriefingSynopsisPage({ intro, canEdit, reportId }) {
  const editor = useInlineIntroEditor(intro, canEdit, reportId);
  const imagePath = intro?.synopsis_image_path;

  return (
    <div className="relative flex h-[810px] flex-col bg-white">
      <InlineEditControls editor={editor} enabled={canEdit} />
      <div className="px-10 pt-2">
        <InlineField editor={editor} field="synopsis_title" fontField="synopsis_title_font_size" defaultSize={48} className="font-black uppercase text-blue-950 underline" />
      </div>
      <div className="grid min-h-0 flex-1 grid-cols-[38%_1fr] gap-8 px-10 py-3">
        <div className="flex min-h-0 flex-col">
          <InlineWeatherImage editor={editor} imagePath={imagePath} />
        </div>
        <div className="flex min-h-0 flex-col">
          <InlineField editor={editor} field="synopsis_body" fontField="synopsis_body_font_size" defaultSize={24} multiline className="whitespace-pre-line font-medium leading-relaxed text-slate-950" />
          <div className="mt-auto space-y-1 text-right">
            <InlineField editor={editor} field="synopsis_issued" fontField="synopsis_meta_font_size" defaultSize={20} className="font-bold italic text-blue-900" />
            <InlineField editor={editor} field="synopsis_source" fontField="synopsis_meta_font_size" defaultSize={20} className="font-bold italic text-blue-900" />
            <InlineField editor={editor} field="synopsis_url" fontField="synopsis_meta_font_size" defaultSize={20} className="italic text-blue-900" />
          </div>
        </div>
      </div>
    </div>
  );
}

function PossibleAffectedFamiliesPage({ intro, populationLgus, canEdit, reportId }) {
  const editor = useInlineIntroEditor(intro, canEdit, reportId);
  const selectedCodes = String(editor.draft.possible_affected_lgu_codes || "")
    .split(",")
    .map((code) => code.trim())
    .filter(Boolean);
  const selectedRows = (populationLgus || []).filter((row) => selectedCodes.includes(String(row.code)));
  const groupedLgus = (populationLgus || []).reduce((groups, row) => {
    const district = row.district || row.district_code || "Other district";
    groups[row.province] ??= {};
    groups[row.province][district] ??= [];
    groups[row.province][district].push(row);
    return groups;
  }, {});
  const setSelection = (codes) => editor.update("possible_affected_lgu_codes", [...new Set(codes.map(String))].join(","));
  const toggleLgu = (code) => {
    const value = String(code);
    const next = selectedCodes.includes(value)
      ? selectedCodes.filter((item) => item !== value)
      : [...selectedCodes, value];
    setSelection(next);
  };
  const toggleProvince = (province) => {
    const codes = (populationLgus || []).filter((row) => row.province === province).map((row) => String(row.code));
    const allSelected = codes.every((code) => selectedCodes.includes(code));
    setSelection(allSelected ? selectedCodes.filter((code) => !codes.includes(code)) : [...selectedCodes, ...codes]);
  };
  const tableRows = Object.values(selectedRows.reduce((provinces, row) => {
    provinces[row.province] ??= { province: row.province, population: 0, lgus: [] };
    provinces[row.province].population += Number(row.population || 0);
    provinces[row.province].lgus.push(row.name);
    return provinces;
  }, {})).map((row) => {
    const population = row.population;
    const affectedPopulation = Math.round(population * 0.30);
    return { ...row, population, affectedPopulation, families: Math.round(affectedPopulation / 5) };
  });
  const totals = tableRows.reduce((sum, row) => ({
    population: sum.population + row.population,
    affectedPopulation: sum.affectedPopulation + row.affectedPopulation,
    families: sum.families + row.families,
  }), { population: 0, affectedPopulation: 0, families: 0 });

  return (
    <div className="relative flex h-full min-h-0 flex-col bg-white px-12 py-8">
      <h1 className="text-center text-5xl font-black text-blue-900">Data on the Possible Affected Families</h1>
      <p className="mt-3 text-center text-lg font-semibold text-slate-600">Population estimates for selected cities and municipalities in the Caraga Region</p>
      {canEdit && !editor.editing && <button data-export-ignore="true" type="button" onClick={() => editor.start("possible_affected_lgu_codes")} className="absolute right-12 top-8 inline-flex items-center gap-2 rounded-xl bg-blue-900 px-4 py-2.5 text-xs font-black uppercase text-white shadow-lg"><Pencil className="h-4 w-4" /> Select LGUs</button>}
      {editor.isEditing("possible_affected_lgu_codes") && (
        <div data-export-ignore="true" className="absolute right-10 top-20 z-50 flex max-h-[700px] w-[560px] flex-col overflow-hidden rounded-2xl border border-blue-200 bg-white shadow-2xl">
          <div className="border-b border-slate-200 p-4"><h2 className="font-black text-blue-950">Select cities and municipalities</h2><p className="mt-1 text-xs text-slate-500">LGUs are grouped by province and district. Population data comes directly from the system population registry.</p><div className="mt-3 flex gap-2"><button type="button" onClick={() => setSelection((populationLgus || []).map((row) => row.code))} className="rounded-lg bg-blue-900 px-3 py-2 text-[10px] font-black uppercase text-white">Select all Caraga</button><button type="button" onClick={() => setSelection([])} className="rounded-lg border border-slate-300 px-3 py-2 text-[10px] font-black uppercase">Clear all</button></div></div>
          <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
            {Object.entries(groupedLgus).map(([province, districts]) => <section key={province}><div className="sticky top-0 z-10 flex items-center justify-between bg-white py-1"><h3 className="text-xs font-black uppercase tracking-wide text-blue-800">{province}</h3><button type="button" onClick={() => toggleProvince(province)} className="rounded border border-blue-200 px-2 py-1 text-[9px] font-black uppercase text-blue-800">Select all province</button></div>{Object.entries(districts).map(([district, lgus]) => <div key={district} className="mt-3"><h4 className="border-b border-slate-200 pb-1 text-[11px] font-black uppercase text-slate-500">{district}</h4><div className="mt-2 grid grid-cols-2 gap-2">{lgus.map((lgu) => <label key={lgu.code} className="flex items-start gap-2 rounded-lg border border-slate-200 p-2 text-xs font-semibold text-slate-700"><input type="checkbox" checked={selectedCodes.includes(String(lgu.code))} onChange={() => toggleLgu(lgu.code)} className="mt-0.5 rounded border-slate-300 text-blue-700" /><span>{lgu.name}<small className="block font-normal text-slate-500">Population: {number(lgu.population)}</small></span></label>)}</div></div>)}</section>)}
          </div>
          <div className="flex items-center justify-between border-t border-slate-200 p-4"><span className="text-xs font-bold text-slate-500">{selectedCodes.length} LGUs selected</span><div className="flex gap-2"><button type="button" onClick={editor.cancel} className="rounded-lg border px-4 py-2 text-xs font-black uppercase">Cancel</button><button type="button" onClick={editor.save} disabled={editor.saving} className="rounded-lg bg-blue-900 px-4 py-2 text-xs font-black uppercase text-white disabled:opacity-50">{editor.saving ? "Saving\u2026" : "Save selection"}</button></div></div>
        </div>
      )}
      <div className="mt-8 overflow-hidden rounded-xl border border-slate-300">
        <table className="w-full table-fixed text-center text-lg">
          <thead className="bg-blue-900 text-white"><tr><th className="w-[28%] px-4 py-4 text-left">Province / Selected LGUs</th><th className="w-[24%] px-4 py-4">Total Number of Population</th><th className="w-[24%] px-4 py-4">30% Population</th><th className="w-[24%] px-4 py-4">Number of Families</th></tr></thead>
          <tbody>
            {tableRows.length ? tableRows.map((row) => <tr key={row.province} className="border-t border-slate-300 odd:bg-white even:bg-slate-50"><td className="px-4 py-3 text-left font-bold"><span className="block text-lg font-black text-blue-900">{row.province}</span><span className="mt-1 block text-[11px] font-medium leading-snug text-slate-500">{row.lgus.sort((a, b) => a.localeCompare(b)).join(", ")}</span></td><td className="whitespace-nowrap px-4 py-3 font-bold tabular-nums">{number(row.population)}</td><td className="whitespace-nowrap px-4 py-3 font-bold tabular-nums">{number(row.affectedPopulation)}</td><td className="whitespace-nowrap px-4 py-3 font-bold tabular-nums">{number(row.families)}</td></tr>) : <tr><td colSpan="4" className="px-6 py-20 text-center text-xl font-semibold text-slate-400">Select cities or municipalities to display their population estimates.</td></tr>}
          </tbody>
          {tableRows.length > 0 && <tfoot><tr className="border-t-2 border-blue-900 bg-blue-50 font-black text-blue-950"><td className="px-4 py-4 text-left uppercase">Total</td><td className="whitespace-nowrap px-4 py-4 tabular-nums">{number(totals.population)}</td><td className="whitespace-nowrap px-4 py-4 tabular-nums">{number(totals.affectedPopulation)}</td><td className="whitespace-nowrap px-4 py-4 tabular-nums">{number(totals.families)}</td></tr></tfoot>}
        </table>
      </div>
      <p className="mt-4 text-right text-sm font-semibold italic text-blue-800">Formula: Possible affected population = 30% of total population; families = possible affected population {"\u00F7"} 5.</p>
    </div>
  );
}

function BriefingForecastPage({ intro, canEdit, asOf, reportId }) {
  const editor = useInlineIntroEditor(intro, canEdit, reportId);
  const provinces = ["Agusan del Norte", "Agusan del Sur", "Province of Dinagat Islands", "Surigao del Norte", "Surigao del Sur"];
  const regionalForecast = editor.draft.forecast_scope === "regional";
  const forecastTitleFor = (scope) => {
    const title = String(editor.draft.forecast_title || "LOCAL FORECAST WEATHER CONDITIONS");
    const suffix = scope === "regional" ? "(REGIONAL)" : "(PER PROVINCE)";
    return /\((?:provincial|per province|regional|whole region)\)/i.test(title)
      ? title.replace(/\((?:provincial|per province|regional|whole region)\)/i, suffix)
      : `${title} ${suffix}`;
  };
  return (
    <div className="relative flex h-[810px] flex-col bg-white">
      <InlineEditControls editor={editor} enabled={canEdit} />
      <div className="mx-auto w-full px-12 pt-3 text-center">
        <InlineField editor={editor} field="forecast_title" fontField="forecast_title_font_size" defaultSize={36} className="w-full text-center font-black uppercase text-blue-950 underline" />
        <p style={{ fontSize: `${editor.draft.forecast_title_font_size || 36}px` }} className="w-full text-center font-black text-blue-950 underline">As of {asOf}</p>
      </div>
      {canEdit && <div data-export-ignore="true" className="absolute right-12 top-24 z-50 flex items-center gap-1 rounded-xl border border-blue-200 bg-white p-1.5 shadow-lg"><span className="px-2 text-[9px] font-black uppercase text-slate-500">Forecast coverage</span><button type="button" disabled={editor.saving} onClick={() => editor.saveValues({ forecast_scope: "province", forecast_title: forecastTitleFor("province") })} className={`rounded-lg px-3 py-2 text-[10px] font-black uppercase ${!regionalForecast ? "bg-blue-900 text-white" : "text-blue-900 hover:bg-blue-50"}`}>Per Province</button><button type="button" disabled={editor.saving} onClick={() => editor.saveValues({ forecast_scope: "regional", forecast_title: forecastTitleFor("regional") })} className={`rounded-lg px-3 py-2 text-[10px] font-black uppercase ${regionalForecast ? "bg-blue-900 text-white" : "text-blue-900 hover:bg-blue-50"}`}>Whole Region</button></div>}
      <div className={`mx-12 mt-3 grid min-h-0 flex-1 grid-cols-[19%_54%_10%_17%] border ${regionalForecast ? "grid-rows-[3.5rem_minmax(0,1fr)]" : "grid-rows-[3.5rem_repeat(5,minmax(0,1fr))]"}`}>
        <div className="p-3 text-center text-2xl font-black">{regionalForecast ? "Coverage" : "Province"}</div>
        <div className="border-l p-3 text-center text-2xl font-black">Weather Updates</div>
        <div className="border-l p-3 text-center text-2xl font-black">Signal #</div>
        <div className="border-l p-3 text-center text-2xl font-black">Remarks</div>
        {regionalForecast ? <>
          <div style={{ fontSize: `${editor.draft.forecast_province_font_size || 24}px` }} className="min-h-0 border-t p-4 font-bold text-blue-950">Caraga Region</div>
          <div className="min-h-0 min-w-0 border-l border-t p-4"><InlineField editor={editor} field="forecast_weather" fontField="forecast_weather_font_size" defaultSize={22} multiline label="Regional Weather Updates" className="leading-relaxed" /></div>
          <div className="min-h-0 min-w-0 border-l border-t p-4"><InlineField editor={editor} field="forecast_signal" fontField="forecast_signal_font_size" defaultSize={20} multiline label="Regional Signal #" className="leading-relaxed" /></div>
          <div className="min-h-0 min-w-0 border-l border-t p-4"><InlineField editor={editor} field="forecast_remarks" fontField="forecast_remarks_font_size" defaultSize={19} multiline label="Regional Remarks" className="leading-relaxed" /></div>
        </> : provinces.map((province, index) => (
          <Fragment key={province}>
            <div style={{ fontSize: `${editor.draft.forecast_province_font_size || 24}px` }} className="min-h-0 border-t p-3">{province}</div>
            <div className="min-h-0 min-w-0 border-l border-t p-2">
              <InlineField editor={editor} field={`forecast_weather_${index}`} fallbackValue={editor.draft.forecast_weather} fontField="forecast_weather_font_size" defaultSize={18} multiline className="leading-snug" />
            </div>
            <div className="min-h-0 min-w-0 border-l border-t p-2">
              <InlineField editor={editor} field={`forecast_signal_${index}`} fallbackValue={editor.draft.forecast_signal} fontField="forecast_signal_font_size" defaultSize={17} multiline className="leading-snug" />
            </div>
            <div className="min-h-0 min-w-0 border-l border-t p-2">
              <InlineField editor={editor} field={`forecast_remarks_${index}`} fallbackValue={editor.draft.forecast_remarks} fontField="forecast_remarks_font_size" defaultSize={16} multiline className="leading-snug" />
            </div>
          </Fragment>
        ))}
      </div>
      {editor.editing && <label className="absolute bottom-2 left-12 z-40 rounded bg-blue-50 px-3 py-2 text-xs font-black text-blue-900">Province font size <input type="number" min="8" max="180" value={editor.draft.forecast_province_font_size || 24} onChange={(event) => editor.update("forecast_province_font_size", event.target.value)} className="ml-2 w-16 rounded border bg-white px-2 py-1" /></label>}
    </div>
  );
}

function ResourceCapacityTitlePage({ asOf }) {
  return <div className="relative flex h-full min-h-0 w-full flex-col items-center justify-center overflow-hidden bg-white text-center"><img src="/images/preparedness/branding/resource-capacity-background-v2.png" alt="" aria-hidden="true" className="pointer-events-none absolute inset-0 h-full w-full object-cover object-center opacity-30" /><div className="relative z-10"><h1 className="text-[9rem] font-black uppercase leading-none text-blue-900">Resource Capacity</h1><p className="mt-12 text-5xl font-black uppercase text-red-600">As of {asOf}</p></div></div>;
}

function ReportSectionTitlePage({ title, asOf = null }) {
  return <div className="flex h-full min-h-0 w-full items-center justify-center bg-white px-16 text-center"><div><h1 className={`${asOf ? "text-[9rem] leading-none" : "text-[7.5rem] leading-tight"} font-black uppercase text-blue-900`}>{title}</h1>{asOf && <p className="mt-12 text-5xl font-black uppercase text-red-600">As of {asOf}</p>}</div></div>;
}

// Keep the full-width photography and soft caption overlay independent of table height.
function ActionPhotoPanel({ src, label, fullHeight = false }) {
  return <aside className={`relative min-h-0 w-full overflow-hidden rounded-2xl border border-blue-100 bg-blue-950 shadow-sm ${fullHeight ? "h-full" : "h-[680px] self-start"}`}>
    <img src={src} alt={label} className="absolute inset-0 block h-full w-full object-cover object-center" />
    <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-blue-950 via-blue-950/90 to-transparent px-5 pb-4 pt-12 text-white"><p className="text-[10px] font-black uppercase tracking-[.18em] text-yellow-300">Response in Action</p><p className="mt-1 text-sm font-black leading-snug">{label}</p></div>
  </aside>;
}

function ChallengeText({ children }) {
  const ref = useRef(null);
  useEffect(() => {
    const element = ref.current;
    if (!element) return;
    const fit = () => {
      let size = 25;
      element.style.fontSize = `${size}px`;
      while ((element.scrollHeight > element.clientHeight || element.scrollWidth > element.clientWidth) && size > 8) {
        element.style.fontSize = `${--size}px`;
      }
    };
    fit();
    const observer = new ResizeObserver(fit);
    observer.observe(element);
    document.fonts.ready.then(() => { if (ref.current) fit(); });
    return () => observer.disconnect();
  }, [children]);
  return <p ref={ref} className="h-full min-h-0 min-w-0 flex-1 whitespace-pre-wrap break-words leading-snug">{children}</p>;
}

function ChallengesRecommendationsPage({ intro, canEdit, reportId, pageIndex = 0 }) {
  const savedPages = useMemo(() => challengePagesFromContent(intro?.content), [intro]);
  const [pages, setPages] = useState(savedPages);
  const [editing, setEditing] = useState(false);
  const [selectedPage, setSelectedPage] = useState(pageIndex);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    setPages(savedPages);
    setSelectedPage(pageIndex);
    setEditing(false);
    setError("");
  }, [savedPages, pageIndex]);
  const currentIndex = editing ? Math.min(selectedPage, pages.length - 1) : pageIndex;
  const page = (editing ? pages : savedPages)[currentIndex] ?? savedPages[savedPages.length - 1];
  const updateColumn = (column, update) => setPages(current => current.map((item, index) => index === currentIndex ? { ...item, [column]: update(item[column]) } : item));
  const save = () => {
    if (pages.some(item => Object.values(item).some(rows => rows.some(value => value.length > 1000)))) {
      setError("Keep each row within 1,000 characters. Add another row or page for the remaining text.");
      return;
    }
    setSaving(true);
    setError("");
    router.patch(`/preparedness-for-response/reports/${reportId}/data/briefing-intro`, {
      content: { ...intro?.content, ...challengePagesToContent(pages) },
    }, {
      preserveScroll: true,
      onSuccess: () => setEditing(false),
      onError: errors => setError(Object.values(errors).join(" ")),
      onFinish: () => setSaving(false),
    });
  };
  const buttonClass = "rounded-lg border border-blue-200 bg-white px-4 py-2 text-xs font-black uppercase text-blue-900 disabled:opacity-40";
  return <div className="flex h-full min-h-0 flex-col gap-4 bg-white px-12 py-6">
    <h1 className="shrink-0 text-center text-4xl font-black uppercase text-blue-950">Challenges and Concerns / Ways Forward</h1>
    <div className="grid min-h-0 flex-1 grid-cols-2 border border-blue-100">
      {[["challenges", "Challenges and Concerns"], ["recommendations", "Recommendation"]].map(([column, label]) => <section key={column} className="flex min-h-0 min-w-0 flex-col border-r border-blue-100 last:border-r-0">
        <h2 className="shrink-0 bg-blue-950 px-6 py-4 text-2xl font-black text-white">{label}</h2>
        <div className="grid min-h-0 flex-1" style={{ gridTemplateRows: `repeat(${Math.max(1, page[column].length)}, minmax(0, 1fr))` }}>
          {page[column].map((value, index) => <div key={index} className="flex min-h-0 gap-2 border-b border-blue-100 px-5 py-3 odd:bg-blue-50/50">
            <span className="text-[25px] leading-snug">{index + 1}.</span>
            {editing ? <textarea aria-label={`${label} ${index + 1}`} value={value} disabled={saving} maxLength={1000}
              onChange={event => updateColumn(column, rows => rows.map((row, rowIndex) => rowIndex === index ? event.target.value : row))}
              className="h-full min-h-0 w-full min-w-0 flex-1 resize-none overflow-auto rounded-lg border border-blue-200 bg-white p-2 text-[23px] leading-snug outline-none focus:ring-2 focus:ring-blue-400"
            /> : <ChallengeText>{value}</ChallengeText>}
            {editing && <button type="button" disabled={saving} onClick={() => updateColumn(column, rows => rows.filter((_, rowIndex) => rowIndex !== index))} aria-label={`Remove ${label} ${index + 1}`} className="self-start rounded-lg border border-red-200 bg-white p-2 text-red-600"><Trash2 className="h-4 w-4" /></button>}
          </div>)}
        </div>
        {editing && <div data-export-ignore="true" className="flex shrink-0 items-center gap-3 p-3">
          <button type="button" disabled={saving || page[column].length >= CHALLENGE_ROWS_PER_PAGE} onClick={() => updateColumn(column, rows => [...rows, ""])} className={buttonClass}>Add row</button>
          <span className="text-xs text-slate-500">{page[column].length} / {CHALLENGE_ROWS_PER_PAGE} rows · Add a page for more</span>
        </div>}
      </section>)}
    </div>
    <div className="flex shrink-0 items-center justify-between gap-3">
      <span className="text-xs font-bold text-blue-900">Page {currentIndex + 1} / {(editing ? pages : savedPages).length}</span>
      {canEdit && <div data-export-ignore="true" className="flex items-center gap-2">
        {editing ? <>
          <button type="button" disabled={saving || currentIndex === 0} onClick={() => setSelectedPage(currentIndex - 1)} className={buttonClass}>Previous page</button>
          <button type="button" disabled={saving || currentIndex === pages.length - 1} onClick={() => setSelectedPage(currentIndex + 1)} className={buttonClass}>Next page</button>
          <button type="button" disabled={saving || pages.length >= CHALLENGE_PAGE_LIMIT} onClick={() => { setPages(current => [...current, { challenges: [""], recommendations: [""] }]); setSelectedPage(pages.length); }} className={buttonClass}>Add page</button>
          <button type="button" disabled={saving || pages.length === 1} onClick={() => { setPages(current => current.filter((_, index) => index !== currentIndex)); setSelectedPage(Math.max(0, currentIndex - 1)); }} className={buttonClass}>Remove page</button>
          <button type="button" disabled={saving} onClick={() => { setPages(savedPages); setSelectedPage(pageIndex); setEditing(false); setError(""); }} className={buttonClass}>Cancel</button>
          <button type="button" disabled={saving} onClick={save} className="rounded-lg bg-blue-900 px-5 py-2 text-xs font-black uppercase text-white disabled:opacity-50">{saving ? "Saving…" : "Save"}</button>
        </> : <button type="button" onClick={() => setEditing(true)} className="inline-flex items-center gap-2 rounded-lg bg-blue-900 px-4 py-2 text-xs font-black uppercase text-white"><Pencil className="h-4 w-4" /> Edit</button>}
      </div>}
    </div>
    {error && <p data-export-ignore="true" role="alert" className="shrink-0 text-sm text-red-700">{error}</p>}
  </div>;
}

function CccmIdppUpdatesPage({ intro, canEdit, reportId }) {
  const editor = useInlineIntroEditor(intro, canEdit, reportId);
  return (
    <div className="grid h-full min-h-0 grid-cols-[minmax(0,1fr)_320px] gap-7 bg-white p-6">
      <div className="flex min-h-0 flex-col">
        <h1 className="pt-3 text-center text-5xl font-black uppercase text-blue-950">CCCM and IDPP Updates</h1>
        <div className="mt-8 flex min-h-0 flex-1 flex-col justify-center gap-8 rounded-2xl border border-blue-100 bg-blue-50/40 p-8 text-justify">
          {[1, 2, 3].map((number) => (
            <div key={number} className="grid grid-cols-[2rem_1fr] items-start gap-3">
              <span aria-hidden="true" className="pt-1 text-4xl font-black leading-none text-blue-900">{"\u2022"}</span>
              <InlineField editor={editor} field={`cccm_idpp_bullet_${number}`} fallbackValue="" fontField="cccm_idpp_bullet_font_size" defaultSize={28} multiline label={`Bullet ${number}`} className="min-h-[4rem] w-full text-justify font-semibold leading-snug text-slate-800" />
            </div>
          ))}
        </div>
      </div>
      <ActionPhotoPanel src="/images/preparedness/cccm-idpp-updates.png" label="CCCM and IDPP response activities across Caraga" fullHeight />
    </div>
  );
}

function EvacuationCenterPhoto({ photo }) {
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [photo.image_url]);
  return <figure className="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border border-blue-100 bg-slate-50">
    <div className="relative min-h-0 flex-1 bg-slate-900">
      {failed ? <div className="flex h-full items-center justify-center p-4 text-center text-sm text-slate-200">Site photo temporarily unavailable</div> : <img src={photo.image_url} alt={photo.name} onError={() => setFailed(true)} className="h-full w-full object-contain" />}
      <span className={`absolute left-2 top-2 rounded px-2 py-1 text-[10px] font-black uppercase text-white ${photo.status === "Permanent" ? "bg-emerald-700" : "bg-amber-700"}`}>{photo.status}</span>
    </div>
    <figcaption className="shrink-0 px-3 py-2"><p className="text-xs font-black leading-tight text-blue-950">{photo.name}</p><p className="mt-0.5 text-[11px] leading-tight text-slate-600">{[photo.barangay, photo.municipality].filter(Boolean).join(", ")}</p><p className="mt-1 text-[10px] font-semibold tabular-nums text-blue-700">{Number(photo.lat).toFixed(5)}° N, {Number(photo.lng).toFixed(5)}° E</p></figcaption>
  </figure>;
}

function EvacuationCentersReportPage({ summary, intro, populationLgus }) {
  const selectedCodes = String(intro?.content?.possible_affected_lgu_codes || "").split(",").map((code) => code.trim()).filter(Boolean);
  const selected = (populationLgus || []).filter((row) => selectedCodes.includes(String(row.code)));
  const totalPopulation = selected.reduce((sum, row) => sum + Number(row.population || 0), 0);
  const affectedPopulation = Math.round(totalPopulation * 0.30);
  const facilities = summary?.facilities || {};
  const totalCenters = Number(summary?.total_identified || 0);
  const photos = summary?.selected_photos || [];
  const facilityColumns = [
    ["Child-Friendly Spaces", "child_friendly_space"], ["Women-Friendly Spaces", "women_friendly_space"], ["Shelter and accommodation", "shelter_accommodation"],
    ["Camp Management Desk or office", "camp_management_desk"], ["Community Kitchen", "community_kitchen"], ["Storage Area", "storage_area"],
    ["Toilets and Bathing Areas", "toilets_bathing"], ["Hand-washing Facility", "handwashing"], ["Water Based Facilities", "water_based"], ["Laundry Space", "laundry_space"],
    ["Health Facilities", "health_facilities"], ["Couple’s Room", "couples_room"], ["Prayer Rooms", "prayer_rooms"], ["Livestock Area", "livestock_area"],
  ];
  const value = (item) => item === null || item === undefined ? "—" : number(item);
  return (
    <div className="flex h-full min-h-0 flex-col bg-white px-10 py-5 gap-3 text-slate-950">
      <h1 className="text-center text-4xl font-black text-blue-900">Evacuation Center Preparedness</h1>
      <div className="grid shrink-0 grid-cols-2 gap-x-8 gap-y-2">
        <section className="flex h-28 flex-col justify-center rounded-xl bg-blue-950 px-6 py-2 text-white"><p className="text-xs font-black uppercase tracking-widest text-blue-100">Identified evacuation centers · Caraga</p><div className="mt-1 flex items-end gap-4"><strong className="text-5xl leading-none">{number(totalCenters)}</strong><span className="pb-1 text-sm text-blue-100">Total ECs in the inventory</span></div></section>
        <table className="w-full table-fixed border-collapse text-center text-lg"><thead><tr className="h-16">{["Total Population", "30% of the Population", "Number of Families"].map((label) => <th key={label} className="border border-slate-500 px-3 py-2 text-lg font-black">{label}</th>)}</tr></thead><tbody><tr className="h-12"><td className="border border-slate-500 py-2 font-black">{number(totalPopulation)}</td><td className="border border-slate-500 py-2 font-black">{number(affectedPopulation)}</td><td className="border border-slate-500 py-2 font-black">{number(Math.round(affectedPopulation / 5))}</td></tr></tbody></table>
        <p className="col-start-2 text-right text-xs italic text-slate-500">Based on the LGUs selected under Possible Affected Families.</p>
      </div>
      {Number(summary?.unclassified_centers || 0) > 0 && <p className="text-xs text-slate-600">{number(summary.unclassified_centers)} identified ECs have an unclassified status ({(summary.unclassified_centers / totalCenters * 100).toFixed(1)}%).</p>}
      <section className="shrink-0">
        <h2 className="mb-2 text-xl font-black">Number of Established Evacuation Center and Camps</h2>
        <table className="w-full table-fixed border-collapse text-center text-base">
          <thead className="bg-[#c8ddec]"><tr className="h-16">{["Permanent Evacuation Centers (ECs)", "ECs with Camp Safety Audit Conducted", "Schools used as camps and other temporary shelters"].map(label => <th key={label} className="border border-slate-500 px-3 py-2 font-bold">{label}</th>)}</tr></thead>
          <tbody><tr className="h-12">
            <td className="border border-slate-500 py-2 text-lg font-black">{value(summary?.permanent_centers)} <span className="ml-2 text-sm font-semibold text-slate-500">({Number(summary?.permanent_percentage || 0).toFixed(1)}%)</span></td>
            <td className="border border-slate-500 py-2 text-lg font-black">{value(summary?.camp_safety_audited)}</td>
            <td className="border border-slate-500 py-2 text-lg font-black">{value(summary?.temporary_centers)} <span className="ml-2 text-sm font-semibold text-slate-500">({Number(summary?.temporary_percentage || 0).toFixed(1)}%)</span></td>
          </tr></tbody>
        </table>
        <p className="mt-2 text-xs italic text-slate-500">Camp Safety Audit count is shown as unavailable when no audit field is recorded in the inventory. Percentages are based on all identified ECs.</p>
      </section>
      <section className="shrink-0"><h2 className="mb-2 text-xl font-black">Number of Established Evacuation Center Facilities</h2><table className="w-full table-fixed border-collapse text-center text-[12px] leading-tight"><thead className="bg-[#c8ddec]"><tr>{facilityColumns.slice(0,6).map(([label]) => <th key={label} rowSpan="2" className="border border-slate-500 px-1.5 py-2 font-bold">{label}</th>)}<th colSpan="4" className="border border-slate-500 px-2 py-2 font-bold">WASH Facilities</th>{facilityColumns.slice(10).map(([label]) => <th key={label} rowSpan="2" className="border border-slate-500 px-1.5 py-2 font-bold">{label}</th>)}</tr><tr>{facilityColumns.slice(6,10).map(([label]) => <th key={label} className="border border-slate-500 px-1 py-2 font-bold">{label}</th>)}</tr></thead><tbody><tr>{facilityColumns.map(([label,key]) => <td key={key} className="border border-slate-500 py-2 text-base font-black">{value(facilities[key])}</td>)}</tr></tbody></table><p className="mt-2 text-xs font-bold uppercase text-red-600">Note: The number of available facilities reflects the number of permanent evacuation centers.</p></section>
      <section className="flex min-h-0 flex-1 flex-col gap-2">
        <div className="flex shrink-0 items-center justify-between"><h2 className="text-xl font-black text-blue-950">Permanent Evacuation Centers</h2><span className="text-xs font-semibold text-slate-500">Mainit / Alegria / Sison / Geotagged ECs</span></div>
        {photos.length ? <div className="grid min-h-0 flex-1 grid-flow-col auto-cols-fr grid-rows-1 gap-3">{photos.map(photo => <EvacuationCenterPhoto key={photo.id} photo={photo} />)}</div> : <div className="flex min-h-0 flex-1 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-500">No geotagged EC photos are available in the inventory.</div>}
      </section>
      <p className="shrink-0 text-right text-xs font-semibold italic text-blue-800">Source: Caraga Evacuation Center Inventory{summary?.generated_at ? ` · Updated ${new Date(summary.generated_at).toLocaleDateString()}` : ""}</p>
    </div>
  );
}
function ActionsTakenTitlePage({ canEdit, asOf, reportId }) {
  return <div className="relative flex h-full min-h-0 w-full items-center justify-center bg-white px-16 text-center"><div><h1 className="text-[9rem] font-black uppercase leading-none text-blue-900">Actions Taken</h1><p className="mt-12 text-5xl font-black uppercase text-red-600">As of {asOf}</p></div>{canEdit && <button type="button" onClick={() => router.post(`/preparedness-for-response/reports/${reportId}/action-pages`, {}, { preserveScroll: true })} className="absolute right-10 top-8 inline-flex items-center gap-2 rounded-xl bg-blue-900 px-5 py-3 text-sm font-black uppercase text-white shadow-lg"><Plus className="h-4 w-4" /> Add page</button>}</div>;
}

function ActionsTakenPage({ page, pageNumber, canEdit, canRemove, reportId }) {
  const initialActions = () => (page.actions?.length >= 2 ? page.actions.slice(0, 3) : ['', '']);
  const [editing, setEditing] = useState(false);
  const [actions, setActions] = useState(initialActions);
  const [captions, setCaptions] = useState(() => initialActions().map((_, index) => page.captions?.[index] ?? ''));
  const [imagePaths, setImagePaths] = useState(() => initialActions().map((_, index) => page.image_paths?.[index] ?? (index === 0 ? page.image_path : null)));
  const [images, setImages] = useState(() => initialActions().map(() => null));
  const [saving, setSaving] = useState(false);
  const [previews, setPreviews] = useState([null, null, null]);
  useEffect(() => {
    setEditing(false);
    const nextActions = page.actions?.length >= 2 ? page.actions.slice(0, 3) : ['', ''];
    setActions(nextActions);
    setCaptions(nextActions.map((_, index) => page.captions?.[index] ?? ''));
    setImagePaths(nextActions.map((_, index) => page.image_paths?.[index] ?? (index === 0 ? page.image_path : null)));
    setImages(nextActions.map(() => null));
  }, [page.id, page.actions, page.captions, page.image_paths, page.image_path]);
  useEffect(() => {
    const urls = images.map((image) => image ? URL.createObjectURL(image) : null);
    setPreviews(urls);
    return () => urls.forEach((url) => { if (url) URL.revokeObjectURL(url); });
  }, [images]);
  const save = () => {
    setSaving(true);
    router.post(`/preparedness-for-response/reports/${reportId}/action-pages/${page.id}`, { _method: 'patch', actions, captions, existing_image_paths: imagePaths, images }, { forceFormData: true, preserveScroll: true, onSuccess: () => router.reload({ only: ['actionPages'], preserveScroll: true, onSuccess: () => { setEditing(false); setImages(actions.map(() => null)); } }), onFinish: () => setSaving(false) });
  };
  const addAction = () => {
    if (actions.length >= 3) return;
    setActions((current) => [...current, '']); setCaptions((current) => [...current, '']); setImagePaths((current) => [...current, null]); setImages((current) => [...current, null]);
  };
  const removeAction = (index) => {
    if (actions.length <= 2) return;
    setActions((current) => current.filter((_, itemIndex) => itemIndex !== index));
    setCaptions((current) => current.filter((_, itemIndex) => itemIndex !== index));
    setImagePaths((current) => current.filter((_, itemIndex) => itemIndex !== index));
    setImages((current) => current.filter((_, itemIndex) => itemIndex !== index));
  };
  return (
    <div className="relative grid h-full min-h-0 grid-cols-[minmax(0,1fr)_420px] gap-7 bg-white p-7">
      <div className="flex min-h-0 flex-col">
        <h1 className="text-center text-5xl font-black uppercase text-blue-950">Actions Taken</h1>
        <div className="mt-8 flex flex-1 flex-col justify-center gap-8 rounded-2xl border border-blue-100 bg-blue-50/40 p-9 text-justify">
          {actions.map((action, index) => <div key={index} className="grid grid-cols-[2rem_minmax(0,1fr)_auto] items-start gap-3"><span aria-hidden="true" className="pt-1 text-4xl font-black leading-none text-blue-900">{"\u2022"}</span>{editing ? <textarea value={action} onChange={(event) => setActions((current) => current.map((value, itemIndex) => itemIndex === index ? event.target.value : value))} rows={3} placeholder={`Action taken ${index + 1}`} className="w-full resize-none rounded-xl border border-blue-200 bg-white p-3 text-justify text-2xl font-semibold outline-none focus:ring-2 focus:ring-blue-400" /> : <p className="min-h-[4rem] text-justify text-3xl font-semibold leading-snug text-slate-800">{action}</p>}{editing && actions.length > 2 && <button type="button" onClick={() => removeAction(index)} className="mt-1 rounded-lg border border-red-200 bg-white p-2 text-red-600 shadow-sm" aria-label={`Remove action ${index + 1}`}><Trash2 className="h-4 w-4" /></button>}</div>)}
          {editing && actions.length < 3 && <button type="button" onClick={addAction} className="inline-flex w-fit items-center gap-2 rounded-lg border border-blue-200 bg-white px-4 py-2 text-xs font-black uppercase text-blue-900"><Plus className="h-4 w-4" /> Add action</button>}
        </div>
      </div>
      <aside className="grid min-h-0 gap-3" style={{ gridTemplateRows: `repeat(${actions.length}, minmax(0, 1fr))` }}>
        {actions.map((action, index) => {
          const imagePath = previews[index] || imagePaths[index];
          return <div key={index} className="relative min-h-0 overflow-hidden rounded-2xl border border-blue-100 bg-slate-100 shadow-sm">{imagePath ? <img src={imagePath} alt={`Action taken ${index + 1}`} className="h-full w-full object-contain object-center" /> : <div className="flex h-full items-center justify-center p-5 text-center text-sm font-bold text-slate-400">Upload photo for action {index + 1}</div>}<div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-blue-950 via-blue-950/90 to-transparent px-4 pb-3 pt-10 text-white"><p className="text-[8px] font-black uppercase tracking-[.18em] text-yellow-300">Response in Action</p>{editing ? <input type="text" value={captions[index]} onChange={(event) => setCaptions((current) => current.map((value, captionIndex) => captionIndex === index ? event.target.value : value))} placeholder={`Photo caption ${index + 1}`} className="mt-1 w-full rounded border border-white/30 bg-white/95 px-2 py-1 text-xs font-bold text-slate-900 outline-none" /> : captions[index] ? <p className="mt-0.5 line-clamp-2 text-xs font-black">{captions[index]}</p> : null}</div>{editing && <input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => setImages((current) => current.map((value, imageIndex) => imageIndex === index ? (event.target.files?.[0] ?? null) : value))} className="absolute left-2 right-2 top-2 z-20 rounded-md bg-white/95 p-2 text-[9px] shadow" />}</div>;
        })}
      </aside>
      {canEdit && !editing && <button type="button" onClick={() => setEditing(true)} className="absolute right-10 top-10 z-30 inline-flex items-center gap-2 rounded-xl bg-blue-900 px-4 py-2 text-sm font-black uppercase text-white shadow-lg"><Pencil className="h-4 w-4" /> Edit</button>}
      {editing && <div className="absolute bottom-10 right-[455px] z-30 flex gap-2 rounded-xl bg-white p-2 shadow-lg"><button type="button" onClick={() => { const resetActions = page.actions?.length >= 2 ? page.actions.slice(0, 3) : ['', '']; setEditing(false); setActions(resetActions); setCaptions(resetActions.map((_, index) => page.captions?.[index] ?? '')); setImagePaths(resetActions.map((_, index) => page.image_paths?.[index] ?? (index === 0 ? page.image_path : null))); setImages(resetActions.map(() => null)); }} className="rounded-lg border px-4 py-2 text-xs font-black uppercase">Cancel</button>{canRemove && <button type="button" onClick={() => router.delete(`/preparedness-for-response/reports/${reportId}/action-pages/${page.id}`, { preserveScroll: true })} className="rounded-lg border border-red-200 px-4 py-2 text-xs font-black uppercase text-red-600">Remove page</button>}<button type="button" onClick={save} disabled={saving} className="rounded-lg bg-blue-900 px-4 py-2 text-xs font-black uppercase text-white disabled:opacity-50">{saving ? 'Saving\u2026' : 'Save'}</button></div>}
      <span className="absolute bottom-2 left-8 text-[10px] font-black uppercase tracking-widest text-blue-900">Actions Taken {"\u00B7"} {pageNumber}</span>
    </div>
  );
}

function ThankYouPage() {
  return (
    <div className="relative flex h-[900px] items-center justify-center bg-transparent">
      <h1 className="whitespace-nowrap text-center text-[138px] font-semibold leading-none tracking-[-0.045em] text-white">
        Thank you!
      </h1>
    </div>
  );
}

function ItemPage({ pageTitle, subtitle, asOf, rows, heroImage, heroLabel }) {
  const quantity = total(rows, "quantity");
  const value = total(rows, "value");
  return (
    <>
      <BriefingHeader title={pageTitle} subtitle={subtitle} asOf={asOf} />
      <div className="grid gap-5 p-5 lg:grid-cols-[22rem_1fr] sm:p-7">
        <aside className="rounded-2xl bg-gradient-to-b from-blue-950 to-blue-800 p-5 text-white">
          <div className="flex h-64 items-center justify-center rounded-xl bg-white p-5">
            <img
              src={assetUrl(heroImage)}
              alt={heroLabel}
              className="max-h-full max-w-full object-contain"
            />
          </div>
          <p className="mt-5 text-xs font-black uppercase tracking-widest text-blue-200">
            Total available
          </p>
          <p className="mt-1 text-4xl font-black text-yellow-300">
            {number(quantity)}
          </p>
          <p className="mt-1 text-sm font-bold">{heroLabel}</p>
          <div className="mt-4 border-t border-white/20 pt-4">
            <p className="text-[10px] font-black uppercase tracking-widest text-blue-200">
              Current stockpile value
            </p>
            <p className="mt-1 text-xl font-black">{money(value)}</p>
          </div>
        </aside>
        <section className="overflow-hidden rounded-2xl border border-slate-200">
          <DataTable
            rows={rows}
            columns={[
              { key: "item", label: "Item", bold: true },
              { key: "category", label: "Category" },
              { key: "warehouses", label: "Locations", align: "right" },
              {
                key: "quantity",
                label: "Available",
                align: "right",
                bold: true,
                render: (row) => number(row.quantity),
              },
              {
                key: "value",
                label: "Total value",
                align: "right",
                bold: true,
                render: (row) => money(row.value),
              },
            ]}
          />
        </section>
      </div>
    </>
  );
}

function Overview({
  asOf,
  summary,
  provinceRows,
  alerts,
  operations,
  geographyLabel = "Province",
}) {
  const requestTotal = Object.values(operations.requests || {}).reduce(
    (sum, value) => sum + Number(value || 0),
    0,
  );
  const dispatchTotal = Object.values(operations.dispatches || {}).reduce(
    (sum, value) => sum + Number(value || 0),
    0,
  );
  return (
    <>
      <BriefingHeader
        title="Regional Preparedness for Response"
        subtitle="Executive resource capacity and operational readiness picture"
        asOf={asOf}
      />
      <div className="space-y-5 p-5 sm:p-7">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Metric
            icon={CircleDollarSign}
            label="Total Standby Resources"
            value={money(summary.total_resources)}
            note={`${money(summary.standby_funds)} standby funds`}
          />
          <Metric
            icon={PackageCheck}
            label="Available Stockpile"
            value={number(summary.stockpile_quantity)}
            note={money(summary.stockpile_value)}
          />
          <Metric
            icon={Warehouse}
            label="Active Warehouses"
            value={`${number(summary.active_warehouses)} / ${number(summary.total_warehouses)}`}
            note="Active versus recorded nodes"
          />
          <Metric
            icon={AlertTriangle}
            label="Active Alerts"
            value={number(summary.active_alerts)}
            note={`${number(summary.near_expiry)} near-expiry batches`}
            red={Number(summary.active_alerts) > 0}
          />
        </div>
        <div className="grid gap-5 xl:grid-cols-[1.3fr_.7fr]">
          <section className="overflow-hidden rounded-2xl border border-slate-200">
            <div className="bg-blue-950 px-5 py-3 text-sm font-black uppercase text-white">
              Resource Capacity by Province
            </div>
            <DataTable
              rows={provinceRows}
              columns={[
                { key: "province", label: geographyLabel, bold: true },
                {
                  key: "active_warehouses",
                  label: "Active / Total Nodes",
                  align: "right",
                  render: (row) =>
                    `${number(row.active_warehouses)} / ${number(row.warehouses)}`,
                },
                {
                  key: "ffp",
                  label: "Family Food Packs",
                  align: "right",
                  bold: true,
                  render: (row) => number(row.ffp),
                },
                {
                  key: "quantity",
                  label: "All Stockpile",
                  align: "right",
                  render: (row) => number(row.quantity),
                },
                {
                  key: "value",
                  label: "Stockpile Value",
                  align: "right",
                  bold: true,
                  render: (row) => money(row.value),
                },
              ]}
            />
          </section>
          <section className="rounded-2xl bg-blue-950 p-5 text-white">
            <h3 className="text-sm font-black uppercase tracking-wide">
              Operational Picture
            </h3>
            <div className="mt-4 grid grid-cols-2 gap-3">
              {[
                [operations.dromic_reports, "DROMIC reports"],
                [operations.reports_this_month, "This month"],
                [requestTotal, "Requests"],
                [dispatchTotal, "Dispatches"],
              ].map(([value, label]) => (
                <div key={label} className="rounded-xl bg-white/10 p-4">
                  <p className="text-2xl font-black text-yellow-300">
                    {number(value)}
                  </p>
                  <p className="text-[10px] font-black uppercase text-blue-100">
                    {label}
                  </p>
                </div>
              ))}
            </div>
            <div className="mt-5 border-t border-white/15 pt-4">
              <p className="text-xs font-black uppercase text-blue-200">
                Current alert posture
              </p>
              {alerts.length ? (
                alerts.slice(0, 3).map((alert) => (
                  <div
                    key={alert.id}
                    className="mt-3 rounded-lg bg-red-500/20 p-3"
                  >
                    <p className="text-xs font-black">
                      {alert.incident || title(alert.level)}
                    </p>
                    <p className="mt-1 text-[10px] text-blue-100">
                      {alert.coverage || "Regional coverage"}
                    </p>
                  </div>
                ))
              ) : (
                <p className="mt-2 text-sm font-bold text-emerald-300">
                  No active regional alert recorded.
                </p>
              )}
            </div>
          </section>
        </div>
      </div>
    </>
  );
}

function NfiPage({ asOf, rows }) {
  const cards = [
    ["family-clothing-kit.png", "Family Clothing Kit", ["clothing"]],
    ["sleeping-kit.png", "Sleeping Kit", ["sleeping"]],
    ["hygiene-kit.png", "Hygiene Kit", ["hygiene"]],
    ["kitchen-kit.png", "Kitchen Kit", ["kitchen"]],
  ];
  const find = (terms) =>
    rows.filter((row) =>
      terms.some((term) => row.item.toLowerCase().includes(term)),
    );
  return (
    <>
      <BriefingHeader
        title="Breakdown of Available Non-Food Items"
        subtitle="Essential relief commodities currently reflected in DRIMS inventory"
        asOf={asOf}
      />
      <div className="space-y-5 p-5 sm:p-7">
        <div className="grid gap-4 lg:grid-cols-2">
          {cards.map(([image, label, terms]) => {
            const matches = find(terms);
            return (
              <ResourceCard
                key={label}
                image={image}
                label={label}
                quantity={total(matches, "quantity")}
                value={total(matches, "value")}
                note={
                  matches.length
                    ? `${total(matches, "warehouses")} recorded location entries`
                    : "No matching DRIMS inventory record"
                }
              />
            );
          })}
        </div>
        <section className="overflow-hidden rounded-2xl border border-slate-200">
          <DataTable
            rows={rows}
            columns={[
              { key: "item", label: "Other NFI", bold: true },
              { key: "warehouses", label: "Locations", align: "right" },
              {
                key: "quantity",
                label: "Available",
                align: "right",
                bold: true,
                render: (row) => number(row.quantity),
              },
              {
                key: "value",
                label: "Value",
                align: "right",
                bold: true,
                render: (row) => money(row.value),
              },
            ]}
          />
        </section>
      </div>
    </>
  );
}

function ShelterPage({ asOf, rows, itemRows }) {
  const inventoryFor = (terms) =>
    itemRows.filter((row) =>
      terms.some((term) => row.item.toLowerCase().includes(term)),
    );
  const cards = [
    ["family-tent.png", "Family Tent", ["family tent"]],
    ["modular-tent.png", "Modular Tent", ["modular tent"]],
    ["sleeping-kit.png", "Sleeping Kit", ["sleeping"]],
    ["cccm-kit.png", "CCCM Kit", ["cccm"]],
  ];
  return (
    <>
      <BriefingHeader
        title="Emergency Shelter Resource Capacity"
        subtitle="Shelter, sleeping, and camp coordination commodities"
        asOf={asOf}
      />
      <div className="space-y-5 p-5 sm:p-7">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {cards.map(([image, label, terms]) => {
            const found = inventoryFor(terms);
            return (
              <ResourceCard
                key={label}
                image={image}
                label={label}
                quantity={found.length ? total(found, "quantity") : null}
                value={found.length ? total(found, "value") : null}
                note={
                  found.length
                    ? null
                    : "Not currently identified in DRIMS stock records"
                }
              />
            );
          })}
        </div>
        <section className="overflow-hidden rounded-2xl border border-slate-200">
          <DataTable
            rows={rows}
            columns={[
              { key: "item", label: "Shelter Resource", bold: true },
              { key: "category", label: "Category" },
              { key: "warehouses", label: "Locations", align: "right" },
              {
                key: "quantity",
                label: "Available",
                align: "right",
                bold: true,
                render: (row) => number(row.quantity),
              },
              {
                key: "value",
                label: "Value",
                align: "right",
                bold: true,
                render: (row) => money(row.value),
              },
            ]}
          />
        </section>
      </div>
    </>
  );
}

function FacilitiesPage({ asOf, assets, onEdit, hideZeroValues = false }) {
  const visibleAssets = hideZeroValues
    ? assets.filter((asset) => Number(asset.quantity || 0) !== 0)
    : assets;
  return (
    <>
      <BriefingHeader
        title="Mobile Response Vehicles and Equipment"
        subtitle="Deployable logistics vehicles, communications equipment, and handling resources currently on standby"
        asOf={asOf}
        onEdit={onEdit}
      />
      <div className="space-y-5 p-5 sm:p-7">
        <div className="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {visibleAssets.map((asset) => (
            <ResourceCard
              key={asset.id ?? asset.label}
              image={asset.image_path}
              label={asset.label}
              quantity={asset.quantity}
              value={null}
              note={asset.status}
              compact
              expandImage={["Mobile Command Center (MCC) and ICT Equipment", "Mobile Kitchen", "Wing Van", "Starlinks"].includes(asset.label)}
            />
          ))}
        </div>
        <aside className="relative overflow-hidden rounded-2xl border border-blue-100 bg-slate-100 shadow-sm">
          <img src={assetUrl("mcc-dep2.png")} alt="Mobile response vehicles and equipment supporting field operations" className="block h-auto w-full object-contain" />
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-blue-950 via-blue-950/90 to-transparent px-5 pb-4 pt-12 text-white">
            <p className="text-[10px] font-black uppercase tracking-[.18em] text-yellow-300">Response in Action</p>
            <p className="mt-1 text-sm font-black">Mobile response vehicles and equipment supporting field operations</p>
          </div>
        </aside>
      </div>
    </>
  );
}

function QrtHumanResourcesPage({ asOf, rows, onEdit }) {
  const geographicRows = rows.map((row) => ({ ...row, type: row.coverage_type }));
  const totalMembers = total(geographicRows, "members");
  const localMembers = geographicRows
    .filter((row) => row.type !== "Regional")
    .reduce((sum, row) => sum + row.members, 0);
  const maxArea = Math.max(...geographicRows.map((row) => row.members), 1);

  return (
    <>
      <BriefingHeader
        title="Human Resources (QRT)"
        subtitle="Quick Response Team membership and geographic coverage across Caraga"
        asOf={asOf}
        onEdit={onEdit}
      />
      <div className="grid h-[46rem] grid-cols-[minmax(0,1fr)_28rem] items-stretch gap-5 p-7">
        <div className="grid min-w-0 grid-rows-[auto_auto_1fr] gap-4">
          <div className="grid grid-cols-3 gap-3">
            <Metric icon={UsersRound} label="Total QRT Members" value={number(totalMembers)} compact />
            <Metric icon={ShieldCheck} label="Regional QRT / FO" value={number(total(geographicRows.filter((row) => row.type === "Regional"), "members"))} compact />
            <Metric icon={Building2} label="P/C/M QRT Members" value={number(localMembers)} compact />
          </div>
          <section className="overflow-hidden rounded-2xl border border-sky-200 bg-gradient-to-br from-white via-sky-50 to-cyan-50 shadow-sm">
            <div className="border-b border-sky-100 px-5 py-3">
              <p className="text-[10px] font-black uppercase tracking-[.2em] text-cyan-700">Regional Deployment Profile</p>
              <h3 className="mt-1 text-xl font-black text-blue-950">QRT Members by Coverage Area</h3>
              <p className="mt-1 text-xs font-semibold text-slate-600">Field Office and local teams available for rapid assessment, coordination, logistics, reporting, protection, and emergency operations.</p>
            </div>
            <div className="grid grid-cols-2 gap-2 p-3">
              {geographicRows.map((row) => (
                <article key={row.area} className="rounded-xl border border-sky-100 bg-white/90 p-2.5 shadow-sm">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-black uppercase leading-tight text-slate-600">{row.area}</p>
                      <p className="mt-1 text-xs font-bold uppercase text-cyan-700">{row.type}</p>
                    </div>
                    <span className="whitespace-nowrap text-2xl font-black tabular-nums text-blue-900">{number(row.members)}</span>
                  </div>
                  <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-sky-100">
                    <div className="h-full rounded-full bg-gradient-to-r from-blue-500 to-cyan-400" style={{ width: `${(row.members / maxArea) * 100}%` }} />
                  </div>
                </article>
              ))}
            </div>
          </section>
          <aside className="relative min-h-0 self-stretch overflow-hidden rounded-2xl border border-blue-100 bg-slate-900 shadow-sm">
            <img src="/images/preparedness/swad-qrt-final.png" alt="SWAD Quick Response Team in action" className="absolute inset-0 h-[calc(100%_+_1rem)] w-full -translate-y-4 object-cover object-center" />
            <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-blue-950 via-blue-950/85 to-transparent px-5 pb-3 pt-10 text-white">
              <p className="text-[10px] font-black uppercase tracking-[.18em] text-yellow-300">Response in Action</p>
              <p className="mt-1 text-sm font-black">SWAD Quick Response Teams supporting field operations</p>
            </div>
          </aside>
        </div>
        <aside className="relative min-h-0 self-stretch overflow-hidden rounded-2xl border border-blue-100 bg-slate-100 shadow-sm">
          <img src="/images/preparedness/qrt-eoc.png" alt="Quick Response Team at the Emergency Operations Center" className="absolute inset-0 h-full w-full object-cover" />
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-blue-950 via-blue-950/90 to-transparent px-5 pb-4 pt-12 text-white">
            <p className="text-[10px] font-black uppercase tracking-[.18em] text-yellow-300">Response in Action</p>
            <p className="mt-1 text-sm font-black">Quick Response Team coordinating at the Emergency Operations Center</p>
          </div>
        </aside>
      </div>
    </>
  );
}

function QrtSpecializationsPage({ asOf, rows, onEdit }) {
  return (
    <>
      <BriefingHeader title="QRT Specializations" subtitle="Trained Quick Response Team capability pool across Caraga" asOf={asOf} onEdit={onEdit} />
      <div className="grid h-[46rem] grid-cols-[minmax(0,1fr)_22rem] items-stretch gap-5 p-7">
        <div className="grid min-w-0 grid-rows-[auto_1fr] gap-4">
        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 bg-gradient-to-r from-sky-50 to-cyan-50 px-5 py-3">
            <h3 className="text-xl font-black text-blue-950">Trained Capability Pool</h3>
            <p className="mt-1 text-xs font-semibold text-slate-600">Members may hold more than one specialization; totals represent training records, not unique personnel.</p>
          </div>
          <table className="w-full table-fixed text-base">
            <thead className="bg-gradient-to-r from-blue-950 via-blue-900 to-blue-800 text-white">
              <tr><th className="w-[75%] px-5 py-3 text-left text-sm font-black uppercase">Specialization</th><th className="w-[25%] px-5 py-3 text-right text-sm font-black uppercase">Number of QRT</th></tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.specialization} className="border-b border-slate-100 odd:bg-white even:bg-slate-50/80">
                  <td className="px-5 py-2.5 font-bold text-slate-800">{row.specialization}</td>
                  <td className="px-5 py-2.5 text-right text-lg font-black tabular-nums text-blue-950">{number(row.members)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
        <aside className="relative min-h-0 overflow-hidden rounded-2xl border border-blue-100 bg-slate-900 shadow-sm">
          <img src="/images/preparedness/cccm-idpp.png" alt="CCCM and IDPP Quick Response Team in action" className="absolute inset-0 h-full w-full object-cover" />
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-blue-950 via-blue-950/85 to-transparent px-5 pb-3 pt-10 text-white">
            <p className="text-[10px] font-black uppercase tracking-[.18em] text-yellow-300">Response in Action</p>
            <p className="mt-1 text-sm font-black">CCCM and IDPP Quick Response Team capability in action</p>
          </div>
        </aside>
        </div>
        <aside className="relative min-h-0 self-stretch overflow-hidden rounded-2xl border border-blue-100 bg-slate-100 shadow-sm">
          <img src="/images/preparedness/qrt-qrt.png" alt="Quick Response Team specialization activities" className="absolute inset-0 h-full w-full object-cover" />
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-blue-950 via-blue-950/90 to-transparent px-5 pb-4 pt-12 text-white">
            <p className="text-[10px] font-black uppercase tracking-[.18em] text-yellow-300">Response in Action</p>
            <p className="mt-1 text-sm font-black">Quick Response Teams strengthening specialized response capabilities</p>
          </div>
        </aside>
      </div>
    </>
  );
}

const preparednessEditorConfig = {
  "response-assets": {
    title: "Mobile Response Vehicles and Equipment",
    fields: [
      ["label", "Item / Equipment", "text"],
      ["quantity", "Quantity", "number"],
      ["status", "Status", "status"],
      ["image_path", "Image filename", "image"],
    ],
    blank: { label: "", quantity: 0, status: "On Standby", image_path: "" },
  },
  "qrt-coverage": {
    title: "Human Resources (QRT)",
    fields: [
      ["area", "Coverage Area", "text"],
      ["coverage_type", "Coverage Type", "text"],
      ["members", "QRT Members", "number"],
    ],
    blank: { area: "", coverage_type: "Provincial / City / Municipal", members: 0 },
  },
  "qrt-specializations": {
    title: "QRT Specializations",
    fields: [
      ["specialization", "Specialization", "text"],
      ["members", "Number of QRT", "number"],
    ],
    blank: { specialization: "", members: 0 },
  },
};

function PreparednessDataEditor({ section, initialRows, onClose, reportId }) {
  const config = preparednessEditorConfig[section];
  const [rows, setRows] = useState(() => initialRows.map((row) => ({ ...row })));
  const [errors, setErrors] = useState({});
  const [processing, setProcessing] = useState(false);
  const updateRow = (index, field, value, type) => {
    setRows((current) => current.map((row, rowIndex) => rowIndex === index
      ? { ...row, [field]: type === "number" ? Math.max(0, Number(value || 0)) : value }
      : row));
  };
  const save = (event) => {
    event.preventDefault();
    setProcessing(true);
    setErrors({});
    router.post(`/preparedness-for-response/reports/${reportId}/data/${section}`, { _method: "patch", rows }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        const prop = {
          "response-assets": "responseAssets",
          "qrt-coverage": "qrtCoverageAreas",
          "qrt-specializations": "qrtSpecializations",
        }[section];
        router.reload({
          only: [prop, "asOf"],
          preserveScroll: true,
          onSuccess: onClose,
        });
      },
      onError: setErrors,
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <div className="fixed inset-0 z-[600] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label={`Edit ${config.title}`}>
      <form onSubmit={save} className="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <header className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <div><p className="text-[10px] font-black uppercase tracking-[.18em] text-blue-600">Preparedness Data Editor</p><h2 className="mt-1 text-xl font-black text-blue-950">{config.title}</h2></div>
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 p-2 text-slate-500 hover:bg-slate-50" aria-label="Close editor"><X className="h-5 w-5" /></button>
        </header>
        <div className="min-h-0 flex-1 overflow-auto p-5">
          <p className="mb-4 text-xs font-semibold text-slate-500">Rows are displayed in this order on the briefing page. Removing a row takes effect when you save.{section === "response-assets" ? " Existing image filenames are locked; new rows require an image upload." : ""}</p>
          <div className="overflow-hidden rounded-xl border border-slate-200">
            <table className="w-full text-left text-xs">
              <thead className="bg-blue-950 text-white"><tr>{config.fields.map(([, label]) => <th key={label} className="px-3 py-3 font-black uppercase">{label}</th>)}<th className="w-16 px-3 py-3 text-center">Remove</th></tr></thead>
              <tbody>
                {rows.map((row, index) => (
                  <tr key={row.id ?? `new-${index}`} className="border-t border-slate-100 odd:bg-white even:bg-slate-50">
                    {config.fields.map(([field, label, type]) => {
                      const errorField = type === "image" && !row.id ? "image_file" : field;
                      return (
                        <td key={field} className="px-3 py-2 align-top">
                          {type === "status" ? (
                            <select value={row[field] ?? "On Standby"} onChange={(event) => updateRow(index, field, event.target.value, type)} className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-800 focus:border-blue-500 focus:outline-none" aria-label={`${label} row ${index + 1}`}>
                              <option>On Standby</option>
                              <option>Deployed</option>
                              <option>Demobilized</option>
                            </select>
                          ) : type === "image" && row.id ? (
                            <input type="text" value={row[field] ?? ""} readOnly className="w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 font-semibold text-slate-500" aria-label={`${label} row ${index + 1}`} />
                          ) : type === "image" ? (
                            <div>
                              <input type="file" required accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" onChange={(event) => updateRow(index, "image_file", event.target.files?.[0] ?? null, "file")} className="block w-full rounded-lg border border-slate-200 bg-white text-[11px] font-semibold text-slate-700 file:mr-3 file:border-0 file:bg-blue-50 file:px-3 file:py-2.5 file:font-black file:text-blue-900" aria-label={`Upload image row ${index + 1}`} />
                              {row.image_file?.name && <p className="mt-1 truncate text-[10px] font-semibold text-slate-500">{row.image_file.name}</p>}
                            </div>
                          ) : (
                            <input type={type} min={type === "number" ? 0 : undefined} value={row[field] ?? ""} onChange={(event) => updateRow(index, field, event.target.value, type)} className="w-full rounded-lg border border-slate-200 px-3 py-2 font-semibold text-slate-800 focus:border-blue-500 focus:outline-none" aria-label={`${label} row ${index + 1}`} />
                          )}
                          {errors[`rows.${index}.${errorField}`] && <p className="mt-1 text-[10px] font-bold text-red-600">{errors[`rows.${index}.${errorField}`]}</p>}
                        </td>
                      );
                    })}
                    <td className="px-3 py-2 text-center align-middle"><button type="button" onClick={() => setRows((current) => current.filter((_, rowIndex) => rowIndex !== index))} className="rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label={`Remove row ${index + 1}`}><Trash2 className="h-4 w-4" /></button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <button type="button" onClick={() => setRows((current) => [...current, { ...config.blank }])} className="mt-4 inline-flex items-center gap-2 rounded-xl border border-blue-200 px-4 py-2.5 text-xs font-black uppercase text-blue-900 hover:bg-blue-50"><Plus className="h-4 w-4" /> Add row</button>
        </div>
        <footer className="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-black uppercase text-slate-600">Cancel</button>
          <button type="submit" disabled={processing} className="rounded-xl bg-blue-900 px-5 py-2.5 text-xs font-black uppercase text-white disabled:opacity-50">{processing ? "Saving\u2026" : "Save changes"}</button>
        </footer>
      </form>
    </div>
  );
}

const preparednessArtwork = [
  ["Family Clothing Kit", "family-clothing-kit.png"],
  ["Hygiene Kit", "hygiene-kit.png"],
  ["Kitchen Kit", "kitchen-kit.png"],
  ["Sleeping Kit", "sleeping-kit.png"],
  ["Sleeping Bag", "/images/sleeping_bag-removebg-preview.png"],
  ["Family Water Filtration Kit", "/images/water-filtration-kit.png"],
  ["Regular Slotted Carton", "/images/reg-slotted-carton.png"],
  ["Packaging Tape, 2\" x 100m", "/images/packaging-tape-transparent.png"],
  ["Family Tent", "family-tent.png"],
  ["Modular Tent", "modular-tent.png"],
  ["Children Friendly Space Kit", "cfs-kit.png"],
  ["Women Friendly Space Kit", "wfs-kit.png"],
  ["Rice Bag, 3-Kilo Vacuum Plastic", "/images/rice-bag-3-kilo-vacuum-plastic.png"],
  ["Rice", "/images/6kg-rice.png"],
  ["Laminated Sack, Pre-cut", "/images/laminated-sack-pre-cut.png"],
  ["Tarpaulin, Roll", "/images/tarpaulin-roll.png"],
  ["Plastic Twine", "/images/plastic-twine.png"],
];

function GroupedInventoryPage({
  asOf,
  heading,
  subtitle,
  rows,
  provinceNames,
  scopeRows,
  showAllProvinces = false,
  cardColumns = "xl:grid-cols-4",
  heroImage = null,
  heroLabel = null,
  currentLabel = "Current Stockpile",
  capacityLabel = "Capacity Not Encoded",
  showCapacity = true,
  actionImage = null,
  actionLabel = "Distribution across Caraga",
  hideZeroValues = false,
}) {
  const visibleRows = hideZeroValues
    ? rows.filter((row) => displayedWholeIsNonZero(row.quantity) || displayedMoneyIsNonZero(row.value) || displayedWholeIsNonZero(row.warehouses))
    : rows;
  const denseLayout = !heroImage && visibleRows.length > 8;
  const artwork = Object.fromEntries(
    preparednessArtwork.map(([item, image]) => [item.toLowerCase(), image]),
  );
  const artworkRows = visibleRows.map((row) => ({
    row,
    image: artwork[row.item.toLowerCase()] || null,
    expandImage: row.item.toLowerCase() === "regular slotted carton",
    blendImage: false,
  }));
  const quantity = (province, item) =>
    scopeRows
      .filter(
        (row) =>
          row.province === province &&
          row.item.toLowerCase() === item.toLowerCase(),
      )
      .reduce((sum, row) => sum + Number(row.quantity || 0), 0);
  const populatedProvinces = hideZeroValues
    ? provinceNames.filter((province) => visibleRows.some((row) => displayedWholeIsNonZero(quantity(province, row.item))))
    : showAllProvinces
      ? provinceNames
      : provinceNames.filter((province) => rows.some((row) => quantity(province, row.item) !== 0));

  return (
    <>
      <BriefingHeader title={heading} subtitle={subtitle} asOf={asOf} />
      <div className={`grid ${denseLayout ? "gap-3 p-4 sm:p-5" : "gap-5 p-5 sm:p-7"} ${actionImage ? "lg:grid-cols-[minmax(0,1fr)_26rem] lg:items-start" : ""}`}>
        <div className={`min-w-0 ${denseLayout ? "space-y-3" : "space-y-6"}`}>
        {heroImage ? (
          <StockHeroSummary
            image={heroImage}
            label={heroLabel || heading}
            current={total(visibleRows, "quantity")}
            currentLabel={currentLabel}
            capacity={0}
            capacityLabel={capacityLabel}
            showCapacity={showCapacity}
            totalAmount={total(visibleRows, "value")}
            compact
          />
        ) : artworkRows.length > 0 && (
          <div className={`grid ${denseLayout ? "gap-3" : "gap-4"} lg:grid-cols-2 ${cardColumns}`}>
            {artworkRows.map(({ image, row, expandImage, blendImage }) => (
              <ResourceCard
                key={row.item}
                image={image}
                label={row.item}
                quantity={row.quantity}
                value={row.value}
                note={`${number(row.warehouses)} stocked location${Number(row.warehouses) === 1 ? "" : "s"}`}
                compact={denseLayout}
                expandImage={expandImage}
                blendImage={blendImage}
              />
            ))}
          </div>
        )}
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full table-fixed border-separate border-spacing-0 text-center text-xs sm:text-sm lg:text-[15px]">
            <thead>
              <tr className="bg-gradient-to-r from-blue-950 via-blue-900 to-blue-800 text-white">
                <th className={`w-[15%] border-r border-white/20 px-2 ${denseLayout ? "py-2.5" : "py-3"} text-sm sm:text-base`}>Province</th>
                {visibleRows.map((row) => (
                  <th key={row.item} className={`break-words border-r border-white/20 px-1.5 ${denseLayout ? "py-2.5" : "py-3"} text-xs leading-tight sm:text-sm lg:text-[15px]`}>
                    {row.item}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {populatedProvinces.map((province) => (
                <tr key={province} className="border-b border-slate-100 transition odd:bg-white even:bg-slate-50/80 hover:bg-blue-50">
                  <td className={`border-r border-slate-200 px-3 ${denseLayout ? "py-2.5" : "py-3"} text-left text-sm font-black sm:text-base`}>{province}</td>
                  {visibleRows.map((row) => (
                    <td key={row.item} className={`whitespace-nowrap border-r border-slate-100 px-1 ${denseLayout ? "py-2" : "py-3"} text-base font-bold tabular-nums sm:text-lg`}>
                      {number(quantity(province, row.item))}
                    </td>
                  ))}
                </tr>
              ))}
              <tr className="border-t-2 border-blue-900 bg-slate-100 font-black text-blue-950">
                <td className={`border-r border-slate-200 px-2 ${denseLayout ? "py-2" : "py-3"} text-xs`}>TOTAL &gt;&gt;&gt;</td>
                {visibleRows.map((row) => (
                  <td key={row.item} className={`whitespace-nowrap border-r border-slate-200 px-1 ${denseLayout ? "py-2" : "py-3"} text-base tabular-nums sm:text-lg`}>
                    {number(provinceNames.reduce((sum, province) => sum + quantity(province, row.item), 0))}
                  </td>
                ))}
              </tr>
            </tbody>
          </table>
        </div>
        </div>
        {actionImage && (
          <ActionPhotoPanel src={assetUrl(actionImage)} label={actionLabel} />
        )}
      </div>
    </>
  );
}

function NetworkPage({ asOf, rows, operations }) {
  return (
    <>
      <BriefingHeader
        title="Prepositioning and Logistics Network"
        subtitle="Warehouse-level stockpile, Family Food Packs, and current delivery pipeline"
        asOf={asOf}
      />
      <div className="space-y-5 p-5 sm:p-7">
        <div className="grid gap-3 sm:grid-cols-3">
          <Metric
            icon={Building2}
            label="Stocked Locations"
            value={number(rows.length)}
          />
          <Metric
            icon={Boxes}
            label="Total Units"
            value={number(total(rows, "quantity"))}
          />
          <Metric
            icon={Truck}
            label="Active Dispatch Pipeline"
            value={number(
              Object.entries(operations.dispatches || {})
                .filter(([status]) =>
                  ["planned", "released", "in_transit"].includes(status),
                )
                .reduce((sum, [, value]) => sum + Number(value), 0),
            )}
            red
          />
        </div>
        <section className="overflow-hidden rounded-2xl border border-slate-200">
          <DataTable
            rows={rows}
            columns={[
              {
                key: "warehouse",
                label: "Warehouse / Prepositioning Area",
                bold: true,
              },
              { key: "province", label: "Province" },
              { key: "municipality", label: "Municipality" },
              { key: "type", label: "Type" },
              {
                key: "ffp",
                label: "FFPs",
                align: "right",
                bold: true,
                render: (row) => number(row.ffp),
              },
              {
                key: "quantity",
                label: "All Stockpile",
                align: "right",
                render: (row) => number(row.quantity),
              },
              {
                key: "value",
                label: "Total Value",
                align: "right",
                bold: true,
                render: (row) => money(row.value),
              },
            ]}
          />
        </section>
        <div className="flex justify-end">
          <Link
            href="/dispatches"
            className="inline-flex items-center gap-2 rounded-xl bg-blue-900 px-4 py-3 text-xs font-black uppercase text-white hover:bg-blue-800"
          >
            <Truck className="h-4 w-4" /> Open dispatch operations
          </Link>
        </div>
      </div>
    </>
  );
}

function GeographicTablePage({ asOf, heading, subtitle, rows, level }) {
  return (
    <>
      <BriefingHeader title={heading} subtitle={subtitle} asOf={asOf} />
      <div className="space-y-5 p-5 sm:p-7">
        <div className="grid gap-3 sm:grid-cols-3">
          <Metric
            icon={Building2}
            label={`${level} Entries`}
            value={number(rows.length)}
          />
          <Metric
            icon={PackageCheck}
            label="Family Food Packs"
            value={number(total(rows, "ffp"))}
          />
          <Metric
            icon={CircleDollarSign}
            label="Stockpile Value"
            value={money(total(rows, "value"))}
          />
        </div>
        <section className="overflow-hidden rounded-2xl border border-slate-200">
          <DataTable
            rows={rows}
            columns={[
              { key: "label", label: level, bold: true },
              { key: "warehouses", label: "Warehouses", align: "right" },
              {
                key: "ffp",
                label: "Family Food Packs",
                align: "right",
                bold: true,
                render: (row) => number(row.ffp),
              },
              {
                key: "quantity",
                label: "All Stockpile",
                align: "right",
                render: (row) => number(row.quantity),
              },
              {
                key: "value",
                label: "Total Cost",
                align: "right",
                bold: true,
                render: (row) => money(row.value),
              },
            ]}
          />
        </section>
      </div>
    </>
  );
}

function CategoryTablePage({ asOf, category, rows }) {
  return (
    <>
      <BriefingHeader
        title={category}
        subtitle="Regional inventory breakdown by item, recorded locations, quantity, and valuation"
        asOf={asOf}
      />
      <div className="space-y-5 p-5 sm:p-7">
        <div className="grid gap-3 sm:grid-cols-3">
          <Metric icon={Boxes} label="Item Types" value={number(rows.length)} />
          <Metric
            icon={PackageCheck}
            label="Available Units"
            value={number(total(rows, "quantity"))}
          />
          <Metric
            icon={CircleDollarSign}
            label="Total Value"
            value={money(total(rows, "value"))}
          />
        </div>
        <section className="overflow-hidden rounded-2xl border border-slate-200">
          <DataTable
            rows={rows}
            columns={[
              { key: "item", label: "Item", bold: true },
              { key: "warehouses", label: "Locations", align: "right" },
              {
                key: "quantity",
                label: "Available Stockpile",
                align: "right",
                bold: true,
                render: (row) => number(row.quantity),
              },
              {
                key: "value",
                label: "Total Cost",
                align: "right",
                bold: true,
                render: (row) => money(row.value),
              },
            ]}
          />
        </section>
      </div>
    </>
  );
}

function CapacityPage({
  asOf,
  heading,
  image,
  actionImage = null,
  actionLabel = "Distribution across Caraga",
  compactContext = false,
  rows,
  currentKey,
  capacityKey,
  currentLabel,
  capacityLabel,
  amountKey = null,
  ffpLayout = false,
  showVariance = true,
  varianceMode = "current-minus-capacity",
}) {
  const current = total(rows, currentKey);
  const capacity = total(rows, capacityKey);
  const totalAmount = amountKey ? total(rows, amountKey) : null;
  return (
    <>
      <BriefingHeader
        title={heading}
        subtitle="Current stockpile compared with encoded warehouse capacity"
        asOf={asOf}
      />
      <div className={`grid gap-5 p-5 sm:p-7 ${actionImage ? (ffpLayout ? "lg:grid-cols-[minmax(0,1fr)_28rem] lg:items-start" : "items-stretch lg:grid-cols-[minmax(0,3fr)_minmax(16rem,1fr)]") : ""}`}>
        <div className={`min-w-0 ${ffpLayout ? "space-y-3" : "space-y-5"}`}>
        <StockHeroSummary
          image={image}
          label={heading}
          current={current}
          currentLabel={currentLabel}
          capacity={capacity}
          capacityLabel={capacityLabel}
          totalAmount={totalAmount}
          compact={compactContext || ffpLayout}
          showVariance={showVariance}
          varianceMode={varianceMode}
        />
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className={`w-full table-fixed text-sm ${compactContext ? "[&_td]:!py-2.5 [&_th]:!py-3 lg:text-[15px]" : "lg:text-lg"}`}>
            <colgroup>
              {compactContext ? <><col className="w-[15%]" /><col className="w-[17%]" /><col className="w-[30%]" /><col className="w-[13%]" /><col className="w-[13%]" />{showVariance && <col className="w-[12%]" />}</> : <><col className="w-[13%]" /><col className="w-[11%]" /><col className="w-[12%]" /><col className="w-[21%]" /><col className="w-[11%]" /><col className="w-[11%]" /><col className="w-[11%]" />{showVariance && <col className="w-[10%]" />}</>}
            </colgroup>
            <thead className="bg-gradient-to-r from-blue-950 via-blue-900 to-blue-800 text-white">
              <tr>
                <th className="px-4 py-3 text-left">Province</th>
                {compactContext ? (
                  <>
                    <th className="px-4 py-3 text-left">District / Location</th>
                    <th className="px-4 py-3 text-left">Warehouse / Partnership</th>
                  </>
                ) : (
                  <>
                    <th className="px-4 py-3 text-left">District</th>
                    <th className="px-4 py-3 text-left">Location</th>
                    <th className="px-4 py-3 text-left">Warehouse Name</th>
                    <th className="px-4 py-3 text-left">Partnership</th>
                  </>
                )}
                <th className="px-4 py-3 text-right">Current</th>
                <th className="px-4 py-3 text-right">Capacity</th>
                {showVariance && <th className="px-4 py-3 text-right">Variance</th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => {
                const rowCurrent = Number(row[currentKey] || 0);
                const rowCapacity = Number(row[capacityKey] || 0);
                const rowVariance = rowCapacity > 0
                  ? varianceMode === "capacity-minus-current" ? rowCapacity - rowCurrent : rowCurrent - rowCapacity
                  : null;
                return (
                  <tr
                    key={`${row.warehouse}-${index}`}
                    className="border-b border-slate-100 transition odd:bg-white even:bg-slate-50/80 hover:bg-blue-50"
                  >
                    <td className="px-4 py-3 font-bold">{row.province}</td>
                    {compactContext ? (
                      <>
                        <td className="px-4 py-3">
                          <span className="block font-bold text-slate-800">{row.district}</span>
                          <span className="block text-xs text-slate-500">{row.municipality}</span>
                        </td>
                        <td className="px-4 py-3">
                          <span className="block font-bold text-slate-800">{row.warehouse}</span>
                          <span className="block text-xs font-semibold uppercase text-blue-700">{row.partnership}</span>
                        </td>
                      </>
                    ) : (
                      <>
                        <td className="px-4 py-3">{row.district}</td>
                        <td className="px-4 py-3">{row.municipality}</td>
                        <td className="px-4 py-3 font-bold">{row.warehouse}</td>
                        <td className="px-4 py-3">{row.partnership}</td>
                      </>
                    )}
                    <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                      {number(rowCurrent)}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">
                      {number(rowCapacity)}
                    </td>
                    {showVariance && <td
                      className={`whitespace-nowrap px-4 py-3 text-right font-black tabular-nums ${rowVariance != null && rowVariance < 0 ? "text-red-600" : ""}`}
                    >
                      {rowVariance == null ? "\u2014" : number(rowVariance)}
                    </td>}
                  </tr>
                );
              })}
              <tr className="border-t-2 border-blue-900 bg-slate-100 font-black text-blue-950">
                <td colSpan={compactContext ? 3 : 5} className="px-4 py-3 text-right uppercase">
                  Grand Total
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right">{number(current)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right">{number(capacity)}</td>
                {showVariance && <td className="whitespace-nowrap px-4 py-3 text-right">
                  {capacity > 0 ? number(varianceMode === "capacity-minus-current" ? capacity - current : current - capacity) : "\u2014"}
                </td>}
              </tr>
            </tbody>
          </table>
        </div>
        </div>
        {actionImage && (
          <ActionPhotoPanel src={assetUrl(actionImage)} label={actionLabel} />
        )}
      </div>
    </>
  );
}

function FfpProvincePage({
  asOf,
  rows,
  actionImage = null,
  heading = "Family Food Packs Per Province",
  subtitle = "Current FFP stockpile, warehouse coverage, capacity, and variance",
  showVariance = true,
}) {
  const current = total(rows, "ffp");
  const capacity = total(rows, "capacity");
  const totalAmount = total(rows, "ffp_cost");
  return (
    <>
      <BriefingHeader title={heading} subtitle={subtitle} asOf={asOf} />
      <div className={`grid gap-5 p-5 sm:p-7 ${actionImage ? "lg:grid-cols-[minmax(0,1fr)_28rem] lg:items-start" : ""}`}>
        <div className="min-w-0 space-y-3">
        <StockHeroSummary
          image="family-food-pack.png"
          label={heading}
          current={current}
          currentLabel="No. of Food Packs"
          capacity={capacity}
          capacityLabel="FFP Full Capacity"
          totalAmount={totalAmount}
          compact
          showVariance={showVariance}
        />
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full table-fixed text-center text-sm sm:text-base lg:text-lg">
            <colgroup><col className="w-[24%]" /><col className="w-[19%]" /><col className="w-[20%]" /><col className="w-[19%]" />{showVariance && <col className="w-[18%]" />}</colgroup>
            <thead className="bg-gradient-to-r from-blue-950 via-blue-900 to-blue-800 text-white">
              <tr>
                <th className="px-4 py-2">PROVINCE</th>
                <th className="px-4 py-2">NO. OF WHs WITH FFPs</th>
                <th className="px-4 py-2">CURRENT STOCKPILE</th>
                <th className="px-4 py-2">CAPACITY</th>
                {showVariance && <th className="px-4 py-2">VARIANCE</th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.province} className="border-b border-slate-100 transition odd:bg-white even:bg-slate-50/80 hover:bg-blue-50">
                  <td className="px-4 py-2 font-black">{row.province}</td>
                  <td className="whitespace-nowrap px-4 py-2">{number(row.warehouses)}</td>
                  <td className="whitespace-nowrap px-4 py-2">{number(row.ffp)}</td>
                  <td className="whitespace-nowrap px-4 py-2">{number(row.capacity)}</td>
                  {showVariance && <td className="whitespace-nowrap px-4 py-2">
                    {Number(row.capacity || 0) > 0
                      ? number(row.ffp - row.capacity)
                      : "\u2014"}
                  </td>}
                </tr>
              ))}
              <tr className="border-t-2 border-blue-900 bg-slate-100 font-black text-blue-950">
                <td className="px-4 py-2">TOTAL &gt;&gt;&gt;</td>
                <td className="whitespace-nowrap px-4 py-2">
                  {number(total(rows, "warehouses"))}
                </td>
                <td className="whitespace-nowrap px-4 py-2">{number(current)}</td>
                <td className="whitespace-nowrap px-4 py-2">{number(capacity)}</td>
                {showVariance && <td className="whitespace-nowrap px-4 py-2">{capacity > 0 ? number(current - capacity) : "\u2014"}</td>}
              </tr>
            </tbody>
          </table>
        </div>
        </div>
        {actionImage && (
          <ActionPhotoPanel src={assetUrl(actionImage)} label="Family Food Pack distribution across Caraga" />
        )}
      </div>
    </>
  );
}

function NfiProvinceMatrixPage({
  asOf,
  provinceNames,
  rows,
  heading = "Non-Food Items per Province",
  subtitle = "Province matrix of essential non-food relief commodities",
  items: configuredItems,
  hideZeroValues = false,
}) {
  const allItems = configuredItems || [
    {
      label: "Family Clothing Kits",
      image: "family-clothing-kit.png",
      terms: ["clothing"],
    },
    { label: "Hygiene Kits", image: "hygiene-kit.png", terms: ["hygiene"] },
    { label: "Kitchen Kits", image: "kitchen-kit.png", terms: ["kitchen"] },
    { label: "Sleeping Kits", image: "sleeping-kit.png", terms: ["sleeping kit"] },
    {
      label: "Modular Tent",
      image: "modular-tent.png",
      terms: ["modular tent"],
      expandImage: true,
    },
    { label: "Family Tent", image: "family-tent.png", terms: ["family tent"] },
    { label: "Family Water Filtration Kits", image: "/images/water-filtration-kit.png", terms: ["family water filtration kit"] },
  ];
  const quantity = (province, terms) =>
    rows
      .filter(
        (row) =>
          row.province === province &&
          terms.some((term) => row.item.toLowerCase().includes(term)),
      )
      .reduce((sum, row) => sum + Number(row.quantity || 0), 0);
  const regionalQuantity = (terms) =>
    provinceNames.reduce(
      (sum, province) => sum + quantity(province, terms),
      0,
    );
  const regionalValue = (terms) =>
    rows
      .filter((row) =>
        terms.some((term) => row.item.toLowerCase().includes(term)),
      )
      .reduce((sum, row) => sum + Number(row.value || 0), 0);
  const items = hideZeroValues
    ? allItems.filter((item) => displayedWholeIsNonZero(regionalQuantity(item.terms)) || displayedMoneyIsNonZero(regionalValue(item.terms)))
    : allItems;
  const populatedProvinces = hideZeroValues
    ? provinceNames.filter((province) => items.some((item) => displayedWholeIsNonZero(quantity(province, item.terms))))
    : provinceNames;
  return (
    <>
      <BriefingHeader
        title={heading}
        subtitle={subtitle}
        asOf={asOf}
      />
      {items.length > 0 && (
        <div className="grid gap-2 px-5 pt-2 sm:px-7 md:grid-cols-2 xl:grid-cols-4">
          {items.map((item) => (
            <ResourceCard
              key={item.label}
              image={item.image}
              label={item.label}
              quantity={regionalQuantity(item.terms)}
              value={regionalValue(item.terms)}
              expandImage={item.expandImage}
              compact
            />
          ))}
        </div>
      )}
      <div className="p-4 sm:px-7">
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table className="w-full table-fixed border-separate border-spacing-0 text-center text-xs sm:text-sm lg:text-[15px]">
          <thead>
            <tr className="bg-gradient-to-r from-blue-950 via-blue-900 to-blue-800 text-white">
              <th className="border-r border-white/20 px-2 py-2 text-xs sm:text-sm">
                Province
              </th>
              {items.map((item) => (
                <th
                  key={item.label}
                  className="break-words border-r border-white/20 px-1.5 py-2 text-xs leading-tight sm:text-sm"
                >
                  {item.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {populatedProvinces.map((province) => (
              <tr key={province} className="border-b border-slate-100 transition odd:bg-white even:bg-slate-50/80 hover:bg-blue-50">
                <td className="border-r border-slate-200 px-3 py-2 text-left text-sm font-black sm:text-base">
                  {province}
                </td>
                {items.map((item) => (
                  <td
                    key={item.label}
                    className="whitespace-nowrap border-r border-slate-100 px-1.5 py-2 text-base font-bold tabular-nums sm:text-lg"
                  >
                    {number(quantity(province, item.terms))}
                  </td>
                ))}
              </tr>
            ))}
            <tr className="border-t-2 border-blue-900 bg-slate-100 font-black text-blue-950">
              <td className="border-r border-slate-200 px-2 py-2 text-xs">
                TOTAL &gt;&gt;&gt;
              </td>
              {items.map((item) => (
                <td
                  key={item.label}
                  className="border-r border-slate-200 px-1 py-2 text-sm sm:text-base"
                >
                  {number(
                    provinceNames.reduce(
                      (sum, province) => sum + quantity(province, item.terms),
                      0,
                    ),
                  )}
                </td>
              ))}
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </>
  );
}

function RrosFfpSummaryPage({ asOf, data }) {
  const rows = data?.province_rows || [];
  return (
    <>
      <BriefingHeader
        title="FFP Summary"
        subtitle="Family Food Pack stockpile by province using current inventory data"
        asOf={asOf}
      />
      <div className="space-y-5 p-5 sm:p-7">
        <div className="grid gap-4 sm:grid-cols-2">
          <Metric
            icon={PackageCheck}
            label="Total No. of FFPs"
            value={number(data?.total_current)}
          />
          <Metric
            icon={CircleDollarSign}
            label="Total Cost"
            value={money(data?.total_cost)}
          />
        </div>
        <div className="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
          <section className="rounded-xl border border-slate-200 p-4">
            <h3 className="text-sm font-black uppercase text-blue-950">
              Current No. of FFPs
            </h3>
            <div className="mt-5 flex h-64 items-end justify-around gap-3 border-b border-slate-300 px-2">
              {rows.map((row, index) => {
                const max = Math.max(
                  1,
                  ...rows.map((item) => Number(item.ffp || 0)),
                );
                const colors = [
                  "bg-amber-300",
                  "bg-green-400",
                  "bg-emerald-200",
                  "bg-cyan-500",
                  "bg-orange-300",
                ];
                return (
                  <div
                    key={row.province}
                    className="flex h-full flex-1 flex-col items-center justify-end"
                  >
                    <span className="mb-2 text-[10px] font-black">
                      {number(row.ffp)}
                    </span>
                    <div
                      className={`w-full max-w-20 rounded-t-lg ${colors[index % colors.length]}`}
                      style={{
                        height: `${Math.max(4, (Number(row.ffp || 0) / max) * 82)}%`,
                      }}
                    />
                  </div>
                );
              })}
            </div>
            <div className="mt-2 grid grid-cols-5 gap-2 text-center text-[9px] font-bold text-slate-600">
              {rows.map((row) => (
                <span key={row.province}>{row.province}</span>
              ))}
            </div>
          </section>
          <section className="overflow-x-auto rounded-xl border border-slate-200">
            <table className="w-full min-w-[620px] text-sm">
              <thead className="bg-slate-100 text-xs uppercase text-slate-600">
                <tr>
                  <th className="px-4 py-3 text-left">Province</th>
                  <th className="px-4 py-3 text-right">FFPs Full Capacity</th>
                  <th className="px-4 py-3 text-right">
                    No. of Available FFPs
                  </th>
                  <th className="px-4 py-3 text-right">Total Cost</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.province} className="border-t border-slate-200">
                    <td className="px-4 py-3 font-bold">{row.province}</td>
                    <td className="px-4 py-3 text-right">
                      {number(row.capacity)}
                    </td>
                    <td className="px-4 py-3 text-right">{number(row.ffp)}</td>
                    <td className="px-4 py-3 text-right">{money(row.value)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t border-slate-300 bg-slate-50 font-black">
                  <td className="px-4 py-3">Total</td>
                  <td className="px-4 py-3 text-right">
                    {number(data?.total_capacity)}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {number(data?.total_current)}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {money(data?.total_cost)}
                  </td>
                </tr>
              </tfoot>
            </table>
          </section>
        </div>
      </div>
    </>
  );
}

function RrosStandbySummaryPage({ asOf, data }) {
  const ffpBreakdown = data?.ffp_breakdown || [];
  const otherBreakdown = data?.other_breakdown || [];
  return (
    <>
      <BriefingHeader
        title="Standby Funds and Prepositioned Stockpile Summary"
        subtitle="Standby fund and current stockpile valuation using the latest synchronized WIT inventory and standby fund sources"
        asOf={asOf}
      />
      <div className="space-y-6 p-5 sm:p-7">
        <div className="overflow-x-auto rounded-md border border-slate-300">
          <div className="min-w-[1000px]">
            <div className="grid grid-cols-[1.25fr_.95fr_1fr_1.05fr_1.1fr_1.25fr] bg-[#052963] text-center font-black uppercase text-white">
              <div className="flex items-center justify-center border border-slate-400 p-5">
                Office
              </div>
              <div className="flex items-center justify-center border border-slate-400 p-5">
                Standby Funds
              </div>
              <div className="border border-slate-400 p-5">FFP Quantity</div>
              <div className="border border-slate-400 p-5">FFP Total Cost</div>
              <div className="border border-slate-400 p-5">
                Other Food and NFIs
              </div>
              <div className="flex items-center justify-center border border-slate-400 p-5">
                Total Standby Funds &amp; Stockpile
              </div>
            </div>
            <div className="grid grid-cols-[1.25fr_.95fr_1fr_1.05fr_1.1fr_1.25fr] bg-white text-center text-lg font-black">
              <div className="border border-slate-300 p-5">
                {data?.office || "DSWD Field Office Caraga"}
              </div>
              <div className="border border-slate-300 p-5">
                {money(data?.standby_funds)}
              </div>
              <div className="border border-slate-300 p-5">
                {number(data?.ffp_quantity)}
              </div>
              <div className="border border-slate-300 p-5">
                {money(data?.ffp_cost)}
              </div>
              <div className="border border-slate-300 p-5">
                {money(data?.other_food_non_food_cost)}
              </div>
              <div className="border border-slate-300 p-5">
                {money(data?.total_standby_funds_stockpile)}
              </div>
            </div>
          </div>
        </div>
        <div className="grid gap-5 xl:grid-cols-2">
          <section>
            <h3 className="mb-3 text-lg font-black">
              Family Food Packs Breakdown
            </h3>
            <DataTable
              rows={ffpBreakdown}
              columns={[
                { key: "warehouse_type", label: "Warehouse Type", bold: true },
                {
                  key: "current",
                  label: "FFPs Current",
                  align: "right",
                  render: (row) => number(row.current),
                },
                {
                  key: "cost",
                  label: "FFPs Cost",
                  align: "right",
                  render: (row) => money(row.cost),
                },
              ]}
            />
          </section>
          <section>
            <h3 className="mb-3 text-lg font-black">
              Other Food and Non-Food Items Amount Breakdown
            </h3>
            <DataTable
              rows={otherBreakdown}
              columns={[
                { key: "label", label: "Category", bold: true },
                {
                  key: "cost",
                  label: "Amount",
                  align: "right",
                  render: (row) => money(row.cost),
                },
              ]}
            />
          </section>
        </div>
      </div>
    </>
  );
}

function LegacyPreparednessForResponseIndex({
  asOf,
  summary = {},
  provinceRows = [],
  warehouseRows = [],
  itemRows = [],
  itemScopeRows = [],
  alerts = [],
  operations = {},
  ffpSummary = {},
  standbyStockpileSummary = {},
}) {
  const [pageIndex, setPageIndex] = useState(0);
  const [selectedProvince, setSelectedProvince] = useState("all");
  const [selectedDistrict, setSelectedDistrict] = useState("all");
  const asOfLabel = useMemo(
    () =>
      new Date(asOf).toLocaleString("en-PH", {
        dateStyle: "long",
        timeStyle: "short",
      }),
    [asOf],
  );
  const navigate = (index) => {
    if (index >= 0 && index < pages.length) {
      setPageIndex(index);
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };
  const provinces = useMemo(
    () =>
      [
        ...new Set(warehouseRows.map((row) => row.province).filter(Boolean)),
      ].sort(),
    [warehouseRows],
  );
  const districts = useMemo(
    () =>
      [
        ...new Set(
          warehouseRows
            .filter(
              (row) =>
                selectedProvince === "all" || row.province === selectedProvince,
            )
            .map((row) => row.district)
            .filter((value) => value && value !== "Unspecified"),
        ),
      ].sort(),
    [warehouseRows, selectedProvince],
  );
  const scopedWarehouses = useMemo(
    () =>
      warehouseRows.filter(
        (row) =>
          (selectedProvince === "all" || row.province === selectedProvince) &&
          (selectedDistrict === "all" || row.district === selectedDistrict),
      ),
    [warehouseRows, selectedProvince, selectedDistrict],
  );
  const scopedItems = useMemo(
    () =>
      aggregateItems(
        itemScopeRows.filter(
          (row) =>
            (selectedProvince === "all" || row.province === selectedProvince) &&
            (selectedDistrict === "all" || row.district === selectedDistrict),
        ),
      ),
    [itemScopeRows, selectedProvince, selectedDistrict],
  );
  const scopedSummary = useMemo(
    () => ({
      ...summary,
      stockpile_quantity: total(scopedItems, "quantity"),
      stockpile_value: total(scopedItems, "value"),
      total_resources:
        Number(summary.standby_funds || 0) + total(scopedItems, "value"),
      active_warehouses: scopedWarehouses.length,
      total_warehouses: scopedWarehouses.length,
    }),
    [summary, scopedItems, scopedWarehouses],
  );
  const geographicRows = useMemo(() => {
    if (selectedProvince === "all") return provinceRows;
    return Object.values(
      scopedWarehouses.reduce((groups, row) => {
        const key = row.district || "Unspecified";
        groups[key] ??= {
          province: key,
          warehouses: 0,
          active_warehouses: 0,
          ffp: 0,
          quantity: 0,
          value: 0,
        };
        groups[key].warehouses += 1;
        groups[key].active_warehouses += 1;
        groups[key].ffp += Number(row.ffp || 0);
        groups[key].quantity += Number(row.quantity || 0);
        groups[key].value += Number(row.value || 0);
        return groups;
      }, {}),
    );
  }, [provinceRows, scopedWarehouses, selectedProvince]);
  const ffpRows = scopedItems.filter((row) =>
    row.item.toLowerCase().includes("family food pack"),
  );
  const rtefRows = scopedItems.filter((row) =>
    ["ready to eat", "rtef"].some((term) =>
      row.item.toLowerCase().includes(term),
    ),
  );
  const nfiRows = scopedItems.filter((row) =>
    ["non food", "nfi"].some((term) =>
      row.category.toLowerCase().includes(term),
    ),
  );
  const shelterRows = scopedItems.filter((row) =>
    ["tent", "tarpaulin", "shelter", "sleeping"].some((term) =>
      row.item.toLowerCase().includes(term),
    ),
  );
  const specialRows = scopedItems.filter((row) =>
    [
      "mobile",
      "command center",
      "kitchen",
      "child friendly",
      "women friendly",
      "cccm",
    ].some((term) => row.item.toLowerCase().includes(term)),
  );
  useEffect(() => {
    const handler = (event) => {
      const target = event.target;
      if (target instanceof HTMLElement && (target.matches('input, textarea, select') || target.isContentEditable)) return;
      if (event.key === "ArrowLeft") navigate(pageIndex - 1);
      if (event.key === "ArrowRight") navigate(pageIndex + 1);
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [pageIndex]);
  const content = [
    <Overview
      key="overview"
      asOf={asOfLabel}
      summary={scopedSummary}
      provinceRows={geographicRows}
      geographyLabel={selectedProvince === "all" ? "Province" : "District"}
      alerts={alerts}
      operations={operations}
    />,
    <ItemPage
      key="ffp"
      pageTitle="Family Food Pack Stockpile"
      subtitle="Prepositioned food assistance by recorded item and location"
      asOf={asOfLabel}
      rows={ffpRows}
      heroImage="family-food-pack.png"
      heroLabel="Family Food Packs"
    />,
    <ItemPage
      key="rtef"
      pageTitle="Ready-to-Eat Food Stockpile"
      subtitle="Immediate food resources available for rapid response"
      asOf={asOfLabel}
      rows={rtefRows}
      heroImage="rtef.png?v=20260822-clean"
      heroLabel="Ready-to-Eat Food"
    />,
    <NfiPage key="nfi" asOf={asOfLabel} rows={nfiRows} />,
    <ShelterPage
      key="shelter"
      asOf={asOfLabel}
      rows={shelterRows}
      itemRows={scopedItems}
    />,
    <FacilitiesPage
      key="facilities"
      asOf={asOfLabel}
      rows={specialRows}
      itemRows={scopedItems}
    />,
    <NetworkPage
      key="network"
      asOf={asOfLabel}
      rows={scopedWarehouses}
      operations={operations}
    />,
  ][pageIndex];

  return (
    <AppLayout title="Preparedness for Response">
      <Head title="Preparedness for Response" />
      <style>{`.ppt-export-root .inline-editable { background: transparent !important; outline: none !important; } .ppt-export-root .dashboard-live-frame { display: none !important; } .ppt-export-root .dashboard-export-snapshot { display: block !important; }`}</style>
      <div className="space-y-3 pb-6">
        <nav
          className="grid grid-cols-2 gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-8"
          aria-label="Preparedness briefing pages"
        >
          {pages.map(([label], index) => (
            <button
              key={label}
              type="button"
              onClick={() => navigate(index)}
              aria-current={pageIndex === index ? "page" : undefined}
              className={`min-w-0 rounded-xl px-3 py-2.5 text-left text-[11px] font-black leading-tight ${pageIndex === index ? "bg-blue-900 text-white shadow" : "bg-slate-50 text-slate-600 hover:bg-blue-50 hover:text-blue-900"}`}
            >
              <span className="mr-2 opacity-60">
                {String(index + 1).padStart(2, "0")}
              </span>
              {label}
            </button>
          ))}
        </nav>
        <section className="grid gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4 shadow-sm sm:grid-cols-[1fr_1fr_auto] sm:items-end">
          <label className="text-xs font-black uppercase tracking-wide text-blue-950">
            Province
            <select
              value={selectedProvince}
              onChange={(event) => {
                setSelectedProvince(event.target.value);
                setSelectedDistrict("all");
              }}
              className="mt-2 block w-full rounded-xl border-blue-200 bg-white text-sm font-bold normal-case"
            >
              <option value="all">All Caraga provinces</option>
              {provinces.map((province) => (
                <option key={province} value={province}>
                  {province}
                </option>
              ))}
            </select>
          </label>
          <label className="text-xs font-black uppercase tracking-wide text-blue-950">
            District
            <select
              value={selectedDistrict}
              onChange={(event) => setSelectedDistrict(event.target.value)}
              disabled={selectedProvince === "all"}
              className="mt-2 block w-full rounded-xl border-blue-200 bg-white text-sm font-bold normal-case disabled:bg-slate-100 disabled:text-slate-400"
            >
              <option value="all">All districts</option>
              {districts.map((district) => (
                <option key={district} value={district}>
                  {district}
                </option>
              ))}
            </select>
          </label>
          <div className="rounded-xl bg-blue-900 px-4 py-3 text-white">
            <p className="text-[9px] font-black uppercase tracking-widest text-blue-200">
              Viewing scope
            </p>
            <p className="mt-1 text-sm font-black">
              {selectedDistrict !== "all"
                ? `${selectedDistrict}, ${selectedProvince}`
                : selectedProvince === "all"
                  ? "Entire Caraga Region"
                  : selectedProvince}
            </p>
          </div>
        </section>
        <main className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          {content}
        </main>
        <nav className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
          <button
            type="button"
            disabled={pageIndex === 0}
            onClick={() => navigate(pageIndex - 1)}
            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-xs font-black uppercase text-blue-950 disabled:opacity-30"
          >
            <ArrowLeft className="h-4 w-4" /> Previous
          </button>
          <div className="hidden text-center sm:block">
            <p className="text-sm font-black text-blue-950">
              {pages[pageIndex][0]}
            </p>
            <p className="text-[10px] uppercase tracking-wide text-slate-500">
              Page {pageIndex + 1} of {pages.length}
            </p>
          </div>
          <button
            type="button"
            disabled={pageIndex === pages.length - 1}
            onClick={() => navigate(pageIndex + 1)}
            className="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-3 text-xs font-black uppercase text-white disabled:opacity-30"
          >
            Next <ArrowRight className="h-4 w-4" />
          </button>
        </nav>
        <p className="text-center text-[10px] text-slate-400">
          Native DRIMS briefing {"\u2022"} All displayed quantities and valuations are
          sourced from current system records. Downloaded artwork is used for
          resource identification only.
        </p>
      </div>
    </AppLayout>
  );
}

export default function PreparednessForResponseIndex({
  currentReport,
  asOf,
  summary = {},
  provinceRows = [],
  warehouseRows = [],
  itemRows = [],
  itemScopeRows = [],
  bottledWaterVariants = [],
  briefingIntro = null,
  responseAssets = [],
  qrtCoverageAreas = [],
  qrtSpecializations = [],
  populationLgus = [],
  evacuationCenterSummary = {},
  actionPages = [],
  canEditPreparednessData = false,
  canRevisePreparednessReport = false,
  alerts = [],
  operations = {},
  ffpSummary = {},
  standbyStockpileSummary = {},
}) {
  const [pageIndex, setPageIndex] = useState(0);
  const [editorSection, setEditorSection] = useState(null);
  const [exportingPpt, setExportingPpt] = useState(false);
  const [exportingEditablePpt, setExportingEditablePpt] = useState(false);
  const [exportProgress, setExportProgress] = useState(0);
  const [reportModal, setReportModal] = useState(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [showVariance, setShowVariance] = useState(() => {
    if (typeof window === "undefined") return true;
    return window.localStorage.getItem(`preparedness:${currentReport.id}:show-variance`) !== "false";
  });
  const [hideZeroValues, setHideZeroValues] = useState(() => {
    if (typeof window === "undefined") return false;
    return window.localStorage.getItem(`preparedness:${currentReport.id}:hide-zero-values`) === "true";
  });
  const exportSlidesRef = useRef(null);
  const reportWorkspaceRef = useRef(null);
  const autoDownloadTriggeredRef = useRef(false);
  const toggleVariance = () => setShowVariance((visible) => {
    const next = !visible;
    window.localStorage.setItem(`preparedness:${currentReport.id}:show-variance`, String(next));
    return next;
  });
  const toggleZeroValues = () => setHideZeroValues((hidden) => {
    const next = !hidden;
    window.localStorage.setItem(`preparedness:${currentReport.id}:hide-zero-values`, String(next));
    return next;
  });
  const asOfLabel = useMemo(
    () =>
      new Date(asOf).toLocaleString("en-PH", {
        dateStyle: "long",
        timeStyle: "short",
      }),
    [asOf],
  );
  const finalizationSnapshot = {
    asOf,
    summary,
    provinceRows,
    warehouseRows,
    itemRows,
    itemScopeRows,
    bottledWaterVariants,
    alerts,
    operations,
    ffpSummary,
    standbyStockpileSummary,
    populationLgus,
    evacuationCenterSummary,
  };
  useEffect(() => {
    const handleFullscreenChange = () => setIsFullscreen(document.fullscreenElement === reportWorkspaceRef.current);
    document.addEventListener("fullscreenchange", handleFullscreenChange);
    return () => document.removeEventListener("fullscreenchange", handleFullscreenChange);
  }, []);

  const toggleFullscreen = async () => {
    try {
      if (document.fullscreenElement === reportWorkspaceRef.current) await document.exitFullscreen();
      else if (reportWorkspaceRef.current?.requestFullscreen) await reportWorkspaceRef.current.requestFullscreen();
      else throw new Error("Fullscreen mode is not supported by this browser.");
    } catch (error) {
      setReportModal({ type: "error", title: "Fullscreen unavailable", message: error?.message || "The report could not enter fullscreen mode." });
    }
  };

  const pageDefinitions = useMemo(() => {
    const matchesAny = (row, terms) =>
      terms.some((term) => row.item.toLowerCase().includes(term));
    const reliefTerms = [
      "family clothing",
      "hygiene kit",
      "kitchen kit",
      "sleeping kit",
      "family tent",
      "modular tent",
      "water filtration",
      "water",
    ];
    const protectionTerms = [
      "camp management",
      "children friendly",
      "child friendly",
      "women friendly",
      "evacuation center",
      "gender-based violence",
      "faced form",
    ];
    const headlineTerms = ["family food pack", "ready to eat", "rtef"];
    const productionRows = itemRows.filter(
      (row) =>
        !matchesAny(row, [...headlineTerms, ...reliefTerms, ...protectionTerms]),
    );
    const productionScopeRows = itemScopeRows.filter(
      (row) =>
        !matchesAny(row, [...headlineTerms, ...reliefTerms, ...protectionTerms]),
    );
    const visibleProductionRows = hideZeroValues
      ? productionRows.filter((row) => displayedWholeIsNonZero(row.quantity) || displayedMoneyIsNonZero(row.value) || displayedWholeIsNonZero(row.warehouses))
      : productionRows;
    const productionPageRows = Array.from(
      { length: Math.max(1, Math.ceil(visibleProductionRows.length / 7)) },
      (_, index) => visibleProductionRows.slice(index * 7, index * 7 + 7),
    );
    const bottledWaterScopeRows = itemScopeRows
      .filter(
        (row) =>
          row.item.trim().toLowerCase() === "water" &&
          bottledWaterVariants.includes(row.brand_description),
      )
      .map((row) => ({ ...row, item: row.brand_description }));
    const bottledWaterRows = bottledWaterVariants.map((variant) => {
      const variantRows = bottledWaterScopeRows.filter(
        (row) => row.item === variant,
      );
      return {
        item: variant,
        category: "Food",
        quantity: total(variantRows, "quantity"),
        value: total(variantRows, "value"),
        warehouses: new Set(
          variantRows.map((row) => row.warehouse_id).filter(Boolean),
        ).size,
      };
    });
    const dashboardFfpSummary = {
      ...ffpSummary,
      province_rows: (ffpSummary?.province_rows || []).map((row) => ({
        ...row,
        current: row.current ?? row.ffp ?? 0,
        cost: row.cost ?? 0,
      })),
      callouts: ffpSummary?.callouts || [],
    };
    const ffpProvincePageRows = provinceRows.map((row) => ({
      ...row,
      warehouses: row.ffp_warehouses ?? row.warehouses ?? 0,
    }));
    const dswdWarehouses = warehouseRows.filter(
      (row) =>
        !String(row.type || "")
          .toLowerCase()
          .includes("preposition"),
    );
    const lguWarehouses = warehouseRows.filter((row) =>
      String(row.type || "")
        .toLowerCase()
        .includes("preposition"),
    );
    const lguProvinceRows = provinceRows.map((province) => {
      const rows = lguWarehouses.filter(
        (row) => row.province === province.province,
      );
      return {
        province: province.province,
        warehouses: rows.filter(
          (row) =>
            Number(row.ffp || 0) > 0 || Number(row.ffp_capacity || 0) > 0,
        ).length,
        ffp: total(rows, "ffp"),
        ffp_cost: total(rows, "ffp_cost"),
        capacity: total(rows, "ffp_capacity"),
      };
    });
    const definitions = [
      {
        key: "briefing-title", label: "Preparedness for Response", group: "Briefing Introduction",
        render: () => <BriefingTitlePage intro={briefingIntro} canEdit={canEditPreparednessData} asOf={asOfLabel} reportId={currentReport.id} />,
      },
      {
        key: "briefing-synopsis", label: "Synopsis", group: "Briefing Introduction",
        render: () => <BriefingSynopsisPage intro={briefingIntro} canEdit={canEditPreparednessData} reportId={currentReport.id} />,
      },
      {
        key: "briefing-forecast", label: "Provincial Weather Forecast", group: "Briefing Introduction",
        render: () => <BriefingForecastPage intro={briefingIntro} canEdit={canEditPreparednessData} asOf={asOfLabel} reportId={currentReport.id} />,
      },
      {
        key: "possible-affected-families",
        label: "Possible Affected Families",
        group: "Briefing Introduction",
        render: () => <PossibleAffectedFamiliesPage intro={briefingIntro} populationLgus={populationLgus} canEdit={canEditPreparednessData} reportId={currentReport.id} />,
      },
      {
        key: "resource-capacity-title", label: "Resource Capacity", group: "Briefing Introduction",
        render: () => <ResourceCapacityTitlePage asOf={asOfLabel} />,
      },
      {
        key: "food-nfi-title", label: "Food and Non-Food Items", group: "Resource Capacity",
        render: () => <ReportSectionTitlePage title="Food and Non-Food Items" />,
      },
      {
        key: "rros-standby-summary",
        label: "Standby Funds & Stockpile",
        group: "RROS Summary",
        render: () => (
          <StandbyStockpileSummaryV2 summary={standbyStockpileSummary} headerAside={<PreparednessPopulationTable />} />
        ),
      },
      {
        key: "rros-ffp-summary",
        label: "FFP Summary",
        group: "RROS Summary",
        render: () => <FamilyFoodPackReport data={dashboardFfpSummary} presentation headerAside={<PreparednessPopulationTable />} />,
      },
      {
        key: "ffp-province",
        label: "FFPs per Province",
        group: "Family Food Packs",
        render: () => (
          <FfpProvincePage
            asOf={asOfLabel}
            heading="Family Food Packs Per Province"
            rows={ffpProvincePageRows}
            actionImage="ffp-distribution.png?v=20260821-1533"
            showVariance={showVariance}
          />
        ),
      },
      {
        key: "ffp-dswd",
        label: "FFPs at DSWD Warehouses",
        group: "Family Food Packs",
        render: () => (
          <CapacityPage
            asOf={asOfLabel}
            heading="Family Food Packs Stored at DSWD Warehouses"
            image="family-food-pack.png"
            actionImage="ffp-distribution.png?v=20260821-1533"
            actionLabel="Family Food Pack distribution across Caraga"
            compactContext
            ffpLayout
            rows={dswdWarehouses.filter(
              (row) =>
                Number(row.ffp || 0) > 0 || Number(row.ffp_capacity || 0) > 0,
            )}
            currentKey="ffp"
            capacityKey="ffp_capacity"
            amountKey="ffp_cost"
            currentLabel="No. of Food Packs"
            capacityLabel="FFP Full Capacity"
            showVariance={showVariance}
          />
        ),
      },
      {
        key: "ffp-lgu-province-summary",
        label: "LGU FFPs per Province",
        group: "Family Food Packs \u00B7 LGU Warehouses",
        render: () => (
          <FfpProvincePage
            asOf={asOfLabel}
            heading="Family Food Packs Prepositioned at LGU Warehouses"
            subtitle="Province-level LGU warehouse coverage, current stockpile, capacity, and variance"
            actionImage="ffp-distribution.png?v=20260821-1533"
            rows={lguProvinceRows}
            showVariance={showVariance}
          />
        ),
      },
      {
        key: "rtef-capacity",
        label: "Ready-to-Eat Food",
        group: "Food Stockpile",
        render: () => (
          <CapacityPage
            asOf={asOfLabel}
            heading="Ready-to-Eat Foods at DSWD and PMO Warehouses"
            image="rtef.png?v=20260822-clean"
            actionImage="rtef-distribution.png?v=20260821-1510"
            actionLabel="Ready-to-eat food distribution across Caraga"
            compactContext
            rows={warehouseRows.filter(
              (row) =>
                Number(row.rtef || 0) > 0 || Number(row.rtef_capacity || 0) > 0,
            )}
            currentKey="rtef"
            capacityKey="rtef_capacity"
            varianceMode="capacity-minus-current"
            showVariance={showVariance}
            amountKey="rtef_cost"
            currentLabel="No. of Ready-to-Eat Foods"
            capacityLabel="Full Capacity"
          />
        ),
      },
      {
        key: "bottled-water",
        label: "Bottled Water",
        group: "Food Stockpile",
        render: () => (
          <GroupedInventoryPage
            asOf={asOfLabel}
            heading="Bottled Water per Province"
            subtitle="Current bottled water stockpile and valuation by province from WIT inventory records"
            rows={bottledWaterRows}
            provinceNames={provinceRows.map((row) => row.province)}
            scopeRows={bottledWaterScopeRows}
            showAllProvinces
            heroImage="/images/bottled-water-transparent.png"
            heroLabel="Bottled Water Stockpile"
            currentLabel="Total Bottled Water"
            showCapacity={false}
            actionImage="/images/bottle-water-dist.png"
            actionLabel="Bottled water distribution across Caraga"
            hideZeroValues={hideZeroValues}
          />
        ),
      },
      {
        key: "relief-shelter-items",
        label: "Non-Food Items per Province",
        group: "Non-Food Items",
        render: () => (
          <NfiProvinceMatrixPage
            asOf={asOfLabel}
            provinceNames={provinceRows.map((row) => row.province)}
            rows={itemScopeRows}
            hideZeroValues={hideZeroValues}
          />
        ),
      },
      {
        key: "protection-cccm-items",
        label: "CCCM & IDPP Resources",
        group: "CCCM and IDPP",
        render: () => (
          <NfiProvinceMatrixPage
            asOf={asOfLabel}
            heading="CCCM and IDPP Resources per Province"
            subtitle="Province matrix of internally displaced population protection and camp coordination resources"
            provinceNames={provinceRows.map((row) => row.province)}
            rows={itemScopeRows}
            hideZeroValues={hideZeroValues}
            items={[
              { label: "CCCM Kits", image: "cccm-kit.png", terms: ["camp management"], expandImage: true },
              { label: "CFS Kits", image: "cfs-kit.png", terms: ["children friendly space kit"], expandImage: true },
              { label: "WFS Kits", image: "wfs-kit.png", terms: ["women friendly space kit"], expandImage: true },
              { label: "CFS Tents", image: "child-friendly-space.png", terms: ["children friendly space tent"], expandImage: true },
              { label: "WFS Tents", image: "women-friendly-space.png", terms: ["women friendly space tent"], expandImage: true },
              { label: "EC Signages", terms: ["evacuation center signages"] },
              { label: "EC Information Boards", image: "ec-information-board.png", terms: ["evacuation center information board"] },
              { label: "GBV Referral Pathways", terms: ["gender-based violence"] },
              { label: "FACED Forms", image: "faced-form.png", terms: ["faced form"] },
            ]}
          />
        ),
      },
      ...productionPageRows.map((pageRows, index) => ({
        key: index === 0 ? "production-materials" : `production-materials-${index + 1}`,
        label: `Other NFIs, Raw and Indirect Materials${productionPageRows.length > 1 ? ` ${index + 1}` : ""}`,
        group: "Other NFIs, Raw and Indirect Materials",
        render: () => (
          <GroupedInventoryPage
            asOf={asOfLabel}
            heading={`Other NFIs, Raw and Indirect Materials${productionPageRows.length > 1 ? ` (${index + 1} of ${productionPageRows.length})` : ""}`}
            subtitle="Other non-food items, raw materials, and indirect materials recorded in WIT inventory"
            rows={pageRows}
            provinceNames={provinceRows.map((row) => row.province)}
            scopeRows={productionScopeRows}
            cardColumns="xl:grid-cols-4"
            hideZeroValues={hideZeroValues}
          />
        ),
      })),
      {
        key: "response-assets-title",
        label: "Mobile Response Vehicles and Equipment",
        group: "Mobile Response Vehicles and Equipment",
        render: () => <ReportSectionTitlePage title="Mobile Response Vehicles and Equipment" />,
      },
      {
        key: "response-assets",
        label: "Vehicles & Equipment",
        group: "Mobile Response Vehicles and Equipment",
        render: () => (
          <FacilitiesPage asOf={asOfLabel} assets={responseAssets} hideZeroValues={hideZeroValues} onEdit={canEditPreparednessData ? () => setEditorSection("response-assets") : null} />
        ),
      },
      {
        key: "qrt-human-resources-title",
        label: "Human Resources (QRT)",
        group: "Response Workforce",
        render: () => <ReportSectionTitlePage title="Human Resources (QRT)" />,
      },
      {
        key: "qrt-human-resources",
        label: "Human Resources (QRT)",
        group: "Response Workforce",
        render: () => <QrtHumanResourcesPage asOf={asOfLabel} rows={qrtCoverageAreas} onEdit={canEditPreparednessData ? () => setEditorSection("qrt-coverage") : null} />,
      },
      {
        key: "qrt-specializations",
        label: "QRT Specializations",
        group: "Response Workforce",
        render: () => <QrtSpecializationsPage asOf={asOfLabel} rows={qrtSpecializations} onEdit={canEditPreparednessData ? () => setEditorSection("qrt-specializations") : null} />,
      },
      {
        key: "cccm-idpp-title",
        label: "CCCM and IDPP",
        group: "CCCM and IDPP",
        render: () => <ReportSectionTitlePage title="CCCM and IDPP" asOf={asOfLabel} />,
      },
      {
        key: "cccm-idpp-updates",
        label: "CCCM and IDPP Updates",
        group: "CCCM and IDPP",
        render: () => <CccmIdppUpdatesPage intro={briefingIntro} canEdit={canEditPreparednessData} reportId={currentReport.id} />,
      },
      {
        key: "evacuation-centers-dashboard",
        label: "Evacuation Center Preparedness",
        group: "CCCM and IDPP",
        render: () => <EvacuationCentersReportPage summary={evacuationCenterSummary} intro={briefingIntro} populationLgus={populationLgus} />,
      },
      {
        key: "actions-taken-title",
        label: "Actions Taken",
        group: "Actions Taken",
        render: () => <ActionsTakenTitlePage canEdit={canEditPreparednessData} asOf={asOfLabel} reportId={currentReport.id} />,
      },
      ...actionPages.map((actionPage, index) => ({
        key: `actions-taken-${actionPage.id}`,
        label: `Actions Taken ${index + 1}`,
        group: "Actions Taken",
        render: () => <ActionsTakenPage key={actionPage.id} page={actionPage} pageNumber={index + 1} canEdit={canEditPreparednessData} canRemove={actionPages.length > 2} reportId={currentReport.id} />,
      })),
      {
        key: "challenges-ways-forward-title",
        label: "Challenges and Concerns / Ways Forward",
        group: "Ways Forward",
        render: () => <ReportSectionTitlePage title="Challenges and Concerns / Ways Forward" />,
      },
      ...challengePagesFromContent(briefingIntro?.content).map((_, index) => ({
        key: index === 0 ? "challenges-recommendations" : `challenges-recommendations-${index + 1}`,
        label: `Challenges and Recommendations ${index + 1}`,
        group: "Ways Forward",
        render: () => <ChallengesRecommendationsPage key={`challenges-${currentReport.id}-${index}`} intro={briefingIntro} canEdit={canEditPreparednessData} reportId={currentReport.id} pageIndex={index} />,
      })),
      {
        key: "thank-you",
        label: "Thank You",
        group: "Closing",
        render: () => <ThankYouPage />,
      },
    ];

    const foodStart = definitions.findIndex(page => page.key === "food-nfi-title");
    const foodEnd = definitions.findIndex(page => page.key === "response-assets-title");
    const population = preparednessPopulationTotals(briefingIntro, populationLgus);
    return definitions.map((page, index) => index > foodStart && index < foodEnd ? {
      ...page,
      render: () => <PreparednessPopulationContext.Provider value={population}>{page.render()}</PreparednessPopulationContext.Provider>,
    } : page);
  }, [
    alerts,
    asOfLabel,
    briefingIntro,
    currentReport,
    canEditPreparednessData,
    itemScopeRows,
    itemRows,
    bottledWaterVariants,
    responseAssets,
    qrtCoverageAreas,
    qrtSpecializations,
    populationLgus,
    evacuationCenterSummary,
    actionPages,
    operations,
    provinceRows,
    ffpSummary,
    standbyStockpileSummary,
    summary,
    warehouseRows,
    showVariance,
    hideZeroValues,
  ]);

  const navigate = (index) => {
    if (index < 0 || index >= pageDefinitions.length) return;
    setPageIndex(index);
    window.history.replaceState(null, "", `#${pageDefinitions[index].key}`);
    window.dispatchEvent(new HashChangeEvent("hashchange"));
    window.scrollTo({ top: 0, behavior: "smooth" });
  };
  useEffect(() => {
    const selectPage = (pageKey) => {
      const index = pageDefinitions.findIndex((page) => page.key === pageKey);
      if (index >= 0) {
        setPageIndex(index);
        window.scrollTo({ top: 0, behavior: "smooth" });
      }
    };
    const initialKey = window.location.hash.slice(1);
    if (initialKey) selectPage(initialKey);
    const handler = (event) => selectPage(event.detail?.pageKey);
    window.addEventListener("preparedness:navigate", handler);
    return () => window.removeEventListener("preparedness:navigate", handler);
  }, [pageDefinitions]);
  useEffect(() => {
    const handler = (event) => {
      const target = event.target;
      if (target instanceof HTMLElement && (target.matches('input, textarea, select') || target.isContentEditable)) return;
      if (event.key === "ArrowLeft") navigate(pageIndex - 1);
      if (event.key === "ArrowRight") navigate(pageIndex + 1);
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [pageIndex, pageDefinitions.length]);

  const activePage =
    pageDefinitions[Math.min(pageIndex, pageDefinitions.length - 1)];
  const editorRows = editorSection === "response-assets"
    ? responseAssets
    : editorSection === "qrt-coverage"
      ? qrtCoverageAreas
      : qrtSpecializations;

  const powerPointContentIssues = () => {
    const issues = [];
    const content = briefingIntro?.content ?? {};
    const present = (value) => String(value ?? '').trim().length > 0;
    if (!present(content.title_event)) issues.push('Opening Page: enter the event title.');
    if (!briefingIntro?.synopsis_image_path) issues.push('Synopsis: upload the weather image.');
    ['synopsis_title', 'synopsis_body', 'synopsis_issued', 'synopsis_source', 'synopsis_url'].forEach((field) => {
      if (!present(content[field])) issues.push(`Synopsis: complete ${field.replaceAll('_', ' ')}.`);
    });
    const forecastLabels = ['Weather Updates', 'Signal #', 'Remarks'];
    const regionalForecast = content.forecast_scope === 'regional';
    ['forecast_weather', 'forecast_signal', 'forecast_remarks'].forEach((field, fieldIndex) => {
      if (regionalForecast) {
        if (!present(content[field])) issues.push(`Regional Weather Forecast: complete ${forecastLabels[fieldIndex]}.`);
        return;
      }
      for (let index = 0; index < 5; index += 1) {
        if (!present(content[`${field}_${index}`] ?? content[field])) issues.push(`Provincial Weather Forecast: complete ${forecastLabels[fieldIndex]} for row ${index + 1}.`);
      }
    });
    for (let index = 1; index <= 3; index += 1) {
      if (!present(content[`cccm_idpp_bullet_${index}`])) issues.push(`CCCM and IDPP Updates: complete bullet ${index}.`);
    }
    if (!itemRows.length) issues.push('Food and Non-Food Items: inventory data is empty.');
    if (!warehouseRows.length) issues.push('Warehouse and stockpile pages: warehouse data is empty.');
    if (!provinceRows.length) issues.push('Province summary pages: province data is empty.');
    if (!responseAssets.length) issues.push('Mobile Response Vehicles and Equipment: no records are available.');
    if (!qrtCoverageAreas.length) issues.push('Human Resources (QRT): no coverage records are available.');
    if (!qrtSpecializations.length) issues.push('QRT Specializations: no records are available.');
    if (actionPages.length < 2) issues.push('Actions Taken: at least two pages are required.');
    actionPages.forEach((page, pageIndex) => {
      const actions = page.actions ?? [];
      const captions = page.captions ?? [];
      const photos = page.image_paths ?? [];
      if (actions.length < 2) issues.push(`Actions Taken ${pageIndex + 1}: add at least two action rows.`);
      actions.forEach((action, rowIndex) => {
        if (!present(action)) issues.push(`Actions Taken ${pageIndex + 1}: enter action ${rowIndex + 1}.`);
        if (!present(captions[rowIndex])) issues.push(`Actions Taken ${pageIndex + 1}: enter photo caption ${rowIndex + 1}.`);
        if (!photos[rowIndex] && !(rowIndex === 0 && page.image_path)) issues.push(`Actions Taken ${pageIndex + 1}: upload photo ${rowIndex + 1}.`);
      });
    });
    return [...new Set(issues)];
  };

  const validatedContentIssues = (action) => {
    const issues = powerPointContentIssues();
    if (issues.length) {
      setReportModal({
        type: "error",
        title: `${action} blocked`,
        message: `Complete the following required content:\n\u2022 ${issues.join('\n\u2022 ')}`,
      });
      return issues;
    }
    return [];
  };

  const finalizeReport = () => {
    if (!["draft", "revised"].includes(currentReport.status) || validatedContentIssues("Report finalization").length) return;
    setReportModal({
      type: "confirm-finalize",
      title: "Finalize this report?",
      message: "The report will become read-only and its current inventory figures will be preserved. This action cannot be undone.",
    });
  };

  const confirmFinalizeReport = () => {
    setReportModal(null);
    router.patch(`/preparedness-for-response/reports/${currentReport.id}/finalize`, { data_snapshot: finalizationSnapshot });
  };

  const requestRevision = () => {
    if (!canRevisePreparednessReport) return;
    setReportModal({
      type: "confirm-revise",
      title: "Revise this finalized report?",
      message: "The report will reopen in Revised status for editing. Both PowerPoint download options remain available after the required report content passes validation.",
    });
  };

  const confirmReportRevision = () => {
    setReportModal(null);
    router.patch(`/preparedness-for-response/reports/${currentReport.id}/revise`);
  };

  const downloadPowerPoint = async () => {
    if (exportingPpt || !exportSlidesRef.current) return;
    if (validatedContentIssues("PowerPoint download").length) return;
    setExportingPpt(true);
    setExportProgress(0);

    let restoreExportImages = null;
    let restoreDashboardLayers = null;
    try {
      await document.fonts?.ready;
      const liveDashboardFrames = Array.from(exportSlidesRef.current.querySelectorAll(".dashboard-live-frame"));
      const dashboardSnapshots = Array.from(exportSlidesRef.current.querySelectorAll(".dashboard-export-snapshot"));
      const originalLiveDisplays = liveDashboardFrames.map((element) => element.style.display);
      const originalSnapshotDisplays = dashboardSnapshots.map((element) => element.style.display);
      liveDashboardFrames.forEach((element) => { element.style.display = "none"; });
      dashboardSnapshots.forEach((element) => { element.style.display = "block"; });
      restoreDashboardLayers = () => {
        liveDashboardFrames.forEach((element, index) => { element.style.display = originalLiveDisplays[index]; });
        dashboardSnapshots.forEach((element, index) => { element.style.display = originalSnapshotDisplays[index]; });
      };
      await waitForImages(exportSlidesRef.current);
      restoreExportImages = await inlineExportImages(exportSlidesRef.current);
      await waitForImages(exportSlidesRef.current);
      const slideElements = Array.from(exportSlidesRef.current.querySelectorAll("[data-ppt-slide]"));
      exportSlidesRef.current.querySelectorAll(".inline-editable").forEach((element) => {
        Object.assign(element.style, {
          background: "transparent",
          backgroundColor: "transparent",
          borderColor: "transparent",
          boxShadow: "none",
          outline: "none",
        });
      });
      const presentation = new PptxGenJS();
      presentation.layout = "LAYOUT_WIDE";
      presentation.author = "DSWD Field Office Caraga";
      presentation.company = "Department of Social Welfare and Development";
      presentation.subject = "Preparedness for Response";
      presentation.title = "Preparedness for Response Briefing";
      presentation.lang = "en-PH";
      const fontEmbedCSS = await htmlToImage.getFontEmbedCSS(slideElements[0]);

      for (let index = 0; index < slideElements.length; index += 1) {
        const imageData = await htmlToImage.toPng(slideElements[index], {
          backgroundColor: "#ffffff",
          cacheBust: false,
          pixelRatio: 1,
          width: SLIDE_WIDTH,
          height: SLIDE_HEIGHT,
          canvasWidth: SLIDE_WIDTH,
          canvasHeight: SLIDE_HEIGHT,
          fontEmbedCSS,
          skipAutoScale: true,
          style: {
            backgroundColor: "#ffffff",
            colorScheme: "light",
          },
          filter: (node) => node?.tagName !== "BUTTON" && node?.dataset?.exportIgnore !== "true",
        });
        const slide = presentation.addSlide();
        slide.background = { color: "FFFFFF" };
        slide.addImage({ data: imageData, x: 0, y: 0, w: 13.333, h: 7.5 });
        setExportProgress(index + 1);
      }

      const now = new Date();
      const date = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, "0"),
        String(now.getDate()).padStart(2, "0"),
      ].join("-");
      await presentation.writeFile({ fileName: `preparedness-for-response-${date}.pptx` });
    } catch (error) {
      console.error("PowerPoint export failed", error);
      setReportModal({
        type: "error",
        title: "PowerPoint export failed",
        message: error?.message?.startsWith("Unable to prepare image files")
          ? `${error.message} Reload the page and verify that each uploaded image is still available.`
          : "The PowerPoint could not be generated. Please try again.",
      });
    } finally {
      restoreExportImages?.();
      restoreDashboardLayers?.();
      setExportingPpt(false);
    }
  };

  const downloadEditablePowerPoint = async () => {
    if (exportingEditablePpt || exportingPpt || !exportSlidesRef.current) return;
    if (validatedContentIssues("Editable PowerPoint download").length) return;
    setExportingEditablePpt(true);
    setExportProgress(0);
    let restoreExportImages = null;
    let restoreDashboardLayers = null;
    try {
      await document.fonts?.ready;
      const liveDashboardFrames = Array.from(exportSlidesRef.current.querySelectorAll(".dashboard-live-frame"));
      const dashboardSnapshots = Array.from(exportSlidesRef.current.querySelectorAll(".dashboard-export-snapshot"));
      const originalLiveDisplays = liveDashboardFrames.map((element) => element.style.display);
      const originalSnapshotDisplays = dashboardSnapshots.map((element) => element.style.display);
      liveDashboardFrames.forEach((element) => { element.style.display = "none"; });
      dashboardSnapshots.forEach((element) => { element.style.display = "block"; });
      restoreDashboardLayers = () => {
        liveDashboardFrames.forEach((element, index) => { element.style.display = originalLiveDisplays[index]; });
        dashboardSnapshots.forEach((element, index) => { element.style.display = originalSnapshotDisplays[index]; });
      };
      await waitForImages(exportSlidesRef.current);
      restoreExportImages = await inlineExportImages(exportSlidesRef.current);
      await waitForImages(exportSlidesRef.current);
      const slideElements = Array.from(exportSlidesRef.current.querySelectorAll("[data-ppt-slide]"));
      const presentation = new PptxGenJS();
      presentation.layout = "LAYOUT_WIDE";
      presentation.author = "DSWD Field Office Caraga";
      presentation.company = "Department of Social Welfare and Development";
      presentation.subject = "Editable Preparedness for Response";
      presentation.title = "Preparedness for Response Briefing \u2014 Editable";
      presentation.lang = "en-PH";
      const fontEmbedCSS = await htmlToImage.getFontEmbedCSS(slideElements[0]);
      for (let index = 0; index < slideElements.length; index += 1) {
        const element = slideElements[index];
        const pageKey = pageDefinitions[index]?.key || "";
        const isEditableContentPage = pageKey === "briefing-title"
          || pageKey === "briefing-synopsis"
          || pageKey === "briefing-forecast"
          || pageKey === "cccm-idpp-updates"
          || pageKey === "challenges-ways-forward-title"
          || pageKey.startsWith("challenges-recommendations")
          || (pageKey.startsWith("actions-taken-") && pageKey !== "actions-taken-title");
        if (isEditableContentPage) {
          addEditableDomSlide(presentation, element);
        } else {
          const imageData = await htmlToImage.toPng(element, {
            backgroundColor: "#ffffff",
            cacheBust: false,
            pixelRatio: 1,
            width: SLIDE_WIDTH,
            height: SLIDE_HEIGHT,
            canvasWidth: SLIDE_WIDTH,
            canvasHeight: SLIDE_HEIGHT,
            fontEmbedCSS,
            skipAutoScale: true,
            style: { backgroundColor: "#ffffff", colorScheme: "light" },
            filter: (node) => node?.tagName !== "BUTTON" && node?.dataset?.exportIgnore !== "true",
          });
          const slide = presentation.addSlide();
          slide.background = { color: "FFFFFF" };
          slide.addImage({ data: imageData, x: 0, y: 0, w: 13.333, h: 7.5 });
        }
        setExportProgress(index + 1);
      }
      const now = new Date();
      const date = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, "0"), String(now.getDate()).padStart(2, "0")].join("-");
      await presentation.writeFile({ fileName: `preparedness-for-response-editable-${date}.pptx` });
    } catch (error) {
      console.error("Editable PowerPoint export failed", error);
      setReportModal({
        type: "error",
        title: "Editable PowerPoint export failed",
        message: error?.message?.startsWith("Unable to prepare image files")
          ? `${error.message} Reload the page and verify that each uploaded image is still available.`
          : "The editable PowerPoint could not be generated. Please try again.",
      });
    } finally {
      restoreExportImages?.();
      restoreDashboardLayers?.();
      setExportingEditablePpt(false);
    }
  };

  useEffect(() => {
    const parameters = new URLSearchParams(window.location.search);
    if (currentReport.status !== "finalized" || parameters.get("download") !== "ppt" || autoDownloadTriggeredRef.current) return undefined;
    autoDownloadTriggeredRef.current = true;
    const timeout = window.setTimeout(() => {
      parameters.delete("download");
      const cleanUrl = `${window.location.pathname}${parameters.size ? `?${parameters}` : ""}${window.location.hash}`;
      window.history.replaceState(null, "", cleanUrl);
      downloadPowerPoint();
    }, 500);
    return () => {
      window.clearTimeout(timeout);
      autoDownloadTriggeredRef.current = false;
    };
  }, [currentReport.id, currentReport.status]);

  return (
    <AppLayout title="Preparedness for Response">
      <Head title="Preparedness for Response" />
      <style>{`.ppt-export-root .inline-editable { background: transparent !important; outline: none !important; } .ppt-export-root .dashboard-live-frame { display: none !important; } .ppt-export-root .dashboard-export-snapshot { display: block !important; }`}</style>
      <div ref={reportWorkspaceRef} className={isFullscreen ? "h-screen overflow-y-auto bg-slate-100 p-4" : ""}>
      <div className="space-y-3 pb-6">
        <section className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <Link href="/preparedness-for-response" className="text-xs font-black uppercase text-blue-700 hover:underline">All reports</Link>
              <span className="text-slate-300">/</span>
              <span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${currentReport.status === "finalized" ? "bg-emerald-100 text-emerald-800" : currentReport.status === "revised" ? "bg-violet-100 text-violet-800" : "bg-amber-100 text-amber-800"}`}>{currentReport.status}</span>
            </div>
            <h1 className="mt-1 truncate text-xl font-black text-blue-950">{currentReport.title}</h1>
            <p className="text-xs text-slate-500">Reporting schedule: {new Date(currentReport.reporting_as_of).toLocaleString("en-PH")} {currentReport.status === "draft" ? ` \u00B7 Draft editing cutoff: ${new Date(currentReport.revision_deadline).toLocaleString("en-PH")}` : ""}{currentReport.finalized_at ? ` \u00B7 Finalized ${new Date(currentReport.finalized_at).toLocaleString("en-PH")}` : canEditPreparednessData ? " \u00B7 Draft changes can still be saved" : " \u00B7 Revision period closed"}</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <button type="button" onClick={toggleVariance} className="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-white px-4 py-3 text-xs font-black uppercase text-blue-900 shadow-sm hover:bg-blue-50">{showVariance ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}{showVariance ? "Hide variance" : "Show variance"}</button>
            <button type="button" onClick={toggleZeroValues} className="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-white px-4 py-3 text-xs font-black uppercase text-blue-900 shadow-sm hover:bg-blue-50">{hideZeroValues ? <Eye className="h-4 w-4" /> : <EyeOff className="h-4 w-4" />}{hideZeroValues ? "Show zero values" : "Hide zero values"}</button>
            <button type="button" onClick={toggleFullscreen} className="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-white px-4 py-3 text-xs font-black uppercase text-blue-900 shadow-sm hover:bg-blue-50">{isFullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}{isFullscreen ? "Exit fullscreen" : "Fullscreen"}</button>
            <Link href="/preparedness-for-response" className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs font-black uppercase text-slate-700 shadow-sm hover:bg-slate-50"><X className="h-4 w-4" /> Exit</Link>
            <button type="button" onClick={downloadPowerPoint} disabled={exportingPpt || exportingEditablePpt} title="Download every slide as a fixed canvas image" className="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs font-black uppercase text-blue-900 shadow-sm transition hover:bg-blue-100 disabled:cursor-wait disabled:opacity-60"><Download className="h-4 w-4" />{exportingPpt ? `Preparing ${exportProgress} / ${pageDefinitions.length}` : "Download Canvas PPT"}</button>
            <button type="button" onClick={downloadEditablePowerPoint} disabled={exportingPpt || exportingEditablePpt} title="Download the supported report pages as editable PowerPoint objects" className="inline-flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-xs font-black uppercase text-violet-900 shadow-sm transition hover:bg-violet-100 disabled:cursor-wait disabled:opacity-60"><FileText className="h-4 w-4" />{exportingEditablePpt ? `Building ${exportProgress} / ${pageDefinitions.length}` : "Download Editable PPT"}</button>
            {currentReport.status === "finalized" && canRevisePreparednessReport && <button type="button" onClick={requestRevision} className="rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-xs font-black uppercase text-amber-800 shadow-sm hover:bg-amber-100">Revise report</button>}
            {currentReport.status !== "finalized" && <button type="button" onClick={finalizeReport} className="rounded-xl bg-emerald-700 px-5 py-3 text-xs font-black uppercase text-white shadow-sm hover:bg-emerald-800">Finalize report</button>}
          </div>
        </section>
        <main className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <SlideCanvas>
            <BriefingSlideContents page={activePage} pageNumber={pageIndex + 1} pageCount={pageDefinitions.length} />
          </SlideCanvas>
        </main>
        <nav className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
          <button
            type="button"
            disabled={pageIndex === 0}
            onClick={() => navigate(pageIndex - 1)}
            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-xs font-black uppercase text-blue-950 disabled:opacity-30"
          >
            <ArrowLeft className="h-4 w-4" /> Previous
          </button>
          <div className="hidden text-center sm:block">
            <p className="text-sm font-black text-blue-950">
              {activePage.label}
            </p>
            <p className="text-[10px] uppercase tracking-wide text-slate-500">
              {activePage.group}
            </p>
          </div>
          <button
            type="button"
            disabled={pageIndex === pageDefinitions.length - 1}
            onClick={() => navigate(pageIndex + 1)}
            className="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-3 text-xs font-black uppercase text-white disabled:opacity-30"
          >
            Next <ArrowRight className="h-4 w-4" />
          </button>
        </nav>
        <p className="text-center text-[10px] text-slate-400">
          Native DRIMS briefing {"\u2022"} Province and district data are confined to
          dedicated summary pages; all other pages remain regional.
        </p>
      </div>
      <div ref={exportSlidesRef} className="ppt-export-root pointer-events-none fixed left-[-20000px] top-0 z-[-1]" aria-hidden="true">
        {pageDefinitions.map((page, index) => (
          <div key={page.key} data-ppt-slide className="flex h-[900px] w-[1600px] flex-col overflow-hidden bg-white">
            <BriefingSlideContents page={page} pageNumber={index + 1} pageCount={pageDefinitions.length} />
          </div>
        ))}
      </div>
      {editorSection && (
        <PreparednessDataEditor section={editorSection} initialRows={editorRows} onClose={() => setEditorSection(null)} reportId={currentReport.id} />
      )}
      {reportModal && <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/60 p-5" role="presentation"><div role="dialog" aria-modal="true" aria-labelledby="preparedness-report-modal-title" className="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"><div className={`h-2 ${reportModal.type === "error" ? "bg-red-500" : reportModal.type === "confirm-revise" ? "bg-amber-500" : "bg-emerald-600"}`} /><div className="p-6"><div className={`flex h-12 w-12 items-center justify-center rounded-full ${reportModal.type === "error" ? "bg-red-100 text-red-700" : reportModal.type === "confirm-revise" ? "bg-amber-100 text-amber-700" : "bg-emerald-100 text-emerald-700"}`}>{reportModal.type === "error" ? <AlertTriangle className="h-6 w-6" /> : <ShieldCheck className="h-6 w-6" />}</div><h2 id="preparedness-report-modal-title" className="mt-4 text-xl font-black text-blue-950">{reportModal.title}</h2><p className="mt-3 whitespace-pre-line text-sm font-medium leading-relaxed text-slate-600">{reportModal.message}</p><div className="mt-7 flex justify-end gap-2">{reportModal.type !== "error" && <button type="button" onClick={() => setReportModal(null)} className="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-black uppercase text-slate-700 hover:bg-slate-50">Cancel</button>}<button type="button" onClick={reportModal.type === "confirm-finalize" ? confirmFinalizeReport : reportModal.type === "confirm-revise" ? confirmReportRevision : () => setReportModal(null)} className={`rounded-xl px-5 py-2.5 text-xs font-black uppercase text-white ${reportModal.type === "error" ? "bg-blue-900 hover:bg-blue-800" : reportModal.type === "confirm-revise" ? "bg-amber-600 hover:bg-amber-700" : "bg-emerald-700 hover:bg-emerald-800"}`}>{reportModal.type === "confirm-finalize" ? "Finalize report" : reportModal.type === "confirm-revise" ? "Revise report" : "Close"}</button></div></div></div></div>}
      </div>
    </AppLayout>
  );
}



