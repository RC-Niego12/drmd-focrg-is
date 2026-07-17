import { Link, router, usePage } from "@inertiajs/react";
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
  FileClock,
  FileText,
  IdCard,
  LayoutDashboard,
  LogOut,
  Mail,
  LoaderCircle,
  MessageCircle,
  Moon,
  PackageCheck,
  Phone,
  Send,
  Sparkles,
  Sun,
  Truck,
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
    href: "/lgu/dromic-requests",
    label: "LGU DROMIC",
    icon: FileText,
    permissions: ["submit lgu dromic requests"],
  },
  {
    href: "/drmd-aa/lgu-intake",
    label: "LGU Intake",
    icon: FileText,
    permissions: ["route lgu dromic requests"],
  },
  {
    href: "/drmd-chief/lgu-intake",
    label: "Chief Directives",
    icon: ClipboardList,
    permissions: ["route lgu dromic requests"],
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
  const { auth, flash, activeRegion, systemName, systemNameShort, notificationCenter: initialNotificationCenter } = usePage().props;
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
  const [notificationCenter, setNotificationCenter] = useState(initialNotificationCenter);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [messageCenterOpen, setMessageCenterOpen] = useState(false);
  const [messageCenter, setMessageCenter] = useState({ unread_count: 0, messages: [], contacts: [] });
  const [messageDraft, setMessageDraft] = useState({ recipient_id: "", subject: "", body: "" });
  const [messageSending, setMessageSending] = useState(false);
  const [messageLoading, setMessageLoading] = useState(false);
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
  const inactivityLoggedOut = useRef(false);
  const previousUnread = useRef(0);
  const previousPendingRequests = useRef(0);

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
      previousUnread.current = next.unread_count;
      previousPendingRequests.current = next.action_required_count;
      setNotificationCenter(next);
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

  useEffect(() => {
    const interval = window.setInterval(refreshNotificationCenter, 10000);
    return () => window.clearInterval(interval);
  }, [soundEnabled]);

  useEffect(() => {
    refreshMessageCenter();
    const interval = window.setInterval(refreshMessageCenter, 15000);
    return () => window.clearInterval(interval);
  }, []);

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

  const hideWorkspaceLoaderNow = () => {
    if (loaderTimeout.current) {
      clearTimeout(loaderTimeout.current);
    }

    window.__drmdPageLoaderVisibleUntil = 0;
    loaderStartedAt.current = 0;
    setPageLoading(false);
  };

  const openNotification = async (notification) => {
    if (notification.action_required && notification.acted) {
      hideWorkspaceLoaderNow();
      setNotificationsOpen(false);
      showToast({ message: "This notification has already been acted on.", type: "success" });
      if (!notification.read_at) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(`/notifications/${notification.id}/read`, {
          method: "PATCH",
          credentials: "same-origin",
          headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
        }).then(refreshNotificationCenter).catch(() => {});
      }
      return;
    }

    if (!notification.read_at) {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
      await fetch(`/notifications/${notification.id}/read`, {
        method: "PATCH",
        credentials: "same-origin",
        headers: { "X-CSRF-TOKEN": csrf, Accept: "application/json" },
      });
    }

    if (notification.kind === "access_requested") {
      const pending = notificationCenter?.pending_requests?.find((item) => item.id === notification.access_user_id);
      if (pending) {
        setSelectedAccessRequest(pending);
        setNotificationsOpen(false);
      } else {
        router.visit(notification.url || "/access-management");
      }
    } else {
      router.visit(notification.url || "/access/request");
    }

    refreshNotificationCenter();
  };

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
              <div className="min-w-0 flex-1">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                  Signed in
                </p>
                <div className="flex min-w-0 items-center gap-1.5">
                  <p className="truncate text-sm font-bold text-slate-950 dark:text-white">
                    {auth.user?.name}
                  </p>
                  <button
                    type="button"
                    onClick={() => setProfileOpen(true)}
                    title="View employee profile"
                    className="shrink-0 rounded-md p-1 text-slate-400 transition hover:bg-white hover:text-brand-700 dark:hover:bg-zinc-800 dark:hover:text-brand-100"
                  >
                    <IdCard className="h-3.5 w-3.5" />
                  </button>
                </div>
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
              <div className="relative">
                <button
                  type="button"
                  onClick={() => {
                    setNotificationsOpen((open) => !open);
                    setMessageCenterOpen(false);
                  }}
                  title="Notifications"
                  className="relative rounded-md border border-slate-200 bg-white p-2 shadow-sm hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                >
                  <Bell className="h-4 w-4" />
                  {Math.max(notificationCenter?.unread_count ?? 0, notificationCenter?.action_required_count ?? 0) > 0 && <span className="absolute -right-2 -top-2 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-black text-white">{Math.min(Math.max(notificationCenter?.unread_count ?? 0, notificationCenter?.action_required_count ?? 0), 99)}</span>}
                </button>
                {notificationsOpen && (
                  <div className="absolute right-0 top-12 z-50 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
                    <div className="flex items-center justify-between border-b border-slate-200 p-4 dark:border-zinc-800">
                      <div><p className="font-black">Notifications</p><p className="text-xs text-slate-500">{notificationCenter?.unread_count ?? 0} unread · {notificationCenter?.action_required_count ?? 0} awaiting action</p></div>
                      {isSuperAdmin && <button type="button" onClick={toggleNotificationSound} title={soundEnabled ? "Disable voice alerts" : "Enable voice alerts"} className="rounded-md p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-zinc-900">{soundEnabled ? <Volume2 className="h-4 w-4" /> : <VolumeX className="h-4 w-4" />}</button>}
                    </div>
                    <div className="max-h-96 overflow-y-auto">
                      {(notificationCenter?.notifications ?? []).length ? notificationCenter.notifications.map((notification) => (
                        <button key={notification.id} type="button" onClick={() => openNotification(notification)} className={clsx("block w-full border-b border-slate-100 p-4 text-left transition dark:border-zinc-900", notification.action_required && notification.acted ? "cursor-default opacity-70" : "hover:bg-slate-50 dark:hover:bg-zinc-900", !notification.read_at && "bg-brand-50/70 dark:bg-brand-950/20")}>
                          <div className="flex items-start justify-between gap-3">
                            <p className="text-sm font-black">{notification.title}</p>
                            {notification.action_required && notification.acted && <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-100 dark:ring-emerald-800">Acted</span>}
                          </div>
                          <p className="mt-1 text-sm text-slate-600 dark:text-zinc-300">{notification.message}</p>
                          <p className="mt-2 text-xs text-slate-400">{notification.created_at ? new Date(notification.created_at).toLocaleString() : ""}</p>
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
                  title="DROMIS Messages"
                  className="relative rounded-md border border-slate-200 bg-white p-2 shadow-sm transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                >
                  <MessageCircle className="h-4 w-4" />
                  {(messageCenter?.unread_count ?? 0) > 0 && (
                    <span className="absolute -right-2 -top-2 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-sky-600 px-1 text-[10px] font-black text-white">
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
      {selectedAccessRequest && (
        <AccessDecisionModal
          user={selectedAccessRequest}
          roleOptions={notificationCenter?.role_options ?? []}
          onClose={() => setSelectedAccessRequest(null)}
          onSuccess={refreshNotificationCenter}
        />
      )}
      {profileOpen && (
        <EmployeeProfileModal
          user={auth.user}
          onClose={() => setProfileOpen(false)}
        />
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

function MessageCenterPanel({ center, draft, setDraft, loading, sending, onSubmit, onRead }) {
  const messages = center?.messages ?? [];
  const contacts = center?.contacts ?? [];

  return (
    <div className="absolute right-0 top-12 z-50 w-[min(28rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
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
                    {message.is_mine && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase text-slate-500 dark:bg-zinc-800 dark:text-zinc-300">Sent</span>}
                    {unread && <span className="h-2.5 w-2.5 rounded-full bg-sky-600" />}
                  </div>
                </div>
                <p className="mt-2 text-sm font-bold text-slate-800 dark:text-zinc-100">{message.subject}</p>
                <p className="mt-1 line-clamp-3 whitespace-pre-wrap text-sm text-slate-600 dark:text-zinc-300">{message.body}</p>
                <p className="mt-2 text-xs text-slate-400">{message.created_at ? new Date(message.created_at).toLocaleString() : ""}</p>
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
    <div className="fixed bottom-5 right-5 z-40">
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
            : "min-h-14 bg-gradient-to-r from-emerald-700 via-brand-700 to-sky-700 px-4 pr-5 shadow-brand-900/30",
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
          <span className="relative hidden text-left sm:block">
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
  const ssoCheckedAt = user?.sso_profile?.sso_checked_at;
  const myPortalVerified = Boolean(user?.myportal_profile?.verified);
  const myPortalCheckedAt = user?.myportal_profile?.last_checked_at;
  const [syncing, setSyncing] = useState(false);
  const profileValue = (value) => value || "Not returned yet";
  const refreshMyPortal = () => {
    setSyncing(true);
    router.post("/profile/myportal/sync", {}, {
      preserveScroll: true,
      onSuccess: () => router.reload({ only: ["auth", "flash"], preserveScroll: true }),
      onFinish: () => setSyncing(false),
    });
  };
  const employeeFields = [
    { label: "Employee Name", value: profileValue(user?.name), icon: UserRound },
    { label: "Employee ID", value: profileValue(user?.id_number), icon: IdCard },
    { label: "SSO Username", value: profileValue(user?.username), icon: UserCog },
    { label: "Email", value: profileValue(user?.email), icon: Mail },
    { label: "Contact Number", value: profileValue(user?.contact_number || user?.mobile_no), icon: Phone },
    { label: "Office / Section", value: profileValue(user?.office), icon: UserCog },
    { label: "Area of Assignment", value: profileValue(user?.area_of_assignment), icon: UserCog },
    { label: "Position", value: profileValue(user?.position), icon: UserCog },
    { label: "Designation", value: profileValue(user?.designation), icon: UserCog },
    { label: "Employment Status", value: profileValue(user?.employment_status), icon: CheckCircle2 },
    { label: "DROMIS User Level", value: roles, icon: UsersRound },
    { label: "Access Status", value: profileValue(user?.access_status), icon: CheckCircle2 },
    { label: "SSO Subject", value: profileValue(user?.sso_sub), icon: Database },
  ];
  const avatar = user?.avatar || null;

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 py-6 backdrop-blur-sm sm:items-center">
      <div className="flex max-h-[calc(100vh-3rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-950">
        <div className="shrink-0 flex items-start justify-between border-b border-slate-200 bg-slate-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
          <div className="flex min-w-0 items-center gap-4">
            <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white text-brand-700 shadow-sm ring-1 ring-slate-200 dark:bg-zinc-950 dark:text-brand-100 dark:ring-zinc-700">
              {avatar ? (
                <img src={avatar} alt={user?.name ?? "Employee"} className="h-full w-full object-cover" />
              ) : (
                <UserRound className="h-7 w-7 opacity-70" />
              )}
            </div>
            <div className="min-w-0">
              <p className="text-xs font-black uppercase tracking-wide text-brand-700 dark:text-brand-100">Caraga Connect SSO profile</p>
              <h2 className="mt-1 truncate text-xl font-black text-slate-950 dark:text-white">{user?.name || "SSO User"}</h2>
              <p className="truncate text-sm text-slate-500 dark:text-zinc-400">{user?.email || user?.username || "Signed in through Caraga Connect"}</p>
            </div>
          </div>
          <button type="button" onClick={onClose} className="rounded-md p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-zinc-800 dark:hover:text-white">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="min-h-0 overflow-y-auto">
          <div className="border-b border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
            <div className={clsx("rounded-md border p-4", myPortalVerified ? "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100" : "border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100")}>
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm font-black">{myPortalVerified ? "MyPortal profile connected" : "MyPortal profile not yet refreshed"}</p>
                  <p className="mt-1 text-sm">
                    {myPortalVerified
                      ? "These fields were refreshed from the documented Caraga Connect MyPortal API."
                      : "Click refresh to retrieve the employee profile through the MyPortal API token flow."}
                  </p>
                  {myPortalCheckedAt && (
                    <p className="mt-2 text-xs font-semibold opacity-80">Latest MyPortal check: {myPortalCheckedAt}</p>
                  )}
                  {ssoCheckedAt && !myPortalCheckedAt && (
                    <p className="mt-2 text-xs font-semibold opacity-80">Latest SSO sign-in: {ssoCheckedAt}</p>
                  )}
                </div>
                <button
                  type="button"
                  onClick={refreshMyPortal}
                  disabled={syncing}
                  className="shrink-0 rounded-md bg-brand-700 px-3 py-2 text-xs font-black text-white shadow-sm transition hover:bg-brand-800 disabled:opacity-60"
                >
                  {syncing ? "Refreshing..." : "Refresh from MyPortal"}
                </button>
              </div>
            </div>
          </div>

          <div className="grid gap-3 p-5 sm:grid-cols-2">
            {employeeFields.map(({ label, value, icon: Icon }) => (
              <div key={label} className="rounded-md border border-slate-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950">
                <div className="flex items-start gap-3">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-100">
                    <Icon className="h-4 w-4" />
                  </span>
                  <div className="min-w-0">
                    <p className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</p>
                    <p className="mt-1 break-words text-sm font-bold text-slate-800 dark:text-zinc-100">{value || "-"}</p>
                  </div>
                </div>
              </div>
            ))}
          </div>

        </div>

        <div className="shrink-0 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
          DROMIS signs users in through SSO, then uses the configured MyPortal API credential only when profile refresh is requested.
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
