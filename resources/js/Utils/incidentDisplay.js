/**
 * Format affected barangay / area names the way LGU DROMIC titles do:
 * "Brgy. A", "Brgy. A and Brgy. B", "Brgy. A, Brgy. B, and Brgy. C".
 */
export function formatAffectedAreaList(areas = []) {
  const names = [...new Set(
    (Array.isArray(areas) ? areas : [areas])
      .map((value) => String(value ?? "").trim())
      .filter(Boolean)
      .map((value) => value.replace(/^(?:brgy\.?|barangay)\s+/i, "").trim())
      .filter(Boolean)
      .map((value) => `Brgy. ${value}`),
  )];

  if (names.length === 0) return "";
  if (names.length === 1) return names[0];
  if (names.length === 2) return `${names[0]} and ${names[1]}`;
  return `${names.slice(0, -1).join(", ")}, and ${names[names.length - 1]}`;
}

function toUsDateParts(isoDate) {
  const match = String(isoDate || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) return null;
  return { year: match[1], month: match[2], day: match[3] };
}

/**
 * Single date → mm/dd/yyyy
 * Same month → mm/dd-dd/yyyy
 * Same year, different months → mm/dd-mm/dd/yyyy
 * Different years → mm/dd/yyyy-mm/dd/yyyy
 */
export function formatIncidentDateDisplay(start, end = start) {
  const from = toUsDateParts(start);
  if (!from) return "";
  const to = toUsDateParts(end || start) || from;

  if (from.year === to.year && from.month === to.month && from.day === to.day) {
    return `${from.month}/${from.day}/${from.year}`;
  }
  if (from.year === to.year && from.month === to.month) {
    return `${from.month}/${from.day}-${to.day}/${from.year}`;
  }
  if (from.year === to.year) {
    return `${from.month}/${from.day}-${to.month}/${to.day}/${from.year}`;
  }
  return `${from.month}/${from.day}/${from.year}-${to.month}/${to.day}/${to.year}`;
}

/**
 * Earliest/latest occurrence dates from structured incident rows.
 */
export function incidentOccurrenceBounds(incidents = []) {
  const dates = [...new Set(
    (Array.isArray(incidents) ? incidents : [])
      .map((row) => String(row?.occurrence_at || row?.occurrence_started_at || row?.incident_date || "").slice(0, 10))
      .filter((value) => /^\d{4}-\d{2}-\d{2}$/.test(value)),
  )].sort();

  if (dates.length === 0) {
    return { start: "", end: "", isRange: false, display: "" };
  }

  const start = dates[0];
  const end = dates[dates.length - 1];
  const isRange = start !== end;

  return {
    start,
    end,
    isRange,
    display: formatIncidentDateDisplay(start, end),
  };
}

export function collectAffectedAreasFromIncidents(incidents = [], fallbackAreas = []) {
  const fromRows = (Array.isArray(incidents) ? incidents : [])
    .flatMap((row) => {
      if (Array.isArray(row?.affected_barangays) && row.affected_barangays.length) {
        return row.affected_barangays;
      }
      return String(row?.barangay || "")
        .split(",")
        .map((part) => part.trim())
        .filter(Boolean);
    });

  return [...new Set([
    ...fromRows,
    ...(Array.isArray(fallbackAreas) ? fallbackAreas : []),
  ].map((value) => String(value ?? "").trim()).filter(Boolean))];
}

export function resolveAffectedPersonsCount(...candidates) {
  for (const candidate of candidates) {
    if (Array.isArray(candidate)) {
      const sum = candidate.reduce((total, row) => {
        if (row == null) return total;
        if (typeof row === "number" || typeof row === "string") {
          return total + (Number(row) || 0);
        }
        return total + (Number(row.affected_persons ?? row.persons ?? 0) || 0);
      }, 0);
      if (sum > 0) return sum;
      continue;
    }
    const value = Number(candidate);
    if (Number.isFinite(value) && value > 0) return value;
  }
  return "";
}

/**
 * One latest SitRep per series (highest report_number), then sum affected_persons.
 */
export function personsFromLatestLinkedSitreps(reports = []) {
  const bySeries = new Map();
  for (const row of Array.isArray(reports) ? reports : []) {
    if (!row) continue;
    const key = String(row.series_key || row.id || "");
    if (!key) continue;
    const prev = bySeries.get(key);
    if (!prev || Number(row.report_number || 0) > Number(prev.report_number || 0)) {
      bySeries.set(key, row);
    }
  }
  return resolveAffectedPersonsCount([...bySeries.values()]);
}
