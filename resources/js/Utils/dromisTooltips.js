/**
 * App-wide tooltips: upgrade title/aria-label to data-tip, and render a single
 * fixed portal on document.body so overflow-hidden modals cannot clip labels.
 */
export function installDromisTooltips() {
  if (typeof document === "undefined") {
    return () => {};
  }

  document.body.classList.add("dromis-tooltips-portaled");

  const portal = document.createElement("div");
  portal.className = "dromis-tip-portal";
  portal.setAttribute("role", "tooltip");
  portal.hidden = true;
  document.body.appendChild(portal);

  let activeElement = null;

  const upgradeTooltips = (root = document) => {
    const elements = [];
    // Match former CSS tip targets: title, or aria-label without title.
    const selector = "button[title], a[title], button[aria-label]:not([title]), a[aria-label]:not([title])";
    if (root instanceof Element && root.matches(selector)) elements.push(root);
    root.querySelectorAll?.(selector).forEach((element) => elements.push(element));
    elements.forEach((element) => {
      const label = element.getAttribute("title") || element.getAttribute("aria-label");
      if (!label) return;
      // Prefer an explicit data-tip from React when present; still refresh from
      // title so reused table action buttons stay in sync with row state.
      if (element.getAttribute("title") || !element.dataset.tip) {
        element.dataset.tip = label;
      }
      element.removeAttribute("title");
      element.classList.remove("native-modal-close-tooltip");
      element.classList.add("dromis-tip");
      delete element.dataset.nativeTooltip;

      const rect = element.getBoundingClientRect();
      if (!element.dataset.tipAlign) {
        if (rect.left < 170) element.dataset.tipAlign = "left";
        else if (rect.right > window.innerWidth - 170) element.dataset.tipAlign = "right";
      }
      if (!element.dataset.tipSide) {
        let ancestor = element.parentElement;
        while (ancestor && ancestor !== document.body) {
          const overflow = getComputedStyle(ancestor);
          if (/(auto|scroll|hidden|clip)/.test(`${overflow.overflow} ${overflow.overflowX} ${overflow.overflowY}`)) {
            const boundary = ancestor.getBoundingClientRect();
            if (rect.top - boundary.top < 52) element.dataset.tipSide = "bottom";
            break;
          }
          ancestor = ancestor.parentElement;
        }
      }
    });
  };

  const resolvePlacement = (element) => {
    if (element.dataset.tipLocked === "true") return;

    const rect = element.getBoundingClientRect();
    let boundary = { left: 0, right: window.innerWidth, top: 0, bottom: window.innerHeight };
    let ancestor = element.parentElement;
    while (ancestor && ancestor !== document.body) {
      const overflow = getComputedStyle(ancestor);
      if (/(auto|scroll|hidden|clip)/.test(`${overflow.overflow} ${overflow.overflowX} ${overflow.overflowY}`)) {
        const candidate = ancestor.getBoundingClientRect();
        boundary = {
          left: Math.max(0, candidate.left),
          right: Math.min(window.innerWidth, candidate.right),
          top: Math.max(0, candidate.top),
          bottom: Math.min(window.innerHeight, candidate.bottom),
        };
        break;
      }
      ancestor = ancestor.parentElement;
    }

    const preferredSide = element.dataset.tipPreferredSide || element.dataset.tipSide || "";

    if (preferredSide === "left" || preferredSide === "right") {
      element.dataset.tipSide = preferredSide;
      delete element.dataset.tipAlign;
      return;
    }

    const estimatedWidth = Math.min(256, Math.max(90, String(element.dataset.tip || "").length * 7.2));

    if (preferredSide !== "bottom") {
      if (rect.left - boundary.left < estimatedWidth / 2 + 8) element.dataset.tipAlign = "left";
      else if (boundary.right - rect.right < estimatedWidth / 2 + 8) element.dataset.tipAlign = "right";
      else delete element.dataset.tipAlign;

      if (rect.top - boundary.top < 52) element.dataset.tipSide = "bottom";
      else if (element.dataset.tipSide === "bottom" && rect.bottom + 70 > boundary.bottom) delete element.dataset.tipSide;
    } else {
      element.dataset.tipSide = "bottom";
      if (rect.left - boundary.left < estimatedWidth / 2 + 8) element.dataset.tipAlign = "left";
      else if (boundary.right - rect.right < estimatedWidth / 2 + 8) element.dataset.tipAlign = "right";
      else delete element.dataset.tipAlign;
    }
  };

  const hideTooltip = () => {
    activeElement = null;
    portal.hidden = true;
    portal.classList.remove("is-visible");
    portal.removeAttribute("data-side");
    portal.removeAttribute("data-align");
  };

  const showTooltip = (element) => {
    const text = String(element.dataset.tip || "").trim();
    if (!text) return;

    resolvePlacement(element);

    const side = element.dataset.tipSide || "top";
    const align = element.dataset.tipAlign || "center";
    const rect = element.getBoundingClientRect();

    activeElement = element;
    portal.textContent = text;
    portal.dataset.side = side;
    portal.dataset.align = align;
    portal.hidden = false;
    portal.style.visibility = "hidden";
    portal.classList.add("is-visible");

    const tipRect = portal.getBoundingClientRect();
    const gap = 8;
    let top = 0;
    let left = 0;

    if (side === "right") {
      top = rect.top + rect.height / 2 - tipRect.height / 2;
      left = rect.right + gap;
    } else if (side === "left") {
      top = rect.top + rect.height / 2 - tipRect.height / 2;
      left = rect.left - tipRect.width - gap;
    } else if (side === "bottom") {
      top = rect.bottom + gap;
      if (align === "left") left = rect.left;
      else if (align === "right") left = rect.right - tipRect.width;
      else left = rect.left + rect.width / 2 - tipRect.width / 2;
    } else {
      top = rect.top - tipRect.height - gap;
      if (align === "left") left = rect.left;
      else if (align === "right") left = rect.right - tipRect.width;
      else left = rect.left + rect.width / 2 - tipRect.width / 2;
    }

    const pad = 8;
    left = Math.min(window.innerWidth - tipRect.width - pad, Math.max(pad, left));
    top = Math.min(window.innerHeight - tipRect.height - pad, Math.max(pad, top));

    portal.style.top = `${Math.round(top)}px`;
    portal.style.left = `${Math.round(left)}px`;
    portal.style.visibility = "visible";
  };

  const findTipTarget = (event) => {
    const el = event.target instanceof Element ? event.target.closest(".dromis-tip[data-tip]") : null;
    return el instanceof HTMLElement ? el : null;
  };

  const resolveTipTarget = (event) => {
    let element = findTipTarget(event);
    if (element) return element;
    const candidate = event.target instanceof Element
      ? event.target.closest("button[aria-label], a[aria-label], button[title], a[title]")
      : null;
    if (!candidate) return null;
    upgradeTooltips(candidate);
    return findTipTarget(event);
  };

  const onPointerOver = (event) => {
    const element = resolveTipTarget(event);
    if (!element) return;
    showTooltip(element);
  };

  const onFocusIn = (event) => {
    const element = resolveTipTarget(event);
    if (!element) return;
    showTooltip(element);
  };

  const onPointerOut = (event) => {
    if (!activeElement) return;
    const related = event.relatedTarget instanceof Element ? event.relatedTarget : null;
    if (related && activeElement.contains(related)) return;
    if (related?.closest?.(".dromis-tip[data-tip]") === activeElement) return;
    hideTooltip();
  };

  const onFocusOut = (event) => {
    if (!activeElement) return;
    const related = event.relatedTarget instanceof Element ? event.relatedTarget : null;
    if (related && (activeElement === related || activeElement.contains(related))) return;
    hideTooltip();
  };

  const onScrollOrResize = () => {
    if (!activeElement || !document.contains(activeElement)) {
      hideTooltip();
      return;
    }
    showTooltip(activeElement);
  };

  upgradeTooltips();
  document.addEventListener("pointerover", onPointerOver, true);
  document.addEventListener("focusin", onFocusIn, true);
  document.addEventListener("pointerout", onPointerOut, true);
  document.addEventListener("focusout", onFocusOut, true);
  window.addEventListener("scroll", onScrollOrResize, true);
  window.addEventListener("resize", onScrollOrResize);

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (mutation.type === "attributes") upgradeTooltips(mutation.target);
      mutation.addedNodes.forEach((node) => {
        if (node instanceof Element) upgradeTooltips(node);
      });
    });
  });
  observer.observe(document.body, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ["title"],
  });

  return () => {
    observer.disconnect();
    document.removeEventListener("pointerover", onPointerOver, true);
    document.removeEventListener("focusin", onFocusIn, true);
    document.removeEventListener("pointerout", onPointerOut, true);
    document.removeEventListener("focusout", onFocusOut, true);
    window.removeEventListener("scroll", onScrollOrResize, true);
    window.removeEventListener("resize", onScrollOrResize);
    portal.remove();
    document.body.classList.remove("dromis-tooltips-portaled");
  };
}
