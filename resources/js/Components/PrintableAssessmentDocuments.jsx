const blank = (value) => {
  const text = String(value ?? "").trim();
  return text || "—";
};

const formatCount = (value) => {
  const n = Math.max(0, Math.round(Number(value) || 0));
  return n.toLocaleString();
};

const formatQty = (value) => {
  const n = Math.trunc(Number(value));
  if (!Number.isFinite(n) || n <= 0) return "—";
  return n.toLocaleString();
};

const signatoryName = (value) => String(value || "").split("|")[0]?.trim() || "—";
const signatoryPosition = (value) => String(value || "").split("|")[1]?.trim() || "";

const formatDate = (value) => {
  if (!value) return "—";
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return String(value);
  return parsed.toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" });
};

const summarizeGoodsTypes = (items = []) => {
  const names = items
    .filter((item) => Number(item.requested_quantity || item.approved_quantity || 0) > 0 && item.item_name)
    .map((item) => String(item.item_name).toLowerCase());
  if (!names.length) return "food and non-food items";
  const hasFood = names.some((name) => /food|ffp|family food|rice|meal|nutrition/.test(name));
  const hasNonFood = names.some((name) => !/food|ffp|family food|rice|meal|nutrition/.test(name));
  if (hasFood && hasNonFood) return "food and non-food items";
  if (hasFood) return "food items";
  if (hasNonFood) return "non-food items";
  return "food and non-food items";
};

const ASSESSMENT_PREVIEW_CSS = `
.assessment-preview-sheet {
  width: 100%;
  color: #111;
  font-family: "Times New Roman", Times, Georgia, serif;
  font-size: 9px;
  line-height: 1.15;
  background: #fff;
}
.assessment-preview-sheet table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}
.assessment-preview-sheet th,
.assessment-preview-sheet td {
  border: 0.75pt solid #111;
  padding: 3px 4px;
  vertical-align: middle;
  word-break: break-word;
}
.assessment-preview-sheet .no-border,
.assessment-preview-sheet .no-border td {
  border: 0 !important;
}
.assessment-preview-sheet .section {
  background: #e4e4e4;
  text-align: center;
  font-weight: 700;
  font-size: 8.5px;
  letter-spacing: 0.04em;
}
.assessment-preview-sheet .center { text-align: center; }
.assessment-preview-sheet .bold { font-weight: 700; }
.assessment-preview-sheet .muted { color: #64748b; font-style: italic; font-size: 8px; }
.assessment-preview-sheet .header-logos img { max-height: 42px; width: auto; }
.assessment-preview-sheet .title {
  font-size: 13px;
  font-weight: 700;
  text-align: center;
  padding: 6px 4px;
}
.assessment-preview-sheet .narrative {
  white-space: pre-wrap;
  text-align: justify;
  min-height: 72px;
  vertical-align: top;
  font-size: 8.5px;
  line-height: 1.25;
}
`;

/**
 * In-progress A4 assessment worksheet preview from live form / saved request data.
 */
export function PrintableAssessmentDocument({ formData, request = null }) {
  const data = formData || {};
  const meta = data.assessment_form_data || request?.assessment_form_data || {};
  const items = (data.items || request?.items || []).filter(
    (item) => item?.item_name || item?.fni_library_item_id,
  );
  const previous = (meta.previous_augmentations || []).filter((row) =>
    Object.values(row || {}).some((value) => String(value ?? "").trim() !== ""),
  );
  const batches = (meta.delivery_batches || []).filter((row) =>
    Object.values(row || {}).some((value) => String(value ?? "").trim() !== ""),
  );
  const isDisaster = meta.request_type === "Disaster" || data.purpose === "Relief Augmentation";
  const provideAugmentation = meta.provide_augmentation !== false;
  const incidents = Array.isArray(meta.incidents) ? meta.incidents.filter((row) => row?.incident_type) : [];
  const incidentSummary = incidents.map((row, index) => {
    const date = row.occurrence_at ? new Date(row.occurrence_at).toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" }) : "date not encoded";
    const place = [row.barangay, row.city_municipality].filter(Boolean).join(", ");
    return `${index + 1}. ${row.incident_type} — ${date}${place ? `, ${place}` : ""} (${formatCount(row.affected_families)} families)`;
  }).join("; ");

  return (
    <article className="assessment-preview-sheet print-document doc-preview-sheet">
      <style>{ASSESSMENT_PREVIEW_CSS}</style>
      <table className="no-border">
        <tbody>
          <tr>
            <td style={{ width: "42%" }} className="header-logos">
              <div style={{ display: "flex", gap: 10, alignItems: "center", padding: "4px 6px" }}>
                <img src="/images/dswd_logo_3.png" alt="DSWD" />
                <img src="/images/Bagong_PilipinasTransparent.png" alt="Bagong Pilipinas" />
              </div>
            </td>
            <td style={{ width: "58%" }} className="center">
              <div className="bold" style={{ fontSize: 11 }}>DISASTER RESPONSE MANAGEMENT DIVISION</div>
              <div className="muted">DSWD-DRMG-GF-001 | REV 00 | 21 MAR 2022</div>
              <div style={{ marginTop: 6 }} className="bold">
                DRN: {blank(data.assessment_drn || request?.assessment_drn)}
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div className="title">FNI ASSESSMENT AND DELIVERY FORM</div>
      <table>
        <tbody>
          <tr>
            <td colSpan={8}><span className="bold">RIS Number:</span> —</td>
            <td colSpan={4}><span className="bold">Assessment Date:</span> {blank(meta.assessment_date)}</td>
          </tr>
          <tr>
            <td colSpan={2} className="bold">Requesting Party</td>
            <td colSpan={10}>{blank(data.requesting_agency || request?.requesting_agency)}</td>
          </tr>
          <tr>
            <td colSpan={2} className="bold">Purpose</td>
            <td colSpan={4}>{isDisaster ? "Disaster" : blank(meta.response_purpose || data.purpose)}</td>
            <td colSpan={3}>{blank(data.incident_name || request?.incident?.name || request?.incident_name)}</td>
            <td colSpan={3}>{blank(data.incident_details || request?.incident_details)}</td>
          </tr>
          {incidents.length > 0 && (
            <tr>
              <td colSpan={2} className="bold">{incidents.length > 1 ? "Separate Incidents" : "Incident Occurrence"}</td>
              <td colSpan={10}>{incidentSummary}</td>
            </tr>
          )}
          <tr>
            <td colSpan={12} className="section">DETAILS OF REQUEST</td>
          </tr>
          <tr>
            <td colSpan={3} className="bold">Affected Families / Persons</td>
            <td colSpan={3}>{formatCount(data.affected_families || request?.affected_families)} families</td>
            <td colSpan={3}>{formatCount(meta.affected_persons || request?.affected_persons)} persons</td>
            <td colSpan={3}>{blank(meta.office_agency_details || data.office_agency_details)}</td>
          </tr>
          <tr>
            <td colSpan={12} className="section">PREVIOUS AUGMENTATION</td>
          </tr>
          <tr className="center bold">
            <td colSpan={2}>Unit</td>
            <td colSpan={4}>Description</td>
            <td colSpan={2}>Quantity</td>
            <td colSpan={4}>Remarks</td>
          </tr>
          {(previous.length ? previous : [{ unit: "", description: "", quantity: "", remarks: "" }]).slice(0, 3).map((row, index) => (
            <tr key={`prev-${index}`}>
              <td colSpan={2}>{blank(row.unit)}</td>
              <td colSpan={4}>{blank(row.description)}</td>
              <td colSpan={2} className="center">{row.quantity === "" || row.quantity == null ? "—" : (Number.isFinite(Number(row.quantity)) ? Math.trunc(Number(row.quantity)).toLocaleString() : blank(row.quantity))}</td>
              <td colSpan={4}>{blank(row.remarks)}</td>
            </tr>
          ))}
          <tr>
            <td colSpan={12} className="section">RECOMMENDATION / ASSESSMENT</td>
          </tr>
          <tr>
            <td colSpan={5} className="bold center">Recommended FNI</td>
            <td colSpan={7} className="bold center">Assessment Narrative</td>
          </tr>
          <tr>
            <td colSpan={5} style={{ padding: 0, verticalAlign: "top" }}>
              <table>
                <tbody>
                  <tr className="center bold">
                    <td style={{ width: "48%" }}>Item</td>
                    <td style={{ width: "22%" }}>Qty</td>
                    <td style={{ width: "30%" }}>Unit</td>
                  </tr>
                  {(items.length ? items : [{ item_name: "", requested_quantity: "", unit: "" }]).map((item, index) => (
                    <tr key={`item-${index}`}>
                      <td>{blank(item.item_name)}</td>
                      <td className="center">{formatQty(item.requested_quantity ?? item.approved_quantity)}</td>
                      <td className="center">{blank(item.unit)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <div style={{ padding: 4 }} className="bold">
                Provide augmentation: {provideAugmentation ? "Yes" : "No"}
              </div>
            </td>
            <td colSpan={7} className="narrative">{blank(data.recommendations || request?.recommendations)}</td>
          </tr>
          <tr>
            <td colSpan={12} className="section">DELIVERY / RELEASE DETAILS</td>
          </tr>
          <tr className="center bold">
            <td colSpan={2}>Quantity</td>
            <td colSpan={3}>Date</td>
            <td colSpan={3}>Available</td>
            <td colSpan={4}>Details</td>
          </tr>
          {(batches.length ? batches : [{ quantity: "", date: "", available: "", details: "" }]).slice(0, 5).map((row, index) => (
            <tr key={`batch-${index}`}>
              <td colSpan={2} className="center">{row.quantity === "" || row.quantity == null ? "—" : (Number.isFinite(Number(row.quantity)) ? Math.trunc(Number(row.quantity)).toLocaleString() : blank(row.quantity))}</td>
              <td colSpan={3} className="center">{blank(row.date)}</td>
              <td colSpan={3} className="center">{blank(row.available)}</td>
              <td colSpan={4}>{blank(row.details)}</td>
            </tr>
          ))}
          <tr>
            <td colSpan={6} style={{ verticalAlign: "top", minHeight: 64 }}>
              <div className="bold">Prepared by:</div>
              <div className="center bold" style={{ marginTop: 18, textTransform: "uppercase" }}>
                {blank(meta.prepared_by || data.assigned_social_worker || request?.assigned_social_worker)}
              </div>
              <div className="center">{blank(meta.prepared_by_position || meta.prepared_by_designation)}</div>
              <div style={{ marginTop: 8 }}>Date / Time: {blank(meta.prepared_at)}</div>
            </td>
            <td colSpan={6} style={{ verticalAlign: "top" }}>
              <div className="bold">Reviewed by:</div>
              <div className="center bold" style={{ marginTop: 18, textTransform: "uppercase" }}>
                {signatoryName(meta.reviewed_by)}
              </div>
              <div className="center">{signatoryPosition(meta.reviewed_by)}</div>
            </td>
          </tr>
          <tr>
            <td colSpan={12}><span className="bold">Other Remarks:</span> {blank(data.remarks || request?.remarks)}</td>
          </tr>
          <tr>
            <td colSpan={12} className="center" style={{ padding: "10px 6px" }}>
              <div className="bold">Approved by:</div>
              <div className="bold" style={{ marginTop: 16, textTransform: "uppercase" }}>
                {signatoryName(meta.approved_by)}
              </div>
              <div>{signatoryPosition(meta.approved_by)}</div>
            </td>
          </tr>
        </tbody>
      </table>
    </article>
  );
}

const RESPONSE_LETTER_CSS = `
.response-letter-sheet {
  width: 100%;
  min-height: 297mm;
  box-sizing: border-box;
  padding: 18mm 20mm 22mm;
  color: #111;
  font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
  font-size: 11px;
  line-height: 1.55;
  background: #fff;
}
.response-letter-sheet .division {
  font-family: "Times New Roman", Times, Georgia, serif;
  font-size: 11px;
  font-weight: 700;
  text-align: center;
  line-height: 1.15;
}
.response-letter-sheet .meta {
  text-align: center;
  margin-top: 4px;
  font-size: 8px;
  font-style: italic;
  font-weight: 700;
}
.response-letter-sheet .drn {
  margin-top: 6px;
  font-size: 9px;
  font-style: normal;
}
.response-letter-sheet .draft {
  margin: 12px 0 14px;
  border: 1px solid #9f1239;
  padding: 5px;
  color: #9f1239;
  font-size: 9px;
  font-weight: 700;
  text-align: center;
}
.response-letter-sheet p { margin: 0 0 11px; text-align: justify; }
.response-letter-sheet .items {
  width: 100%;
  border-collapse: collapse;
  margin: 12px 0 15px;
}
.response-letter-sheet .items th,
.response-letter-sheet .items td {
  border: 1px solid #333;
  padding: 5px 7px;
}
.response-letter-sheet .items th { background: #e8e8e8; text-align: center; }
.response-letter-sheet .qty { text-align: right; white-space: nowrap; width: 22%; }
.response-letter-sheet .unit { white-space: nowrap; width: 18%; }
.response-letter-sheet .signature { margin-top: 36px; min-height: 72px; }
.response-letter-sheet .signature-name {
  margin-top: 28px;
  font-weight: 700;
  text-transform: uppercase;
}
.response-letter-sheet .footer {
  margin-top: 40px;
  border-top: 1px solid #777;
  padding-top: 6px;
  color: #555;
  font-size: 8px;
  text-align: center;
}
`;

/**
 * In-progress response letter preview from live assessment form / saved request data.
 */
export function PrintableResponseLetterDocument({
  formData,
  request = null,
  responseApprover = null,
  advanceCopy = false,
}) {
  const data = formData || {};
  const meta = data.assessment_form_data || request?.assessment_form_data || {};
  const items = (data.items || request?.items || []).filter(
    (item) => Number(item.requested_quantity || item.approved_quantity || 0) > 0 && item.item_name,
  );
  const areas = (meta.affected_areas || []).filter(Boolean);
  const areaText = !areas.length
    ? ""
    : areas.length <= 5
      ? ` affecting ${areas.join(", ")}`
      : ` affecting ${areas.length} identified areas`;
  const status = request?.status || data.status;
  const approvedStatus = ["approved", "partially_approved"].includes(status);
  const goodsTypes = summarizeGoodsTypes(items);
  const incidentName = data.incident_name || request?.incident?.name || request?.incident_name || "the reported incident";
  const incidentDate = data.incident_date || request?.incident?.incident_date || request?.incident_date;
  const approverName = responseApprover?.name
    || signatoryName(meta.response_letter_approved_by)
    || "Regional Director";
  const approverDesignation = responseApprover?.designation
    || signatoryPosition(meta.response_letter_approved_by)
    || "";
  const responseDrn = data.response_drn || request?.response_drn || "";
  const requester = data.requester || request?.requester || data.requesting_agency || request?.requesting_agency;
  const position = data.requester_position || request?.requester_position || data.office_agency_details || request?.office_agency_details;
  const address = data.requester_address
    || request?.requester_address
    || [data.municipality || request?.municipality, data.province || request?.province].filter(Boolean).join(", ");

  return (
    <article className="response-letter-sheet print-document doc-preview-sheet">
      <style>{RESPONSE_LETTER_CSS}</style>
      <div className="division">DISASTER RESPONSE MANAGEMENT DIVISION</div>
      <div className="meta">
        DSWD-DRMG-GF-010 | REV 00 | 12 OCT 2021
        <div className="drn"><b>DRN:</b> {blank(responseDrn)}</div>
      </div>
      {advanceCopy ? (
        <div className="draft">ADVANCE COPY — FOR LGU INFORMATION AND ACKNOWLEDGEMENT</div>
      ) : (
        <div className="draft">DRAFT — FOR REVIEW AND APPROVAL</div>
      )}
      <div style={{ marginBottom: 14 }}>
        {formatDate(new Date().toISOString())}
        <br />
        DRN: {blank(responseDrn)}
      </div>
      <div style={{ marginBottom: 14 }}>
        <b>{blank(requester)}</b>
        <br />
        {blank(position)}
        <br />
        {blank(address)}
      </div>
      <p><b>ATTENTION:</b>&nbsp;&nbsp;&nbsp;{blank(data.office_agency_details || request?.office_agency_details || data.requesting_agency || request?.requesting_agency)}</p>
      <p>Dear Sir/Madam:</p>
      <p>Greetings of service excellence and resilience!</p>
      <p>
        This is in reference to your request for {goodsTypes} intended for{" "}
        <b>{formatCount(data.affected_families || request?.affected_families)}</b> disaster-affected families due to{" "}
        <b>{incidentName}</b>
        {incidentDate ? `, which occurred on ${formatDate(incidentDate)}` : ""}
        {areaText}.
      </p>
      <p>After assessment and validation of the submitted information, the requested augmentation is documented as follows:</p>
      <table className="items">
        <thead>
          <tr>
            <th>Food and Non-Food Item</th>
            <th className="qty">Quantity</th>
            <th className="unit">Unit</th>
          </tr>
        </thead>
        <tbody>
          {(items.length ? items : [{ item_name: "—", requested_quantity: "", unit: "—" }]).map((item, index) => {
            const qty = approvedStatus
              ? (item.approved_quantity || item.requested_quantity)
              : item.requested_quantity;
            return (
              <tr key={`rl-item-${index}`}>
                <td>{blank(item.item_name)}</td>
                <td className="qty">{formatQty(qty)}</td>
                <td className="unit">{blank(item.unit)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <p>
        {approvedStatus
          ? "The Regional Resource Operations Section (RROS) will prepare the necessary Requisition and Issuance Slip and coordinate with the requesting party once the documents are complete and the goods are ready for delivery or pick-up."
          : "The requested assistance remains subject to final review, approval, and the completion of the required issuance documents."}
      </p>
      <p>For your information. Thank you.</p>
      <div className="signature">
        Very truly yours,
        <div className="signature-name">{approverName}</div>
        <div>{approverDesignation}</div>
      </div>
      <div className="footer">DSWD Field Office Caraga · Disaster Response Management Division</div>
    </article>
  );
}
