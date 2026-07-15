const dateTimeFormatter = new Intl.DateTimeFormat(undefined, {
  month: "short",
  day: "numeric",
  year: "numeric",
  hour: "numeric",
  minute: "2-digit",
});

const dateFormatter = new Intl.DateTimeFormat(undefined, {
  month: "short",
  day: "numeric",
  year: "numeric",
});

const expiryFormatter = new Intl.DateTimeFormat("en-US", {
  month: "short",
  year: "numeric",
});

const parseDate = (value, dateOnly = false) => {
  if (!value) return null;
  const normalized = dateOnly && /^\d{4}-\d{2}-\d{2}$/.test(String(value))
    ? `${value}T00:00:00`
    : value;
  const parsed = new Date(normalized);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
};

export const formatDateTime = (value, fallback = "-") => {
  const parsed = parseDate(value);
  return parsed ? dateTimeFormatter.format(parsed) : fallback;
};

export const formatDate = (value, fallback = "-") => {
  const parsed = parseDate(value, true);
  return parsed ? dateFormatter.format(parsed) : fallback;
};

export const formatExpiryMonth = (value, fallback = "N/A") => {
  if (!value || String(value).toUpperCase() === "N/A") return fallback;
  const direct = parseDate(value, true);
  if (direct) return expiryFormatter.format(direct);

  const monthYear = String(value).trim().match(/^([A-Za-z]{3,9})\s+(\d{4})$/);
  if (!monthYear) return String(value);
  const parsed = new Date(`${monthYear[1]} 1, ${monthYear[2]} 00:00:00`);
  return Number.isNaN(parsed.getTime()) ? String(value) : expiryFormatter.format(parsed);
};
