const today = () => new Date().toLocaleDateString("en-CA");

/** Year/month prefix for RIS / DR DRN, e.g. CARAGA-FO-DRMD-RROS-A-REQ-26-08- */
export const risDrnPrefixForDate = (date) =>
  `CARAGA-FO-DRMD-RROS-A-REQ-${String(date || today()).slice(2, 7)}-`;

export const risDrnSequenceFromValue = (value, prefix) => {
  const full = String(value || "");
  if (!full) return "";
  if (prefix && full.startsWith(prefix)) return full.slice(prefix.length);
  const match = full.match(/^CARAGA-FO-DRMD-RROS-A-REQ-\d{2}-\d{2}-(.*)$/);
  return match ? match[1] : full;
};

export const composeRisDrn = (prefix, sequence) => {
  const suffix = String(sequence || "")
    .replace(/^-+/, "")
    .trim();
  return suffix ? `${prefix}${suffix}` : prefix;
};
