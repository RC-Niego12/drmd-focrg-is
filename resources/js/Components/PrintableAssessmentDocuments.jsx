import {
  formatAffectedAreaList,
  formatIncidentDateDisplay,
  incidentOccurrenceBounds,
} from "@/Utils/incidentDisplay";

const MARK = "\u2713";
const DASH = "\u2014";

const blank = (value, fallback = DASH) => {
  const text = String(value ?? "").trim();
  return text || fallback;
};

const formatCount = (value) => {
  const n = Math.max(0, Math.round(Number(value) || 0));
  return n.toLocaleString();
};

const formatQty = (value) => {
  const n = Math.trunc(Number(value));
  if (!Number.isFinite(n) || n < 0) return DASH;
  return n.toLocaleString();
};

const signatoryName = (value) => String(value || "").split("|")[0]?.trim().toUpperCase() || "";
const signatoryPosition = (value) => String(value || "").split("|")[1]?.trim() || "";

/** Match DomPDF / Word long date style: Month D, YYYY */
const formatLongDate = (value) => {
  if (!value) return "";
  const raw = String(value).trim();
  const iso = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (iso) {
    const date = new Date(Date.UTC(Number(iso[1]), Number(iso[2]) - 1, Number(iso[3])));
    if (!Number.isNaN(date.getTime())) {
      return date.toLocaleDateString("en-US", {
        year: "numeric",
        month: "long",
        day: "numeric",
        timeZone: "UTC",
      });
    }
  }
  const parsed = new Date(raw);
  if (Number.isNaN(parsed.getTime())) return raw;
  return parsed.toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" });
};

const formatPreparedAt = (value) => {
  if (!value) return "";
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return String(value);
  return parsed.toLocaleString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
};

const sanitizeNarrative = (value = "") => String(value)
  .split(/\r?\n/)
  .filter((line) => !/^(Approved\s+by|Prepared\s+by|Reviewed\s+by|Signature|Date)\s*:\s*(?:_+.*)?$/i.test(line.trim()))
  .join("\n")
  .trim();

const countNoun = (value, singular, plural) => {
  const n = Math.max(0, Math.round(Number(value) || 0));
  return `${formatCount(n)} ${n === 1 ? singular : plural}`;
};

const padRows = (rows, minimum, blankRow) => {
  const filled = (Array.isArray(rows) ? rows : []).filter((row) =>
    Object.values(row || {}).some((value) => String(value ?? "").trim() !== ""),
  );
  const target = Math.min(Math.max(filled.length, minimum), blankRow._max || 99);
  const next = [...filled];
  while (next.length < target) next.push({ ...blankRow });
  return next.slice(0, blankRow._max || target);
};

const resolveOccurrenceDisplay = (meta = {}, data = {}, request = null) => {
  const incidents = Array.isArray(meta.incidents) ? meta.incidents : [];
  const bounds = incidentOccurrenceBounds(incidents);
  if (bounds.display) return bounds.display;

  const stored = String(meta.incident_occurrence_display || "").trim();
  if (stored) {
    const rangeMatch = stored.match(/^(\d{4}-\d{2}-\d{2})\s+to\s+(\d{4}-\d{2}-\d{2})$/i);
    if (rangeMatch) return formatIncidentDateDisplay(rangeMatch[1], rangeMatch[2]);
    if (/^\d{4}-\d{2}-\d{2}$/.test(stored.slice(0, 10)) && !stored.includes("/")) {
      return formatIncidentDateDisplay(stored.slice(0, 10)) || stored;
    }
    return stored;
  }

  const start = String(meta.occurrence_started_at || data.incident_date || request?.incident_date || "").slice(0, 10);
  const end = String(meta.occurrence_ended_span || start).slice(0, 10);
  if (/^\d{4}-\d{2}-\d{2}$/.test(start)) {
    return formatIncidentDateDisplay(start, end) || formatLongDate(start);
  }
  return formatLongDate(request?.incident?.incident_date || data.incident_date);
};

const ASSESSMENT_PREVIEW_CSS = `
.assessment-preview-sheet {
  width: 100%;
  color: #000;
  font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
  font-size: 7.2px;
  line-height: 1.05;
  background: #fff;
}
.assessment-preview-sheet table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}
.assessment-preview-sheet th,
.assessment-preview-sheet td {
  border: 0.75pt solid #000;
  padding: 2px 3px;
  vertical-align: middle;
  word-break: break-word;
}
.assessment-preview-sheet .no-border,
.assessment-preview-sheet .no-border td {
  border: 0 !important;
  padding: 0;
}
.assessment-preview-sheet .section {
  background: #e4e4e4;
  text-align: center;
  font-weight: 700;
  font-size: 8px;
  letter-spacing: 0.04em;
  padding: 3px 2px;
}
.assessment-preview-sheet .center { text-align: center; }
.assessment-preview-sheet .bold { font-weight: 700; }
.assessment-preview-sheet .mark {
  text-align: center;
  font-size: 11px;
  font-weight: 700;
}
.assessment-preview-sheet .title {
  font-family: "Times New Roman", Times, Georgia, serif;
  font-size: 12px;
  font-weight: 700;
  text-align: center;
  padding: 6px 4px;
  border-top: 0;
  border-left: 0;
  border-right: 0;
}
.assessment-preview-sheet .division {
  font-family: "Times New Roman", Times, Georgia, serif;
  font-size: 9px;
  font-weight: 700;
  text-align: center;
  line-height: 1.1;
}
.assessment-preview-sheet .form-code {
  font-family: "Times New Roman", Times, Georgia, serif;
  font-size: 7px;
  font-style: italic;
  font-weight: 700;
}
.assessment-preview-sheet .header-logos img { max-height: 36px; width: auto; }
.assessment-preview-sheet .narrative {
  white-space: pre-wrap;
  text-align: justify;
  vertical-align: top;
  font-size: 7px;
  line-height: 1.15;
  min-height: 90px;
}
.assessment-preview-sheet .signature-block {
  vertical-align: top;
  min-height: 56px;
  padding-top: 3px;
}
.assessment-preview-sheet .signature-name {
  margin-top: 18px;
  text-align: center;
  font-weight: 700;
  text-transform: uppercase;
  line-height: 1.15;
}
.assessment-preview-sheet .small { font-size: 6.2px; }
.assessment-preview-sheet .embedded td,
.assessment-preview-sheet .embedded th {
  border: 0.75pt solid #000;
}
`;

/**
 * A4 assessment worksheet preview aligned with documents/assessment.blade.php
 * and live worksheet / saved request field values.
 */
export function PrintableAssessmentDocument({ formData, request = null }) {
  const data = formData || {};
  const meta = data.assessment_form_data || request?.assessment_form_data || {};
  const items = (data.items || request?.items || []).filter(
    (item) => item?.item_name || item?.fni_library_item_id,
  );
  const previous = padRows(meta.previous_augmentations, meta.has_previous_augmentation ? 1 : 1, {
    unit: "", description: "", quantity: "", remarks: "", _max: 3,
  });
  const batches = padRows(meta.delivery_batches, 2, {
    quantity: "", date: "", available: "", details: "", _max: 5,
  });
  const isDisaster = meta.request_type === "Disaster" || data.purpose === "Relief Augmentation";
  const provideAugmentation = meta.provide_augmentation === true;
  const hasPrevious = meta.has_previous_augmentation === true;
  const affectedFamilies = Number(data.affected_families ?? request?.affected_families ?? 0) || 0;
  const affectedPersons = Number(meta.affected_persons ?? request?.affected_persons ?? 0) || 0;
  const occurrenceDisplay = resolveOccurrenceDisplay(meta, data, request);
  const narrative = sanitizeNarrative(data.recommendations || request?.recommendations || "");
  const responsePurpose = meta.response_purpose || data.purpose || "";
  const preparedName = String(meta.prepared_by || data.assigned_social_worker || request?.assigned_social_worker || "").trim().toUpperCase();
  const preparedRole = [meta.prepared_by_position, meta.prepared_by_designation].filter(Boolean)
    .filter((value, index, all) => all.indexOf(value) === index)
    .join(" / ");
  const stockAvailable = (item) => {
    if (item.available_quantity == null || item.available_quantity === "") return DASH;
    return formatQty(item.available_quantity);
  };
  const disasterName = data.incident_name || request?.incident?.name || request?.incident_name || meta.incident_type || "";
  const affectedBarangayText = (() => {
    const fromDetails = String(data.incident_details || request?.incident_details || meta.incident_specific_details || "").trim();
    if (fromDetails) return fromDetails;
    return formatAffectedAreaList(meta.affected_areas || []);
  })();
  const typeOfDisasterDisplay = !isDisaster
    ? ""
    : [disasterName, affectedBarangayText].filter(Boolean).join(` ${DASH} `);

  return (
    <article className="assessment-preview-sheet print-document doc-preview-sheet">
      <style>{ASSESSMENT_PREVIEW_CSS}</style>
      <table>
        <tbody>
          <tr>
            <td colSpan={12} style={{ border: 0, padding: "2px 6px" }}>
              <table className="no-border">
                <tbody>
                  <tr>
                    <td style={{ width: "42%" }} className="header-logos">
                      <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                        <img src="/images/dswd_logo_3.png" alt="DSWD" />
                        <img src="/images/Bagong_PilipinasTransparent.png" alt="Bagong Pilipinas" />
                      </div>
                    </td>
                    <td style={{ width: "58%" }} className="center">
                      <div className="division">DISASTER RESPONSE MANAGEMENT DIVISION</div>
                      <div className="form-code">DSWD-DRMG-GF-001 | REV 00 | 21 MAR 2022</div>
                      <div style={{ marginTop: 4 }} className="bold">
                        DRN: {blank(data.assessment_drn || request?.assessment_drn, "")}
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </td>
          </tr>
          <tr>
            <th colSpan={12} className="title">FNI ASSESSMENT AND DELIVERY FORM</th>
          </tr>
          <tr>
            <td colSpan={8}><span className="bold">RIS Number:</span></td>
            <td colSpan={4}>
              <span className="bold">Assessment Date:</span>{" "}
              {formatLongDate(meta.assessment_date) || formatLongDate(new Date().toISOString().slice(0, 10))}
            </td>
          </tr>
          <tr>
            <td colSpan={2} className="bold">Requesting Party</td>
            <td colSpan={10}>{blank(data.requesting_agency || request?.requesting_agency, "")}</td>
          </tr>
          <tr>
            <td colSpan={2} className="bold">Purpose</td>
            <td>Disaster</td>
            <td className="mark">{isDisaster ? MARK : ""}</td>
            <td colSpan={2} className="bold">Type of Disaster</td>
            <td colSpan={3}>{blank(typeOfDisasterDisplay, "")}</td>
            <td colSpan={3}>{blank(responsePurpose, "")}</td>
          </tr>
          <tr>
            <td colSpan={2} className="bold">Date of Request</td>
            <td colSpan={10}>{formatLongDate(data.date_requested || request?.date_requested)}</td>
          </tr>

          <tr>
            <th colSpan={5} className="section">DETAILS OF REQUEST</th>
            <th colSpan={7} className="section">AVAILABILITY OF STOCKPILE</th>
          </tr>
          <tr className="center bold">
            <td colSpan={4}>Description</td>
            <td>Quantity</td>
            <td colSpan={2}>Procured</td>
            <td colSpan={2}>Donated</td>
            <td colSpan={3}>Quantity</td>
          </tr>
          {(items.length ? items : [{ item_name: "", requested_quantity: "", available_quantity: "" }]).map((item, index) => (
            <tr key={`detail-${index}`}>
              <td colSpan={4}>{blank(item.item_name, "")}</td>
              <td className="center">{item.requested_quantity === "" || item.requested_quantity == null ? "" : formatQty(item.requested_quantity)}</td>
              <td colSpan={2} className="center">{DASH}</td>
              <td colSpan={2} className="center">{DASH}</td>
              <td colSpan={3} className="center">{stockAvailable(item)}</td>
            </tr>
          ))}

          <tr>
            <th colSpan={12} className="section">ASSESSMENT AND VALIDATION</th>
          </tr>
          <tr>
            <td colSpan={12}>
              <span className="bold">Date of Disaster Occurrence:</span>{" "}
              {blank(occurrenceDisplay, "")}
            </td>
          </tr>
          <tr>
            <td colSpan={6}>
              Actual Affected Families: {countNoun(affectedFamilies, "family", "families")}
              {affectedPersons > 0 ? ` (${countNoun(affectedPersons, "person", "persons")})` : ""}
            </td>
            <td colSpan={6}>
              No. of Families Served:{" "}
              {meta.families_served === "" || meta.families_served == null
                ? ""
                : (Number.isFinite(Number(meta.families_served))
                  ? Number(meta.families_served).toLocaleString()
                  : blank(meta.families_served, ""))}
            </td>
          </tr>
          <tr>
            <td colSpan={6}>Source of Information: {blank(meta.information_source, "")}</td>
            <td colSpan={6}>Date of Information: {formatLongDate(meta.information_date)}</td>
          </tr>

          <tr>
            <th colSpan={12} className="section">PREVIOUS AUGMENTATION</th>
          </tr>
          <tr>
            <td colSpan={4}>With previous augmentation?</td>
            <td>YES</td>
            <td className="mark">{hasPrevious ? MARK : ""}</td>
            <td>NO</td>
            <td className="mark">{!hasPrevious ? MARK : ""}</td>
            <td colSpan={4}></td>
          </tr>
          <tr>
            <td colSpan={12}>Details of previous augmentation (Indicate Month and Year), if any:</td>
          </tr>
          <tr>
            <td colSpan={12} style={{ padding: 0 }}>
              <table className="embedded">
                <tbody>
                  <tr className="center bold">
                    <td style={{ width: "11%" }}>UNIT</td>
                    <td style={{ width: "19%" }}>DESCRIPTION</td>
                    <td style={{ width: "8%" }}>QUANTITY</td>
                    <td style={{ width: "62%" }}>REMARKS</td>
                  </tr>
                  {previous.map((row, index) => (
                    <tr key={`prev-${index}`}>
                      <td>{blank(row.unit, "\u00A0")}</td>
                      <td>{blank(row.description, "\u00A0")}</td>
                      <td className="center">
                        {row.quantity === "" || row.quantity == null
                          ? "\u00A0"
                          : (Number.isFinite(Number(row.quantity)) ? Math.trunc(Number(row.quantity)).toLocaleString() : blank(row.quantity))}
                      </td>
                      <td>{blank(row.remarks, "\u00A0")}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </td>
          </tr>

          <tr>
            <th colSpan={12} className="section">
              DELIVERY / HAULING DETAILS
              <div className="small"><i>(use separate sheet if necessary)</i></div>
            </th>
          </tr>
          <tr className="center bold">
            <td rowSpan={2}>Batch</td>
            <td rowSpan={2} colSpan={2}>Quantity</td>
            <td rowSpan={2} colSpan={2}>Date</td>
            <td colSpan={7} className="small" style={{ fontWeight: 400 }}>
              If YES, indicate which vehicle will be used for hauling/pickup;
              If NO, put projected date of transportation asset availability
            </td>
          </tr>
          <tr className="center bold">
            <td>YES</td>
            <td>NO</td>
            <td colSpan={5}></td>
          </tr>
          {batches.map((row, index) => (
            <tr key={`batch-${index}`}>
              <td>Batch {index + 1}</td>
              <td colSpan={2} className="center">
                {row.quantity === "" || row.quantity == null
                  ? "\u00A0"
                  : (Number.isFinite(Number(row.quantity)) ? Math.trunc(Number(row.quantity)).toLocaleString() : blank(row.quantity))}
              </td>
              <td colSpan={2} className="center">{formatLongDate(row.date) || "\u00A0"}</td>
              <td className="mark">{row.available === "YES" ? MARK : ""}</td>
              <td className="mark">{row.available === "NO" ? MARK : ""}</td>
              <td colSpan={5}>{blank(row.details, "\u00A0")}</td>
            </tr>
          ))}

          <tr>
            <th colSpan={12} className="section">RECOMMENDATION</th>
          </tr>
          <tr>
            <td colSpan={4}>Provide Augmentation?</td>
            <td>YES</td>
            <td className="mark">{provideAugmentation ? MARK : ""}</td>
            <td>NO</td>
            <td className="mark">{!provideAugmentation ? MARK : ""}</td>
            <td colSpan={4}></td>
          </tr>
          <tr>
            <td colSpan={12} style={{ padding: 0 }}>
              <table className="embedded">
                <tbody>
                  <tr className="center bold">
                    <td style={{ width: "38%", padding: 0 }}>
                      <table>
                        <tbody>
                          <tr className="center bold">
                            <td style={{ width: "29%" }}>UNIT</td>
                            <td style={{ width: "53%" }}>DESCRIPTION</td>
                            <td style={{ width: "18%" }}>QUANTITY</td>
                          </tr>
                        </tbody>
                      </table>
                    </td>
                    <td style={{ width: "62%" }}>REMARKS / ASSESSMENT</td>
                  </tr>
                  <tr>
                    <td style={{ padding: 0, verticalAlign: "top" }}>
                      <table>
                        <tbody>
                          {(items.length ? items : [{ unit: "", item_name: "", requested_quantity: "" }]).map((item, index) => (
                            <tr key={`rec-${index}`}>
                              <td className="center bold" style={{ width: "29%" }}>{blank(String(item.unit || "").toUpperCase(), "")}</td>
                              <td className="center" style={{ width: "53%" }}>{blank(item.item_name, "")}</td>
                              <td className="center" style={{ width: "18%" }}>
                                {item.requested_quantity === "" || item.requested_quantity == null ? "" : formatQty(item.requested_quantity)}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </td>
                    <td className="narrative">{narrative || ""}</td>
                  </tr>
                </tbody>
              </table>
            </td>
          </tr>

          <tr>
            <td colSpan={6} className="signature-block">
              Prepared by:
              <div className="signature-name">{preparedName || "\u00A0"}</div>
              <div className="center">{preparedRole}</div>
              <div style={{ marginTop: 8 }}>Date / Time: {formatPreparedAt(meta.prepared_at)}</div>
            </td>
            <td colSpan={6} className="signature-block">
              Reviewed by:
              <div className="signature-name">{signatoryName(meta.reviewed_by) || "\u00A0"}</div>
              <div className="center">{signatoryPosition(meta.reviewed_by)}</div>
              <div style={{ marginTop: 8 }}>Date / Time: ____________________</div>
            </td>
          </tr>
          <tr>
            <td colSpan={12} style={{ verticalAlign: "top", minHeight: 24 }}>
              <span className="bold">Other Remarks:</span>
              <br />
              {blank(data.remarks || request?.remarks, "")}
            </td>
          </tr>
          <tr>
            <td colSpan={12} className="center" style={{ verticalAlign: "top", minHeight: 70, paddingTop: 4 }}>
              <div className="bold">Approved by:</div>
              <div className="signature-name">{signatoryName(meta.approved_by) || "\u00A0"}</div>
              <div>{signatoryPosition(meta.approved_by)}</div>
              <div style={{ marginTop: 8 }}>Date / Time: ____________________</div>
            </td>
          </tr>
        </tbody>
      </table>
    </article>
  );
}

