/**
 * Helper functions for system configuration from operational libraries
 */

export function getSystemName(operationalLibraries = []) {
  const systemNameEntry = operationalLibraries.find(
    (entry) => entry.library_type === 'system_name' && entry.is_active
  );
  return systemNameEntry?.value || 'Disaster Response Information Management System (DRIMS)';
}

export function getSystemNames(operationalLibraries = []) {
  const entry = operationalLibraries.find(
    (row) => row.library_type === "system_name" && row.is_active,
  );
  const longName = entry?.value || "Disaster Response Information Management System (DRIMS)";

  return {
    longName,
    shortName: entry?.metadata?.short_name || longName,
  };
}
