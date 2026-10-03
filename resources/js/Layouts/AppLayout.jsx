import { Link, router, useForm, usePage } from "@inertiajs/react";
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  BadgeDollarSign,
  Bell,
  Bot,
  Boxes,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Database,
  Eye,
  EyeOff,
  FileClock,
  FileCheck2,
  FileSignature,
  FileText,
  IdCard,
  LayoutDashboard,
  LogOut,
  Mail,
  Menu,
  LoaderCircle,
  MessageCircle,
  Moon,
  MoreHorizontal,
  PackageCheck,
  PencilLine,
  Phone,
  RadioTower,
  RefreshCw,
  Save,
  Send,
  ShieldCheck,
  Sparkles,
  Sun,
  Tent,
  Truck,
  UploadCloud,
  UserCog,
  UserRound,
  UsersRound,
  Volume2,
  VolumeX,
  Warehouse,
  X,
} from "lucide-react";
import clsx from "clsx";
import {
  Children,
  cloneElement,
  isValidElement,
  useEffect,
  useRef,
  useState,
} from "react";
import CreativePageLoader from "@/Components/CreativePageLoader";
import ExportButtons, { exportFilename } from "@/Components/ExportButtons";
import AccessDecisionModal from "@/Components/AccessDecisionModal";
import AgencyProfileModal from "@/Components/AgencyProfileModal";
import AorScopeFields from "@/Components/AorScopeFields";
import SectionTabs from "@/Components/SectionTabs";
import {
  connectRealtime,
  disconnectRealtime,
  listenRealtime,
} from "@/realtime";
import { roleAcronymLabel } from "@/Utils/roleAcronyms";
import { formatDateTime } from "@/Utils/dateFormat";
import { installDromisTooltips } from "@/Utils/dromisTooltips";

// Matches alert-acknowledgments route role_or_permission middleware (not LGU).
const alertAcknowledgementRoles = [
  "Super Admin",
  "RROS",
  "RROS AA",
  "DRRS",
  "DRRS AA",
  "DRIMS",
  "DRMD AA",
  "DRMD Chief",
  "DRMD Financial Analyst",
  "OCD Caraga",
  "QRT",
  "Quick Response Team",
];

// Dashboard / inventory view roles seeded with view dashboards (not LGU).
const dashboardViewRoles = [
  "Super Admin",
  "RROS",
  "RROS AA",
  "DRRS",
  "DRRS AA",
  "DRIMS",
  "DRMD Chief",
  "DRMD Financial Analyst",
];

const inventoryManageRoles = ["Super Admin", "RROS", "RROS AA"];
const nearExpiryRoles = ["Super Admin", "RROS", "RROS AA", "DRRS", "DRIMS"];
const dispatchRoles = ["Super Admin", "RROS", "RROS AA"];
const deliveryMonitoringRoles = [
  "Super Admin", "RROS", "RROS AA", "DRRS", "DRRS AA", "DRIMS", "DRMD AA",
  "DRMD Chief", "DRMD Financial Analyst", "QRT", "Quick Response Team",
  "Regional Director", "RD", "Assistant Regional Director", "ARD",
  "Assistant Regional Director for Operations", "ARDO",
];
const dswdStaffRoles = [
  "Super Admin", "RROS", "RROS AA", "DRRS", "DRRS AA", "DRIMS",
  "DRMD AA", "DRMD Chief", "DRMD Financial Analyst", "QRT", "Quick Response Team",
];

const nav = [
  {
    href: "/",
    label: "Dashboard",
    icon: LayoutDashboard,
    permissions: ["view dashboards", "submit drmd aa requests", "submit lgu dromic requests"],
    roles: [...dashboardViewRoles, "DRMD AA", "LGU"],
  },
  {
    href: "/requests",
    label: "Requests Workspace",
    icon: ClipboardList,
    permissions: ["encode requests", "monitor requests", "process requests"],
    // Role fallback when permission pivots drift; matches route middleware.
    roles: ["Super Admin", "DRRS", "DRIMS", "RROS", "RROS AA"],
    // Keep sidebar highlight when DRRS workspace switches to LGU request letters.
    matchHrefs: ["/requests", "/dromic/lgu-reports"],
    hideForRoles: ["DRIMS"],
  },
  {
    href: "/rros/requests",
    label: "RIS/DR/STF Workspace",
    icon: ClipboardList,
    roles: ["Super Admin"],
    roleOnly: true,
  },
  {
    href: "/preparedness-for-response",
    label: "Preparedness for Response",
    icon: ShieldCheck,
    roles: ["DRIMS", "Super Admin"],
    roleOnly: true,
  },
  {
    href: "/dispatches",
    label: "Dispatch/Delivery",
    icon: Truck,
    permissions: ["manage dispatches"],
    roles: dispatchRoles,
  },
  {
    href: "/delivery-monitoring",
    label: "Delivery Monitoring",
    icon: RadioTower,
    permissions: ["view dispatch delivery monitoring"],
    roles: deliveryMonitoringRoles,
  },
  {
    href: "/delivery-escort",
    label: "Delivery Escort Workspace",
    icon: Truck,
    roles: dswdStaffRoles,
    roleOnly: true,
  },
  {
    href: "/dromic/lgu-reports",
    label: "LGU DROMIC Reports",
    icon: FileText,
    permissions: ["monitor requests", "manage regional alerts"],
    roles: ["DRIMS", "DRRS", "QRT", "Quick Response Team", "OCD Caraga", "Super Admin"],
    roleOnly: true,
  },
  {
    href: "/evacuation-centers",
    label: "Evacuation Centers",
    icon: Tent,
    permissions: ["manage dromic reports"],
    roles: ["DRIMS", "Super Admin"],
    roleOnly: true,
  },
  {
    href: "/warehouses",
    label: "Warehouses",
    icon: Warehouse,
    permissions: ["manage warehouses"],
    // Role fallback when permission pivots/cache drift; matches route middleware.
    roles: inventoryManageRoles,
  },
  {
    href: "/inventory",
    label: "Inventory",
    icon: Boxes,
    permissions: ["manage inventory", "view dashboards"],
    roles: dashboardViewRoles,
    hideForRoles: ["DRIMS"],
  },
  {
    href: "/inventory/e-stock-card",
    label: "E-Stock Card",
    icon: ClipboardList,
    permissions: ["manage inventory"],
    roles: inventoryManageRoles,
  },
  {
    href: "/near-expiry",
    label: "Near Expiry",
    icon: Send,
    permissions: ["manage near expiry"],
    roles: nearExpiryRoles,
  },
  {
    href: "/fni-issuances",
    label: "FNI Issuances",
    icon: PackageCheck,
    permissions: ["manage inventory", "view dashboards"],
    roles: dashboardViewRoles,
    hideForRoles: ["DRIMS"],
  },
  {
    href: "/drmd-aa/requests",
    label: "Request Workspace",
    icon: FileText,
    permissions: [],
    roles: ["DRMD AA"],
    roleOnly: true,
    matchHrefs: ["/drmd-aa/requests", "/drmd-aa/proposals", "/drmd-aa/lgu-intake"],
  },
  {
    href: "/drrs-aa/epirma",
    label: "e-Pirma",
    icon: FileSignature,
    permissions: ["route epirma documents"],
    roles: ["DRRS AA", "Super Admin"],
    roleOnly: true,
  },
  {
    href: "/lgu/dromic-sitrep",
    label: "DROMIC / SitRep",
    icon: FileText,
    permissions: ["submit lgu dromic requests"],
    roles: ["LGU"],
  },
  {
    href: "/lgu/response-letters",
    label: "Response Letters",
    icon: FileCheck2,
    permissions: ["submit lgu dromic requests"],
    roles: ["LGU", "Super Admin"],
  },
  {
    href: "/lgu/evacuation-centers",
    label: "Evacuation Centers",
    icon: Tent,
    permissions: ["submit lgu dromic requests"],
    roles: ["LGU"],
    roleOnly: true,
  },
  {
    href: "/lgu/inventory",
    label: "Inventory",
    icon: Boxes,
    permissions: ["submit lgu dromic requests"],
    roles: ["LGU"],
    roleOnly: true,
  },
  {
    href: "/lgu/near-expiry",
    label: "Near Expiry",
    icon: Send,
    permissions: ["submit lgu dromic requests"],
    roles: ["LGU"],
    roleOnly: true,
  },
  {
    href: "/lgu/population",
    label: "Population Data",
    icon: UsersRound,
    permissions: ["submit lgu dromic requests"],
    roles: ["LGU"],
    roleOnly: true,
  },
  {
    href: "/drmd-chief/lgu-intake",
    label: "Validated LGU Documents",
    icon: ClipboardList,
    permissions: [],
    roles: ["DRMD Chief", "Super Admin"],
    roleOnly: true,
  },
  {
    href: "/libraries",
    label: "Libraries",
    icon: Database,
    permissions: ["manage inventory", "encode requests", "manage users"],
    // Nav filter still Super Admin–only; roles keep deep-link alignment with routes.
    roles: ["Super Admin", "RROS", "RROS AA", "DRRS"],
  },
  {
    href: "/dromic",
    label: "DSWD DROMIC Reporting",
    icon: ClipboardList,
    permissions: ["manage dromic reports"],
    roles: ["Super Admin", "DRIMS"],
  },
  {
    href: "/ocd/alerts",
    label: "Regional Alerts",
    icon: RadioTower,
    permissions: ["manage regional alerts"],
    roles: ["Super Admin", "OCD Caraga"],
  },
  {
    href: "/alert-acknowledgments",
    label: "Alert Acknowledgments",
    icon: CheckCircle2,
    permissions: ["view regional alert acknowledgements"],
    // Role fallback when permission pivots/cache drift; matches route middleware. Not LGU.
    roles: alertAcknowledgementRoles,
  },
  {
    href: "/audit-trail",
    label: "Audit Trail",
    icon: FileClock,
    permissions: ["view audit logs"],
    roles: ["Super Admin"],
  },
  {
    href: "/access-management",
    label: "User Access",
    icon: UsersRound,
    permissions: ["manage users"],
    roles: ["Super Admin"],
  },
  {
    href: "/psgc-addresses",
    label: "PSGC Addresses",
    icon: Database,
    permissions: ["manage users", "manage psgc addresses"],
    roles: ["Super Admin"],
  },
  {
    href: "/population",
    label: "Population",
    icon: UsersRound,
    permissions: ["manage users", "manage population"],
    roles: ["Super Admin"],
  },
  {
    href: "/standby-funds",
    label: "Standby Funds",
    icon: BadgeDollarSign,
    permissions: ["manage standby funds"],
    roles: ["Super Admin", "DRMD Financial Analyst"],
  },
];

// Super Admin–only routes DRRS (PDRC) must never see, even if permissions drift.
const drrsExcludedNavHrefs = new Set([
  "/access-management",
  "/audit-trail",
  "/psgc-addresses",
  "/population",
  "/standby-funds",
  "/dromic",
  "/libraries",
  "/warehouses",
  "/inventory/e-stock-card",
  "/dispatches",
  "/ocd/alerts",
]);

const superAdminAccessGroups = [
  {
    key: "rros",
    label: "RROS",
    icon: Warehouse,
    hrefs: [
      "/",
      "/rros/requests",
      "/dispatches",
      "/delivery-monitoring",
      "/delivery-escort",
      "/warehouses",
      "/inventory",
      "/inventory/e-stock-card",
      "/near-expiry",
      "/fni-issuances",
    ],
  },
  {
    key: "drrs",
    label: "DRRS",
    icon: ClipboardList,
    hrefs: [
      "/",
      "/requests",
      "/delivery-monitoring",
      "/delivery-escort",
      "/inventory",
      "/near-expiry",
      "/fni-issuances",
    ],
  },
  {
    key: "drims",
    label: "DRIMS",
    icon: RadioTower,
    hrefs: [
      "/",
      "/preparedness-for-response",
      "/delivery-monitoring",
      "/delivery-escort",
      "/near-expiry",
      "/dromic/lgu-reports",
      "/dromic",
    ],
  },
  {
    key: "drmd-financial-analyst",
    label: "DRMD Financial Analyst",
    icon: BadgeDollarSign,
    hrefs: [
      "/",
      "/delivery-monitoring",
      "/delivery-escort",
      "/inventory",
      "/fni-issuances",
      "/standby-funds",
    ],
  },
];

// Super Admin navigation is intentionally opt-in. Gate::before grants a Super
// Admin every permission, but that must not make newly added role workspaces
// appear automatically in the administration sidebar.
const superAdminCoreHrefs = new Set([
  "/access-management",
  "/audit-trail",
  "/psgc-addresses",
  "/population",
  "/libraries",
  "/alert-acknowledgments",
]);

const dashboardTree = [
  { id: "dashboard-overview", label: "Overview Cards" },
  { id: "warehouse-summary", label: "Warehouse Summary" },
  { id: "ffp-summary", label: "FFP Summary" },
  { id: "rtef-summary", label: "RTEF Summary" },
  { id: "bottled-water-summary", label: "Bottled Water Summary" },
  { id: "non-food-summary", label: "Non-Food Items" },
  { id: "other-nfi-summary", label: "Other NFIs" },
  { id: "indirect-raw-materials-summary", label: "Indirect & Raw Materials" },
  { id: "standby-stockpile-summary", label: "Standby & Stockpile" },
  { id: "dashboard-near-expiry-summary", label: "Near Expiry Summary" },
  { id: "top-warehouse-stockpile", label: "Top Warehouse Stockpile" },
  { id: "recent-inventory-transactions", label: "Recent Transactions" },
];

const pageTrees = {
  "/": dashboardTree,
  "/rros-dashboard": dashboardTree,
  "/preparedness-for-response": [
    { id: "report-register", label: "Report Register", href: "/preparedness-for-response", exact: true },
    { id: "briefing-title", label: "01  Opening Page", children: [
      { id: "briefing-synopsis", label: "02  Synopsis" },
      { id: "briefing-forecast", label: "03  Provincial Weather Forecast" },
    ] },
    { id: "resource-capacity-title", label: "04  Resource Capacity" },
    { id: "food-nfi-title", label: "05  Food and Non-Food Items", children: [
      { id: "rros-standby-summary", label: "06  Standby Funds & Stockpile" },
      { id: "rros-ffp-summary", label: "07  FFP Summary" },
      { id: "ffp-province", label: "08  FFPs per Province" },
      { id: "ffp-dswd", label: "09  FFPs at DSWD Warehouses" },
      { id: "ffp-lgu-province-summary", label: "10  LGU FFPs per Province" },
      { id: "rtef-capacity", label: "11  Ready-to-Eat Food" },
      { id: "bottled-water", label: "12  Bottled Water" },
      { id: "relief-shelter-items", label: "13  Non-Food Items per Province" },
      { id: "protection-cccm-items", label: "14  CCCM & IDPP Resources" },
      { id: "production-materials", label: "15  Other NFIs, Raw & Indirect" },
    ] },
    { id: "response-assets-title", label: "16  Mobile Response Vehicles and Equipment", children: [
      { id: "response-assets", label: "17  Vehicles & Equipment" },
    ] },
    { id: "qrt-human-resources-title", label: "18  Human Resources (QRT)", children: [
      { id: "qrt-human-resources", label: "19  Human Resources Details" },
      { id: "qrt-specializations", label: "20  QRT Specializations" },
    ] },
    { id: "cccm-idpp-title", label: "21  CCCM and IDPP", children: [
      { id: "cccm-idpp-updates", label: "22  CCCM and IDPP Updates" },
      { id: "evacuation-centers-dashboard", label: "23  Evacuation Centers Dashboard" },
    ] },
    { id: "actions-taken-title", label: "24  Actions Taken", children: [
      { id: "actions-taken-1", label: "25  Actions Taken 1" },
      { id: "actions-taken-2", label: "26  Actions Taken 2" },
    ] },
    { id: "challenges-ways-forward-title", label: "27  Challenges and Concerns / Ways Forward", children: [
      { id: "challenges-recommendations", label: "28  Challenges and Recommendations" },
    ] },
    { id: "thank-you", label: "29  Closing Page" },
  ],
  "/warehouses": [
    { id: "warehouse-overview", label: "Overview & Sync" },
    { id: "warehouse-dashboard", label: "Warehouse Dashboard" },
    { id: "warehouse-filters", label: "Filters & Search" },
    { id: "warehouse-master-list", label: "Warehouse Master List" },
  ],
  "/inventory/e-stock-card": [
    { id: "estock-overview", label: "Stock Card Overview" },
    { id: "estock-filters", label: "Filters & Search" },
    { id: "estock-ledger", label: "Transaction Ledger" },
  ],
  "/inventory": [
    { id: "inventory-overview", label: "Inventory Overview" },
    { id: "inventory-filters", label: "Filters & Search" },
    { id: "warehouse-stockpile", label: "Warehouse Stockpile" },
  ],
  "/lgu/inventory": [
    { id: "inventory-overview", label: "Inventory Overview" },
    { id: "inventory-filters", label: "Filters & Search" },
    { id: "warehouse-stockpile", label: "Warehouse Stockpile" },
  ],
  "/near-expiry": [
    { id: "near-expiry-overview", label: "Expiry & Ageing Overview" },
    { id: "near-expiry-filters", label: "Filters & Tabs" },
    { id: "near-expiry-item-breakdown", label: "Item Breakdown" },
    { id: "near-expiry-warehouse-breakdown", label: "Warehouse Breakdown" },
    { id: "near-expiry-stock", label: "Monitoring Table" },
    { id: "near-expiry-plans", label: "Distribution Plan" },
    { id: "near-expiry-register", label: "Allocation Register" },
  ],
  "/lgu/near-expiry": [
    { id: "near-expiry-overview", label: "Expiry & Ageing Overview" },
    { id: "near-expiry-filters", label: "Filters & Tabs" },
    { id: "near-expiry-stock", label: "Monitoring Table" },
  ],
  "/fni-issuances": [
    { id: "fni-issuance-overview", label: "Issuance Overview" },
    { id: "fni-issuance-filters", label: "Filters & Search" },
    { id: "fni-monthly-trend", label: "Monthly Trend" },
    { id: "fni-category-breakdown", label: "Category Breakdown" },
    { id: "fni-warehouse-breakdown", label: "Warehouse Distribution" },
    { id: "fni-purpose-breakdown", label: "Purpose of Transaction" },
    { id: "fni-issuance-ledger", label: "Issuance Ledger" },
  ],
  "/psgc-addresses": [
    { id: "psgc-summary-cards", label: "Summary Cards" },
    { id: "psgc-reference", label: "Local PSGC Address Reference" },
    { id: "psgc-barangay-browser", label: "PSGC Barangay Browser" },
    { id: "psgc-district-options", label: "Province District Options" },
    { id: "psgc-city-assignment", label: "City / Municipality Assignment" },
    { id: "psgc-district-coverage", label: "District Coverage Atlas" },
  ],
  "/population": [
    { id: "population-summary-cards", label: "Summary Cards" },
    { id: "population-management", label: "Population Management" },
    { id: "caraga-population-summary", label: "Caraga Population Summary" },
    { id: "population-map", label: "Interactive Map" },
    { id: "population-by-province", label: "Population by Province" },
    { id: "population-distribution", label: "Population Distribution" },
    { id: "top-cities-population", label: "Top Cities / Municipalities" },
    { id: "population-records", label: "Population Records" },
  ],
  "/lgu/population": [
    { id: "population-summary-cards", label: "Summary Cards" },
    { id: "population-management", label: "Filters & Search" },
    { id: "caraga-population-summary", label: "Caraga Population Summary" },
    { id: "population-map", label: "Interactive LGU Map" },
    { id: "top-cities-population", label: "Cities / Municipalities" },
    { id: "population-records", label: "Population Records" },
  ],
  "/lgu/evacuation-centers": [
    { id: "evac-dashboard-table", label: "Dashboard" },
    { id: "evac-dashboard-charts", label: "Charts" },
    { id: "evac-map", label: "Map" },
    { id: "evac-details", label: "Details" },
    { id: "evac-latrines", label: "Latrines" },
    { id: "evac-facilities", label: "Facilities" },
    { id: "evac-photo-gallery", label: "Photos" },
  ],
  "/evacuation-centers": [
    { id: "evac-dashboard-table", label: "Dashboard" },
    { id: "evac-dashboard-charts", label: "Charts" },
    { id: "evac-map", label: "Map" },
    { id: "evac-details", label: "Details" },
    { id: "evac-latrines", label: "Latrines" },
    { id: "evac-facilities", label: "Facilities" },
    { id: "evac-photo-gallery", label: "Photos" },
  ],
  "/requests": [
    {
      id: "lgu-reports-requests",
      label: "Request letters",
      href: "/dromic/lgu-reports?tab=requests",
      roles: ["DRRS", "Super Admin"],
    },
    { id: "fni-requests", label: "FNI assessments", href: "/requests" },
  ],
  "/rros/requests": [
    { id: "ris-dr", label: "RIS/DR", href: "/rros/requests" },
    { id: "stf", label: "STF", href: "/rros/requests?section=stf" },
    { id: "ris-epirma", label: "e-PIRMA", href: "/rros-aa/epirma", roles: ["RROS AA"] },
  ],
  "/drmd-aa/requests": [
    { id: "aa-fni-requests", label: "FNI Requests", href: "/drmd-aa/requests" },
    { id: "aa-proposals", label: "Proposals", href: "/drmd-aa/proposals" },
  ],
  "/dispatches": [
    { id: "dispatch-ready", label: "Still for Action", href: "/dispatches?bucket=still_for_action#dispatch-ready" },
    { id: "dispatch-list", label: "Dispatch / Delivery", href: "/dispatches?bucket=in_progress#dispatch-list" },
  ],
  "/libraries": [
    { id: "fni-library-overview", label: "Libraries Overview" },
    { id: "library-group-rros-references", label: "RROS References" },
    { id: "library-group-drims-references", label: "DRIMS References" },
    { id: "library-group-drrs-references", label: "DRRS References" },
    { id: "library-group-system-configuration", label: "System Configuration" },
  ],
  "/dromic": [
    { id: "dromic-create", label: "Prepare consolidated report" },
    { id: "dromic-list", label: "Saved DSWD reports" },
  ],
  "/audit-trail": [
    { id: "audit-summary", label: "Activity Summary" },
    { id: "audit-filters", label: "Filters & Search" },
    { id: "audit-log", label: "System Activity Log" },
  ],
  "/access-management": [
    { id: "access-summary", label: "Access Summary" },
    { id: "access-users", label: "User Access Records" },
  ],
  "/standby-funds": [
    { id: "standby-current", label: "Current Standby Funds" },
    { id: "standby-sync-source", label: "WIT Sync Source" },
    { id: "standby-update", label: "Update Standby Funds" },
  ],
};

const rrosPagePaths = [
  "/",
  "/dashboard",
  "/warehouses",
  "/inventory",
  "/near-expiry",
  "/fni-issuances",
  "/requests",
  "/dispatches",
];

const isRrosPage = (url) => {
  const path = String(url || "/")
    .split("?")[0]
    .split("#")[0];

  return rrosPagePaths.some((allowedPath) =>
    allowedPath === "/"
      ? path === "/"
      : path === allowedPath || path.startsWith(`${allowedPath}/`),
  );
};

const newestUnreadRegionalAlert = (center) =>
  center?.regional_alert_prompt ??
  (center?.notifications ?? []).find(
    (notification) =>
      notification.action_key === "ocd_alert_changed" && !notification.read_at,
  ) ?? null;

export default function AppLayout({ title, children }) {
  const {
    auth,
    flash,
    activeRegion,
    systemName,
    systemNameShort,
    notificationCenter: initialNotificationCenter,
    realtime,
    sessionPolicy,
    actionPages: preparednessActionPages = [],
  } = usePage().props;
  const currentPageUrl = usePage().url;
  const currentUrl = currentPageUrl.split("?")[0].split("#")[0];
  const permissions = auth.user?.permissions ?? [];
  const isLguAccess = Boolean(
    auth.user?.is_lgu ||
      auth.user?.lgu_profile ||
      auth.user?.roles?.includes("LGU"),
  );
  const isAgencyAccess = Boolean(
    auth.user?.is_agency || auth.user?.roles?.includes("OCD Caraga"),
  );
  const userTheme = auth.user?.theme_mode;
  const [dark, setDark] = useState(
    userTheme
      ? userTheme === "dark"
      : document.documentElement.classList.contains("dark"),
  );
  const [sidebarCollapsed, setSidebarCollapsed] = useState(
    typeof window !== "undefined" ? window.innerWidth < 1024 : false,
  );
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
  const [compactActionsOpen, setCompactActionsOpen] = useState(false);
  const getGlobalLoaderRemaining = () =>
    Math.max((window.__drmdPageLoaderVisibleUntil ?? 0) - Date.now(), 0);
  const [pageLoading, setPageLoading] = useState(
    () => getGlobalLoaderRemaining() > 0,
  );
  const [toast, setToast] = useState(null);
  const [openPageTrees, setOpenPageTrees] = useState({});
  const [openPageSections, setOpenPageSections] = useState({});
  const [currentHash, setCurrentHash] = useState(() => window.location.hash);
  const [openAccessGroups, setOpenAccessGroups] = useState({});
  const [selectedAccessGroupKey, setSelectedAccessGroupKey] = useState("rros");
  const [notificationCenter, setNotificationCenter] = useState(initialNotificationCenter);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [regionalAlertPrompt, setRegionalAlertPrompt] = useState(() =>
    newestUnreadRegionalAlert(initialNotificationCenter),
  );
  const [regionalAlertAcknowledging, setRegionalAlertAcknowledging] = useState(false);
  const [messageCenterOpen, setMessageCenterOpen] = useState(false);

  useEffect(() => installDromisTooltips(), []);
  useEffect(() => {
    setMobileSidebarOpen(false);
    setCompactActionsOpen(false);
  }, [currentUrl]);
  useEffect(() => {
    const updateHash = () => setCurrentHash(window.location.hash);
    window.addEventListener("hashchange", updateHash);
    return () => window.removeEventListener("hashchange", updateHash);
  }, []);
  const [messageCenter, setMessageCenter] = useState({ unread_count: 0, messages: [], contacts: [] });
  const [messageDraft, setMessageDraft] = useState({ recipient_id: "", subject: "", body: "" });
  const [messageSending, setMessageSending] = useState(false);
  const [messageLoading, setMessageLoading] = useState(false);
  const [realtimeConnected, setRealtimeConnected] = useState(false);
  const [witSyncing, setWitSyncing] = useState(false);
  const [selectedAccessRequest, setSelectedAccessRequest] = useState(null);
  const [profileOpen, setProfileOpen] = useState(false);
  const [aiRogerOpen, setAiRogerOpen] = useState(false);
  const [aiRogerMessages, setAiRogerMessages] = useState([
    {
      role: "assistant",
      content: "Hi, I’m AI Roger. Ask me anything about DROMIS workflows, reports, access levels, routing, or drafting official text.",
    },
  ]);
  const [aiRogerDraft, setAiRogerDraft] = useState("");
  const [aiRogerThinking, setAiRogerThinking] = useState(false);
  const [soundEnabled, setSoundEnabled] = useState(() => localStorage.getItem("dromis-access-alerts") !== "off");
  const loaderStartedAt = useRef(0);
  const loaderTimeout = useRef(null);
  const toastTimeout = useRef(null);
  const inactivityTimeout = useRef(null);
  const inactivityWarningTimeout = useRef(null);
  const inactivityLoggedOut = useRef(false);
  const lastActivityResetAt = useRef(0);
  const sessionKeepaliveInterval = useRef(null);
  const lastKeepaliveAt = useRef(0);
  const [sessionWarningOpen, setSessionWarningOpen] = useState(false);
  const [sessionWarningSecondsLeft, setSessionWarningSecondsLeft] = useState(0);
  const sessionWarningTicker = useRef(null);
  const previousUnread = useRef(initialNotificationCenter?.unread_count || 0);
  const previousPendingRequests = useRef(initialNotificationCenter?.action_required_count || 0);
  const realtimePageRefreshTimer = useRef(null);

  const playAccessAlert = (request) => {
    if (!soundEnabled || !request) return;

    try {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      const context = AudioContext ? new AudioContext() : null;
      if (context) {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.frequency.setValueAtTime(740, context.currentTime);
        oscillator.frequency.exponentialRampToValueAtTime(1040, context.currentTime + 0.25);
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.18, context.currentTime + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.45);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.48);
      }
      window.speechSynthesis?.cancel();
      const speech = new SpeechSynthesisUtterance(`New DROMIS access request from ${request.name}. Please review and act on the request.`);
      speech.rate = 0.95;
      window.speechSynthesis?.speak(speech);
    } catch {
      // Browsers may block audio until the first user interaction.
    }
  };

  const refreshNotificationCenter = async () => {
    try {
      const response = await fetch("/notifications", { headers: { Accept: "application/json" }, credentials: "same-origin" });
      if (!response.ok) return;
      const next = await response.json();
      const hasNew = next.unread_count > previousUnread.current || next.action_required_count > previousPendingRequests.current;
      const newestRequest = next.pending_requests?.[next.pending_requests.length - 1];
      if (hasNew && auth.user?.roles?.includes("Super Admin")) playAccessAlert(newestRequest);
      if (hasNew && !realtimeConnected) refreshCurrentPageData();
      previousUnread.current = next.unread_count;
      previousPendingRequests.current = next.action_required_count;
      setNotificationCenter(next);
      setRegionalAlertPrompt(newestUnreadRegionalAlert(next));
    } catch {
      // Poll again after transient connection failures.
    }
  };

  const refreshMessageCenter = async () => {
    setMessageLoading(true);

    try {
      const response = await fetch("/messages", { headers: { Accept: "application/json" }, credentials: "same-origin" });
      if (!response.ok) return;
      setMessageCenter(await response.json());
    } catch {
      // Keep the last visible inbox during transient connection failures.
    } finally {
      setMessageLoading(false);
    }
  };

  const refreshCurrentPageData = () => {
    window.clearTimeout(realtimePageRefreshTimer.current);
    realtimePageRefreshTimer.current = window.setTimeout(() => {
      router.reload({
        preserveScroll: true,
        preserveState: true,
      });
    }, 350);
  };

  useEffect(() => {
    connectRealtime(realtime);
    const stopConnection = listenRealtime("realtime.connection", ({ connected }) => {
      setRealtimeConnected(Boolean(connected));
      if (connected) {
        refreshNotificationCenter();
        refreshMessageCenter();
      }
    });
    const stopNotifications = listenRealtime("notification.changed", (payload = {}) => {
      refreshNotificationCenter();
      if (payload.reason === "created") refreshCurrentPageData();
    });
    const stopMessages = listenRealtime("message.changed", (payload = {}) => {
      refreshMessageCenter();

      if (
        payload.reason === "created"
        && Number(payload.recipient_id) === Number(auth.user?.id)
      ) {
        showToast({
          type: "message",
          title: `New message from ${payload.sender_name || "DROMIS User"}`,
          message: payload.subject || payload.body_preview || "You received a new DROMIS message.",
        });
      }
    });
    const stopAlerts = listenRealtime("regional-alert.changed", () => {
      refreshNotificationCenter();
      refreshCurrentPageData();
    });
    const stopWorkflowReceipts = listenRealtime("workflow.receipt.changed", () => {
      refreshCurrentPageData();
    });

    return () => {
      stopConnection();
      stopNotifications();
      stopMessages();
      stopAlerts();
      stopWorkflowReceipts();
      window.clearTimeout(realtimePageRefreshTimer.current);
      disconnectRealtime();
    };
  }, [realtime?.url, realtime?.auth_url]);

  useEffect(() => {
    const interval = window.setInterval(
      refreshNotificationCenter,
      realtimeConnected ? 300000 : 60000,
    );
    return () => window.clearInterval(interval);
  }, [soundEnabled, realtimeConnected]);

  useEffect(() => {
    setNotificationCenter(initialNotificationCenter);
    setRegionalAlertPrompt(newestUnreadRegionalAlert(initialNotificationCenter));
  }, [initialNotificationCenter]);

  useEffect(() => {
    if (!regionalAlertPrompt) return undefined;

    const previousTitle = document.title;
    document.title = `⚠ ${String(regionalAlertPrompt.meta?.alert_level || "Regional").toUpperCase()} ALERT · DROMIS`;
    window.navigator.vibrate?.([180, 100, 180]);

    return () => {
      document.title = previousTitle;
    };
  }, [regionalAlertPrompt?.id]);

  useEffect(() => {
    refreshMessageCenter();

    if (realtimeConnected) {
      return undefined;
    }

    const interval = window.setInterval(
      refreshMessageCenter,
      10000,
    );
    return () => window.clearInterval(interval);
  }, [realtimeConnected]);

  useEffect(() => {
    if (messageCenterOpen) {
      refreshMessageCenter();
    }
  }, [messageCenterOpen]);

  const toggleNotificationSound = () => {
    const next = !soundEnabled;
    setSoundEnabled(next);
    localStorage.setItem("dromis-access-alerts", next ? "on" : "off");
  };

  const askAiRoger = async (event) => {
    event.preventDefault();
    const message = aiRogerDraft.trim();

    if (!message || aiRogerThinking) {
      return;
    }

    const nextMessages = [...aiRogerMessages, { role: "user", content: message }];
    setAiRogerMessages(nextMessages);
    setAiRogerDraft("");
    setAiRogerThinking(true);

    try {
      const { data } = await window.axios.post(
        "/ai-roger/chat",
        {
          message,
          current_url: window.location.pathname + window.location.search,
          history: nextMessages.slice(-8),
        },
        {
          headers: { Accept: "application/json" },
          withXSRFToken: true,
        },
      );
      setAiRogerMessages((current) => [
        ...current,
        {
          role: "assistant",
          content: data.answer || "AI Roger could not prepare a response. Please try again.",
        },
      ]);
    } catch (error) {
      const message = error?.response?.data?.answer
        || error?.response?.data?.message
        || (error?.response?.status === 419
          ? "AI Roger could not verify your session token. Please refresh the page, then try again."
          : "AI Roger could not connect right now. Existing DROMIS features are unaffected.");
      setAiRogerMessages((current) => [
        ...current,
        {
          role: "assistant",
          content: message,
        },
      ]);
    } finally {
      setAiRogerThinking(false);
    }
  };

  const sendSystemMessage = async (event) => {
    event.preventDefault();

    if (!messageDraft.recipient_id || !messageDraft.body.trim() || messageSending) {
      return;
    }

    setMessageSending(true);

    try {
      const { data } = await window.axios.post(
        "/messages",
        {
          recipient_id: messageDraft.recipient_id,
          subject: messageDraft.subject,
          body: messageDraft.body,
        },
        {
          headers: { Accept: "application/json" },
          withXSRFToken: true,
        },
      );

      setMessageCenter((current) => ({
        ...current,
        messages: [data.item, ...(current.messages ?? [])].slice(0, 40),
      }));
      setMessageDraft({ recipient_id: "", subject: "", body: "" });
      showToast({ type: "success", message: "Message sent." });
    } catch (error) {
      showToast({
        type: "error",
        message: error?.response?.data?.message || "Message was not sent. Please check the recipient and try again.",
      });
    } finally {
      setMessageSending(false);
      refreshMessageCenter();
    }
  };

  const markSystemMessageRead = async (message) => {
    if (message.is_mine || message.read_at) {
      return;
    }

    try {
      await window.axios.patch(`/messages/${message.id}/read`, {}, {
        headers: { Accept: "application/json" },
        withXSRFToken: true,
      });
      refreshMessageCenter();
    } catch {
      // Non-blocking: message details are still visible.
    }
  };

  useEffect(() => {
    if (!messageCenterOpen) return;

    (messageCenter?.messages ?? [])
      .filter((message) => !message.is_mine && !message.read_at)
      .forEach((message) => markSystemMessageRead(message));
  }, [messageCenterOpen, messageCenter?.messages]);

  const hideWorkspaceLoaderNow = () => {
    if (loaderTimeout.current) {
      clearTimeout(loaderTimeout.current);
    }

    window.__drmdPageLoaderVisibleUntil = 0;
    loaderStartedAt.current = 0;
    setPageLoading(false);
  };

  const markNotificationRead = async (notification) => {
    if (!notification || notification.read_at) return true;

    const readAt = new Date().toISOString();
    setNotificationCenter((current) => ({
      ...current,
      unread_count: Math.max(0, Number(current?.unread_count || 0) - 1),
      notifications: (current?.notifications ?? []).map((item) => (
        item.id === notification.id ? { ...item, read_at: readAt } : item
      )),
    }));

    try {
      await window.axios.patch(`/notifications/${notification.id}/read`, {}, {
        headers: { Accept: "application/json" },
        withXSRFToken: true,
      });
      return true;
    } catch {
      await refreshNotificationCenter();
      showToast({
        type: "error",
        message: "The notification was opened, but its read status could not be saved. Please try again.",
      });
      return false;
    }
  };

  const markAllNotificationsRead = async () => {
    const unread = Number(notificationCenter?.unread_count || 0);
    if (!unread) return;

    const readAt = new Date().toISOString();
    setNotificationCenter((current) => ({
      ...current,
      unread_count: 0,
      unread_overflow: 0,
      notifications: (current?.notifications ?? []).map((item) => ({
        ...item,
        read_at: item.read_at || readAt,
      })),
    }));

    try {
      await window.axios.patch("/notifications/read-all", {}, {
        headers: { Accept: "application/json" },
        withXSRFToken: true,
      });
      await refreshNotificationCenter();
    } catch {
      await refreshNotificationCenter();
      showToast({
        type: "error",
        message: "Notifications could not be marked as read. Please try again.",
      });
    }
  };

  const normalizeNotificationDestination = (value, fallback = "/") => {
    try {
      const destination = new URL(value || fallback, window.location.origin);
      if (destination.origin !== window.location.origin) return fallback;
      return `${destination.pathname}${destination.search}${destination.hash}`;
    } catch {
      return fallback;
    }
  };

  const visitNotificationDestination = (value, fallback = "/") => {
    const destination = normalizeNotificationDestination(value, fallback);
    const destinationPath = destination.split("?")[0].split("#")[0];
    const destinationQuery = destination.includes("?")
      ? destination.slice(destination.indexOf("?"))
      : "";
    const currentFull = `${currentUrl}${typeof window !== "undefined" ? window.location.search : ""}`;
    setNotificationsOpen(false);

    // Each monitoring / LGU deep link can target a different record while sharing
    // the same pathname. Always apply the complete URL — comparing path only made
    // clicks appear dead when the user was already on that workspace.
    if (destinationPath === "/delivery-monitoring" || destinationPath === "/lgu/dromic-sitrep" || destinationPath.startsWith("/lgu/dispatch-plans/")) {
      window.location.assign(destination);
      return;
    }

    if (currentFull === destination || (currentUrl === destinationPath && !destinationQuery)) {
      hideWorkspaceLoaderNow();
      showToast({
        type: "success",
        message: "Notification marked as read. You are already viewing its workspace.",
      });
      return;
    }

    if (destinationPath === "/ocd/alerts") {
      window.location.assign(destination);
      return;
    }

    router.visit(destination, {
      onError: () => {
        hideWorkspaceLoaderNow();
        showToast({
          type: "error",
          message: "This notification's destination is no longer available.",
        });
      },
      onFinish: hideWorkspaceLoaderNow,
    });
  };

  const openNotification = async (notification) => {
    setNotificationsOpen(false);
    await markNotificationRead(notification);

    if (notification.action_key === "ocd_alert_changed") {
      if (!notification.regional_alert_acknowledged_at) {
        setRegionalAlertPrompt({ ...notification, read_at: notification.read_at || new Date().toISOString() });
        return;
      }

      const alertId = notification.meta?.alert_id;
      const canViewAlertAcknowledgements =
        permissions.includes("view regional alert acknowledgements")
        || (auth.user?.roles ?? []).some((role) => alertAcknowledgementRoles.includes(role));
      const destination = isLguAccess
        ? "/lgu/dromic-sitrep"
        : canViewAlertAcknowledgements
          ? `/alert-acknowledgments${alertId ? `?alert_id=${alertId}` : ""}`
          : "/";
      visitNotificationDestination(destination);
      return;
    }

    if (notification.action_required && notification.acted) {
      hideWorkspaceLoaderNow();
      showToast({ message: "This notification has already been acted on.", type: "success" });
      return;
    }

    if (notification.kind === "access_requested") {
      const pending = notificationCenter?.pending_requests?.find((item) => item.id === notification.access_user_id);
      if (pending) {
        setSelectedAccessRequest(pending);
      } else {
        visitNotificationDestination(notification.url, "/access-management");
      }
    } else {
      visitNotificationDestination(notification.url, "/");
    }

    await refreshNotificationCenter();
  };

  const acknowledgeRegionalAlert = async (openReportingPage = false) => {
    if (!regionalAlertPrompt || regionalAlertAcknowledging) return;

    setRegionalAlertAcknowledging(true);
    try {
      await window.axios.patch(
        `/notifications/${regionalAlertPrompt.id}/acknowledge-regional-alert`,
        {},
        {
          headers: { Accept: "application/json" },
          withXSRFToken: true,
        },
      );

      setRegionalAlertPrompt(null);
      await refreshNotificationCenter();
      if (openReportingPage && isLguAccess) {
        router.visit(regionalAlertPrompt.url || "/lgu/dromic-sitrep");
      }
    } catch {
      showToast({
        type: "error",
        message: "The OCD alert could not be acknowledged. Check your connection and try again.",
      });
    } finally {
      setRegionalAlertAcknowledging(false);
    }
  };

  const showToast = (nextToast) => {
    if (!nextToast?.message) {
      return;
    }

    if (toastTimeout.current) {
      clearTimeout(toastTimeout.current);
    }

    setToast(nextToast);
    toastTimeout.current = setTimeout(
      () => setToast(null),
      nextToast.type === "message" ? 6000 : nextToast.type === "success" ? 5000 : 4000,
    );
  };

  useEffect(() => {
    document.documentElement.classList.toggle("dark", dark);
    document.cookie = `theme=${dark ? "dark" : "light"}; path=/; max-age=31536000`;
  }, [dark]);

  useEffect(() => {
    if (userTheme) {
      setDark(userTheme === "dark");
    }
  }, [userTheme]);

  useEffect(() => {
    const message = flash?.success || flash?.error;

    if (!message) {
      return undefined;
    }

    showToast({
      type: flash?.success ? "success" : "error",
      message,
    });

    return undefined;
  }, [flash?.success, flash?.error]);

  useEffect(() => {
    const removeSuccessListener = router.on("success", (event) => {
      const pageFlash = event.detail.page.props.flash;
      const message = pageFlash?.success || pageFlash?.error;

      if (!message) {
        return;
      }

      showToast({
        type: pageFlash?.success ? "success" : "error",
        message,
      });
    });

    const onAppToast = (event) => {
      const detail = event?.detail;
      if (!detail?.message) {
        return;
      }
      showToast({
        type: detail.type || "error",
        title: detail.title,
        message: detail.message,
      });
    };
    window.addEventListener("dromis:toast", onAppToast);

    return () => {
      if (toastTimeout.current) {
        clearTimeout(toastTimeout.current);
      }

      removeSuccessListener();
      window.removeEventListener("dromis:toast", onAppToast);
    };
  }, []);

  useEffect(() => {
    const collapseOnSmallScreens = () => {
      if (window.innerWidth < 1024) {
        setSidebarCollapsed(true);
      }
    };

    collapseOnSmallScreens();
    window.addEventListener("resize", collapseOnSmallScreens);

    return () => window.removeEventListener("resize", collapseOnSmallScreens);
  }, []);

  useEffect(() => {
    const minimumLoaderTime = 1000;
    const showGlobalLoader = () => {
      if (loaderTimeout.current) {
        clearTimeout(loaderTimeout.current);
      }

      loaderStartedAt.current = Date.now();
      window.__drmdPageLoaderVisibleUntil = Date.now() + minimumLoaderTime;
      setPageLoading(true);
    };

    const hideGlobalLoaderAfterMinimum = () => {
      const elapsed = Date.now() - loaderStartedAt.current;
      const remaining = Math.max(
        getGlobalLoaderRemaining(),
        minimumLoaderTime - elapsed,
        0,
      );

      loaderTimeout.current = setTimeout(() => {
        setPageLoading(false);
        if (getGlobalLoaderRemaining() <= 0) {
          window.__drmdPageLoaderVisibleUntil = 0;
        }
      }, remaining);
    };

    const restoreVisibleLoader = () => {
      const remaining = getGlobalLoaderRemaining();

      if (remaining <= 0) {
        return;
      }

      setPageLoading(true);
      loaderTimeout.current = setTimeout(() => {
        setPageLoading(false);
        window.__drmdPageLoaderVisibleUntil = 0;
      }, remaining);
    };

    restoreVisibleLoader();

    const removeBeforeListener = router.on("before", () => {
      if (window.__drmdSilentWorkspaceRefresh) return;
      showGlobalLoader();
    });
    const removeStartListener = router.on("start", () => {
      if (window.__drmdSilentWorkspaceRefresh) return;
      showGlobalLoader();
    });
    const removeFinishListener = router.on("finish", () => {
      hideGlobalLoaderAfterMinimum();
    });
    const removeInvalidListener = router.on("invalid", (event) => {
      event.preventDefault();
      hideWorkspaceLoaderNow();
      const status = event?.detail?.response?.status;
      showToast({
        type: "error",
        title: status === 403 ? "Access denied" : "Transaction failed",
        message: status === 403
          ? "You do not have permission for that workspace. Ask a Super Admin to grant the needed role permissions."
          : "The requested workspace is unavailable or the link is outdated.",
      });
    });
    const removeExceptionListener = router.on("exception", () => {
      hideWorkspaceLoaderNow();
    });

    return () => {
      if (loaderTimeout.current) {
        clearTimeout(loaderTimeout.current);
      }

      removeBeforeListener();
      removeStartListener();
      removeFinishListener();
      removeInvalidListener();
      removeExceptionListener();
    };
  }, []);

  useEffect(() => {
    const timeoutMinutes = Math.max(
      15,
      Number(sessionPolicy?.inactivity_timeout_minutes) || 90,
    );
    const warningMinutes = Math.min(
      Math.max(1, Number(sessionPolicy?.inactivity_warning_minutes) || 5),
      Math.max(1, timeoutMinutes - 1),
    );
    const inactivityLimit = timeoutMinutes * 60 * 1000;
    const warningLead = warningMinutes * 60 * 1000;
    const activityEvents = [
      "mousemove",
      "mousedown",
      "keydown",
      "scroll",
      "touchstart",
      "wheel",
      "pointerdown",
    ];
    const csrfToken = () =>
      document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

    const clearWarningTicker = () => {
      if (sessionWarningTicker.current) {
        clearInterval(sessionWarningTicker.current);
        sessionWarningTicker.current = null;
      }
    };

    const dismissWarning = () => {
      clearWarningTicker();
      setSessionWarningOpen(false);
      setSessionWarningSecondsLeft(0);
    };

    const touchServerSession = ({ force = false } = {}) => {
      const token = csrfToken();
      if (!token) return;

      const now = Date.now();
      // Debounce remounts / Strict Mode / multi-tab storms (throttle is 12/min).
      if (!force && now - lastKeepaliveAt.current < 60_000) {
        return;
      }
      lastKeepaliveAt.current = now;

      window.axios.post("/session/keepalive", {}, {
        headers: { Accept: "application/json" },
        withXSRFToken: true,
      }).catch((error) => {
        if ([401, 419].includes(Number(error?.response?.status))) {
          window.location.reload();
        }
      });
    };

    const logoutForInactivity = () => {
      if (inactivityLoggedOut.current) {
        return;
      }

      inactivityLoggedOut.current = true;
      dismissWarning();
      router.post(
        "/logout",
        { reason: "inactivity" },
        {
          preserveScroll: false,
          onSuccess: () => window.location.replace("/login"),
          onError: () => {
            inactivityLoggedOut.current = false;
          },
        },
      );
    };

    const openWarning = () => {
      const secondsLeft = Math.max(1, Math.round(warningLead / 1000));
      setSessionWarningSecondsLeft(secondsLeft);
      setSessionWarningOpen(true);
      clearWarningTicker();
      sessionWarningTicker.current = setInterval(() => {
        setSessionWarningSecondsLeft((current) => {
          if (current <= 1) {
            clearWarningTicker();
            return 0;
          }

          return current - 1;
        });
      }, 1000);
    };

    const resetInactivityTimer = () => {
      if (inactivityLoggedOut.current) {
        return;
      }

      const now = Date.now();
      if (now - lastActivityResetAt.current < 15000) {
        return;
      }
      lastActivityResetAt.current = now;

      dismissWarning();

      if (inactivityTimeout.current) {
        clearTimeout(inactivityTimeout.current);
      }
      if (inactivityWarningTimeout.current) {
        clearTimeout(inactivityWarningTimeout.current);
      }

      inactivityWarningTimeout.current = setTimeout(openWarning, Math.max(0, inactivityLimit - warningLead));
      inactivityTimeout.current = setTimeout(logoutForInactivity, inactivityLimit);
    };

    const staySignedIn = () => {
      inactivityLoggedOut.current = false;
      lastActivityResetAt.current = 0;
      dismissWarning();
      touchServerSession({ force: true });
      resetInactivityTimer();
    };

    window.__dromisStaySignedIn = staySignedIn;

    const onVisibilityChange = () => {
      if (document.visibilityState === "visible") {
        resetInactivityTimer();
      }
    };

    activityEvents.forEach((event) =>
      window.addEventListener(event, resetInactivityTimer, { passive: true }),
    );
    window.addEventListener("focus", resetInactivityTimer);
    document.addEventListener("visibilitychange", onVisibilityChange);

    lastActivityResetAt.current = 0;
    resetInactivityTimer();
    touchServerSession();
    sessionKeepaliveInterval.current = setInterval(touchServerSession, 10 * 60 * 1000);

    return () => {
      if (inactivityTimeout.current) {
        clearTimeout(inactivityTimeout.current);
      }
      if (inactivityWarningTimeout.current) {
        clearTimeout(inactivityWarningTimeout.current);
      }
      if (sessionKeepaliveInterval.current) {
        clearInterval(sessionKeepaliveInterval.current);
      }
      clearWarningTicker();
      delete window.__dromisStaySignedIn;
      activityEvents.forEach((event) =>
        window.removeEventListener(event, resetInactivityTimer),
      );
      window.removeEventListener("focus", resetInactivityTimer);
      document.removeEventListener("visibilitychange", onVisibilityChange);
    };
  }, [sessionPolicy?.inactivity_timeout_minutes, sessionPolicy?.inactivity_warning_minutes]);

  const toggleTheme = () => {
    const nextDark = !dark;

    setDark(nextDark);
    router.patch(
      "/settings/theme",
      { theme_mode: nextDark ? "dark" : "light" },
      {
        preserveScroll: true,
        preserveState: true,
      },
    );
  };

  const canSee = (item) => {
    const hasPermission = Array.isArray(item.permissions)
      && item.permissions.some((permission) => permissions.includes(permission));
    const hasRole = Array.isArray(item.roles)
      && item.roles.some((role) => auth.user?.roles?.includes(role));

    // roleOnly: roles alone grant access.
    // Otherwise: permission OR role (when roles are listed as a fallback).
    if (item.roleOnly) {
      return hasRole;
    }

    if (hasPermission) {
      return true;
    }

    return hasRole;
  };
  const isSuperAdmin = auth.user?.roles?.includes("Super Admin") ?? false;
  const isRrosLevel = auth.user?.roles?.some((role) => ["RROS", "RROS AA"].includes(role)) ?? false;
  const isDrrsUser = auth.user?.roles?.includes("DRRS") ?? false;
  const isDrrsAaOnly = Boolean(
    auth.user?.roles?.includes("DRRS AA")
      && !isSuperAdmin
      && !isDrrsUser
      && !auth.user?.roles?.some((role) => ["RROS", "RROS AA"].includes(role))
      && !auth.user?.roles?.includes("DRMD AA"),
  );
  const superAdminSidebarHrefs = new Set([
    ...superAdminCoreHrefs,
    ...superAdminAccessGroups.flatMap((group) => group.hrefs),
  ]);
  const visibleNav = nav.filter(
    (item) =>
      canSee(item) &&
      (!isSuperAdmin || superAdminSidebarHrefs.has(item.href)) &&
      (item.href !== "/libraries" || isSuperAdmin) &&
      (!isDrrsUser || item.href !== "/dromic/lgu-reports") &&
      (!isDrrsUser || !drrsExcludedNavHrefs.has(item.href)) &&
      (!isSuperAdmin || !["/drmd-aa/requests", "/drmd-aa/proposals"].includes(item.href)) &&
      (!isDrrsAaOnly || ["/", "/drrs-aa/epirma"].includes(item.href)) &&
      (!Array.isArray(item.hideForRoles)
        || !item.hideForRoles.some((role) => auth.user?.roles?.includes(role))),
  ).map((item) => {
    if (item.href === "/requests" && isDrrsUser) {
      return {
        ...item,
        label: "Requests Workspace",
        // Land on request-letter validation first — FNI rows appear only after Validated — No Findings.
        href: "/dromic/lgu-reports?tab=requests",
        matchHrefs: ["/requests", "/dromic/lgu-reports"],
      };
    }
    if (item.href === "/requests" && isRrosLevel) {
      return {
        ...item,
        href: "/rros/requests",
        label: "RIS/DR/STF Workspace",
        matchHrefs: ["/rros/requests", "/rros-aa/epirma"],
      };
    }
    return item;
  });
  const groupedSuperAdminHrefs = new Set(
    superAdminAccessGroups.flatMap((group) => group.hrefs),
  );
  const standaloneSuperAdminNav = visibleNav.filter(
    (item) => !groupedSuperAdminHrefs.has(item.href),
  );
  const selectedAccessGroup = superAdminAccessGroups.find(
    (group) => group.key === selectedAccessGroupKey,
  ) ?? superAdminAccessGroups[0];
  const selectedAccessGroupItems = selectedAccessGroup.hrefs
    .map((href) => visibleNav.find((item) => item.href === href))
    .filter(Boolean);
  const pathMatches = (candidate) => {
    const path = String(candidate || "").split("?")[0];
    return currentUrl === path || currentUrl.startsWith(`${path}/`);
  };
  const matchesHref = (itemOrHref) => {
    const item = typeof itemOrHref === "string"
      ? visibleNav.find((navItem) => navItem.href === itemOrHref) ?? { href: itemOrHref }
      : itemOrHref;
    const href = item.href;
    const candidates = Array.isArray(item.matchHrefs) && item.matchHrefs.length > 0
      ? item.matchHrefs
      : [href];

    if (href === "/") {
      return currentUrl === "/" || currentUrl.startsWith("/dashboard");
    }

    return candidates.some((candidate) => pathMatches(candidate));
  };
  const activeHref = visibleNav
    .filter((item) => matchesHref(item))
    .sort((a, b) => {
      // Prefer the nav item whose own href matches the current path (exact / nested),
      // then fall back to longest href so workspace aliases do not steal sibling items.
      const aExact = pathMatches(a.href) ? 1 : 0;
      const bExact = pathMatches(b.href) ? 1 : 0;
      if (aExact !== bExact) {
        return bExact - aExact;
      }
      return b.href.length - a.href.length;
    })[0]?.href;
  const activeItem = visibleNav.find((item) => item.href === activeHref);
  const blankPrimaryDashboardRoles = ["DRIMS", "DRRS", "DRRS AA", "DRMD AA", "DRMD Financial Analyst"];
  const hasBlankPrimaryDashboard = isLguAccess
    || blankPrimaryDashboardRoles.some((role) => auth.user?.roles?.includes(role));
  const HeaderIcon = activeItem?.icon ?? LayoutDashboard;
  const SidebarToggleIcon = sidebarCollapsed ? ChevronRight : ChevronLeft;
  const userInitial = auth.user?.name?.trim()?.charAt(0)?.toUpperCase() ?? "U";
  const sidebarLguProfile = auth.user?.lgu_profile ?? null;
  const sidebarAgencyProfile = auth.user?.agency_profile ?? null;
  const sidebarPersonnelPhoto = sidebarLguProfile?.personnel_photo_url || null;
  const sidebarLguLogo = sidebarLguProfile?.logo_url || null;
  const sidebarAvatar = isLguAccess
    ? (sidebarPersonnelPhoto || sidebarLguLogo || auth.user?.avatar || null)
    : (sidebarAgencyProfile?.logo_url || auth.user?.avatar || null);
  const sidebarAvatarAlt = sidebarPersonnelPhoto
    ? `${auth.user?.name || "LGU user"} photo`
    : sidebarLguLogo
      ? `${sidebarLguProfile?.name || auth.user?.name || "LGU"} logo`
      : sidebarAgencyProfile?.logo_url
        ? `${sidebarAgencyProfile?.agency_name || auth.user?.name || "Agency"} logo`
        : auth.user?.name || "User";
  const formatLguWorkspaceLabel = () => {
    const levelRaw = String(sidebarLguProfile?.level || auth.user?.lgu_level || "").trim();
    const normalized = levelRaw.toLowerCase();
    const level = normalized === "province" || normalized === "plgu"
      ? "PLGU"
      : normalized === "city" || normalized === "city_municipality" || normalized === "clgu"
        ? "CLGU"
        : normalized === "municipality" || normalized === "mlgu"
          ? "MLGU"
          : (levelRaw.toUpperCase() || "LGU");
    const place = String(
      sidebarLguProfile?.name
      || auth.user?.lgu_name
      || "",
    ).trim();
    if (!place) {
      return level;
    }
    // Avoid "MLGU MLGU Tubod" if the stored name already starts with the level.
    const placeWithoutLevel = place.replace(/^(PLGU|CLGU|MLGU|LGU)\s*[-–—:]?\s*/i, "").trim() || place;
    return `${level} ${placeWithoutLevel}`;
  };
  const lguWorkspaceLabel = formatLguWorkspaceLabel();
  const formatLguPersonnelRoleLabel = () => {
    const linkedRoles = Array.isArray(sidebarLguProfile?.roles) ? sidebarLguProfile.roles : [];
    const labels = linkedRoles
      .map((value) => formatSingleLguPersonnelRoleLabel(value))
      .filter(Boolean);
    if (labels.length) {
      return [...new Set(labels)].join(" · ");
    }

    return formatSingleLguPersonnelRoleLabel(
      sidebarLguProfile?.linked?.role
      || auth.user?.lgu_directory_role
      || auth.user?.position
      || auth.user?.designation
      || "",
    );
  };
  const formatSingleLguPersonnelRoleLabel = (rawRole) => {
    const role = String(rawRole || "").trim().toLowerCase().replace(/\s+/g, "_");

    if (!role) {
      return null;
    }

    if (["lce", "local_chief_executive", "mayor"].includes(role) || /\blce\b|\bmayor\b/.test(role.replaceAll("_", " "))) {
      return "LCE";
    }
    if (["lswd_officer", "lswdo", "mswdo", "cswdo", "pswdo"].includes(role) || /\blswdo\b|\bmswdo\b|\bcswdo\b|\bpswdo\b/.test(role.replaceAll("_", " "))) {
      return "LSWDO";
    }
    if (["lswd_officer_alternate", "lswdo_alternate", "alternate_lswdo"].includes(role)) {
      return "Alternate LSWDO";
    }
    if (["ldrrmo", "mdrrmo", "cdrrmo", "pdrrmo"].includes(role) || /\bldrrmo\b|\bmdrrmo\b|\bcdrrmo\b/.test(role.replaceAll("_", " "))) {
      return "LDRRMO";
    }
    if (["ldrrmo_alternate", "alternate_ldrrmo"].includes(role)) {
      return "Alternate LDRRMO";
    }
    if (role === "dromic_encoder" || role.includes("sitrep") || role.includes("dromic")) {
      return "DROMIC / SitReport Encoder";
    }
    if (role === "warehouse_focal" || role.includes("wh_focal") || (role.includes("focal") && role.includes("warehouse"))) {
      return "Warehouse Focal";
    }
    if (role === "warehouse_storekeeper" || role.includes("storekeeper")) {
      return "Warehouse Storekeeper";
    }
    if (role === "driver" || role.includes("driver")) {
      return "Driver";
    }
    if (role.includes("alternate") && (role.includes("lswd") || role.includes("social"))) {
      return "Alternate LSWDO";
    }
    if (role.includes("alternate") && (role.includes("ldrrm") || role.includes("drrm"))) {
      return "Alternate LDRRMO";
    }

    return null;
  };
  const lguPersonnelRoleLabel = isLguAccess ? formatLguPersonnelRoleLabel() : null;
  const scrollToPageSection = (href, sectionId) => {
    if (href === "/preparedness-for-response") {
      window.history.replaceState(null, "", `#${sectionId}`);
      setCurrentHash(`#${sectionId}`);
      window.dispatchEvent(new CustomEvent("preparedness:navigate", {
        detail: { pageKey: sectionId },
      }));
      return;
    }

    if (href === "/near-expiry") {
      window.dispatchEvent(new CustomEvent("near-expiry:navigate", {
        detail: { sectionId },
      }));
    }

    const scroll = (attempt = 0) => {
      const target = document.getElementById(sectionId);
      if (!target && attempt < 20) {
        window.setTimeout(() => scroll(attempt + 1), 100);
        return;
      }
      if (!target) return;

      target.scrollIntoView({ behavior: "smooth", block: "start" });
      const hash = `#${sectionId}`;
      window.history.replaceState(null, "", `${window.location.pathname}${window.location.search}${hash}`);
      setCurrentHash(hash);
    };

    if (!matchesHref({ href })) {
      router.visit(href, {
        preserveScroll: false,
        onSuccess: () => window.setTimeout(() => scroll(), 50),
      });
      return;
    }

    scroll();
  };

  const renderNavItem = (item, groupKey = "main") => {
    const Icon = item.icon;
    const active = activeHref === item.href;
    const treeLookupPath = String(item.href || "").split("?")[0];
    let configuredPageTree = item.href === "/" && hasBlankPrimaryDashboard
      ? null
      : (pageTrees[treeLookupPath] ?? (Array.isArray(item.matchHrefs)
        ? item.matchHrefs.map((href) => pageTrees[String(href).split("?")[0]]).find(Boolean)
        : null) ?? null);
    if (item.href === "/preparedness-for-response" && currentUrl === "/preparedness-for-response") {
      configuredPageTree = null;
    }
    if (item.href === "/preparedness-for-response" && configuredPageTree) {
      configuredPageTree = configuredPageTree.map((section) => section.id === "actions-taken-title"
        ? { ...section, children: preparednessActionPages.map((page, index) => ({ id: `actions-taken-${page.id}`, label: `${25 + index}  Actions Taken ${index + 1}` })) }
        : section.id === "thank-you"
          ? { ...section, label: `${25 + preparednessActionPages.length}  Closing Page` }
          : section);
    }
    const pageTree = configuredPageTree?.filter(
      (section) => !section.roles || section.roles.some((role) => auth.user?.roles?.includes(role)),
    );
    const treeKey = `${groupKey}:${item.href}`;
    const pageTreeOpen = openPageTrees[treeKey] ?? active;
    // Monitoring may be opened from a long-lived installed PWA shell. Use a full
    // document navigation so a newly deployed page cannot be swallowed by stale
    // Inertia component state or an open notification overlay.
    const forceDocumentNavigation = ["/ocd/alerts", "/delivery-monitoring"].includes(item.href);
    const NavLink = forceDocumentNavigation ? "a" : Link;

    return (
      <div key={treeKey} className={clsx("relative", pageTreeOpen && "z-20")}>
        <div
          className={clsx(
            "group relative z-10 flex items-center rounded-xl text-sm font-semibold transition duration-200",
            sidebarCollapsed ? "justify-center" : "gap-1",
            active
              ? "shell-nav-active text-brand-900 shadow-sm ring-1 ring-brand-200/80 dark:text-white dark:ring-brand-400/35"
              : "text-slate-600 hover:bg-white/80 hover:text-slate-950 hover:shadow-sm dark:text-zinc-300 dark:hover:bg-zinc-900/80 dark:hover:text-zinc-50",
          )}
        >
          {active && (
            <span className="absolute bottom-2 left-0 top-2 w-1 rounded-r-full bg-gradient-to-b from-brand-400 via-brand-600 to-emerald-700 dark:from-brand-300 dark:via-brand-400 dark:to-emerald-400" />
          )}
          <NavLink
            href={item.href}
            title={sidebarCollapsed ? item.label : undefined}
            className={clsx(
              "flex min-w-0 flex-1 items-center rounded-xl transition",
              sidebarCollapsed ? "justify-center px-2 py-2.5" : "gap-3 py-2.5 pl-3",
              pageTree ? "pr-1" : "pr-3",
            )}
          >
            <span className={clsx(
              "flex h-8 w-8 items-center justify-center rounded-lg transition",
              active
                ? "bg-white text-brand-700 shadow-sm ring-1 ring-brand-100 dark:bg-brand-950/60 dark:text-brand-100 dark:ring-brand-700/50"
                : "bg-slate-100/80 text-slate-500 group-hover:bg-white group-hover:text-brand-700 dark:bg-zinc-900 dark:text-zinc-400 dark:group-hover:bg-zinc-950 dark:group-hover:text-brand-200",
            )}>
              <Icon className="h-4 w-4" />
            </span>
            <span className={clsx("min-w-0 flex-1 tracking-tight", sidebarCollapsed && "hidden")}>{item.label}</span>
          </NavLink>
          {pageTree && !sidebarCollapsed && (
            <button
              type="button"
              aria-label={pageTreeOpen ? `Collapse ${item.label} menu` : `Expand ${item.label} menu`}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                setOpenPageTrees((current) => ({
                  ...current,
                  [treeKey]: !(current[treeKey] ?? active),
                }));
              }}
              data-tip={pageTreeOpen ? undefined : `Open ${item.label} submenu`}
              data-tip-side="left"
              className="dromis-tip relative z-20 mr-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-white hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 dark:text-zinc-500 dark:hover:bg-zinc-900 dark:hover:text-brand-100"
            >
              {pageTreeOpen ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
            </button>
          )}
        </div>
        {pageTree && pageTreeOpen && !sidebarCollapsed && (
          <div className="relative z-20 ml-7 mt-2 space-y-1 rounded-r-xl border-l-2 border-brand-300/70 bg-gradient-to-br from-white via-brand-50/40 to-emerald-50/30 pb-2 pl-4 pr-1 pt-1 shadow-sm dark:border-brand-400/30 dark:from-zinc-950 dark:via-brand-950/20 dark:to-zinc-950">
            {pageTree.map((section) => {
              const sectionHref = section.href;
              const sectionChildren = section.children ?? [];
              const sectionTreeKey = `${treeKey}:${section.id}`;
              const childActive = sectionChildren.some((child) => currentHash === `#${child.id}`);
              const sectionOpen = openPageSections[sectionTreeKey] ?? childActive;
              const sectionActive = childActive || (sectionHref
                ? (() => {
                    const [sectionPath, sectionQuery = ""] = String(sectionHref).split("?");
                    if (!(section.exact ? currentUrl === sectionPath : pathMatches(sectionPath))) {
                      return false;
                    }
                    const wantedTab = new URLSearchParams(sectionQuery).get("tab");
                    if (!wantedTab) {
                      return true;
                    }
                    const currentTab = new URLSearchParams((currentPageUrl.split("?")[1] || "")).get("tab");
                    return currentTab === wantedTab;
                  })()
                : item.href === "/preparedness-for-response"
                  ? currentHash === `#${section.id}` || (!currentHash && section.id === "overview")
                  : false);
              const itemClass = clsx(
                "group flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs font-bold transition",
                sectionActive
                  ? "bg-white text-brand-800 shadow-sm ring-1 ring-brand-200 dark:bg-brand-950/50 dark:text-brand-100 dark:ring-brand-700/40"
                  : "text-slate-500 hover:bg-white/90 hover:text-brand-800 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-brand-100",
              );
              const content = (
                <>
                  <span className={clsx(
                    "h-1.5 w-1.5 shrink-0 rounded-full transition",
                    sectionActive
                      ? "bg-brand-600 shadow-[0_0_0_3px_rgba(38,114,93,0.18)] dark:bg-brand-300"
                      : "bg-slate-300 group-hover:bg-brand-600 dark:bg-zinc-600 dark:group-hover:bg-brand-300",
                  )} />
                  <span>{section.label}</span>
                </>
              );

              if (sectionChildren.length) {
                return (
                  <div key={section.id} className="space-y-1">
                    <div className="flex items-center gap-1">
                      <button type="button" onClick={() => scrollToPageSection(item.href, section.sectionId || section.id)} className={clsx(itemClass, "min-w-0 flex-1")}>{content}</button>
                      <button type="button" aria-label={sectionOpen ? `Collapse ${section.label}` : `Expand ${section.label}`} onClick={() => setOpenPageSections((current) => ({ ...current, [sectionTreeKey]: !sectionOpen }))} className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 hover:bg-white hover:text-brand-700">
                        {sectionOpen ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                      </button>
                    </div>
                    {sectionOpen && <div className="ml-3 space-y-1 border-l border-brand-200 pl-2">{sectionChildren.map((child) => {
                      const activeChild = currentHash === `#${child.id}`;
                      return <button key={child.id} type="button" onClick={() => scrollToPageSection(item.href, child.id)} className={clsx("flex w-full items-center gap-2 rounded-md px-2 py-1 text-left text-[11px] font-semibold transition", activeChild ? "bg-white text-brand-800 shadow-sm" : "text-slate-500 hover:bg-white/80 hover:text-brand-800")}><span className={clsx("h-1 w-1 shrink-0 rounded-full", activeChild ? "bg-brand-600" : "bg-slate-300")} /><span>{child.label}</span></button>;
                    })}</div>}
                  </div>
                );
              }

              return sectionHref ? (
                <Link key={section.id} href={sectionHref} className={itemClass}>
                  {content}
                </Link>
              ) : (
                <button
                  key={section.id}
                  type="button"
                  onClick={() => scrollToPageSection(item.href, section.sectionId || section.id)}
                  className={itemClass}
                >
                  {content}
                </button>
              );
            })}
          </div>
        )}
      </div>
    );
  };

  const shellActionClass =
    "dromis-tip inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200/90 bg-white/90 text-slate-600 shadow-sm backdrop-blur transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:bg-brand-50 hover:text-brand-800 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900/90 dark:text-zinc-300 dark:hover:border-brand-700 dark:hover:bg-brand-950/40 dark:hover:text-brand-100";

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 via-brand-50/20 to-emerald-50/30 text-slate-900 dark:from-zinc-950 dark:via-brand-950/10 dark:to-zinc-950 dark:text-zinc-100">
      <CreativePageLoader active={pageLoading} />
      {toast && (
        <div
          role="status"
          aria-live="polite"
          aria-atomic="true"
          className="fixed right-4 top-5 z-[1200] w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-md border border-slate-200 bg-white shadow-2xl shadow-slate-950/10 dark:border-zinc-800 dark:bg-zinc-900 dark:shadow-black/30"
        >
          <div
            className={clsx(
              "h-1",
              toast.type === "success"
                ? "bg-emerald-500"
                : toast.type === "message"
                  ? "bg-sky-500"
                  : "bg-rose-500",
            )}
          />
          <div className="flex gap-3 p-4">
            <div
              className={clsx(
                "flex h-9 w-9 shrink-0 items-center justify-center rounded-full",
                toast.type === "success"
                  ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200"
                  : toast.type === "message"
                    ? "bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-200"
                    : "bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-200",
              )}
            >
              {toast.type === "success" ? (
                <CheckCircle2 className="h-5 w-5" />
              ) : toast.type === "message" ? (
                <MessageCircle className="h-5 w-5" />
              ) : (
                <AlertTriangle className="h-5 w-5" />
              )}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold text-slate-950 dark:text-white">
                {toast.title || (toast.type === "success"
                  ? "Transaction successful"
                  : "Transaction failed")}
              </p>
              <p className="mt-1 text-sm text-slate-600 dark:text-zinc-300">
                {toast.message}
              </p>
            </div>
            <button
              type="button"
              onClick={() => setToast(null)}
              className="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>
      )}
      {mobileSidebarOpen && (
        <button
          type="button"
          aria-label="Close navigation menu"
          onClick={() => setMobileSidebarOpen(false)}
          className="fixed inset-0 z-50 bg-slate-950/45 backdrop-blur-[2px] md:hidden"
        />
      )}
      <aside
        data-app-sidebar
        className={clsx(
          "fixed inset-y-0 left-0 z-[60] isolate w-[min(18rem,calc(100vw-2rem))] max-w-[100vw] overflow-visible border-r border-brand-200 bg-gradient-to-b from-white via-brand-50 to-emerald-50 shadow-2xl transition-all duration-200 md:z-40 md:translate-x-0 md:border-brand-200/70 md:via-brand-50/35 md:to-emerald-50/40 md:shadow-none dark:border-brand-900 dark:from-zinc-950 dark:via-brand-950 dark:to-zinc-950 md:dark:border-brand-900/50 md:dark:via-brand-950/25",
          mobileSidebarOpen ? "translate-x-0 shadow-2xl" : "-translate-x-full",
          sidebarCollapsed ? "md:w-20" : "md:w-72",
        )}
      >
        <div
          className="pointer-events-none absolute -left-10 top-16 h-40 w-40 rounded-full bg-brand-300/25 blur-3xl dark:bg-brand-600/15"
          aria-hidden
        />
        <div
          className="pointer-events-none absolute -right-12 bottom-24 h-44 w-44 rounded-full bg-emerald-300/20 blur-3xl dark:bg-emerald-700/10"
          aria-hidden
        />
        <button
          type="button"
          onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
          aria-label={sidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"}
          data-tip={sidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"}
          data-tip-side="right"
          className={clsx(
            "dromis-tip absolute top-6 z-[70] hidden h-8 w-8 items-center justify-center text-brand-700 transition hover:text-brand-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 md:flex dark:text-brand-100 dark:hover:text-white",
            sidebarCollapsed ? "-right-2" : "-right-3",
          )}
        >
          <SidebarToggleIcon className="h-4 w-4" />
        </button>
        <div className="relative flex h-full min-h-0 flex-col">
          <div
            className={clsx(
              "relative overflow-hidden border-b border-brand-200/60 py-5 dark:border-brand-900/40",
              sidebarCollapsed ? "px-3" : "px-5",
            )}
          >
            <div
              className="pointer-events-none absolute inset-x-0 top-0 h-px overflow-hidden bg-brand-700/15 dark:bg-brand-300/20"
              aria-hidden
            >
              <span className="shell-accent-sweep absolute inset-y-0 left-0 w-1/3 bg-gradient-to-r from-transparent via-brand-500 to-transparent dark:via-brand-300" />
            </div>
            <div
              className={clsx(
                "flex items-center gap-3",
                sidebarCollapsed && "justify-center",
              )}
            >
              <div className="relative">
                <span className="absolute -inset-1 rounded-full bg-gradient-to-br from-brand-300/50 to-emerald-400/30 blur-[2px] dark:from-brand-500/30 dark:to-emerald-600/20" aria-hidden />
                <img
                  src="/images/drmd-cir-logo.png"
                  alt="DRMD"
                  className="relative h-11 w-11 rounded-full object-contain shadow-sm ring-2 ring-white dark:ring-zinc-900"
                />
              </div>
              <div className={clsx(sidebarCollapsed && "md:hidden")}>
                <div className="text-[10px] font-black uppercase tracking-[0.18em] text-brand-700 dark:text-brand-200">
                  {activeRegion?.field_office_label || "DSWD CARAGA"}
                </div>
                <div className="text-sm font-extrabold leading-tight text-slate-950 dark:text-white">
                  {systemNameShort || systemName || 'DRIMS'}
                </div>
                <p className="mt-0.5 text-[10px] font-semibold text-slate-500 dark:text-zinc-400">
                  Disaster response workspace
                </p>
              </div>
            </div>
            <div
              className={clsx(
                "mt-4 flex items-center gap-3 rounded-xl border border-brand-200/70 bg-gradient-to-br from-white via-brand-50/60 to-emerald-50/40 px-3 py-3 shadow-sm dark:border-brand-800/60 dark:from-zinc-950 dark:via-brand-950/30 dark:to-zinc-900",
                sidebarCollapsed && "md:hidden",
              )}
            >
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white text-brand-700 shadow-sm ring-1 ring-brand-100 dark:bg-zinc-950 dark:text-brand-100 dark:ring-brand-800">
                {sidebarAvatar ? (
                  <img
                    src={sidebarAvatar}
                    alt={sidebarAvatarAlt}
                    className="h-full w-full rounded-full object-cover"
                  />
                ) : (
                  <div className="relative flex h-full w-full items-center justify-center">
                    {isLguAccess ? (
                      <span className="text-[10px] font-black uppercase tracking-tight">LGU</span>
                    ) : isAgencyAccess ? (
                      <RadioTower className="h-5 w-5 opacity-70" />
                    ) : (
                      <UserRound className="h-5 w-5 opacity-70" />
                    )}
                    <span className="absolute bottom-1 right-1 flex h-4 w-4 items-center justify-center rounded-full bg-brand-600 text-[9px] font-bold text-white ring-2 ring-white dark:bg-brand-300 dark:text-zinc-950 dark:ring-zinc-950">
                      {userInitial}
                    </span>
                  </div>
                )}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                  {isLguAccess ? "LGU account" : isAgencyAccess ? "Agency account" : "Signed in"}
                </p>
                <p className="whitespace-normal break-words text-sm font-bold leading-snug text-slate-950 dark:text-white">
                  {auth.user?.name}
                </p>
                <p className="mt-0.5 truncate text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">
                  {isLguAccess
                    ? [lguWorkspaceLabel, lguPersonnelRoleLabel].filter(Boolean).join(" · ")
                    : isAgencyAccess
                      ? "OCD"
                      : (roleAcronymLabel(auth.user?.roles, auth.user?.office, auth.user?.position) || auth.user?.office || "DSWD")}
                </p>
              </div>
            </div>
          </div>
          <nav className="relative z-10 min-h-0 flex-1 space-y-1 overflow-y-auto overflow-x-hidden px-3 py-4">
            {isSuperAdmin ? (
              <>
                {standaloneSuperAdminNav.map((item) => renderNavItem(item))}
                <div className="my-2 h-px bg-gradient-to-r from-transparent via-brand-300/70 to-transparent dark:via-brand-700/50" />
                <div className="rounded-xl border border-brand-200/60 bg-white/70 p-1 shadow-sm backdrop-blur-sm dark:border-brand-900/40 dark:bg-zinc-900/50">
                  <button
                    type="button"
                    onClick={() => setOpenAccessGroups((current) => ({ ...current, userLevels: !current.userLevels }))}
                    className={clsx(
                      "flex w-full items-center rounded-lg px-2 py-2 text-left text-sm font-black text-slate-700 transition hover:bg-brand-50 hover:text-brand-800 dark:text-zinc-200 dark:hover:bg-brand-950/40 dark:hover:text-brand-100",
                      sidebarCollapsed ? "justify-center" : "gap-3",
                    )}
                    title={sidebarCollapsed ? "User Level Pages" : undefined}
                  >
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-white to-brand-50 text-brand-700 shadow-sm ring-1 ring-brand-100 dark:from-zinc-950 dark:to-brand-950 dark:text-brand-100 dark:ring-brand-800">
                      <UsersRound className="h-4 w-4" />
                    </span>
                    <span className={clsx("min-w-0 flex-1", sidebarCollapsed && "hidden")}>User Level Pages</span>
                    {!sidebarCollapsed && (openAccessGroups.userLevels ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />)}
                  </button>
                  {openAccessGroups.userLevels && !sidebarCollapsed && (
                    <div className="mt-1 border-l-2 border-brand-200 pl-2 dark:border-brand-300/30">
                      <p className="px-2 pb-1 pt-2 text-[10px] font-black uppercase tracking-wide text-slate-400">Navigate as user level</p>
                      <div className="grid gap-1 px-1 pb-2">
                        {superAdminAccessGroups.map((group) => (
                          <button
                            key={group.key}
                            type="button"
                            onClick={() => setSelectedAccessGroupKey(group.key)}
                            className={clsx(
                              "rounded-md px-2 py-1.5 text-left text-xs font-bold transition",
                              selectedAccessGroup.key === group.key
                                ? "bg-brand-700 text-white shadow-sm"
                                : "text-slate-600 hover:bg-brand-50 hover:text-brand-800 dark:text-zinc-300 dark:hover:bg-brand-950/40",
                            )}
                          >
                            {group.label}
                          </button>
                        ))}
                      </div>
                      <div className="space-y-1 border-t border-brand-100 pt-2 dark:border-brand-900/50">
                        {selectedAccessGroupItems.map((item) => renderNavItem(item, `user-level-${selectedAccessGroup.key}`))}
                      </div>
                    </div>
                  )}
                </div>
              </>
            ) : (
              visibleNav.map((item) => renderNavItem(item))
            )}
          </nav>
          <div
            className={clsx(
              "relative shrink-0 overflow-hidden border-t border-brand-200/60 bg-gradient-to-r from-white via-brand-50/50 to-emerald-50/40 px-3 py-3 text-center text-[10px] leading-tight text-slate-500 dark:border-brand-900/40 dark:from-zinc-950 dark:via-brand-950/20 dark:to-zinc-950 dark:text-zinc-400",
              sidebarCollapsed && "md:hidden",
            )}
          >
            <p>
              &copy; Copyright 2026
              <span className="mx-1.5" aria-hidden>•</span>
              All Rights Reserved
            </p>
            <p className="mt-1 font-semibold text-slate-600 dark:text-zinc-300">
              Developer: Roger L. Ongue, Computer Programmer I / DRIMS Head
            </p>
          </div>
        </div>
      </aside>

      <div
        className={clsx(
          "min-w-0 transition-all duration-200",
          sidebarCollapsed ? "md:ml-20" : "md:ml-72",
        )}
      >
        <header
          data-app-header
          className={clsx(
            "fixed left-0 right-0 top-0 z-40 overflow-visible border-b border-brand-200/70 bg-gradient-to-r from-white via-brand-50/40 to-emerald-50/30 px-3 py-2 backdrop-blur-xl transition-all duration-200 md:px-4 md:py-3 dark:border-brand-900/40 dark:from-zinc-950 dark:via-brand-950/20 dark:to-zinc-950",
            sidebarCollapsed ? "md:left-20" : "md:left-72",
          )}
        >
          <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden>
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full bg-brand-300/25 blur-2xl dark:bg-brand-600/15" />
            <div className="absolute bottom-0 left-1/3 h-16 w-40 rounded-full bg-emerald-200/30 blur-2xl dark:bg-emerald-700/10" />
            <div className="absolute inset-x-0 bottom-0 h-px overflow-hidden bg-brand-700/10 dark:bg-brand-300/15">
              <span className="shell-accent-sweep absolute inset-y-0 left-0 w-1/4 bg-gradient-to-r from-transparent via-brand-500/80 to-transparent dark:via-brand-300/70" />
            </div>
          </div>
          <div className="relative z-10 flex min-w-0 items-center justify-between gap-2 md:gap-4">
            <button
              type="button"
              onClick={() => {
                setSidebarCollapsed(false);
                setMobileSidebarOpen(true);
              }}
              aria-label="Open navigation menu"
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-brand-200 bg-white/80 text-brand-700 shadow-sm md:hidden dark:border-brand-800 dark:bg-zinc-900 dark:text-brand-100"
            >
              <Menu className="h-5 w-5" />
            </button>
            <div className="hidden h-16 shrink-0 items-center gap-3 bg-transparent xl:flex">
              <span className="relative block h-12 w-[122px] shrink-0 rounded-lg bg-white/50 p-1 ring-1 ring-brand-100/80 dark:bg-zinc-900/40 dark:ring-brand-900/50">
                <img
                  src="/images/dswd_logo_3.png"
                  alt="DSWD Field Office Caraga Region"
                  className="h-full w-full object-contain dark:hidden"
                />
                <img
                  src="/images/dswd_logo_white.png"
                  alt="DSWD Field Office Caraga Region"
                  className="hidden h-full w-full object-contain dark:block"
                />
              </span>
              <span className="relative block h-12 w-12 shrink-0 rounded-lg bg-white/50 p-1 ring-1 ring-brand-100/80 dark:bg-zinc-900/40 dark:ring-brand-900/50">
                <img
                  src="/images/Bagong_PilipinasTransparent.png"
                  alt="Bagong Pilipinas"
                  className="h-full w-full object-contain dark:hidden"
                />
                <img
                  src="/images/bagong_pilipinas_white.png"
                  alt="Bagong Pilipinas"
                  className="hidden h-full w-full object-contain dark:block"
                />
              </span>
            </div>
            <div className="min-w-0 flex-1">
              <p className="hidden text-[10px] font-black uppercase tracking-[0.18em] text-brand-700/80 sm:block dark:text-brand-200/80">
                Current workspace
              </p>
              <h1 className="mt-0.5 flex min-w-0 items-center gap-2 text-base font-bold leading-tight text-slate-950 sm:text-lg md:gap-3 md:text-xl dark:text-white">
                <span className="hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-brand-200/80 bg-gradient-to-br from-white to-brand-50 text-brand-700 shadow-sm sm:flex dark:border-brand-800 dark:from-zinc-900 dark:to-brand-950 dark:text-brand-100">
                  <HeaderIcon className="h-5 w-5" />
                </span>
                <span className="truncate">{title}</span>
              </h1>
              <p className="hidden truncate text-xs text-slate-500 sm:block dark:text-zinc-400">
                {[
                  (isLguAccess
                    ? lguWorkspaceLabel
                    : isAgencyAccess
                      ? "OCD"
                      : (roleAcronymLabel(auth.user?.roles, auth.user?.office, auth.user?.position) || auth.user?.office || "DSWD")),
                  auth.user?.name,
                  isLguAccess ? lguPersonnelRoleLabel : null,
                ].filter(Boolean).join(" · ")}
              </p>
            </div>
            <div className="relative z-20 flex shrink-0 items-center gap-1 overflow-visible sm:gap-2">
              {isRrosLevel && (
                <button
                  type="button"
                  disabled={witSyncing}
                  onClick={() => {
                    setWitSyncing(true);
                    router.post("/wit/sync", {}, {
                      preserveScroll: true,
                      onFinish: () => setWitSyncing(false),
                    });
                  }}
                  className="hidden h-10 items-center gap-2 rounded-xl border border-brand-200 bg-brand-700 px-3 text-xs font-black text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60 lg:inline-flex dark:border-brand-700 dark:bg-brand-600 dark:hover:bg-brand-500"
                >
                  <RefreshCw className={clsx("h-4 w-4", witSyncing && "animate-spin")} />
                  <span className="hidden xl:inline">Sync WIT</span>
                </button>
              )}
              <button
                type="button"
                onClick={() => setProfileOpen(true)}
                data-tip={isLguAccess ? "Open LGU profile" : isAgencyAccess ? "Open Agency Profile" : "Open DSWD employee profile"}
                data-tip-side="bottom"
                data-tip-align="right"
                data-tip-preferred-side="bottom"
                data-tip-locked="true"
                className={clsx(shellActionClass, "hidden border-emerald-200 text-brand-700 lg:inline-flex dark:border-brand-800")}
                aria-label={isLguAccess ? "Open LGU profile" : isAgencyAccess ? "Open Agency Profile" : "Open employee profile"}
              >
                <IdCard className="h-4 w-4" />
              </button>
              <div className="relative">
                <button
                  type="button"
                  onClick={() => {
                    setNotificationsOpen((open) => !open);
                    setMessageCenterOpen(false);
                  }}
                  aria-label="Notifications"
                  data-tip="Notifications"
                  data-tip-side="bottom"
                  data-tip-align="right"
                  className={clsx(shellActionClass, "relative")}
                >
                  <Bell className="h-4 w-4" />
                  {(notificationCenter?.unread_count ?? 0) > 0 && <span className="absolute -right-2 -top-2 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-black text-white shadow">{Math.min(notificationCenter?.unread_count ?? 0, 99)}</span>}
                </button>
                {notificationsOpen && (
                  <div className="absolute right-0 top-12 z-[80] w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-brand-200/70 bg-white shadow-2xl dark:border-brand-900/50 dark:bg-zinc-950">
                    <div className="flex items-center justify-between border-b border-brand-100 bg-gradient-to-r from-brand-50/80 to-white p-4 dark:border-brand-900/40 dark:from-brand-950/40 dark:to-zinc-950">
                      <div><p className="font-black">Notifications</p><p className="text-xs text-slate-500">{notificationCenter?.unread_count ?? 0} unread · {notificationCenter?.action_required_count ?? 0} awaiting action</p></div>
                      <div className="flex items-center gap-1">
                        {(notificationCenter?.unread_count ?? 0) > 0 && (
                          <button
                            type="button"
                            onClick={markAllNotificationsRead}
                            className="rounded-md px-2 py-1 text-xs font-black text-brand-700 hover:bg-brand-50 dark:text-brand-100 dark:hover:bg-brand-950/30"
                          >
                            Mark all read
                          </button>
                        )}
                        {isSuperAdmin && <button type="button" onClick={toggleNotificationSound} aria-label={soundEnabled ? "Disable voice alerts" : "Enable voice alerts"} data-tip={soundEnabled ? "Disable voice alerts" : "Enable voice alerts"} data-tip-side="bottom" data-tip-align="right" className="dromis-tip rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-zinc-900">{soundEnabled ? <Volume2 className="h-4 w-4" /> : <VolumeX className="h-4 w-4" />}</button>}
                      </div>
                    </div>
                    <div className="max-h-96 overflow-y-auto">
                      {(notificationCenter?.notifications ?? []).length ? notificationCenter.notifications.map((notification) => (
                        <button key={notification.id} type="button" onClick={() => openNotification(notification)} className={clsx("block w-full border-b border-slate-100 p-4 text-left transition dark:border-zinc-900", notification.action_required && notification.acted ? "cursor-default opacity-70" : "hover:bg-slate-50 dark:hover:bg-zinc-900", !notification.read_at && "bg-brand-50/70 dark:bg-brand-950/20")}>
                          <div className="flex items-start justify-between gap-3">
                            <p className="text-sm font-black">{notification.title}</p>
                            {notification.action_required && notification.acted && <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-100 dark:ring-emerald-800">Acted</span>}
                          </div>
                          <p className="mt-1 text-sm text-slate-600 dark:text-zinc-300">{notification.message}</p>
                          <p className="mt-2 text-xs text-slate-400">{notification.created_at ? formatDateTime(notification.created_at, "") : ""}</p>
                        </button>
                      )) : <p className="p-6 text-center text-sm text-slate-500">No notifications yet.</p>}
                    </div>
                  </div>
                )}
              </div>
              <div className="relative">
                <button
                  type="button"
                  onClick={() => {
                    setMessageCenterOpen((open) => !open);
                    setNotificationsOpen(false);
                  }}
                  aria-label="DROMIS Messages"
                  data-tip="DROMIS Messages"
                  data-tip-side="bottom"
                  data-tip-align="right"
                  className={clsx(shellActionClass, "relative hidden lg:inline-flex")}
                >
                  <MessageCircle className="h-4 w-4" />
                  {(messageCenter?.unread_count ?? 0) > 0 && (
                    <span className="absolute -right-2 -top-2 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-sky-600 px-1 text-[10px] font-black text-white shadow">
                      {Math.min(messageCenter.unread_count, 99)}
                    </span>
                  )}
                </button>
                {messageCenterOpen && (
                  <MessageCenterPanel
                    center={messageCenter}
                    draft={messageDraft}
                    setDraft={setMessageDraft}
                    loading={messageLoading}
                    sending={messageSending}
                    onSubmit={sendSystemMessage}
                    onRead={markSystemMessageRead}
                  />
                )}
              </div>
              <button
                type="button"
                onClick={toggleTheme}
                aria-label="Toggle dark mode"
                data-tip={dark ? "Use light mode" : "Use dark mode"}
                data-tip-side="bottom"
                data-tip-align="right"
                className={clsx(shellActionClass, "hidden lg:inline-flex")}
              >
                {dark ? (
                  <Sun className="h-4 w-4" />
                ) : (
                  <Moon className="h-4 w-4" />
                )}
              </button>
              <div className="relative lg:hidden">
                <button
                  type="button"
                  onClick={() => {
                    setCompactActionsOpen((open) => !open);
                    setNotificationsOpen(false);
                  }}
                  aria-label="More workspace actions"
                  className={shellActionClass}
                >
                  <MoreHorizontal className="h-5 w-5" />
                </button>
                {compactActionsOpen && (
                  <div className="absolute right-0 top-12 z-[90] w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                    {isRrosLevel && (
                      <button
                        type="button"
                        disabled={witSyncing}
                        onClick={() => {
                          setCompactActionsOpen(false);
                          setWitSyncing(true);
                          router.post("/wit/sync", {}, { preserveScroll: true, onFinish: () => setWitSyncing(false) });
                        }}
                        className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold hover:bg-slate-100 disabled:opacity-60 dark:hover:bg-zinc-900"
                      >
                        <RefreshCw className={clsx("h-4 w-4", witSyncing && "animate-spin")} /> Sync WIT inventory
                      </button>
                    )}
                    <button type="button" onClick={() => { setCompactActionsOpen(false); setProfileOpen(true); }} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold hover:bg-slate-100 dark:hover:bg-zinc-900">
                      <IdCard className="h-4 w-4" /> Account profile
                    </button>
                    <button type="button" onClick={() => { setCompactActionsOpen(false); setMessageCenterOpen(true); }} className="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold hover:bg-slate-100 dark:hover:bg-zinc-900">
                      <span className="flex items-center gap-3"><MessageCircle className="h-4 w-4" /> DROMIS Messages</span>
                      {(messageCenter?.unread_count ?? 0) > 0 && <span className="rounded-full bg-sky-600 px-2 py-0.5 text-[10px] text-white">{messageCenter.unread_count}</span>}
                    </button>
                    <button type="button" onClick={() => { setCompactActionsOpen(false); toggleTheme(); }} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold hover:bg-slate-100 dark:hover:bg-zinc-900">
                      {dark ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />} {dark ? "Use light mode" : "Use dark mode"}
                    </button>
                  </div>
                )}
              </div>
              <button
                type="button"
                onClick={() => router.post("/logout", {}, {
                  preserveScroll: false,
                  onSuccess: () => window.location.replace("/login"),
                })}
                aria-label="Sign out"
                data-tip="Sign out"
                data-tip-side="bottom"
                data-tip-align="right"
                className={shellActionClass}
              >
                <LogOut className="h-4 w-4" />
              </button>
            </div>
          </div>
        </header>
        <main className="min-w-0 overflow-x-hidden px-2 pb-[calc(6rem+env(safe-area-inset-bottom))] pt-20 sm:px-3 md:px-4 md:pt-28">
          {flash?.success && (
            <div className="mb-4 rounded-md border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-700 dark:border-brand-800 dark:bg-brand-950/50 dark:text-brand-100">
              {flash.success}
            </div>
          )}
          {flash?.error && (
            <div className="mb-4 rounded-md border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
              {flash.error}
            </div>
          )}
          {children}
        </main>
      </div>
      {selectedAccessRequest && (
        <AccessDecisionModal
          user={selectedAccessRequest}
          roleOptions={notificationCenter?.role_options ?? []}
          onClose={() => setSelectedAccessRequest(null)}
          onSuccess={refreshNotificationCenter}
        />
      )}
      {profileOpen && (
        isAgencyAccess
          ? <AgencyProfileModal user={auth.user} onClose={() => setProfileOpen(false)} />
          : <EmployeeProfileModal user={auth.user} onClose={() => setProfileOpen(false)} />
      )}
      {regionalAlertPrompt && (
        <RegionalAlertAttentionModal
          notification={regionalAlertPrompt}
          acknowledging={regionalAlertAcknowledging}
          canOpenReportingPage={isLguAccess}
          onAcknowledge={acknowledgeRegionalAlert}
        />
      )}
      {sessionWarningOpen && (
        <div className="fixed inset-0 z-[220] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="alertdialog" aria-modal="true" aria-label="Session timeout warning">
          <div className="w-full max-w-md rounded-xl border border-amber-200 bg-white p-5 shadow-2xl dark:border-amber-900/60 dark:bg-zinc-900">
            <h2 className="text-lg font-black text-slate-900 dark:text-zinc-50">Still working?</h2>
            <p className="mt-2 text-sm leading-relaxed text-slate-600 dark:text-zinc-300">
              Your session will end soon due to inactivity. Stay signed in if you are still preparing or verifying a report.
            </p>
            <p className="mt-3 text-sm font-semibold text-amber-700 dark:text-amber-300">
              Signing out in {Math.max(sessionWarningSecondsLeft, 0)}s
            </p>
            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                className="rounded-md bg-brand-700 px-4 py-2 text-sm font-bold text-white hover:bg-brand-800"
                onClick={() => window.__dromisStaySignedIn?.()}
              >
                Stay signed in
              </button>
            </div>
          </div>
        </div>
      )}
      <AiRogerWidget
        open={aiRogerOpen}
        onToggle={() => setAiRogerOpen((open) => !open)}
        messages={aiRogerMessages}
        draft={aiRogerDraft}
        setDraft={setAiRogerDraft}
        thinking={aiRogerThinking}
        onSubmit={askAiRoger}
      />
    </div>
  );
}

function RegionalAlertAttentionModal({
  notification,
  acknowledging,
  canOpenReportingPage,
  onAcknowledge,
}) {
  const level = String(notification.meta?.alert_level || "white").toLowerCase();
  const styles = {
    white: {
      badge: "bg-white text-slate-900 ring-slate-300",
      panel: "from-slate-700 via-slate-800 to-slate-950",
      accent: "bg-white",
      button: "bg-white text-slate-950 hover:bg-slate-100",
    },
    blue: {
      badge: "bg-blue-600 text-white ring-blue-300",
      panel: "from-blue-700 via-blue-800 to-slate-950",
      accent: "bg-blue-400",
      button: "bg-blue-500 text-white hover:bg-blue-400",
    },
    red: {
      badge: "bg-red-600 text-white ring-red-300",
      panel: "from-red-700 via-red-800 to-slate-950",
      accent: "bg-red-400",
      button: "bg-red-500 text-white hover:bg-red-400",
    },
  };
  const style = styles[level] || styles.white;
  const reportingTimes = notification.meta?.reporting_times ?? [];

  return (
    <div
      className="fixed inset-0 z-[200] flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-md"
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="regional-alert-title"
    >
      <div className="relative w-full max-w-2xl overflow-hidden rounded-3xl border border-white/20 bg-white shadow-[0_30px_100px_rgba(0,0,0,.55)] dark:bg-zinc-950">
        <div className={clsx("relative overflow-hidden bg-gradient-to-br px-6 py-7 text-white sm:px-8", style.panel)}>
          <div className={clsx("absolute left-0 top-0 h-full w-2", style.accent)} />
          <div className="absolute -right-16 -top-20 h-56 w-56 rounded-full border-[28px] border-white/5" />
          <div className="relative flex flex-col gap-5 sm:flex-row sm:items-start">
            <div className="relative flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-white/10 ring-4 ring-white/20">
              <span className="absolute inset-0 animate-ping rounded-full bg-white/10" />
              {level === "red" ? <AlertTriangle className="relative h-10 w-10" /> : <RadioTower className="relative h-10 w-10" />}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-xs font-black uppercase tracking-[.24em] text-white/75">OCD Caraga Regional Alert Update</p>
              <div className="mt-2 flex flex-wrap items-center gap-3">
                <h2 id="regional-alert-title" className="text-3xl font-black sm:text-4xl">{level.toUpperCase()} ALERT</h2>
                <span className={clsx("rounded-full px-3 py-1 text-xs font-black uppercase ring-2", style.badge)}>Attention required</span>
              </div>
              <p className="mt-3 text-base font-semibold leading-7 text-white/90">{notification.title}</p>
            </div>
          </div>
        </div>

        <div className="space-y-5 p-6 sm:p-8">
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
              <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Coverage</p>
              <p className="mt-1 font-black">{notification.meta?.coverage || "Caraga Region"}</p>
            </div>
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
              <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Incident</p>
              <p className="mt-1 font-black">{notification.meta?.incident_name || "Regional monitoring"}</p>
            </div>
          </div>

          <div className="rounded-xl border-l-4 border-amber-400 bg-amber-50 p-4 text-sm font-semibold leading-6 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
            {notification.message}
          </div>

          <div>
            <p className="text-xs font-black uppercase tracking-widest text-slate-500">LGU reporting schedule</p>
            <div className="mt-2 flex flex-wrap gap-2">
              {reportingTimes.length
                ? reportingTimes.map((time) => <span key={time} className="rounded-full bg-slate-900 px-4 py-2 text-sm font-black text-white dark:bg-white dark:text-slate-950">{time}</span>)
                : <span className="text-sm font-semibold text-slate-500">Follow the schedule stated in the alert notice.</span>}
            </div>
          </div>

          <p className="text-xs text-slate-500">
            Issued {notification.created_at ? formatDateTime(notification.created_at) : "by OCD Caraga"}
          </p>

          <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 dark:border-zinc-800 sm:flex-row sm:justify-end">
            {canOpenReportingPage && (
              <button type="button" disabled={acknowledging} onClick={() => onAcknowledge(true)} className="rounded-xl border-2 border-slate-300 px-5 py-3 text-sm font-black hover:bg-slate-50 disabled:opacity-60 dark:border-zinc-700 dark:hover:bg-zinc-900">
                Acknowledge & Open Reporting
              </button>
            )}
            <button type="button" disabled={acknowledging} onClick={() => onAcknowledge(false)} className={clsx("inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-black shadow-lg disabled:opacity-60", style.button)}>
              {acknowledging ? <LoaderCircle className="h-5 w-5 animate-spin" /> : <CheckCircle2 className="h-5 w-5" />}
              {acknowledging ? "Recording acknowledgment..." : "I Acknowledge This Alert"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function MessageCenterPanel({ center, draft, setDraft, loading, sending, onSubmit, onRead }) {
  const messages = center?.messages ?? [];
  const contacts = center?.contacts ?? [];

  return (
    <div className="absolute right-0 top-12 z-[80] w-[min(28rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
      <div className="border-b border-slate-200 bg-gradient-to-r from-sky-700 to-brand-700 p-4 text-white dark:border-zinc-800">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-white/15 ring-1 ring-white/25">
            <MessageCircle className="h-5 w-5" />
          </div>
          <div>
            <p className="font-black">DROMIS Messages</p>
            <p className="text-xs text-white/80">{center?.unread_count ?? 0} unread · send quick coordination notes</p>
          </div>
        </div>
      </div>

      <form noValidate onSubmit={onSubmit} className="space-y-2 border-b border-slate-200 bg-slate-50 p-4 dark:border-zinc-800 dark:bg-zinc-900/60">
        <div className="space-y-2">
          <select
            value={draft.recipient_id}
            onChange={(event) => setDraft((current) => ({ ...current, recipient_id: event.target.value }))}
            className="w-full rounded-xl text-sm"
          >
            <option value="">Send to...</option>
            {contacts.map((contact) => (
              <option key={contact.id} value={contact.id}>
                {contact.name} {contact.office ? `— ${contact.office}` : ""}
              </option>
            ))}
          </select>
          <input
            type="text"
            value={draft.subject}
            onChange={(event) => setDraft((current) => ({ ...current, subject: event.target.value }))}
            placeholder="Subject (optional)"
            maxLength={120}
            className="w-full rounded-xl text-sm"
          />
        </div>
        <div className="flex items-end gap-2">
          <textarea
            rows={2}
            value={draft.body}
            onChange={(event) => setDraft((current) => ({ ...current, body: event.target.value }))}
            placeholder="Type a message for coordination..."
            maxLength={3000}
            className="min-h-11 flex-1 resize-none rounded-xl text-sm"
          />
          <button
            type="submit"
            disabled={sending || !draft.recipient_id || !draft.body.trim()}
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-700 text-white shadow-sm transition hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50"
            title="Send message"
          >
            {sending ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
          </button>
        </div>
      </form>

      <div className="max-h-[24rem] overflow-y-auto">
        {loading && !messages.length ? (
          <div className="flex items-center justify-center gap-2 p-8 text-sm font-bold text-slate-500 dark:text-zinc-400">
            <LoaderCircle className="h-4 w-4 animate-spin" />
            Loading messages...
          </div>
        ) : messages.length ? (
          messages.map((message) => {
            const unread = !message.is_mine && !message.read_at;

            return (
              <button
                key={message.id}
                type="button"
                onClick={() => onRead(message)}
                className={clsx(
                  "block w-full border-b border-slate-100 p-4 text-left transition dark:border-zinc-900",
                  unread ? "bg-sky-50/80 dark:bg-sky-950/20" : "hover:bg-slate-50 dark:hover:bg-zinc-900",
                )}
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-black text-slate-950 dark:text-white">
                      {message.is_mine ? `To ${message.other_user?.name}` : message.other_user?.name}
                    </p>
                    <p className="truncate text-xs text-slate-500 dark:text-zinc-400">
                      {message.is_mine ? message.recipient?.office : message.sender?.office}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    {message.is_mine && <span className={clsx("rounded-full px-2 py-0.5 text-[10px] font-black uppercase", message.read_at ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200" : "bg-slate-100 text-slate-500 dark:bg-zinc-800 dark:text-zinc-300")}>{message.read_at ? "Seen" : "Sent"}</span>}
                    {unread && <span className="h-2.5 w-2.5 rounded-full bg-sky-600" />}
                  </div>
                </div>
                <p className="mt-2 text-sm font-bold text-slate-800 dark:text-zinc-100">{message.subject}</p>
                <p className="mt-1 line-clamp-3 whitespace-pre-wrap text-sm text-slate-600 dark:text-zinc-300">{message.body}</p>
                <p className="mt-2 text-xs text-slate-400">{message.created_at ? formatDateTime(message.created_at, "") : ""}</p>
              </button>
            );
          })
        ) : (
          <div className="p-8 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-sky-50 text-sky-700 dark:bg-sky-950/30 dark:text-sky-200">
              <Mail className="h-5 w-5" />
            </div>
            <p className="mt-3 text-sm font-black">No messages yet</p>
            <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">Start a quick coordination thread with another DROMIS user.</p>
          </div>
        )}
      </div>
    </div>
  );
}

function AiRogerWidget({ open, onToggle, messages, draft, setDraft, thinking, onSubmit }) {
  const listRef = useRef(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: "smooth" });
  }, [messages, open]);

  return (
    <div className="fixed bottom-[calc(.75rem+env(safe-area-inset-bottom))] right-3 z-40 sm:bottom-5 sm:right-5">
      {open && (
        <div className="mb-3 flex h-[min(38rem,calc(100vh-7rem))] w-[min(26rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
          <div className="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-brand-700 to-emerald-700 p-4 text-white dark:border-zinc-800">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-white/15 ring-1 ring-white/30">
                <Bot className="h-5 w-5" />
              </div>
              <div>
                <p className="font-black">AI Roger</p>
                <p className="text-xs text-white/80">Read-only DROMIS assistant</p>
              </div>
            </div>
            <button type="button" onClick={onToggle} className="rounded-md p-1.5 text-white/80 transition hover:bg-white/10 hover:text-white">
              <X className="h-4 w-4" />
            </button>
          </div>
          <div ref={listRef} className="min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50 p-4 dark:bg-zinc-900/60">
            {messages.map((message, index) => (
              <div key={`${message.role}-${index}`} className={clsx("flex", message.role === "user" ? "justify-end" : "justify-start")}>
                <div className={clsx(
                  "max-w-[85%] rounded-2xl px-3 py-2 text-sm leading-relaxed shadow-sm",
                  message.role === "user"
                    ? "rounded-br-sm bg-brand-700 text-white"
                    : "rounded-bl-sm border border-slate-200 bg-white text-slate-700 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-100",
                )}>
                  <p className="whitespace-pre-wrap">{message.content}</p>
                </div>
              </div>
            ))}
            {thinking && (
              <div className="flex justify-start">
                <div className="inline-flex items-center gap-2 rounded-2xl rounded-bl-sm border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-500 shadow-sm dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-300">
                  <LoaderCircle className="h-4 w-4 animate-spin" />
                  AI Roger is thinking...
                </div>
              </div>
            )}
          </div>
          <form onSubmit={onSubmit} className="border-t border-slate-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950">
            <label className="sr-only" htmlFor="ai-roger-message">Ask AI Roger</label>
            <div className="flex items-end gap-2">
              <textarea
                id="ai-roger-message"
                rows={2}
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === "Enter" && !event.shiftKey) {
                    event.preventDefault();
                    event.currentTarget.form?.requestSubmit();
                  }
                }}
                placeholder="Ask about DROMIS..."
                className="min-h-11 flex-1 resize-none rounded-xl text-sm"
              />
              <button
                type="submit"
                disabled={thinking || !draft.trim()}
                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-700 text-white shadow-sm transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50"
                title="Send to AI Roger"
              >
                {thinking ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
              </button>
            </div>
            <p className="mt-2 text-[11px] text-slate-400 dark:text-zinc-500">AI Roger can guide and draft, but cannot change records or approvals.</p>
          </form>
        </div>
      )}
      <button
        type="button"
        onClick={onToggle}
        className={clsx(
          "group relative ml-auto flex items-center gap-3 overflow-hidden rounded-full text-white shadow-2xl ring-4 ring-white transition hover:-translate-y-0.5 hover:scale-[1.02] dark:ring-zinc-950",
          open
            ? "h-14 w-14 justify-center bg-brand-800 shadow-brand-900/30"
            : "h-14 w-14 justify-center bg-gradient-to-r from-emerald-700 via-brand-700 to-sky-700 shadow-brand-900/30 2xl:min-h-14 2xl:w-auto 2xl:px-4 2xl:pr-5",
        )}
        title={open ? "Close AI Roger" : "Open AI Roger"}
      >
        {!open && (
          <>
            <span className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,0.32),transparent_30%),radial-gradient(circle_at_80%_0%,rgba(255,255,255,0.22),transparent_28%)] opacity-90" />
            <span className="absolute -left-4 top-1/2 h-20 w-20 -translate-y-1/2 rounded-full bg-white/10 blur-xl transition group-hover:translate-x-10" />
          </>
        )}
        <span className="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/15 ring-1 ring-white/30">
          {open ? <X className="h-5 w-5" /> : <Bot className="h-5 w-5" />}
          {!open && <span className="absolute -right-0.5 -top-0.5 h-3 w-3 animate-ping rounded-full bg-cyan-200 opacity-70" />}
        </span>
        {!open && (
          <span className="relative hidden text-left 2xl:block">
            <span className="flex items-center gap-1 text-sm font-black leading-tight">
              Ask AI Roger
              <Sparkles className="h-3.5 w-3.5 text-cyan-100" />
            </span>
            <span className="block text-[11px] font-semibold text-white/75">DROMIS guide & drafting help</span>
          </span>
        )}
      </button>
    </div>
  );
}

function EmployeeProfileModal({ user, onClose }) {
  const roles = user?.roles?.length ? user.roles.join(", ") : "-";
  const lguProfile = user?.lgu_profile || null;
  const isLguProfile = Boolean(lguProfile);
  const canEditLguProfile = Boolean(lguProfile?.can_edit);
  const lguEditScope = lguProfile?.scope || (canEditLguProfile ? "full" : "none");
  const isFullLguEditor = lguEditScope === "full";
  const isSelfLguEditor = lguEditScope === "self";
  const linkedLguIdentity = lguProfile?.linked || null;
  const flash = usePage().props?.flash || {};
  const issuedLguCredentials = Array.isArray(flash.lgu_issued_credentials) ? flash.lgu_issued_credentials : [];
  const identity = user?.identity_profile || {};
  const identityData = identity?.data || {};
  const identitySource = identity?.source || "Caraga Connect SSO";
  const checkedAt = identity?.checked_at || user?.sso_profile?.sso_checked_at;
  const hasIdentityData = Object.keys(identityData || {}).length > 0;
  const [syncing, setSyncing] = useState(false);
  const [visiblePasswords, setVisiblePasswords] = useState({});
  const [avatarFailed, setAvatarFailed] = useState(false);
  const [activeProfileArea, setActiveProfileArea] = useState("overview");
  const [occupiedAorScopes, setOccupiedAorScopes] = useState([]);
  const [aorEditing, setAorEditing] = useState(false);
  const [aorSelectionValid, setAorSelectionValid] = useState(false);
  const [aorSelectionErrors, setAorSelectionErrors] = useState([]);
  const [aorClientError, setAorClientError] = useState("");
  const activeRegionCode = usePage().props?.activeRegion?.code || "1600000000";
  const aorRoles = ["DRRS", "DRIMS", "Super Admin"];
  const aorEnabled = user?.roles?.some((role) => aorRoles.includes(role)) ?? false;
  const initialAorData = {
    aor_provinces: Array.isArray(user?.aor_provinces) ? user.aor_provinces : [],
    aor_districts: Array.isArray(user?.aor_districts) ? user.aor_districts : [],
    aor_cities_municipalities: Array.isArray(user?.aor_cities_municipalities) ? user.aor_cities_municipalities : [],
  };
  const aorForm = useForm(initialAorData);
  const pageErrors = usePage().props?.errors || {};
  const aorError = aorClientError || pageErrors.aor || aorForm.errors?.aor || aorForm.errors?.aor_provinces || aorForm.errors?.aor_districts || aorForm.errors?.aor_cities_municipalities;
  useEffect(() => {
    if (!user) {
      return;
    }

    const nextData = {
      aor_provinces: Array.isArray(user?.aor_provinces) ? user.aor_provinces : [],
      aor_districts: Array.isArray(user?.aor_districts) ? user.aor_districts : [],
      aor_cities_municipalities: Array.isArray(user?.aor_cities_municipalities) ? user.aor_cities_municipalities : [],
    };

    if (JSON.stringify(aorForm.data) !== JSON.stringify(nextData)) {
      aorForm.setData(nextData);
    }
  }, [user?.aor_provinces, user?.aor_districts, user?.aor_cities_municipalities]);
  useEffect(() => {
    const hasSavedAor = Boolean(
      (user?.aor_provinces?.length ?? 0)
      || (user?.aor_districts?.length ?? 0)
      || (user?.aor_cities_municipalities?.length ?? 0),
    );

    setAorEditing((current) => (hasSavedAor ? false : current || !hasSavedAor));
  }, [user?.aor_provinces, user?.aor_districts, user?.aor_cities_municipalities]);
  const passwordForm = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
  });
  const profileValue = (value) =>
    value === null || value === undefined || String(value).trim() === "" ? "-" : value;
  const normalizeLguLevel = (level) => {
    const normalized = String(level || "").toLowerCase();
    if (normalized === "province" || normalized === "plgu") return "PLGU";
    if (normalized === "city" || normalized === "city_municipality" || normalized === "clgu") return "CLGU";
    if (normalized === "municipality" || normalized === "mlgu") return "MLGU";
    return level || "LGU";
  };
  const field = (...keys) => {
    for (const key of keys) {
      const value = identityData?.[key] ?? user?.[key];
      if (value !== null && value !== undefined && String(value).trim() !== "") {
        return value;
      }
    }
    return null;
  };
  const refreshMyPortal = () => {
    setSyncing(true);
    router.post("/profile/myportal/sync", {}, {
      preserveScroll: true,
      onSuccess: () => router.reload({ only: ["auth", "flash"], preserveScroll: true }),
      onFinish: () => setSyncing(false),
    });
  };
  const updatePassword = (event) => {
    event.preventDefault();
    passwordForm.patch("/settings/password", {
      preserveScroll: true,
      onSuccess: () => passwordForm.reset(),
    });
  };
  const sanitizeAorValues = (value) => {
    const items = Array.isArray(value) ? value : [];
    return [...new Set(items
      .filter((item) => item !== null && item !== undefined && String(item).trim() !== "")
      .map((item) => String(item).trim())
      .filter(Boolean))];
  };
  const submitAor = (event) => {
    event.preventDefault();
    setAorClientError("");

    const payload = {
      aor_provinces: sanitizeAorValues(aorForm.data.aor_provinces),
      aor_districts: sanitizeAorValues(aorForm.data.aor_districts),
      aor_cities_municipalities: sanitizeAorValues(aorForm.data.aor_cities_municipalities),
    };

    if (!payload.aor_provinces.length || !payload.aor_districts.length || !payload.aor_cities_municipalities.length || !aorSelectionValid) {
      const message = aorSelectionErrors[0]
        || (!payload.aor_provinces.length
          ? "Province is required. Select at least one province."
          : !payload.aor_districts.length
            ? "District is required. Select at least one district for each selected province."
            : "Municipality / City is required. Select at least one city/municipality for each selected district.");
      setAorClientError(message);
      return;
    }

    aorForm.setData(payload);
    aorForm.post("/profile/aor", {
      preserveScroll: true,
      onSuccess: () => {
        setAorEditing(false);
        setAorClientError("");
        router.reload({ only: ["auth", "flash"], preserveScroll: true });
      },
    });
  };
  const hasSavedAor = Boolean((user?.aor_provinces?.length ?? 0) || (user?.aor_districts?.length ?? 0) || (user?.aor_cities_municipalities?.length ?? 0));
  useEffect(() => {
    if (!aorEnabled) {
      setOccupiedAorScopes([]);
      return;
    }

    const fetchOccupiedAorScopes = async () => {
      try {
        const response = await fetch("/profile/aor/occupied-scopes", {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });

        if (!response.ok) {
          setOccupiedAorScopes([]);
          return;
        }

        const payload = await response.json();
        setOccupiedAorScopes(Array.isArray(payload) ? payload : []);
      } catch (error) {
        setOccupiedAorScopes([]);
      }
    };

    fetchOccupiedAorScopes();
  }, [aorEnabled, user?.id]);
  const displayName = field("fullname", "name") || user?.name || "SSO User";
  const employeeId = field("id_number", "employee_id", "id");
  const avatar = user?.avatar || field("saved_image_path", "image_path", "avatar") || null;
  const lguLevelLabel = normalizeLguLevel(lguProfile?.level);
  const lguChief = lguProfile?.officials?.lce || {};
  const lguLswd = lguProfile?.officials?.lswd_officer || {};
  const lguLswdAlternate = lguProfile?.officials?.lswd_officer_alternate || {};
  const lguLswdoAlternates = Array.isArray(lguProfile?.lswdo_alternates) && lguProfile.lswdo_alternates.length
    ? lguProfile.lswdo_alternates
    : [{
        name: lguLswdAlternate?.name || "",
        position: lguLswdAlternate?.position || "",
        contact_number: lguLswdAlternate?.phone || "",
        id_number: lguLswdAlternate?.id_number || "",
      }];
  const emptyLguStaffRow = () => ({
    office: lguProfile?.name || user?.lgu_name || "",
    name: "",
    position: "",
    id_number: "",
    contact_number: "",
    email: "",
    login_username: "",
    login_password: "",
    link_user_id: null,
    linked_from: "",
  });
  const lguLinkablePersonnel = Array.isArray(lguProfile?.linkable_personnel) ? lguProfile.linkable_personnel : [];
  const resolveLinkedSelfDefaults = () => {
    const linked = linkedLguIdentity;
    if (!linked) {
      return {
        self_name: user?.name || "",
        self_position: user?.position || "",
        self_id_number: user?.id_number || "",
        self_contact_number: user?.contact_number || user?.mobile_no || "",
        self_email: user?.email || "",
        self_office: lguProfile?.name || user?.lgu_name || "",
        login_password: "",
        login_password_confirmation: "",
      };
    }

    if (linked.type === "staff") {
      const pool = [
        ...(lguProfile?.dromic_encoders || []),
        ...(lguProfile?.warehouse_focals || []),
        ...(lguProfile?.warehouse_storekeepers || []),
        ...(lguProfile?.drivers || []),
      ];
      const member = pool.find((row) => Number(row.id) === Number(linked.id)) || {};
      return {
        self_name: member.name || user?.name || "",
        self_position: member.position || "",
        self_id_number: member.id_number || "",
        self_contact_number: member.contact_number || "",
        self_email: member.email || user?.email || "",
        self_office: member.office || lguProfile?.name || "",
        login_password: "",
        login_password_confirmation: "",
      };
    }

    if (linked.type === "lswdo_alternate") {
      const alternate = (lguProfile?.lswdo_alternates || []).find((row) => Number(row.id) === Number(linked.id)) || {};
      return {
        self_name: alternate.name || user?.name || "",
        self_position: alternate.position || "",
        self_id_number: alternate.id_number || "",
        self_contact_number: alternate.contact_number || "",
        self_email: alternate.email || user?.email || "",
        self_office: lguProfile?.name || "",
        login_password: "",
        login_password_confirmation: "",
      };
    }

    if (linked.type === "ldrrmo_officer") {
      const officer = (lguProfile?.ldrrmo?.officers || []).find((row) => Number(row.id) === Number(linked.id)) || {};
      return {
        self_name: officer.name || user?.name || "",
        self_position: officer.designation || "",
        self_id_number: officer.id_number || "",
        self_contact_number: officer.mobile_number || "",
        self_email: officer.email_address || user?.email || "",
        self_office: officer.office || lguProfile?.name || "",
        login_password: "",
        login_password_confirmation: "",
      };
    }

    const roleKey = linked.role === "lce"
      ? "lce"
      : linked.role === "lswd_officer"
        ? "lswd_officer"
        : linked.role === "lswd_officer_alternate"
          ? "lswd_officer_alternate"
          : null;
    const official = roleKey ? (lguProfile?.officials?.[roleKey] || {}) : {};
    return {
      self_name: official.name || user?.name || "",
      self_position: official.position || "",
      self_id_number: official.id_number || "",
      self_contact_number: official.phone || "",
      self_email: official.email || user?.email || "",
      self_office: lguProfile?.name || "",
      login_password: "",
      login_password_confirmation: "",
    };
  };
  const withLinkMeta = (rows) => rows.map((row) => ({
    ...row,
    link_user_id: row.link_user_id ?? row.user_id ?? null,
    linked_from: row.linked_from || "",
    login_password: row.login_password || "",
  }));
  const lguDromicEncoders = Array.isArray(lguProfile?.dromic_encoders) && lguProfile.dromic_encoders.length
    ? withLinkMeta(lguProfile.dromic_encoders)
    : [emptyLguStaffRow()];
  const lguWarehouseFocals = Array.isArray(lguProfile?.warehouse_focals) && lguProfile.warehouse_focals.length
    ? withLinkMeta(lguProfile.warehouse_focals)
    : [emptyLguStaffRow()];
  const lguWarehouseStorekeepers = Array.isArray(lguProfile?.warehouse_storekeepers) && lguProfile.warehouse_storekeepers.length
    ? withLinkMeta(lguProfile.warehouse_storekeepers)
    : [emptyLguStaffRow()];
  const lguHasWarehouses = Boolean(lguProfile?.has_warehouses);
  const lguDrivers = Array.isArray(lguProfile?.drivers) && lguProfile.drivers.length
    ? withLinkMeta(lguProfile.drivers)
    : [emptyLguStaffRow()];
  const lguLdrrmo = lguProfile?.ldrrmo || {};
  const lguLdrrmoOfficers = Array.isArray(lguLdrrmo?.officers) && lguLdrrmo.officers.length
    ? [...lguLdrrmo.officers].sort((left, right) => Number(Boolean(right.is_primary)) - Number(Boolean(left.is_primary)))
    : [{
        name: lguLdrrmo?.name || "",
        designation: lguLdrrmo?.position || "",
        id_number: lguLdrrmo?.id_number || "",
        mobile_number: lguLdrrmo?.contact || "",
        email_address: lguLdrrmo?.email || "",
        facebook: lguLdrrmo?.facebook || "",
        vhf_radio_frequency: lguLdrrmo?.vhf || "",
        is_primary: true,
      }];
  const lguLogoUrl = lguProfile?.logo_url || null;
  const ldrrmcLogoUrl = lguProfile?.ldrrmc_logo_url || null;
  const lguOfficialPhotos = {
    lce: lguProfile?.official_photos?.lce || lguProfile?.photos?.lce || lguProfile?.lce_photo_url || null,
    lswd: lguProfile?.official_photos?.lswd_officer || lguLswd?.photo_url || lguProfile?.photos?.lswd_officer || lguProfile?.lswd_photo_url || null,
    ldrrmo: lguLdrrmo?.photo_url || lguProfile?.official_photos?.ldrrmo || lguProfile?.photos?.ldrrmo || lguProfile?.ldrrmo_photo_url || null,
  };
  const [editingLguProfile, setEditingLguProfile] = useState(false);
  const [lguLogoPreview, setLguLogoPreview] = useState(null);
  const [ldrrmcLogoPreview, setLdrrmcLogoPreview] = useState(null);
  const [lguPhotoPreviews, setLguPhotoPreviews] = useState({});
  useEffect(() => {
    if (!canEditLguProfile) {
      setEditingLguProfile(false);
    }
  }, [canEditLguProfile]);
  const lguProfileForm = useForm({
    lgu_name: lguProfile?.name || user?.lgu_name || user?.name || "",
    province_name: lguProfile?.province_name || "",
    managed_district_name: lguProfile?.managed_district_name || "",
    lce_name: lguChief?.name || "",
    lce_position: lguChief?.position || "",
    lce_id_number: lguChief?.id_number || "",
    lce_email: lguChief?.email || "",
    lce_phone: lguChief?.phone || "",
    lce_login_username: lguChief?.login_username || "",
    lce_login_password: "",
    lswd_name: lguLswd?.name || "",
    lswd_position: lguLswd?.position || "",
    lswd_id_number: lguLswd?.id_number || "",
    lswd_email: lguLswd?.email || "",
    lswd_phone: lguLswd?.phone || "",
    lswd_facebook: lguLswd?.facebook || "",
    lswd_login_username: lguLswd?.login_username || "",
    lswd_login_password: "",
    lswd_alt_name: lguLswdAlternate?.name || "",
    lswd_alt_position: lguLswdAlternate?.position || "",
    lswd_alt_email: lguLswd?.alternate_email || "",
    lswd_alt_phone: lguLswdAlternate?.phone || "",
    lswdo_alternates: lguLswdoAlternates,
    ldrrmo_name: lguLdrrmo?.name || "",
    ldrrmo_position: lguLdrrmo?.position || "",
    ldrrmo_contact: lguLdrrmo?.contact || "",
    ldrrmo_email: lguLdrrmo?.email || "",
    ldrrmo_facebook: lguLdrrmo?.facebook || "",
    ldrrmo_vhf: lguLdrrmo?.vhf || "",
    ldrrmo_officers: lguLdrrmoOfficers,
    dromic_encoders: lguDromicEncoders,
    warehouse_focals: lguWarehouseFocals,
    warehouse_storekeepers: lguWarehouseStorekeepers,
    drivers: lguDrivers,
    logo: null,
    ldrrmc_logo: null,
    lce_photo: null,
    lswd_photo: null,
    ldrrmo_photo: null,
    ...resolveLinkedSelfDefaults(),
  });
  useEffect(() => () => {
    if (lguLogoPreview) {
      URL.revokeObjectURL(lguLogoPreview);
    }
  }, [lguLogoPreview]);
  useEffect(() => () => {
    if (ldrrmcLogoPreview) {
      URL.revokeObjectURL(ldrrmcLogoPreview);
    }
  }, [ldrrmcLogoPreview]);
  useEffect(() => () => {
    Object.values(lguPhotoPreviews).forEach((url) => {
      if (url) URL.revokeObjectURL(url);
    });
  }, [lguPhotoPreviews]);
  const handleLguLogoChange = (event) => {
    const file = event.target.files?.[0] || null;
    lguProfileForm.setData("logo", file);

    if (lguLogoPreview) {
      URL.revokeObjectURL(lguLogoPreview);
    }

    setLguLogoPreview(file ? URL.createObjectURL(file) : null);
  };
  const handleLdrrmcLogoChange = (event) => {
    const file = event.target.files?.[0] || null;
    lguProfileForm.setData("ldrrmc_logo", file);

    if (ldrrmcLogoPreview) {
      URL.revokeObjectURL(ldrrmcLogoPreview);
    }

    setLdrrmcLogoPreview(file ? URL.createObjectURL(file) : null);
  };
  const updateLguLdrrmoOfficer = (index, key, value) => {
    const officers = [...lguProfileForm.data.ldrrmo_officers];
    officers[index] = { ...officers[index], [key]: value };
    lguProfileForm.setData("ldrrmo_officers", officers);
  };
  const updateLguLswdoAlternate = (index, key, value) => {
    const alternates = [...lguProfileForm.data.lswdo_alternates];
    alternates[index] = { ...alternates[index], [key]: value };
    lguProfileForm.setData("lswdo_alternates", alternates);
  };
  const updateLguStaffMember = (field, index, key, value) => {
    const rows = [...(lguProfileForm.data[field] || [])];
    rows[index] = { ...rows[index], [key]: value };
    lguProfileForm.setData(field, rows);
  };
  const applyLinkedLguPersonnel = (field, memberIndex, personKey) => {
    const person = lguLinkablePersonnel.find((row) => row.key === personKey);
    const rows = [...(lguProfileForm.data[field] || [])];
    if (!person) {
      rows[memberIndex] = {
        ...rows[memberIndex],
        linked_from: "",
        link_user_id: null,
      };
      lguProfileForm.setData(field, rows);
      return;
    }

    const defaultPosition = field === "warehouse_focals"
      ? "Warehouse Focal"
      : field === "warehouse_storekeepers"
        ? "Warehouse Storekeeper"
        : (person.position || "");

    rows[memberIndex] = {
      ...rows[memberIndex],
      linked_from: person.key,
      link_user_id: person.user_id || null,
      name: person.name || "",
      office: person.office || rows[memberIndex].office || lguProfileForm.data.lgu_name || "",
      position: person.position || defaultPosition,
      id_number: person.id_number || "",
      contact_number: person.contact_number || "",
      email: person.email || "",
      login_username: person.login_username || "",
      login_password: "",
    };
    lguProfileForm.setData(field, rows);
  };
  const addLguStaffMember = (field) => {
    lguProfileForm.setData(field, [
      ...(lguProfileForm.data[field] || []),
      {
        office: lguProfileForm.data.lgu_name || "",
        name: "",
        position: "",
        id_number: "",
        contact_number: "",
        email: "",
        login_username: "",
        login_password: "",
        link_user_id: null,
        linked_from: "",
      },
    ]);
  };
  const handleLguPhotoChange = (field, event) => {
    const file = event.target.files?.[0] || null;
    lguProfileForm.setData(field, file);

    setLguPhotoPreviews((current) => {
      if (current[field]) URL.revokeObjectURL(current[field]);
      return {
        ...current,
        [field]: file ? URL.createObjectURL(file) : null,
      };
    });
  };
  const submitLguProfile = (event) => {
    event.preventDefault();
    const payload = isSelfLguEditor
      ? {
          self_name: lguProfileForm.data.self_name,
          self_position: lguProfileForm.data.self_position,
          self_id_number: lguProfileForm.data.self_id_number,
          self_contact_number: lguProfileForm.data.self_contact_number,
          self_email: lguProfileForm.data.self_email,
          self_office: lguProfileForm.data.self_office,
          login_password: lguProfileForm.data.login_password,
          login_password_confirmation: lguProfileForm.data.login_password_confirmation,
        }
      : undefined;
    lguProfileForm.transform((data) => (payload ? payload : data)).post("/lgu/profile", {
      forceFormData: !isSelfLguEditor,
      preserveScroll: true,
      onSuccess: () => {
        setEditingLguProfile(false);
        setLguLogoPreview(null);
        setLdrrmcLogoPreview(null);
        lguProfileForm.setData("logo", null);
        lguProfileForm.setData("ldrrmc_logo", null);
        lguProfileForm.setData("lce_photo", null);
        lguProfileForm.setData("lswd_photo", null);
        lguProfileForm.setData("ldrrmo_photo", null);
        lguProfileForm.setData("lce_login_password", "");
        lguProfileForm.setData("lswd_login_password", "");
        lguProfileForm.setData("login_password", "");
        lguProfileForm.setData("login_password_confirmation", "");
        setLguPhotoPreviews({});
        router.reload({ only: ["auth", "flash"], preserveScroll: true });
      },
      onFinish: () => lguProfileForm.transform((data) => data),
    });
  };
  const lguInfoFields = [
    ["Name of LGU", lguProfile?.name],
    ["LGU Level", lguLevelLabel],
    ["Province", lguProfile?.province_name],
    ["District", lguProfile?.managed_district_name || lguProfile?.province_district_label],
    ["PSGC Code", lguProfile?.psgc_code],
  ];
  const lguAccessFields = [
    ["Username", user?.username],
    ["Email", user?.email],
    ["User Level", roles],
    ["Access Status", user?.access_status],
  ];
  const renderPasswordInput = (label, name, autoComplete) => {
    const visible = Boolean(visiblePasswords[name]);

    return (
      <label key={name} className="block text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">
        {label}
        <span className="relative mt-1 block">
          <input
            type={visible ? "text" : "password"}
            value={passwordForm.data[name]}
            onChange={(event) => passwordForm.setData(name, event.target.value)}
            className="w-full pr-10 text-sm normal-case tracking-normal"
            autoComplete={autoComplete}
          />
          <button
            type="button"
            onClick={() => setVisiblePasswords((current) => ({ ...current, [name]: !visible }))}
            data-tip={visible ? `Hide ${label}` : `Show ${label}`}
            data-tip-side="right"
            className="dromis-tip absolute inset-y-0 right-2 my-auto flex h-8 w-8 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-white"
            aria-label={visible ? `Hide ${label}` : `Show ${label}`}
          >
            {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </button>
        </span>
        {passwordForm.errors[name] && <span className="mt-1 block text-xs font-bold normal-case text-red-600 dark:text-red-400">{passwordForm.errors[name]}</span>}
      </label>
    );
  };
  const renderLguFieldLabel = (label, required = false) => (
    <>
      {label}
      {required ? (
        <span className="text-rose-600" aria-hidden="true"> *</span>
      ) : (
        <span className="ml-1 font-normal normal-case tracking-normal text-slate-400">(optional)</span>
      )}
    </>
  );
  const renderLguInput = (label, name, type = "text", required = false) => (
    <label key={name} className="block text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">
      {renderLguFieldLabel(label, required)}
      <input
        type={type}
        required={required}
        value={lguProfileForm.data[name] || ""}
        onChange={(event) => lguProfileForm.setData(name, event.target.value)}
        className="mt-1 w-full text-sm normal-case tracking-normal"
        autoComplete={type === "password" ? "new-password" : undefined}
      />
      {lguProfileForm.errors[name] && (
        <span className="mt-1 block text-xs font-bold normal-case text-red-600 dark:text-red-400">
          {lguProfileForm.errors[name]}
        </span>
      )}
    </label>
  );
  const renderLoginCredentialPair = (usernameName, passwordName, usernameValue, onUsernameChange, onPasswordChange, passwordValue = "") => (
    <div className="grid gap-3 rounded-md border border-dashed border-emerald-200 bg-emerald-50/70 p-3 sm:grid-cols-2 sm:col-span-2 dark:border-emerald-900 dark:bg-emerald-950/30">
      <p className="text-[10px] font-black uppercase tracking-wide text-emerald-800 sm:col-span-2 dark:text-emerald-100">
        Portal login identity
      </p>
      <label className="text-xs font-black text-slate-700 dark:text-zinc-200">
        {renderLguFieldLabel("Login username", false)}
        <input
          value={usernameValue || ""}
          onChange={(event) => onUsernameChange(event.target.value)}
          className="mt-1 w-full"
          autoComplete="off"
        />
        {lguProfileForm.errors[usernameName] && (
          <span className="mt-1 block text-xs font-bold normal-case text-red-600">{lguProfileForm.errors[usernameName]}</span>
        )}
      </label>
      <label className="text-xs font-black text-slate-700 dark:text-zinc-200">
        {renderLguFieldLabel("Set / reset password", false)}
        <input
          type="password"
          value={passwordValue || ""}
          onChange={(event) => onPasswordChange(event.target.value)}
          className="mt-1 w-full"
          autoComplete="new-password"
          placeholder="Leave blank to keep current"
        />
        {lguProfileForm.errors[passwordName] && (
          <span className="mt-1 block text-xs font-bold normal-case text-red-600">{lguProfileForm.errors[passwordName]}</span>
        )}
      </label>
    </div>
  );
  const renderLguSection = (title, fields) => (
    <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
      <div className="mb-3 flex items-center gap-2">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
          <UserCog className="h-4 w-4" />
        </span>
        <h3 className="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-zinc-100">{title}</h3>
      </div>
      <div className="grid gap-3 md:grid-cols-2">{fields}</div>
    </section>
  );
  const renderPhotoUpload = (label, field, fallbackUrl, required = false) => {
    const previewUrl = lguPhotoPreviews[field] || fallbackUrl;

    return (
      <label className="flex items-center gap-3 rounded-lg border border-dashed border-brand-200 bg-white p-3 text-xs font-black uppercase tracking-wide text-brand-800 transition hover:border-brand-400 hover:bg-brand-50 dark:border-brand-900 dark:bg-zinc-900 dark:text-brand-100 dark:hover:bg-brand-950">
        <span className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-50 text-brand-700 ring-1 ring-brand-100 dark:bg-brand-950 dark:text-brand-100 dark:ring-brand-900">
          {previewUrl ? (
            <img src={previewUrl} alt={label} className="h-full w-full object-cover" />
          ) : (
            <UserRound className="h-5 w-5" />
          )}
        </span>
        <span className="min-w-0">
          <span className="block">{renderLguFieldLabel(label, required)}</span>
          <span className="mt-0.5 block text-[10px] font-bold normal-case text-slate-500 dark:text-zinc-400">
            Click to upload or replace photo
          </span>
        </span>
        <input
          type="file"
          accept="image/*"
          onChange={(event) => handleLguPhotoChange(field, event)}
          className="sr-only"
        />
      </label>
    );
  };
  const renderLogoPreview = (label, previewUrl, savedUrl) => (
    <div className="group/logo relative z-10 flex h-24 w-24 shrink-0 items-center justify-center overflow-visible">
      {previewUrl || savedUrl ? (
        <img
          src={previewUrl || savedUrl}
          alt={label}
          className="max-h-24 max-w-24 object-contain drop-shadow-sm transition duration-300 ease-out group-hover/logo:scale-[1.7] group-hover/logo:drop-shadow-2xl"
        />
      ) : (
        <div className="flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-brand-200 bg-white p-2 text-center text-xs font-black leading-tight text-brand-700 shadow-sm dark:border-brand-900 dark:bg-zinc-900 dark:text-brand-100">
          <span>{label}<br /><span className="text-[10px] text-slate-400">Temporary</span></span>
        </div>
      )}
    </div>
  );
  const OfficialProfileCard = ({ title, photo, name, fields = [], children }) => (
    <div className="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
      <div className="flex items-start gap-3">
        <span className="relative z-0 flex h-20 w-20 shrink-0 origin-center items-center justify-center overflow-hidden rounded-full bg-white text-brand-700 shadow-sm ring-1 ring-slate-200 transition duration-300 ease-out hover:z-20 hover:scale-[1.6] hover:shadow-2xl dark:bg-zinc-950 dark:text-brand-100 dark:ring-zinc-800">
          {photo ? (
            <img src={photo} alt={name || title} className="h-full w-full object-cover" />
          ) : (
            <UserRound className="h-7 w-7" />
          )}
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">{title}</p>
          <h4 className="mt-1 text-base font-black text-slate-900 dark:text-white">{profileValue(name)}</h4>
        </div>
      </div>
      <dl className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {fields.map(([label, value]) => (
          <div key={label} className="rounded-md bg-white p-2.5 dark:bg-zinc-950">
            <dt className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</dt>
            <dd className="mt-1 whitespace-pre-line break-words text-sm font-bold text-slate-800 dark:text-zinc-100">{profileValue(value)}</dd>
          </div>
        ))}
      </dl>
      {children}
    </div>
  );
  const cardGroups = [
    {
      title: "Assignment",
      icon: UserCog,
      fields: [
        ["Division", field("division")],
        ["Section", field("section", "office")],
        ["Area of Assignment", field("area_of_assignment")],
        ["Project", field("project")],
        ["Fund Source", field("fundsource")],
      ],
    },
    {
      title: "Employment",
      icon: IdCard,
      fields: [
        ["Position", field("position")],
        ["Employment Status", field("empstatus", "employment_status")],
        ["Salary Grade", field("salary_grade")],
        ["Step Increment", field("step_inc")],
        ["Item Number", field("item_number")],
        ["Former Incumbent", field("former_incumbent")],
        ["Date Vacated", field("date_vacated")],
      ],
    },
    {
      title: "Account & Compensation",
      icon: BadgeDollarSign,
      fields: [
        ["Account Number", field("account_number")],
        ["Salary Rate", field("salary_rate")],
        ["Official Email", field("official_email")],
        ["Personal Email", field("email", "mail")],
      ],
    },
    {
      title: "Personal Details",
      icon: UserRound,
      fields: [
        ["Gender", field("gender")],
        ["Birthdate", field("birthdate")],
        ["Contact Number", field("contact", "contact_number", "mobile_no")],
        ["Address", field("address")],
        ["Course", field("course")],
      ],
    },
    {
      title: "DROMIS Access",
      icon: UsersRound,
      fields: [
        ["DROMIS User Level", roles],
        ["Access Status", user?.access_status],
        ["SSO Subject", user?.sso_sub],
        ["Profile Source", identitySource],
        ["Latest Check", checkedAt],
      ],
    },
  ];

  return (
        <div className="fixed inset-0 z-[1100] flex items-start justify-center overflow-y-auto overflow-x-hidden bg-slate-950/50 p-4 py-6 backdrop-blur-sm sm:items-center" role="dialog" aria-modal="true" aria-label="Employee profile">
      <div className="flex max-h-[calc(100vh-3rem)] w-full max-w-5xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
        <div className="shrink-0 flex items-start justify-between border-b border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
          <div className="min-w-0">
            {!isLguProfile && <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">{identitySource} profile</p>}
            <h2 className="mt-1 truncate text-xl font-black text-slate-950 dark:text-white">{isLguProfile ? "LGU Profile" : "Employee Profile"}</h2>
          </div>
          <div className="flex items-center gap-2">
            {isLguProfile && canEditLguProfile && (
              <button
                type="button"
                onClick={() => setEditingLguProfile((current) => !current)}
                title="Edit LGU profile"
                aria-label="Edit LGU profile"
                data-tip="Edit LGU profile"
                data-tip-side="right"
                className="dromis-tip rounded-md border border-brand-100 bg-white p-2 text-brand-700 shadow-sm transition hover:bg-brand-50 hover:text-brand-900 dark:border-brand-900 dark:bg-zinc-950 dark:text-brand-100 dark:hover:bg-brand-950"
              >
                <UserCog className="h-5 w-5" />
              </button>
            )}
            <button type="button" onClick={onClose} data-tip="Close profile" data-tip-side="left" className="dromis-tip rounded-md p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-white">
              <X className="h-5 w-5" />
            </button>
          </div>
        </div>

        <div className="min-h-0 overflow-y-auto overflow-x-hidden">
          <div className="space-y-4 p-5">
            {!isLguProfile && !hasIdentityData && (
              <div className="rounded-md border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <p className="text-sm font-bold">Employee profile data is not available yet.</p>
                  <button
                    type="button"
                    onClick={refreshMyPortal}
                    disabled={syncing}
                    className="shrink-0 rounded-md bg-brand-700 px-3 py-2 text-xs font-black text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
                  >
                    {syncing ? "Checking..." : "Retry profile lookup"}
                  </button>
                </div>
              </div>
            )}

            {isLguProfile ? (
              <>
                <div className="rounded-lg border border-brand-100 bg-gradient-to-br from-brand-50 to-white p-4 dark:border-brand-900 dark:from-brand-950 dark:to-zinc-950">
                  <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center">
                      <div className="flex shrink-0 items-center gap-3">
                        {renderLogoPreview(`${lguProfile?.name || user?.lgu_name || "LGU"} logo`, lguLogoPreview, lguLogoUrl)}
                        {renderLogoPreview("LDRRMC Logo", ldrrmcLogoPreview, ldrrmcLogoUrl)}
                      </div>
                      <div className="min-w-0">
                        <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">{lguLevelLabel}</p>
                        <h3 className="mt-1 text-2xl font-black text-slate-950 dark:text-white">{lguProfile?.name || user?.lgu_name || user?.name}</h3>
                        {lguProfile?.province_name && (
                          <p className="mt-2 text-sm font-bold text-slate-600 dark:text-zinc-300">{lguProfile.province_name}</p>
                        )}
                        <p className="mt-1 text-xs font-bold text-slate-500 dark:text-zinc-400">
                          Profile details are pulled from the LGU directory and may be updated here by authorized LGU users.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                <div className={`rounded-md border p-3 text-sm font-semibold ${canEditLguProfile
                  ? "border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
                  : "border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                }`}
                >
                  {isFullLguEditor
                    ? "Note: As LCE, LSWDO, LDRRMO, or an alternate, you can update the full LGU profile and create individual login usernames for each listed person."
                    : isSelfLguEditor
                      ? "Note: You can update only your own details in this LGU profile. Ask LCE / LSWDO / LDRRMO (or an alternate) for changes to other personnel."
                      : "Note: Only LCE, LSWDO, LDRRMO, and their alternates can maintain the full LGU profile. Operations staff with a linked login can update their own details only."}
                </div>

                {issuedLguCredentials.length > 0 && (
                  <div className="rounded-md border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">
                    <p className="font-black uppercase tracking-wide text-xs">Issued / updated login credentials</p>
                    <ul className="mt-2 space-y-1 text-xs font-semibold">
                      {issuedLguCredentials.map((entry) => (
                        <li key={`${entry.username}-${entry.password}`}>
                          {entry.name}: username <span className="font-black">{entry.username}</span>
                          {entry.password ? <> · password <span className="font-black">{entry.password}</span></> : null}
                          {entry.created ? " (new account)" : " (password updated)"}
                        </li>
                      ))}
                    </ul>
                    <p className="mt-2 text-[11px] font-medium opacity-80">Share these securely with the personnel. Temporary passwords are shown only once.</p>
                  </div>
                )}

                {editingLguProfile && canEditLguProfile && isSelfLguEditor && (
                  <form onSubmit={submitLguProfile} className="rounded-lg border border-brand-200 bg-brand-50/60 p-4 shadow-sm dark:border-brand-900 dark:bg-brand-950/30">
                    <div className="mb-4">
                      <h3 className="text-base font-black uppercase tracking-wide text-brand-800 dark:text-brand-100">Update my details</h3>
                      <p className="mt-1 text-xs font-bold text-slate-600 dark:text-zinc-300">
                        Changes apply only to your linked personnel record on this LGU profile.
                      </p>
                    </div>
                    <div className="grid gap-3 md:grid-cols-2">
                      {renderLguInput("Full Name", "self_name", "text", true)}
                      {renderLguInput("Position / Designation", "self_position")}
                      {renderLguInput("Office ID Number", "self_id_number")}
                      {renderLguInput("Contact Number", "self_contact_number")}
                      {renderLguInput("Email", "self_email", "email")}
                      {renderLguInput("Office", "self_office")}
                      {renderLguInput("New password", "login_password", "password")}
                      {renderLguInput("Confirm new password", "login_password_confirmation", "password")}
                    </div>
                    <div className="mt-4 flex justify-end">
                      <button
                        type="submit"
                        disabled={lguProfileForm.processing}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-xs font-black uppercase tracking-wide text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
                      >
                        <Save className="h-4 w-4" />
                        {lguProfileForm.processing ? "Saving..." : "Save my details"}
                      </button>
                    </div>
                  </form>
                )}

                {editingLguProfile && canEditLguProfile && isFullLguEditor && (
                  <form onSubmit={submitLguProfile} className="rounded-lg border border-brand-200 bg-brand-50/60 p-4 shadow-sm dark:border-brand-900 dark:bg-brand-950/30">
                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <h3 className="text-base font-black uppercase tracking-wide text-brand-800 dark:text-brand-100">Update LGU Profile</h3>
                        <p className="mt-1 text-xs font-bold text-slate-600 dark:text-zinc-300">
                          Upload the official LGU logo and keep LCE, LSWDO, and LDRRMO details ready for generated reports. Assign a login username per person so they can sign in with their own identity.
                        </p>
                        <p className="mt-1 text-[11px] font-semibold text-slate-500 dark:text-zinc-400">
                          Fields marked with <span className="font-black text-rose-600">*</span> are required. All other fields are optional.
                        </p>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <label className="inline-flex cursor-pointer items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-xs font-black uppercase tracking-wide text-white shadow-sm transition hover:bg-brand-800">
                          <UploadCloud className="h-4 w-4" />
                          Upload LGU logo <span className="normal-case tracking-normal opacity-80">(optional)</span>
                          <input type="file" accept="image/*" onChange={handleLguLogoChange} className="sr-only" />
                        </label>
                        <label className="inline-flex cursor-pointer items-center justify-center gap-2 rounded-md bg-sky-700 px-4 py-2 text-xs font-black uppercase tracking-wide text-white shadow-sm transition hover:bg-sky-800">
                          <UploadCloud className="h-4 w-4" />
                          Upload LDRRMC logo <span className="normal-case tracking-normal opacity-80">(optional)</span>
                          <input type="file" accept="image/*" onChange={handleLdrrmcLogoChange} className="sr-only" />
                        </label>
                      </div>
                    </div>
                    {lguProfileForm.errors.logo && (
                      <p className="mb-3 rounded-md border border-red-200 bg-red-50 p-3 text-xs font-bold text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        {lguProfileForm.errors.logo}
                      </p>
                    )}
                    {lguProfileForm.errors.ldrrmc_logo && (
                      <p className="mb-3 rounded-md border border-red-200 bg-red-50 p-3 text-xs font-bold text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        {lguProfileForm.errors.ldrrmc_logo}
                      </p>
                    )}
                    <div className="mb-4 grid gap-3 md:grid-cols-3">
                      {renderPhotoUpload("LCE photo", "lce_photo", lguOfficialPhotos.lce)}
                      {renderPhotoUpload("LSWDO photo", "lswd_photo", lguOfficialPhotos.lswd)}
                      {renderPhotoUpload("LDRRMO photo", "ldrrmo_photo", lguOfficialPhotos.ldrrmo)}
                    </div>
                    <div className="space-y-4">
                      {renderLguSection("LGU", [
                        renderLguInput("Name of LGU", "lgu_name", "text", true),
                        renderLguInput("Province", "province_name"),
                        renderLguInput("District", "managed_district_name"),
                      ])}
                      {renderLguSection("LCE", [
                        renderLguInput("LCE Full Name", "lce_name"),
                        renderLguInput("Designation", "lce_position"),
                        renderLguInput("Office ID Number", "lce_id_number"),
                        renderLguInput("LGU/LCE Office Email Address", "lce_email", "email"),
                        renderLoginCredentialPair(
                          "lce_login_username",
                          "lce_login_password",
                          lguProfileForm.data.lce_login_username,
                          (value) => lguProfileForm.setData("lce_login_username", value),
                          (value) => lguProfileForm.setData("lce_login_password", value),
                          lguProfileForm.data.lce_login_password,
                        ),
                      ])}
                      {renderLguSection("LSWDO", [
                        renderLguInput("LSWD Officer", "lswd_name"),
                        renderLguInput("Position", "lswd_position"),
                        renderLguInput("Office ID Number", "lswd_id_number"),
                        renderLguInput("LSWD Office Email Address", "lswd_email", "email"),
                        renderLguInput("Alternate Email / Copy Furnish", "lswd_alt_email", "email"),
                        renderLguInput("Contact Number", "lswd_phone"),
                        renderLguInput("Facebook Link", "lswd_facebook"),
                        renderLoginCredentialPair(
                          "lswd_login_username",
                          "lswd_login_password",
                          lguProfileForm.data.lswd_login_username,
                          (value) => lguProfileForm.setData("lswd_login_username", value),
                          (value) => lguProfileForm.setData("lswd_login_password", value),
                          lguProfileForm.data.lswd_login_password,
                        ),
                        <div key="lswdo-alternates" className="space-y-3 sm:col-span-2">
                          {lguProfileForm.data.lswdo_alternates.map((alternate, alternateIndex) => (
                            <div key={alternate.id || alternateIndex} className="grid gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-4 dark:bg-zinc-900">
                              <p className="text-xs font-black uppercase text-brand-700 sm:col-span-2 lg:col-span-4">Alternate LSWDO {alternateIndex + 1}</p>
                              {[
                                ["Full Name", "name", true],
                                ["Position", "position", false],
                                ["Office ID Number", "id_number", false],
                                ["Contact Number", "contact_number", false],
                                ["Email", "email", false],
                              ].map(([label, key, required]) => (
                                <label key={key} className="text-xs font-black text-slate-700 dark:text-zinc-200">
                                  {renderLguFieldLabel(label, required)}
                                  <input
                                    type={key === "email" ? "email" : "text"}
                                    value={alternate[key] || ""}
                                    onChange={(event) => updateLguLswdoAlternate(alternateIndex, key, event.target.value)}
                                    className="mt-1 w-full"
                                  />
                                </label>
                              ))}
                              {renderLoginCredentialPair(
                                `lswdo_alternates.${alternateIndex}.login_username`,
                                `lswdo_alternates.${alternateIndex}.login_password`,
                                alternate.login_username,
                                (value) => updateLguLswdoAlternate(alternateIndex, "login_username", value),
                                (value) => updateLguLswdoAlternate(alternateIndex, "login_password", value),
                                alternate.login_password,
                              )}
                            </div>
                          ))}
                          <button
                            type="button"
                            onClick={() => lguProfileForm.setData("lswdo_alternates", [...lguProfileForm.data.lswdo_alternates, {}])}
                            className="rounded-md border border-brand-200 px-3 py-2 text-xs font-black text-brand-700"
                          >
                            Add alternate LSWDO
                          </button>
                        </div>,
                      ])}
                      <section className="rounded-lg border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <h4 className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">LDRRMO</h4>
                        <p className="mt-1 text-xs text-slate-500">Every Google Sheet contact channel is stored separately. VHF belongs to the main LDRRMO.</p>
                        <div className="mt-3 space-y-4">
                          {lguProfileForm.data.ldrrmo_officers.map((officer, officerIndex) => (
                            <div key={officer.id || officerIndex} className="grid gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-2 dark:bg-zinc-900">
                              <p className="text-xs font-black uppercase text-brand-700 sm:col-span-2">
                                {officerIndex === 0 ? "Main LDRRMO" : `Alternate LDRRMO ${officerIndex}`}
                              </p>
                              {[
                                ["Office", "office", false],
                                ["Officer", "name", true],
                                ["Designation", "designation", false],
                                ["Office ID Number", "id_number", false],
                                ["Mobile Number", "mobile_number", false],
                                ["Hotline Number", "hotline_number", false],
                                ["Landline Number", "landline_number", false],
                                ["Email Address", "email_address", false],
                                ["Alternate Email Address", "alternate_email_address", false],
                                ["Facebook", "facebook", false],
                              ].map(([label, key, required]) => (
                                <label key={key} className="text-xs font-black text-slate-700 dark:text-zinc-200">
                                  {renderLguFieldLabel(label, required)}
                                  <input
                                    type={key.includes("email") ? "email" : "text"}
                                    value={officer[key] || ""}
                                    onChange={(event) => updateLguLdrrmoOfficer(officerIndex, key, event.target.value)}
                                    className="mt-1 w-full"
                                  />
                                </label>
                              ))}
                              {officerIndex === 0 && (
                                <label className="text-xs font-black text-slate-700 dark:text-zinc-200">
                                  {renderLguFieldLabel("VHF Radio Frequency", false)}
                                  <input
                                    value={officer.vhf_radio_frequency || ""}
                                    onChange={(event) => updateLguLdrrmoOfficer(officerIndex, "vhf_radio_frequency", event.target.value)}
                                    className="mt-1 w-full"
                                  />
                                </label>
                              )}
                              {renderLoginCredentialPair(
                                `ldrrmo_officers.${officerIndex}.login_username`,
                                `ldrrmo_officers.${officerIndex}.login_password`,
                                officer.login_username,
                                (value) => updateLguLdrrmoOfficer(officerIndex, "login_username", value),
                                (value) => updateLguLdrrmoOfficer(officerIndex, "login_password", value),
                                officer.login_password,
                              )}
                            </div>
                          ))}
                          <button
                            type="button"
                            onClick={() => lguProfileForm.setData("ldrrmo_officers", [...lguProfileForm.data.ldrrmo_officers, {}])}
                            className="rounded-md border border-brand-200 px-3 py-2 text-xs font-black text-brand-700"
                          >
                            Add alternate LDRRMO
                          </button>
                        </div>
                      </section>
                      {[
                        ["dromic_encoders", "DROMIC / SitReport Encoders", "Add DROMIC / SitRep encoder"],
                        ...(lguHasWarehouses
                          ? [
                              ["warehouse_focals", "Warehouse Focals", "Add warehouse focal"],
                              ["warehouse_storekeepers", "Warehouse Storekeepers", "Add warehouse storekeeper"],
                            ]
                          : []),
                        ["drivers", "Drivers", "Add driver"],
                      ].map(([field, title, addLabel]) => (
                        <section key={field} className="rounded-lg border border-slate-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                          <h4 className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">{title}</h4>
                          <p className="mt-1 text-xs text-slate-500">
                            {field === "warehouse_focals" || field === "warehouse_storekeepers"
                              ? "Defaults come from the RROS warehouse list. Values you save here supersede later warehouse syncs. Assign an existing LCE / LSWDO / LDRRMO / alternate / encoder / driver to reuse their portal login instead of creating a second account."
                              : "Optional Office ID Number is used to autofill dispatch / delivery release forms when this person is selected."}
                            {field === "drivers" ? " Saving also updates the shared operational library." : ""}
                            {field !== "warehouse_focals" && field !== "warehouse_storekeepers"
                              ? " Assign a login username so this person can sign in with their own identity."
                              : ""}
                          </p>
                          <div className="mt-3 space-y-4">
                            {(lguProfileForm.data[field] || []).map((member, memberIndex) => (
                              <div key={member.id || `${field}-${memberIndex}`} className="grid gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-3 dark:bg-zinc-900">
                                <p className="text-xs font-black uppercase text-brand-700 sm:col-span-2 lg:col-span-3">
                                  {title.replace(/s$/, "")} {memberIndex + 1}
                                </p>
                                {(field === "warehouse_focals" || field === "warehouse_storekeepers") && lguLinkablePersonnel.length > 0 && (
                                  <label className="text-xs font-black text-slate-700 sm:col-span-2 lg:col-span-3 dark:text-zinc-200">
                                    Assign from existing LGU personnel
                                    <select
                                      className="mt-1 w-full"
                                      value={member.linked_from || ""}
                                      onChange={(event) => applyLinkedLguPersonnel(field, memberIndex, event.target.value)}
                                    >
                                      <option value="">New person / keep current entry</option>
                                      {lguLinkablePersonnel.map((person) => (
                                        <option key={person.key} value={person.key}>
                                          {person.label}{person.login_username ? ` (${person.login_username})` : ""}
                                        </option>
                                      ))}
                                    </select>
                                  </label>
                                )}
                                {[
                                  ["Name of Office", "office", false],
                                  ["Name of Staff", "name", true],
                                  ["Position", "position", false],
                                  ["ID Number", "id_number", false],
                                  ["Contact Number", "contact_number", false],
                                  ["Email", "email", false],
                                ].map(([label, key, required]) => (
                                  <label key={key} className="text-xs font-black text-slate-700 dark:text-zinc-200">
                                    {renderLguFieldLabel(label, required)}
                                    <input
                                      type={key === "email" ? "email" : "text"}
                                      value={member[key] || ""}
                                      onChange={(event) => updateLguStaffMember(field, memberIndex, key, event.target.value)}
                                      className="mt-1 w-full"
                                    />
                                  </label>
                                ))}
                                {member.link_user_id ? (
                                  <div className="rounded-md border border-brand-200 bg-brand-50/70 p-3 text-xs font-semibold text-brand-800 sm:col-span-2 lg:col-span-3 dark:border-brand-900 dark:bg-brand-950/40 dark:text-brand-100">
                                    Reuses existing portal login <span className="font-mono">{member.login_username || "linked account"}</span>. No separate password is created for this warehouse role.
                                  </div>
                                ) : (
                                  renderLoginCredentialPair(
                                    `${field}.${memberIndex}.login_username`,
                                    `${field}.${memberIndex}.login_password`,
                                    member.login_username,
                                    (value) => updateLguStaffMember(field, memberIndex, "login_username", value),
                                    (value) => updateLguStaffMember(field, memberIndex, "login_password", value),
                                    member.login_password,
                                  )
                                )}
                              </div>
                            ))}
                            <button
                              type="button"
                              onClick={() => addLguStaffMember(field)}
                              className="rounded-md border border-brand-200 px-3 py-2 text-xs font-black text-brand-700"
                            >
                              {addLabel}
                            </button>
                          </div>
                        </section>
                      ))}
                    </div>
                    <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
                      <button
                        type="submit"
                        disabled={lguProfileForm.processing}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-xs font-black uppercase tracking-wide text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
                      >
                        <Save className="h-4 w-4" />
                        {lguProfileForm.processing ? "Saving..." : "Save LGU profile"}
                      </button>
                    </div>
                  </form>
                )}

                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                  <div className="mb-3 flex items-center gap-2">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                      <Database className="h-4 w-4" />
                    </span>
                    <h3 className="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-zinc-100">LGU Information</h3>
                  </div>
                  <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {lguInfoFields.map(([label, value]) => (
                      <div key={label} className="rounded-md bg-slate-50 p-3 dark:bg-zinc-900">
                        <dt className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</dt>
                        <dd className="mt-1 whitespace-pre-line break-words text-sm font-bold text-slate-800 dark:text-zinc-100">{profileValue(value)}</dd>
                      </div>
                    ))}
                  </dl>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                  <div className="mb-3 flex items-center gap-2">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                      <UsersRound className="h-4 w-4" />
                    </span>
                    <div>
                      <h3 className="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-zinc-100">LGU Officials</h3>
                      <p className="text-xs text-slate-500 dark:text-zinc-400">LCE, LSWDO, and LDRRMO details are stored in one LGU directory record.</p>
                    </div>
                  </div>
                  <div className="space-y-4">
                    <OfficialProfileCard
                      title="Local Chief Executive"
                      photo={lguOfficialPhotos.lce}
                      name={lguChief?.name}
                      fields={[
                        ["Designation", lguChief?.position],
                        ["Office ID Number", lguChief?.id_number],
                        ["LGU/LCE Office Email Address", lguChief?.email],
                      ]}
                    />
                    <OfficialProfileCard
                      title="LSWDO"
                      photo={lguOfficialPhotos.lswd}
                      name={lguLswd?.name}
                      fields={[
                        ["Position", lguLswd?.position],
                        ["Office ID Number", lguLswd?.id_number],
                        ["LSWD Office Email Address", lguLswd?.email],
                        ["Alternate Email / Copy Furnish", lguLswd?.alternate_email],
                        ["Contact Number", lguLswd?.phone],
                        ["Facebook Link", lguLswd?.facebook],
                      ]}
                    >
                      <div className="mt-5 border-t border-slate-200 pt-4 dark:border-zinc-700">
                        <div className="flex items-center justify-between gap-3">
                          <p className="text-[10px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Alternate LSWDO Personnel</p>
                          <span className="rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-black text-brand-700 dark:bg-brand-950 dark:text-brand-100">{lguLswdoAlternates.length}</span>
                        </div>
                        <div className="mt-2 divide-y divide-slate-200 dark:divide-zinc-700">
                          {lguLswdoAlternates.map((alternate, alternateIndex) => (
                            <div key={alternate.id || alternateIndex} className="grid gap-2 py-3 sm:grid-cols-[2rem_1.5fr_1fr_1fr_1fr] sm:items-center">
                              <span className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-xs font-black text-brand-700 dark:bg-brand-950 dark:text-brand-100">{alternateIndex + 1}</span>
                              <div>
                                <p className="text-[10px] font-black uppercase text-slate-400">Full Name</p>
                                <p className="font-black">{profileValue(alternate.name)}</p>
                              </div>
                              <div>
                                <p className="text-[10px] font-black uppercase text-slate-400">Position</p>
                                <p className="text-sm font-bold">{profileValue(alternate.position)}</p>
                              </div>
                              <div>
                                <p className="text-[10px] font-black uppercase text-slate-400">Office ID Number</p>
                                <p className="text-sm font-bold">{profileValue(alternate.id_number)}</p>
                              </div>
                              <div>
                                <p className="text-[10px] font-black uppercase text-slate-400">Contact Number</p>
                                <p className="text-sm font-bold">{profileValue(alternate.contact_number)}</p>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    </OfficialProfileCard>
                    <OfficialProfileCard
                      title="LDRRMO"
                      photo={lguOfficialPhotos.ldrrmo}
                      name={lguLdrrmoOfficers[0]?.name}
                      fields={[
                        ["Designation", lguLdrrmoOfficers[0]?.designation],
                        ["Office ID Number", lguLdrrmoOfficers[0]?.id_number],
                        ["Mobile Number", lguLdrrmoOfficers[0]?.mobile_number],
                        ["Hotline Number", lguLdrrmoOfficers[0]?.hotline_number],
                        ["Landline Number", lguLdrrmoOfficers[0]?.landline_number],
                        ["Email Address", lguLdrrmoOfficers[0]?.email_address],
                        ["Alternate Email Address", lguLdrrmoOfficers[0]?.alternate_email_address],
                        ["VHF Radio Frequency", lguLdrrmoOfficers[0]?.vhf_radio_frequency],
                        ["Facebook", lguLdrrmoOfficers[0]?.facebook],
                      ]}
                    >
                      {lguLdrrmoOfficers.length > 1 && (
                        <div className="mt-5 border-t border-slate-200 pt-4 dark:border-zinc-700">
                          <div className="flex items-center justify-between gap-3">
                            <p className="text-[10px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Alternate LDRRMO Personnel</p>
                            <span className="rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-black text-brand-700 dark:bg-brand-950 dark:text-brand-100">{lguLdrrmoOfficers.length - 1}</span>
                          </div>
                          <div className="mt-2 divide-y divide-slate-200 dark:divide-zinc-700">
                          {lguLdrrmoOfficers.slice(1).map((officer, officerIndex) => (
                            <div key={officer.id || officerIndex} className="grid gap-3 py-4 lg:grid-cols-[2rem_1.25fr_3fr]">
                              <span className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-xs font-black text-brand-700 dark:bg-brand-950 dark:text-brand-100">{officerIndex + 1}</span>
                              <div>
                                <p className="text-[10px] font-black uppercase text-slate-400">Full Name</p>
                                <p className="font-black">{profileValue(officer.name)}</p>
                                <p className="mt-1 text-sm font-bold text-slate-500">{profileValue(officer.designation)}</p>
                              </div>
                              <dl className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                {[
                                  ["Office ID Number", officer.id_number],
                                  ["Mobile Number", officer.mobile_number],
                                  ["Hotline Number", officer.hotline_number],
                                  ["Landline Number", officer.landline_number],
                                  ["Email Address", officer.email_address],
                                  ["Alternate Email Address", officer.alternate_email_address],
                                  ["Facebook", officer.facebook],
                                ].map(([label, value]) => (
                                  <div key={label}>
                                    <dt className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</dt>
                                    <dd className="break-words text-sm font-bold">{profileValue(value)}</dd>
                                  </div>
                                ))}
                              </dl>
                            </div>
                          ))}
                          </div>
                        </div>
                      )}
                    </OfficialProfileCard>
                  </div>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                  <div className="mb-3 flex items-center gap-2">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                      <UsersRound className="h-4 w-4" />
                    </span>
                    <div>
                      <h3 className="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-zinc-100">Operations Personnel</h3>
                      <p className="text-xs text-slate-500 dark:text-zinc-400">
                        DROMIC / SitRep encoders{lguHasWarehouses ? ", warehouse focals, storekeepers," : ""} and drivers used in dispatch and delivery releases.
                      </p>
                    </div>
                  </div>
                  <div className="space-y-5">
                    {[
                      ["DROMIC / SitReport Encoders", lguDromicEncoders.filter((row) => String(row?.name || "").trim())],
                      ...(lguHasWarehouses
                        ? [
                            ["Warehouse Focals", lguWarehouseFocals.filter((row) => String(row?.name || "").trim())],
                            ["Warehouse Storekeepers", lguWarehouseStorekeepers.filter((row) => String(row?.name || "").trim())],
                          ]
                        : []),
                      ["Drivers", lguDrivers.filter((row) => String(row?.name || "").trim())],
                    ].map(([title, rows]) => (
                      <div key={title}>
                        <div className="mb-2 flex items-center justify-between gap-3">
                          <p className="text-[10px] font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">{title}</p>
                          <span className="rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-black text-brand-700 dark:bg-brand-950 dark:text-brand-100">{rows.length}</span>
                        </div>
                        {rows.length === 0 ? (
                          <p className="rounded-md bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-500 dark:bg-zinc-900 dark:text-zinc-400">No personnel encoded yet.</p>
                        ) : (
                          <div className="divide-y divide-slate-200 rounded-md border border-slate-200 dark:divide-zinc-700 dark:border-zinc-700">
                            {rows.map((member, memberIndex) => (
                              <div key={member.id || `${title}-${memberIndex}`} className="grid gap-2 p-3 sm:grid-cols-2 lg:grid-cols-5">
                                {[
                                  ["Name of Office", member.office],
                                  ["Name of Staff", member.name],
                                  ["Position", member.position],
                                  ["ID Number", member.id_number],
                                  ["Contact Number", member.contact_number],
                                ].map(([label, value]) => (
                                  <div key={label}>
                                    <p className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</p>
                                    <p className="text-sm font-bold text-slate-800 dark:text-zinc-100">{profileValue(value)}</p>
                                  </div>
                                ))}
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                  <div className="mb-3 flex items-center gap-2">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                      <IdCard className="h-4 w-4" />
                    </span>
                    <h3 className="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-zinc-100">DROMIS Access</h3>
                  </div>
                  <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {lguAccessFields.map(([label, value]) => (
                      <div key={label} className="rounded-md bg-slate-50 p-3 dark:bg-zinc-900">
                        <dt className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</dt>
                        <dd className="mt-1 whitespace-pre-line break-words text-sm font-bold text-slate-800 dark:text-zinc-100">{profileValue(value)}</dd>
                      </div>
                    ))}
                  </dl>
                </div>
              </>
            ) : (
              <>
                <div className="rounded-lg border border-brand-100 bg-gradient-to-br from-brand-50 to-white p-4 dark:border-brand-900 dark:from-brand-950 dark:to-zinc-950">
                  <div className="flex flex-col gap-4 md:flex-row md:items-center">
                    <div className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white text-brand-700 shadow-sm ring-1 ring-brand-100 dark:bg-zinc-900 dark:text-brand-100 dark:ring-brand-900">
                      {avatar && !avatarFailed ? (
                        <img
                          src={avatar}
                          alt={displayName}
                          className="h-full w-full object-cover"
                          onError={() => setAvatarFailed(true)}
                        />
                      ) : (
                        <UserRound className="h-8 w-8" />
                      )}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Employee</p>
                      <h3 className="mt-1 text-2xl font-black text-slate-950 dark:text-white">{displayName}</h3>
                      <div className="mt-3 grid gap-2 text-sm text-slate-600 dark:text-zinc-300 sm:grid-cols-2 lg:grid-cols-4">
                        <span className="font-bold"><IdCard className="mr-1 inline h-4 w-4" /> {profileValue(employeeId)}</span>
                        <span className="font-bold"><UserCog className="mr-1 inline h-4 w-4" /> {profileValue(field("username"))}</span>
                        <span className="font-bold"><Phone className="mr-1 inline h-4 w-4" /> {profileValue(field("contact", "contact_number", "mobile_no"))}</span>
                        <span className="font-bold"><Mail className="mr-1 inline h-4 w-4" /> {profileValue(field("email", "official_email"))}</span>
                      </div>
                    </div>
                  </div>
                </div>

                {aorEnabled && (
                  <SectionTabs
                    label="Profile Sections"
                    appearance="framed"
                    className="mb-4"
                    value={activeProfileArea}
                    onChange={setActiveProfileArea}
                    ariaLabel="Employee profile sections"
                    tabs={[
                      { id: "overview", label: "Overview" },
                      { id: "aor", label: "Area of Responsibility" },
                    ]}
                  />
                )}

                {activeProfileArea === "aor" && aorEnabled ? (
                  <form onSubmit={submitAor} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                    <div className="mb-4 flex items-start justify-between gap-3">
                      <div>
                        <h3 className="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-zinc-100">Area of Responsibility</h3>
                        <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">
                          Select the provinces, districts, and cities/municipalities this user covers
                          {user?.roles?.includes("DRRS")
                            ? " for LGU relief augmentation follow-up."
                            : user?.roles?.includes("DRIMS")
                              ? " for LGU DROMIC / SitRep routing."
                              : "."}
                          {" "}Areas already claimed by other {user?.roles?.includes("DRRS") ? "DRRS" : user?.roles?.includes("DRIMS") ? "DRIMS" : "peer"} users stay unavailable. DRRS and DRIMS AOR pools are separate and do not cross-check each other.
                        </p>
                      </div>
                      {hasSavedAor && !aorEditing && (
                        <button
                          type="button"
                          onClick={() => setAorEditing(true)}
                          aria-label="Edit AOR"
                          data-tip="Edit AOR"
                          data-tip-side="left"
                          className="dromis-tip inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:border-brand-300 hover:text-brand-700 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-200 dark:hover:border-brand-700 dark:hover:text-brand-100"
                        >
                          <PencilLine className="h-4 w-4" />
                        </button>
                      )}
                    </div>

                    <AorScopeFields
                      regionCode={activeRegionCode}
                      value={aorForm.data}
                      occupiedScopes={occupiedAorScopes}
                      showEditors={aorEditing || !hasSavedAor}
                      disabled={aorForm.processing}
                      onChange={(next) => {
                        setAorClientError("");
                        aorForm.setData(next);
                      }}
                      onValidityChange={({ valid, errors }) => {
                        setAorSelectionValid(Boolean(valid));
                        setAorSelectionErrors(Array.isArray(errors) ? errors : []);
                      }}
                    />

                    {aorError && (
                      <div className="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
                        {aorError}
                      </div>
                    )}

                    {aorEditing && (
                      <div className="mt-5 flex justify-end gap-2">
                        {hasSavedAor && (
                          <button
                            type="button"
                            onClick={() => {
                              const saved = {
                                aor_provinces: Array.isArray(user?.aor_provinces) ? user.aor_provinces : [],
                                aor_districts: Array.isArray(user?.aor_districts) ? user.aor_districts : [],
                                aor_cities_municipalities: Array.isArray(user?.aor_cities_municipalities) ? user.aor_cities_municipalities : [],
                              };
                              aorForm.setData(saved);
                              setAorClientError("");
                              setAorEditing(false);
                            }}
                            className="rounded-md border border-slate-200 bg-white px-4 py-2 text-xs font-black text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-200"
                          >
                            Cancel
                          </button>
                        )}
                        <button
                          type="submit"
                          disabled={aorForm.processing || !aorSelectionValid}
                          className="rounded-md bg-brand-700 px-4 py-2 text-xs font-black text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
                        >
                          {aorForm.processing ? "Saving..." : hasSavedAor ? "Update AOR" : "Save AOR"}
                        </button>
                      </div>
                    )}
                  </form>
                ) : (
                  <div className="grid gap-4 lg:grid-cols-2">
                    {cardGroups.map(({ title, fields, icon: Icon }) => (
                      <div key={title} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                        <div className="mb-3 flex items-center gap-2">
                          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                            <Icon className="h-4 w-4" />
                          </span>
                          <h3 className="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-zinc-100">{title}</h3>
                        </div>
                        <dl className="grid gap-3 sm:grid-cols-2">
                          {fields.map(([label, value]) => (
                            <div key={label} className="rounded-md bg-slate-50 p-3 dark:bg-zinc-900">
                              <dt className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</dt>
                              <dd className="mt-1 break-words text-sm font-bold text-slate-800 dark:text-zinc-100">{profileValue(value)}</dd>
                            </div>
                          ))}
                        </dl>
                      </div>
                    ))}
                  </div>
                )}
              </>
            )}

            <form onSubmit={updatePassword} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
              <div className="mb-3 flex items-center gap-2">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                  <IdCard className="h-4 w-4" />
                </span>
                <div>
                  <h3 className="text-sm font-black uppercase tracking-wide text-slate-800 dark:text-zinc-100">Password Settings</h3>
                  <p className="text-xs text-slate-500 dark:text-zinc-400">LGU and local users may update their DROMIS password here.</p>
                </div>
              </div>
              <div className="grid gap-3 md:grid-cols-3">
                {renderPasswordInput("Current password", "current_password", "current-password")}
                {renderPasswordInput("New password", "password", "new-password")}
                {renderPasswordInput("Confirm password", "password_confirmation", "new-password")}
              </div>
              <div className="mt-4 flex justify-end">
                <button
                  type="submit"
                  disabled={passwordForm.processing}
                  className="rounded-md bg-brand-700 px-4 py-2 text-xs font-black text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
                >
                  {passwordForm.processing ? "Updating..." : "Update password"}
                </button>
              </div>
            </form>
          </div>

        </div>

        <div className="shrink-0 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
          {isLguProfile
            ? "DROMIS displays LGU profile details from the local LGU directory and account record."
            : "DROMIS displays verified employee data from Caraga Connect SSO/MyPortal first, with saved account values only as fallback."}
        </div>
      </div>
    </div>
  );
}

export function Card({ children, className, ...props }) {
  return (
    <div
      {...props}
      className={clsx(
        "min-w-0 max-w-full rounded-md border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900",
        className,
      )}
    >
      {children}
    </div>
  );
}

export function ExportableCard({
  children,
  className,
  title = "Component",
  filename,
  headerClassName,
  actionsClassName,
  showExportButtons = true,
  exportButtonProps = {},
  renderHeader,
  ...props
}) {
  const exportRef = useRef(null);
  const exportsAllowed = isRrosPage(usePage().url);
  const displayExportButtons = showExportButtons && exportsAllowed;
  const [exportMessage, setExportMessage] = useState("");
  const showExportMessage = (message) => {
    setExportMessage(message);
    window.setTimeout(() => setExportMessage(""), 3000);
  };
  const showHeader =
    !renderHeader && (displayExportButtons || Boolean(exportMessage));
  const childrenArray = Children.toArray(children);
  const hasHeading = (nodes) =>
    nodes.some((node) => {
      if (!isValidElement(node)) return false;
      const type = node.type;
      if (typeof type === "string" && ["h1", "h2", "h3", "h4"].includes(type))
        return true;
      if (node.props?.children)
        return hasHeading(Children.toArray(node.props.children));
      return false;
    });

  return (
    <div
      ref={exportRef}
      data-export-root="true"
      className="min-w-0 max-w-full scroll-mt-28"
    >
      <Card {...props} className={clsx("overflow-visible", className)}>
        {renderHeader ? (
          renderHeader({
            exportButtons: displayExportButtons ? (
              <ExportButtons
                {...exportButtonProps}
                targetRef={exportRef}
                filename={filename ?? exportFilename(title)}
                label={title}
                onMessage={showExportMessage}
              />
            ) : null,
            exportMessage,
          })
        ) : showHeader && !hasHeading(childrenArray) ? (
          <div
            className={clsx(
              "mb-4 flex items-center justify-between",
              headerClassName,
            )}
          >
            <div>
              <h2 className="font-semibold">{title}</h2>
              {exportMessage && (
                <p className="mt-1 text-xs font-bold text-brand-700 dark:text-brand-200">
                  {exportMessage}
                </p>
              )}
            </div>
            <div
              className={clsx(
                "flex flex-wrap items-center gap-3",
                actionsClassName,
              )}
            >
              {displayExportButtons ? (
                <ExportButtons
                  {...exportButtonProps}
                  targetRef={exportRef}
                  filename={filename ?? exportFilename(title)}
                  label={title}
                  onMessage={showExportMessage}
                />
              ) : null}
            </div>
          </div>
        ) : null}
        {/* If the card already contains a heading, show compact export buttons absolutely positioned (no layout shift) */}
        <div className="relative">
          {!renderHeader &&
            displayExportButtons &&
            hasHeading(childrenArray) && (
              <div
                className={clsx(
                  "absolute right-3 top-3 z-10",
                  actionsClassName,
                )}
              >
                <ExportButtons
                  {...exportButtonProps}
                  targetRef={exportRef}
                  filename={filename ?? exportFilename(title)}
                  label={title}
                  onMessage={showExportMessage}
                />
              </div>
            )}

          {children}
        </div>
      </Card>
    </div>
  );
}

const actionButtonStyles = {
  slate:
    "border-slate-200 bg-white text-slate-700 hover:bg-slate-100 hover:text-slate-950 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-200 dark:hover:bg-zinc-800 dark:hover:text-white",
  brand:
    "border-brand-200 bg-brand-50 text-brand-700 hover:bg-brand-100 hover:text-brand-800 dark:border-brand-900/60 dark:bg-brand-950/40 dark:text-brand-100 dark:hover:bg-brand-900/60",
  emerald:
    "border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-100 dark:hover:bg-emerald-900/60",
  amber:
    "border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 hover:text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100 dark:hover:bg-amber-900/60",
  rose: "border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 hover:text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-100 dark:hover:bg-rose-900/60",
  indigo:
    "border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 hover:text-indigo-800 dark:border-indigo-900/60 dark:bg-indigo-950/40 dark:text-indigo-100 dark:hover:bg-indigo-900/60",
};

export function TableActionButton({
  icon: Icon,
  label,
  onClick,
  tone = "slate",
  className,
  disabled = false,
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={label}
      aria-label={label}
      className={clsx(
        "inline-flex h-8 w-8 items-center justify-center rounded-md border shadow-sm transition hover:-translate-y-0.5 hover:shadow-md",
        actionButtonStyles[tone] ?? actionButtonStyles.slate,
        disabled && "cursor-not-allowed opacity-40 hover:translate-y-0 hover:shadow-sm",
        className,
      )}
    >
      <Icon className="h-4 w-4" />
    </button>
  );
}

function isActionColumn(column) {
  const config = typeof column === "string" ? { label: column } : column;
  return Boolean(config.actionColumn || /action/i.test(config.label ?? ""));
}

export function DataTable({
  columns,
  rows,
  sort,
  onSort,
  stickyHeader = false,
  className,
  tableClassName,
  numbered = true,
}) {
  const actionColumns = columns.filter(isActionColumn);
  const dataColumns = columns.filter((column) => !isActionColumn(column));
  const resolvedColumns = numbered
    ? [
        { label: "#", align: "center" },
        ...(actionColumns.length > 0 ? actionColumns : []),
        ...dataColumns,
      ]
    : columns;
  const renderedRows = numbered
    ? rows.map((row, index) => {
        if (!isValidElement(row) || row.type !== "tr") {
          return row;
        }

        const rowChildren = Children.toArray(row.props.children);
        const shouldSkipNumbering = rowChildren.some(
          (child) => isValidElement(child) && child.props?.colSpan,
        );

        if (shouldSkipNumbering) {
          return row;
        }

        const hasActionColumn = actionColumns.length > 0;
        const actionCell =
          hasActionColumn && rowChildren.length > 0
            ? rowChildren[rowChildren.length - 1]
            : null;
        const dataCells =
          hasActionColumn && actionCell
            ? rowChildren.slice(0, -1)
            : rowChildren;

        return cloneElement(row, { key: row.key ?? index }, [
          <td
            key={`number-${index}`}
            className="whitespace-nowrap px-4 py-3 text-center text-xs font-black text-slate-500 dark:text-zinc-400"
          >
            {index + 1}
          </td>,
          ...(hasActionColumn && actionCell ? [actionCell] : []),
          ...dataCells,
        ]);
      })
    : rows;

  return (
    <div
      className={clsx(
        "dromis-table max-w-full overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900",
        className,
      )}
    >
      <table className={clsx(
        "w-full min-w-max divide-y divide-slate-200 text-sm dark:divide-zinc-800",
        tableClassName,
      )}>
        <thead className="bg-slate-100 text-left text-xs uppercase text-slate-500 dark:bg-zinc-900 dark:text-zinc-400">
          <tr>
            {resolvedColumns.map((column) => {
              const config =
                typeof column === "string" ? { label: column } : column;
              const active = sort?.key === config.sortKey;
              const Icon = active
                ? sort?.direction === "desc"
                  ? ArrowDown
                  : ArrowUp
                : ArrowUpDown;

              return (
                <th
                  key={config.label}
                  className={clsx(
                    "bg-slate-100 px-4 py-3 font-semibold dark:bg-zinc-900",
                    config.align === "right" && "text-right",
                    config.align === "center" && "text-center",
                    config.className,
                    stickyHeader && "sticky top-0 z-10 shadow-sm",
                  )}
                >
                  {config.sortKey ? (
                    <button
                      type="button"
                      onClick={() => onSort?.(config.sortKey)}
                      className={clsx(
                        "inline-flex items-center gap-1.5 hover:text-slate-900 dark:hover:text-zinc-100",
                        config.align === "right" && "justify-end",
                        config.align === "center" && "justify-center",
                      )}
                    >
                      <span>{config.label}</span>
                      <Icon
                        className={clsx(
                          "h-3.5 w-3.5",
                          active && "text-brand-600 dark:text-brand-100",
                        )}
                      />
                    </button>
                  ) : (
                    config.label
                  )}
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 dark:divide-zinc-800 [&>tr]:transition-colors [&>tr:hover]:bg-brand-50/70 dark:[&>tr:hover]:bg-zinc-800/80">
          {renderedRows}
        </tbody>
      </table>
    </div>
  );
}
