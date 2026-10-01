# DSWD DROMIC Reporting

## Reference analysis

Reviewed CARAGA and CCCM&IDP in the supplied blank Google workbook, Annexes in the supplied LPA workbook, and the local ADA initial/progress no. 2, Washington fire first-and-final, and Shearline terminal DOCX examples. CARAGA distinguishes affected population, inside/outside EC cumulative/current counts, totally/partially damaged houses, and assistance providers. Annexes roll up Caraga → province → city/municipality. CCCM&IDP records DSWD protection services; LGU response text cannot be treated as numeric DSWD service delivery.

## Process and interface

1. Select the latest submitted report per LGU incident series, with search and validation filters, visible incident, province, municipality, reporting timestamp and revision. The highest report number supersedes earlier periods; its highest submitted revision supersedes earlier revisions. Receipt time and ID break ties. Unsubmitted drafts, standalone relief requests and request-only corrections do not supersede reports. Resolve latest before validation filtering, so older validated reports cannot reappear when the newest report awaits validation or requires correction. Never sum successive cumulative reports. Different LGUs retain their own latest report for a shared incident. Existing saved snapshots remain historical records; new consolidation always rechecks current sources on save.
2. Enter a common incident/report title, classification (initial, progress with sequence, terminal, first and final), reporting cutoff and relationship rationale for multi-incident consolidation. Review affected, inside EC, outside EC, total displaced, damaged houses and assistance tables with geographic subtotals and source details. Flag possible overlap between incidents in the same locality for explicit review.
3. Prepare an editable situation overview with attributed LGU response actions. Generate only sections I–V from the references: overview, affected, displaced, damaged houses and cost of assistance. Save a draft with a server-computed immutable source/data snapshot. Open the saved snapshot and export narrative PDF and a workbook of the mapped tables.

## Data rules

- Derive province and municipality from the submitting report/address records; keep barangay names scoped to those parents.
- Preserve CUM and NOW independently; never replace missing values with an assertion of zero. Respect sections marked not applicable.
- Assistance cost is quantity × unit cost for provided assistance, never requested or approved augmentation quantities.
- Preserve source IDs, reporting dates, validation status and incident series in the snapshot. Recheck eligibility, scope and reporting cutoff on save.
- Existing legacy reports remain listed. Existing LGU intake and validation workflows remain available.
- Templates are references only. Do not modify linked Google files or claim synchronization. DSWD-only CCCM numeric fields unavailable from LGU payloads remain explicitly unreported.

## Verification

Test consolidation totals, geographic identity, CUM/NOW, assistance source grouping, NA/missing data and source selection safeguards. Compile the frontend and verify application routes and additive migration.
