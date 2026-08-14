/**
 * Whole-number quantity helpers — no trailing decimals in inputs or display.
 */

/** Truncate at `.` and strip non-digits; returns "" or a non-negative integer. */
export function coerceWholeQuantity(raw, { min = 0, allowEmpty = true } = {}) {
  if (raw === "" || raw == null) return allowEmpty ? "" : min;
  const head = String(raw).split(".")[0].replace(/[^\d]/g, "");
  if (head === "") return allowEmpty ? "" : min;
  const n = Math.trunc(Number(head));
  if (!Number.isFinite(n)) return allowEmpty ? "" : min;
  if (n < min) return allowEmpty ? "" : min;
  return n;
}

/** Controlled input value: always an integer string (or ""). */
export function wholeQuantityInputValue(value) {
  if (value === "" || value == null) return "";
  const n = Math.trunc(Number(value));
  return Number.isFinite(n) ? String(n) : "";
}

/** Display / print: "1,234" with no fraction digits. */
export function formatWholeQuantity(value, fallback = "") {
  if (value === "" || value == null) return fallback;
  const n = Math.trunc(Number(value));
  if (!Number.isFinite(n)) return fallback;
  return n.toLocaleString();
}
