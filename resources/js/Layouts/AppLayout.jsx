import { Link, router, usePage } from "@inertiajs/react";
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  BadgeDollarSign,
  Boxes,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Database,
  FileClock,
  FileText,
  LayoutDashboard,
  LogOut,
  Moon,
  PackageCheck,
  Send,
  Sun,
  Truck,
  UserRound,
  UsersRound,
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

const nav = [
  {
    href: "/",
    label: "Dashboard",
    icon: LayoutDashboard,
    permissions: ["view dashboards", "submit drmd aa requests"],
  },
  {
    href: "/warehouses",
    label: "Warehouses",
    icon: Warehouse,
    permissions: ["manage warehouses"],
  },
  {
    href: "/inventory",
    label: "Inventory",
    icon: Boxes,
    permissions: ["manage inventory", "view dashboards"],
  },
  {
    href: "/inventory/e-stock-card",
    label: "E-Stock Card",
    icon: ClipboardList,
    permissions: ["manage inventory"],
  },
  {
    href: "/near-expiry",
    label: "Near Expiry",
    icon: Send,
    permissions: ["manage near expiry"],
  },
  {
    href: "/fni-issuances",
    label: "FNI Issuances",
    icon: PackageCheck,
    permissions: ["manage inventory", "view dashboards"],
  },
  {
    href: "/requests",
    label: "FNI Requests",
    icon: ClipboardList,
    permissions: ["encode requests", "monitor requests", "process requests"],
  },
  {
    href: "/drmd-aa/requests",
    label: "Requests",
    icon: FileText,
    permissions: ["submit drmd aa requests"],
  },
  {
    href: "/drmd-aa/proposals",
    label: "Proposals",
    icon: FileText,
    permissions: ["submit drmd aa requests"],
  },
  {
    href: "/dispatches",
    label: "Dispatch",
    icon: Truck,
    permissions: ["manage dispatches"],
  },
  {
    href: "/libraries",
    label: "Libraries",
    icon: Database,
    permissions: ["manage inventory", "encode requests", "manage users"],
  },
  {
    href: "/dromic",
    label: "DROMIC",
    icon: ClipboardList,
    permissions: ["manage dromic reports"],
  },
  {
    href: "/audit-trail",
    label: "Audit Trail",
    icon: FileClock,
    permissions: ["view audit logs"],
  },
  {
    href: "/access-management",
    label: "User Access",
    icon: UsersRound,
    permissions: ["manage users"],
  },
  {
    href: "/psgc-addresses",
    label: "PSGC Addresses",
    icon: Database,
    permissions: ["manage users", "manage psgc addresses"],
  },
  {
    href: "/population",
    label: "Population",
    icon: UsersRound,
    permissions: ["manage users", "manage population"],
  },
  {
    href: "/standby-funds",
    label: "Standby Funds",
    icon: BadgeDollarSign,
    permissions: ["manage standby funds"],
  },
];

const superAdminAccessGroups = [
  {
    key: "rros",
    label: "RROS",
    icon: Warehouse,
    hrefs: [
      "/",
      "/warehouses",
      "/inventory",
      "/inventory/e-stock-card",
      "/near-expiry",
      "/fni-issuances",
      "/requests",
      "/dispatches",
    ],
  },
  {
    key: "drrs",
    label: "DRRS",
    icon: ClipboardList,
    hrefs: [
      "/",
      "/inventory",
      "/near-expiry",
      "/fni-issuances",
      "/requests",
    ],
  },
];

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
  "/near-expiry": [
    { id: "near-expiry-overview", label: "Expiry & Ageing Overview" },
    { id: "near-expiry-filters", label: "Filters & Tabs" },
    { id: "near-expiry-item-breakdown", label: "Item Breakdown" },
    { id: "near-expiry-warehouse-breakdown", label: "Warehouse Breakdown" },
    { id: "near-expiry-stock", label: "Monitoring Table" },
    { id: "near-expiry-plans", label: "Distribution Plans" },
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
    { id: "psgc-managed-districts", label: "Managed Districts" },
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
  "/requests": [
    { id: "request-encode", label: "Encode Request" },
    { id: "request-list", label: "Request List" },
  ],
  "/dispatches": [
    { id: "dispatch-create", label: "Create Dispatch" },
    { id: "dispatch-list", label: "Dispatch Records" },
  ],
  "/libraries": [
    { id: "fni-library-overview", label: "Libraries Overview" },
    { id: "library-group-rros-references", label: "RROS References" },
    { id: "library-group-drims-references", label: "DRIMS References" },
    { id: "library-group-drrs-references", label: "DRRS References" },
    { id: "library-group-system-configuration", label: "System Configuration" },
  ],
  "/dromic": [
    { id: "dromic-create", label: "Create DROMIC Report" },
    { id: "dromic-list", label: "DROMIC Reports" },
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

export default function AppLayout({ title, children }) {
  const { auth, flash, activeRegion, systemName, systemNameShort } = usePage().props;
  const currentUrl = usePage().url.split("?")[0];
  const permissions = auth.user?.permissions ?? [];
  const userTheme = auth.user?.theme_mode;
  const [dark, setDark] = useState(
    userTheme
      ? userTheme === "dark"
      : document.documentElement.classList.contains("dark"),
  );
  const [sidebarCollapsed, setSidebarCollapsed] = useState(
    typeof window !== "undefined" ? window.innerWidth < 1024 : false,
  );
  const getGlobalLoaderRemaining = () =>
    Math.max((window.__drmdPageLoaderVisibleUntil ?? 0) - Date.now(), 0);
  const [pageLoading, setPageLoading] = useState(
    () => getGlobalLoaderRemaining() > 0,
  );
  const [toast, setToast] = useState(null);
  const [openPageTrees, setOpenPageTrees] = useState({});
  const [openAccessGroups, setOpenAccessGroups] = useState({});
  const loaderStartedAt = useRef(0);
  const loaderTimeout = useRef(null);
  const toastTimeout = useRef(null);
  const inactivityTimeout = useRef(null);
  const inactivityLoggedOut = useRef(false);

  const showToast = (nextToast) => {
    if (!nextToast?.message) {
      return;
    }

    if (toastTimeout.current) {
      clearTimeout(toastTimeout.current);
    }

    setToast(nextToast);
    toastTimeout.current = setTimeout(() => setToast(null), 3000);
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

    return () => {
      if (toastTimeout.current) {
        clearTimeout(toastTimeout.current);
      }

      removeSuccessListener();
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
      showGlobalLoader();
    });
    const removeStartListener = router.on("start", () => {
      showGlobalLoader();
    });
    const removeFinishListener = router.on("finish", () => {
      hideGlobalLoaderAfterMinimum();
    });

    return () => {
      if (loaderTimeout.current) {
        clearTimeout(loaderTimeout.current);
      }

      removeBeforeListener();
      removeStartListener();
      removeFinishListener();
    };
  }, []);

  useEffect(() => {
    const inactivityLimit = 15 * 60 * 1000;
    const activityEvents = [
      "mousemove",
      "mousedown",
      "keydown",
      "scroll",
      "touchstart",
    ];

    const resetInactivityTimer = () => {
      if (inactivityLoggedOut.current) {
        return;
      }

      if (inactivityTimeout.current) {
        clearTimeout(inactivityTimeout.current);
      }

      inactivityTimeout.current = setTimeout(() => {
        inactivityLoggedOut.current = true;
        router.post(
          "/logout",
          { reason: "inactivity" },
          {
            preserveScroll: false,
            onError: () => {
              inactivityLoggedOut.current = false;
            },
          },
        );
      }, inactivityLimit);
    };

    activityEvents.forEach((event) =>
      window.addEventListener(event, resetInactivityTimer, { passive: true }),
    );
    resetInactivityTimer();

    return () => {
      if (inactivityTimeout.current) {
        clearTimeout(inactivityTimeout.current);
      }

      activityEvents.forEach((event) =>
        window.removeEventListener(event, resetInactivityTimer),
      );
    };
  }, []);

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

  const canSee = (item) =>
    item.permissions.some((permission) => permissions.includes(permission));
  const isSuperAdmin = auth.user?.roles?.includes("Super Admin") ?? false;
  const visibleNav = nav.filter(
    (item) =>
      canSee(item) &&
      (item.href !== "/libraries" || isSuperAdmin) &&
      (!isSuperAdmin || !["/drmd-aa/requests", "/drmd-aa/proposals"].includes(item.href)),
  );
  const groupedSuperAdminHrefs = new Set(
    superAdminAccessGroups.flatMap((group) => group.hrefs),
  );
  const standaloneSuperAdminNav = visibleNav.filter(
    (item) => !groupedSuperAdminHrefs.has(item.href),
  );
  const matchesHref = (href) =>
    href === "/"
      ? currentUrl === "/" || currentUrl.startsWith("/dashboard")
      : currentUrl === href || currentUrl.startsWith(`${href}/`);
  const activeHref = visibleNav
    .filter((item) => matchesHref(item.href))
    .sort((a, b) => b.href.length - a.href.length)[0]?.href;
  const activeItem = visibleNav.find((item) => item.href === activeHref);
  const HeaderIcon = activeItem?.icon ?? LayoutDashboard;
  const SidebarToggleIcon = sidebarCollapsed ? ChevronRight : ChevronLeft;
  const userInitial = auth.user?.name?.trim()?.charAt(0)?.toUpperCase() ?? "U";
  const scrollToPageSection = (href, sectionId) => {
    const scroll = () => {
      document
        .getElementById(sectionId)
        ?.scrollIntoView({ behavior: "smooth", block: "start" });
      window.history.replaceState(null, "", `#${sectionId}`);
    };

    if (!matchesHref(href)) {
      router.visit(href, {
        preserveScroll: false,
        onSuccess: () => window.setTimeout(scroll, 150),
      });
      return;
    }

    scroll();
  };

  const renderNavItem = (item, groupKey = "main") => {
    const Icon = item.icon;
    const active = activeHref === item.href;
    const pageTree = item.href === "/" && permissions.includes("submit drmd aa requests") && !permissions.includes("view dashboards")
      ? null
      : (pageTrees[item.href] ?? null);
    const treeKey = `${groupKey}:${item.href}`;
    const pageTreeOpen = Boolean(openPageTrees[treeKey]);

    return (
      <div key={treeKey}>
        <div
          className={clsx(
            "group relative flex items-center rounded-md text-sm font-semibold transition",
            sidebarCollapsed ? "justify-center" : "gap-1",
            active
              ? "bg-brand-50 text-brand-800 shadow-sm ring-1 ring-brand-200 dark:bg-transparent dark:text-white dark:ring-brand-300/60"
              : "text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-zinc-300 dark:hover:bg-zinc-900 dark:hover:text-zinc-50",
          )}
        >
          {active && <span className="absolute bottom-2 left-0 top-2 w-1 rounded-r-full bg-brand-600 dark:bg-brand-300" />}
          <Link
            href={item.href}
            title={sidebarCollapsed ? item.label : undefined}
            className={clsx(
              "flex min-w-0 flex-1 items-center rounded-md transition",
              sidebarCollapsed ? "justify-center px-2 py-2.5" : "gap-3 py-2.5 pl-3",
              pageTree ? "pr-1" : "pr-3",
            )}
          >
            <span className={clsx(
              "flex h-8 w-8 items-center justify-center rounded-md transition",
              active
                ? "bg-white text-brand-700 shadow-sm dark:bg-transparent dark:text-brand-100 dark:shadow-none"
                : "bg-slate-50 text-slate-500 group-hover:text-slate-800 dark:bg-transparent dark:text-zinc-400",
            )}>
              <Icon className="h-4 w-4" />
            </span>
            <span className={clsx("min-w-0 flex-1", sidebarCollapsed && "hidden")}>{item.label}</span>
          </Link>
          {pageTree && !sidebarCollapsed && (
            <button
              type="button"
              aria-label={pageTreeOpen ? `Collapse ${item.label} menu` : `Expand ${item.label} menu`}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                setOpenPageTrees((current) => ({ ...current, [treeKey]: !current[treeKey] }));
              }}
              className="mr-2 flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-brand-700 dark:text-zinc-500 dark:hover:bg-zinc-900 dark:hover:text-brand-100"
            >
              {pageTreeOpen ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
            </button>
          )}
        </div>
        {pageTree && pageTreeOpen && !sidebarCollapsed && (
          <div className="ml-7 mt-2 space-y-1 border-l border-brand-200 pb-2 pl-4 dark:border-brand-300/30">
            {pageTree.map((section) => (
              <button
                key={section.id}
                type="button"
                onClick={() => scrollToPageSection(item.href, section.id)}
                className="group flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-bold text-slate-500 transition hover:bg-brand-50 hover:text-brand-800 dark:text-zinc-400 dark:hover:bg-brand-950/30 dark:hover:text-brand-100"
              >
                <span className="h-1.5 w-1.5 rounded-full bg-slate-300 transition group-hover:bg-brand-600 dark:bg-zinc-600 dark:group-hover:bg-brand-300" />
                <span>{section.label}</span>
              </button>
            ))}
          </div>
        )}
      </div>
    );
  };

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 dark:bg-zinc-950 dark:text-zinc-100">
      <CreativePageLoader active={pageLoading} />
      {toast && (
        <div className="fixed right-4 top-5 z-[60] w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-md border border-slate-200 bg-white shadow-2xl shadow-slate-950/10 dark:border-zinc-800 dark:bg-zinc-900 dark:shadow-black/30">
          <div
            className={clsx(
              "h-1",
              toast.type === "success" ? "bg-emerald-500" : "bg-rose-500",
            )}
          />
          <div className="flex gap-3 p-4">
            <div
              className={clsx(
                "flex h-9 w-9 shrink-0 items-center justify-center rounded-full",
                toast.type === "success"
                  ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200"
                  : "bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-200",
              )}
            >
              {toast.type === "success" ? (
                <CheckCircle2 className="h-5 w-5" />
              ) : (
                <AlertTriangle className="h-5 w-5" />
              )}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold text-slate-950 dark:text-white">
                {toast.type === "success"
                  ? "Transaction successful"
                  : "Transaction failed"}
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
      <aside
        className={clsx(
          "fixed inset-y-0 left-0 z-20 max-w-[100vw] overflow-visible border-r border-slate-200 bg-white transition-all duration-200 dark:border-zinc-800 dark:bg-zinc-950",
          sidebarCollapsed ? "w-20" : "w-72",
        )}
      >
        <button
          type="button"
          onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
          title={sidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"}
          className={clsx(
            "absolute top-6 z-10 flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:text-slate-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-400 dark:hover:text-white dark:hover:bg-zinc-900",
            sidebarCollapsed ? "-right-2" : "-right-3",
          )}
        >
          <SidebarToggleIcon className="h-4 w-4" />
        </button>
        <div className="flex h-full min-h-0 flex-col">
          <div
            className={clsx(
              "border-b border-slate-200 py-5 dark:border-zinc-800",
              sidebarCollapsed ? "px-3" : "px-5",
            )}
          >
            <div
              className={clsx(
                "flex items-center gap-3",
                sidebarCollapsed && "justify-center",
              )}
            >
              <img
                src="/images/drmd-cir-logo.png"
                alt="DRMD"
                className="h-11 w-11 rounded-full object-contain shadow-sm"
              />
              <div className={clsx(sidebarCollapsed && "hidden")}>
                <div className="text-xs font-bold uppercase tracking-wide text-brand-700 dark:text-brand-100">
                  {activeRegion?.field_office_label || "DSWD CARAGA"}
                </div>
                <div className="text-sm font-extrabold leading-tight">
                  {systemNameShort || systemName || 'DRIMS'}
                </div>
              </div>
            </div>
            <div
              className={clsx(
                "mt-4 flex items-center gap-3 rounded-md bg-slate-50 px-3 py-3 dark:bg-zinc-900",
                sidebarCollapsed && "hidden",
              )}
            >
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white text-brand-700 shadow-sm ring-1 ring-slate-200 dark:bg-zinc-950 dark:text-brand-100 dark:ring-zinc-700">
                {auth.user?.avatar ? (
                  <img
                    src={auth.user.avatar}
                    alt={auth.user?.name ?? "User"}
                    className="h-full w-full rounded-full object-cover"
                  />
                ) : (
                  <div className="relative flex h-full w-full items-center justify-center">
                    <UserRound className="h-5 w-5 opacity-70" />
                    <span className="absolute bottom-1 right-1 flex h-4 w-4 items-center justify-center rounded-full bg-brand-600 text-[9px] font-bold text-white ring-2 ring-white dark:bg-brand-300 dark:text-zinc-950 dark:ring-zinc-950">
                      {userInitial}
                    </span>
                  </div>
                )}
              </div>
              <div className="min-w-0">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                  Signed in
                </p>
                <p className="truncate text-sm font-bold text-slate-950 dark:text-white">
                  {auth.user?.name}
                </p>
                <p className="truncate text-xs text-slate-500 dark:text-zinc-400">
                  {auth.user?.office}
                </p>
              </div>
            </div>
          </div>
          <nav className="min-h-0 flex-1 space-y-1 overflow-y-auto overflow-x-hidden px-3 py-4">
            {isSuperAdmin ? (
              <>
                {superAdminAccessGroups.map((group) => {
                  const GroupIcon = group.icon;
                  const groupOpen = Boolean(openAccessGroups[group.key]);
                  const groupItems = group.hrefs
                    .map((href) => visibleNav.find((item) => item.href === href))
                    .filter(Boolean);

                  return (
                    <div key={group.key} className="rounded-lg border border-slate-200 bg-slate-50/70 p-1 dark:border-zinc-800 dark:bg-zinc-900/40">
                      <button
                        type="button"
                        onClick={() => setOpenAccessGroups((current) => ({ ...current, [group.key]: !current[group.key] }))}
                        className={clsx(
                          "flex w-full items-center rounded-md px-2 py-2 text-left text-sm font-black text-slate-700 transition hover:bg-white hover:text-brand-800 dark:text-zinc-200 dark:hover:bg-zinc-900 dark:hover:text-brand-100",
                          sidebarCollapsed ? "justify-center" : "gap-3",
                        )}
                        title={sidebarCollapsed ? group.label : undefined}
                      >
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-white text-brand-700 shadow-sm dark:bg-zinc-950 dark:text-brand-100">
                          <GroupIcon className="h-4 w-4" />
                        </span>
                        <span className={clsx("min-w-0 flex-1", sidebarCollapsed && "hidden")}>{group.label}</span>
                        {!sidebarCollapsed && (groupOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />)}
                      </button>
                      {groupOpen && !sidebarCollapsed && (
                        <div className="mt-1 space-y-1 border-l-2 border-brand-200 pl-2 dark:border-brand-300/30">
                          {groupItems.map((item) => renderNavItem(item, group.key))}
                        </div>
                      )}
                    </div>
                  );
                })}
                <div className="my-2 h-px bg-slate-200 dark:bg-zinc-800" />
                {standaloneSuperAdminNav.map((item) => renderNavItem(item))}
              </>
            ) : (
              visibleNav.map((item) => renderNavItem(item))
            )}
          </nav>
          <div
            className={clsx(
              "shrink-0 border-t border-slate-200 bg-white px-3 py-2 text-center text-[10px] leading-tight text-slate-500 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400",
              sidebarCollapsed && "hidden",
            )}
          >
            <p>&copy; Copyright 2026</p>
            <p>All Rights Reserved</p>
            <p className="mt-1 font-semibold text-slate-600 dark:text-zinc-300">
              Developer: Roger L. Ongue, PDO II
            </p>
          </div>
        </div>
      </aside>

      <div
        className={clsx(
          "min-w-0 overflow-x-hidden transition-all duration-200",
          sidebarCollapsed ? "ml-20" : "ml-72",
        )}
      >
        <header
          className={clsx(
            "fixed right-0 top-0 z-30 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur-xl transition-all duration-200 dark:border-zinc-800 dark:bg-zinc-950/95",
            sidebarCollapsed ? "left-20" : "left-72",
          )}
        >
          <div className="flex items-center justify-between gap-4">
            <div className="flex h-16 shrink-0 items-center gap-3 bg-transparent">
              <span className="relative block h-12 w-[122px] shrink-0">
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
              <span className="relative block h-12 w-12 shrink-0">
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
              <h1 className="flex items-center gap-3 text-xl font-bold leading-tight">
                <span className="flex h-10 w-10 items-center justify-center rounded-md bg-slate-100 text-brand-700 dark:bg-transparent dark:text-brand-100">
                  <HeaderIcon className="h-5 w-5" />
                </span>
                <span className="truncate">{title}</span>
              </h1>
              <p className="text-xs text-slate-500 dark:text-zinc-400">
                {auth.user?.office} · {auth.user?.name}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={toggleTheme}
                title="Toggle dark mode"
                className="rounded-md border border-slate-200 bg-white p-2 shadow-sm hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
              >
                {dark ? (
                  <Sun className="h-4 w-4" />
                ) : (
                  <Moon className="h-4 w-4" />
                )}
              </button>
              <button
                type="button"
                onClick={() => router.post("/logout")}
                title="Sign out"
                className="rounded-md border border-slate-200 bg-white p-2 shadow-sm hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
              >
                <LogOut className="h-4 w-4" />
              </button>
            </div>
          </div>
        </header>
        <main className="min-w-0 px-4 pb-6 pt-28">
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
        "max-w-full overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900",
        className,
      )}
    >
      <table className="w-full min-w-max divide-y divide-slate-200 text-sm dark:divide-zinc-800">
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
