import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Bot, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ClipboardPaste, Clock3, Download, Edit3, ExternalLink, Eye, FileCheck2, FilePlus2, History, Images, ListChecks, MapPin, Move, Plus, Printer, Search, Send, Sparkles, Trash2, Undo2, UploadCloud, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import LookerMultiSelect from '@/Components/LookerMultiSelect';
import AppLayout, { Card, DataTable } from '@/Layouts/AppLayout';
import DromicReportStatus, { CorrectedVersionMark, DromicAdvanceCopyMark, DromicSubmissionMark, DromicValidationMark, ReliefRequestMark, RequestSubmissionMark, RequestValidationMark, StatusLegend } from '@/Components/DromicReportStatus';
import ReadonlyDromicReportModal, { DromicEncodedReportBody } from '@/Components/ReadonlyDromicReportModal';
import { DEFAULT_DOCUMENT_PREVIEW_ZOOM } from '@/Components/DocumentPreviewCanvas';
import DocumentPreviewModal from '@/Components/DocumentPreviewModal';
import SignedPdfPreview from '@/Components/SignedPdfPreview';
import PdfPreviewModal from '@/Components/PdfPreviewModal';
import SectionTabs from '@/Components/SectionTabs';
import { formatDate, formatDateTime } from '@/Utils/dateFormat';
import {
    coerceWholeQuantity,
    formatWholeQuantity,
    wholeQuantityInputValue,
} from '@/Utils/wholeQuantity';
import { listenRealtime } from '@/realtime';

const DEFAULT_NOT_APPLICABLE_SECTIONS = [
    'related_incidents',
    'casualties',
    'infrastructure_damage',
    'agriculture_damage',
    'assistance',
    'class_suspension',
    'work_suspension',
    'roads_bridges',
    'power_lifelines',
    'water_lifelines',
    'communication_lifelines',
    'seaports',
    'airports',
    'land_transport_terminals',
    'stranded_transport',
    'calamity_declaration',
    'preemptive_evacuation',
    'cluster_gaps',
];

const zeroTerminalNowValues = (value) => {
    if (Array.isArray(value)) {
        return value.map(zeroTerminalNowValues);
    }

    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).map(([key, item]) => [
            key,
            key.endsWith('_now') ? 0 : zeroTerminalNowValues(item),
        ]));
    }

    return value;
};

const situationOverviewIssue = (value) => {
    const text = String(value || '').trim();

    if (!text) return 'Situation Overview is required.';
    if (/\b(?:n\/?a|not\s+applicable|to\s+follow|to\s+be\s+(?:followed|advised|determined|updated)|tba|tbd|pending|none|no\s+data)\b/i.test(text)) {
        return 'Situation Overview cannot contain placeholders such as N/A, Not Applicable, To Follow, TBD, pending, or no data. Encode the actual validated situation.';
    }
    if (text.length < 20 || !/[a-z]{3,}/i.test(text)) {
        return 'Situation Overview needs a clear and meaningful description of the validated incident situation.';
    }

    return '';
};

const sumEncodedFniItems = (incidents) => {
    const totals = new Map();

    (incidents || []).forEach((incident) => {
        (incident.requested_fni_items || []).forEach((row) => {
            const id = Number(row.fni_library_item_id);
            const quantity = Number(row.requested_quantity || 0);
            if (!id || quantity < 1) {
                return;
            }
            totals.set(id, (totals.get(id) || 0) + quantity);
        });
    });

    return Array.from(totals.entries()).map(([fni_library_item_id, requested_quantity]) => ({
        fni_library_item_id,
        requested_quantity,
    }));
};

const normalizeAffectedAreas = (incident) => [...new Set((incident?.affected_barangays || [])
    .map((name) => String(name || '').trim())
    .filter(Boolean))];

const AFFECTED_AREA_PREVIEW_LIMIT = 2;

const linkedIncidentsForRow = (row) => {
    const linked = row?.lgu_dromic_payload?.linked_incidents;
    if (Array.isArray(linked) && linked.length) {
        return linked;
    }
    if (row?.incident_code) {
        return [{
            incident_code: row.incident_code,
            incident_name: row.lgu_dromic_payload?.incident_name || row.report_title,
            affected_barangays: row.lgu_dromic_payload?.affected_barangays || [],
        }];
    }
    return [];
};

const linkedAffectedAreasForRow = (row) => {
    const linked = linkedIncidentsForRow(row);
    if (row?.lgu_dromic_payload?.standalone_relief_request && linked.length) {
        return [...new Set(linked.flatMap((incident) => normalizeAffectedAreas(incident)))];
    }
    return normalizeAffectedAreas({
        affected_barangays: row?.lgu_dromic_payload?.affected_barangays || row?.affected_barangays || [],
    });
};

const isStandaloneReliefRequest = (row) => Boolean(row?.lgu_dromic_payload?.standalone_relief_request);

const retainedSignedRequestFor = (row) => {
    if (!row?.id) return null;
    if (row.lgu_signed_request_path) {
        return {
            name: row.lgu_signed_request_name || 'Signed request letter.pdf',
            viewUrl: `/lgu/dromic-sitrep/${row.id}/signed-copy/request`,
            active: true,
        };
    }
    const archived = [...(row.signed_document_versions || [])]
        .filter((version) => version.kind === 'request')
        .sort((left, right) => Number(right.id) - Number(left.id))[0];
    if (!archived?.id) return null;

    return {
        name: archived.original_name || 'Signed request letter.pdf',
        viewUrl: `/lgu/dromic-sitrep/signed-history/${archived.id}`,
        active: false,
        versionId: archived.id,
    };
};

const hasNonZeroNowValue = (value) => {
    if (Array.isArray(value)) return value.some(hasNonZeroNowValue);
    if (!value || typeof value !== 'object') return false;

    return Object.entries(value).some(([key, item]) => (
        key.endsWith('_now') ? Number(item || 0) > 0 : hasNonZeroNowValue(item)
    ));
};

const currentLocalDateTimeInput = () => {
    const now = new Date();
    const localTime = new Date(now.getTime() - (now.getTimezoneOffset() * 60_000));

    return localTime.toISOString().slice(0, 16);
};

const emptyPayload = (lguProfile, defaultIncidentDate) => ({
    requesting_lgu: lguProfile?.name || '',
    requester_name: '',
    requester_position: '',
    requester_address: '',
    contact_number: '',
    incident_name: '',
    incident_date: defaultIncidentDate || '',
    incident_summary: '',
    province: lguProfile?.province || '',
    municipality: lguProfile?.name || '',
    barangay: '',
    affected_families: '',
    affected_persons: '',
    displaced_families: '',
    damaged_houses: '',
    casualties: '',
    needs: '',
    requested_fni_items: [],
    not_applicable_sections: [...DEFAULT_NOT_APPLICABLE_SECTIONS],
    has_relief_request: false,
    narrative: '',
    recommendations: '',
    remarks: '',
    population_justification: '',
    evacuation_center_rows: [],
    assistance_rows: [],
    related_incident_rows: [],
    casualty_rows: [],
    infrastructure_damage_rows: [],
    agriculture_damage_rows: [],
    class_suspension_rows: [],
    work_suspension_rows: [],
    road_bridge_rows: [],
    power_lifeline_rows: [],
    water_lifeline_rows: [],
    communication_lifeline_rows: [],
    seaport_rows: [],
    airport_rows: [],
    land_transport_terminal_rows: [],
    stranded_transport_rows: [],
    calamity_declaration_rows: [],
    preemptive_evacuation_rows: [],
    cluster_gap_rows: [],
    response_action_rows: [],
    official_advisory_rows: [],
    photo_documentation_rows: [],
    photo_collage_rows: [],
    incident_type: '',
    incident_type_other: '',
    incident_types: [],
    incident_specific_details: '',
    affected_areas: '',
    affected_barangays: [],
    occurrence_started_at: '',
    incident_status: '',
    incident_ended_at: '',
    information_received_at: '',
    dromic_reporter: '',
    report_series_key: '',
    report_classification: 'regular',
    area_rows: [
        {
            area: '',
            psgc_code: '',
            psa_2024: '',
            affected_families: '',
            affected_persons: '',
            outside_ec_included: false,
            outside_ec_families_cum: '',
            outside_ec_families_now: '',
            outside_ec_persons_cum: '',
            outside_ec_persons_now: '',
            damaged_houses_included: false,
            damaged_houses_totally: '',
            damaged_houses_partially: '',
            damaged_houses_estimated_cost: '',
        },
    ],
});

const emptyAssistanceRow = (areaRow = {}) => ({
    barangay: areaRow.area || '',
    barangay_code: areaRow.psgc_code || '',
    source: '',
    source_details: '',
    quantity: '',
    unit: '',
    item_type: '',
    particular: '',
    cost_per_unit: '',
    families_served: '',
});

const emptyRelatedIncidentRow = (lguProfile = {}) => ({
    city_municipality: lguProfile?.name || '',
    barangay: '',
    incident_type: '',
    incident_type_other: '',
    occurrence_at: '',
    description: '',
    actions_taken: '',
    status: '',
});

const emptyCasualtyRow = (status = 'Injured', lguProfile = {}) => ({
    casualty_status: status,
    city_municipality: lguProfile?.name || '',
    last_name: '',
    first_name: '',
    middle_name: '',
    age: '',
    sex: '',
    address: '',
    cause: '',
    remarks: '',
    source_of_data: '',
});

const emptyInfrastructureDamageRow = (lguProfile = {}) => ({
    city_municipality: lguProfile?.name || '',
    barangay: '',
    structure_type: '',
    structure_type_other: '',
    damage_description: '',
    length_meters: '',
    estimated_cost: '',
    remarks: '',
});

const emptyAgricultureDamageRow = (lguProfile = {}) => ({
    city_municipality: lguProfile?.name || '',
    barangay: '',
    classification: '',
    classification_other: '',
    type: '',
    type_other: '',
    affected_farmers_fisherfolks: '',
    area_no_chance_recovery: '',
    area_with_chance_recovery: '',
    infrastructure_totally_damaged: '',
    infrastructure_partially_damaged: '',
    production_loss_heads: '',
    production_loss_cost_per_head: '',
    production_loss_volume_mt: '',
    production_loss_value: '',
});

const emptyClassSuspensionRow = (lguProfile = {}) => ({
    province_city_municipality: lguProfile?.name || '',
    coverage: '',
    barangay: '',
    level_from: '',
    level_from_other: '',
    level_to: '',
    level_to_other: '',
    type: '',
    type_other: '',
    suspension_at: '',
    resumed_at: '',
    remarks: '',
});

const emptyWorkSuspensionRow = (lguProfile = {}) => ({
    province_city_municipality: lguProfile?.name || '',
    coverage: '',
    barangay: '',
    type: '',
    type_other: '',
    suspension_at: '',
    resumed_at: '',
    remarks: '',
});

const emptyRoadBridgeRow = (lguProfile = {}) => ({
    province_city_municipality: lguProfile?.name || '',
    barangay: '',
    type: '',
    type_other: '',
    classification: '',
    classification_other: '',
    road_section: '',
    status: '',
    reported_not_passable_at: '',
    reported_passable_at: '',
    remarks: '',
});

const emptyUtilityLifelineRow = () => ({
    coverage: '',
    barangay: '',
    type: '',
    type_other: '',
    service_provider: '',
    interrupted_at: '',
    restored_at: '',
    remarks_status: '',
});

const emptyCommunicationLifelineRow = () => ({
    coverage: '',
    barangay: '',
    communication_status: '',
    communication_status_other: '',
    service_provider: '',
    interrupted_at: '',
    restored_at: '',
    remarks: '',
});

const emptyPortTerminalStatusRow = () => ({
    name: '',
    status: '',
    status_other: '',
    stranded_passengers: '',
    reported_non_operational_at: '',
    reported_operational_at: '',
    remarks: '',
});

const emptyStrandedTransportRow = () => ({
    barangay: '',
    station: '',
    port_terminal: '',
    passengers: '',
    rolling_cargoes: '',
    vessel_bus_liner: '',
    mbca: '',
    remarks: '',
});

const emptyCalamityDeclarationRow = () => ({
    location: '',
    type: '',
    type_other: '',
    resolution_number: '',
    resolution_date: '',
    remarks: '',
});

const emptyPreemptiveEvacuationRow = () => ({
    barangay: '',
    families: '',
    male: '',
    female: '',
    remarks: '',
});

const emptyClusterGapRow = (cluster = '') => ({
    cluster,
    cluster_other: '',
    areas_of_concern: '',
    actions_undertaken: '',
    status_remarks: '',
});

const emptyResponseActionRow = () => ({
    acted_by_office: '',
    acted_by_office_other: '',
    action_intervention: '',
});

const lifelineCoverageOptions = [
    { value: 'Entire LGU', label: 'Entire LGU' },
    { value: 'All affected barangays', label: 'All affected barangays' },
    { value: 'Selected barangay', label: 'Selected barangay' },
];

const responseActionOfficeOptions = [
    'Office of the Mayor',
    'Office of the Vice-Mayor',
    'Office of the Sangguniang Bayan',
    'LSWDO',
    'LDRRMO',
    'LDRRMC',
    'Engineering Office',
    'Health Office',
    'Agriculture Office',
    'RDANA Unit',
    'PDANA Unit',
    'PNP',
    'BFP',
    'PCG',
    'Barangay LGU',
    'NGOs',
    'CSOs',
];

const requestedFniSortRank = (item = {}) => {
    const category = String(item.item_category || '').trim().toLocaleLowerCase();
    const name = String(item.item_name || '').trim().toLocaleLowerCase();

    if (category.includes('family food pack') || name.includes('family food pack')) return 0;
    if (category.includes('food item') || (category.includes('food') && !category.includes('non'))) return 1;
    if (name.includes('kit')) return 2;
    if (name.includes('tent')) return 3;

    return 4;
};

const sortRequestedFniLibraryItems = (items = []) => [...items].sort((left, right) => {
    const rankDiff = requestedFniSortRank(left) - requestedFniSortRank(right);
    if (rankDiff !== 0) return rankDiff;

    const categoryDiff = String(left.item_category || '').localeCompare(String(right.item_category || ''), undefined, { sensitivity: 'base' });
    if (categoryDiff !== 0) return categoryDiff;

    return String(left.item_name || '').localeCompare(String(right.item_name || ''), undefined, { sensitivity: 'base' });
});

const makeClientId = (prefix = 'row') => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

const readFileAsDataUrl = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
});

const loadCanvasImage = (source) => new Promise((resolve, reject) => {
    const image = new Image();
    image.crossOrigin = 'anonymous';
    image.onload = () => resolve(image);
    image.onerror = reject;
    image.src = source;
});

const drawTemporaryLguLogo = (context, x, y, size = 58) => {
    const center = size / 2;

    context.save();
    context.fillStyle = '#ffffff';
    context.beginPath();
    context.arc(x + center, y + center, center, 0, Math.PI * 2);
    context.fill();
    context.strokeStyle = '#facc15';
    context.lineWidth = 4;
    context.stroke();
    context.fillStyle = '#0f766e';
    context.font = '900 19px Arial';
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillText('LGU', x + center, y + center - 1);
    context.textAlign = 'start';
    context.textBaseline = 'alphabetic';
    context.restore();
};

const drawFooterLogo = async (context, source, x, y, width, height, fallbackLabel = '') => {
    if (source) {
        try {
            const logo = await loadCanvasImage(source);
            const scale = Math.min(height / logo.height, width / logo.width);
            const drawWidth = logo.width * scale;
            const drawHeight = logo.height * scale;

            context.save();
            context.imageSmoothingEnabled = true;
            context.imageSmoothingQuality = 'high';
            context.drawImage(
                logo,
                x + ((width - drawWidth) / 2),
                y + ((height - drawHeight) / 2),
                drawWidth,
                drawHeight,
            );
            context.restore();
            return;
        } catch (error) {
            // External image hosts may block canvas use. Keep the collage useful with a text fallback.
        }
    }

    context.save();
    context.fillStyle = '#0f4c9a';
    context.font = '900 22px Arial';
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillText(fallbackLabel || 'LOGO', x + (width / 2), y + (height / 2));
    context.restore();
};

const drawTransparentContainedImage = (context, image, x, y, width, height, padding = 0) => {
    const innerX = x + padding;
    const innerY = y + padding;
    const innerWidth = Math.max(1, width - (padding * 2));
    const innerHeight = Math.max(1, height - (padding * 2));
    const ratio = Math.min(innerWidth / image.width, innerHeight / image.height);
    const drawWidth = image.width * ratio;
    const drawHeight = image.height * ratio;
    const drawX = innerX + ((innerWidth - drawWidth) / 2);
    const drawY = innerY + ((innerHeight - drawHeight) / 2);

    context.save();
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(image, drawX, drawY, drawWidth, drawHeight);
    context.restore();
};

const drawContainedImage = (context, image, x, y, width, height, padding = 14) => {
    const innerX = x + padding;
    const innerY = y + padding;
    const innerWidth = Math.max(1, width - (padding * 2));
    const innerHeight = Math.max(1, height - (padding * 2));
    const ratio = Math.min(innerWidth / image.width, innerHeight / image.height);
    const drawWidth = image.width * ratio;
    const drawHeight = image.height * ratio;
    const drawX = innerX + ((innerWidth - drawWidth) / 2);
    const drawY = innerY + ((innerHeight - drawHeight) / 2);

    context.fillStyle = '#ffffff';
    context.fillRect(x, y, width, height);
    context.strokeStyle = '#d7e1ef';
    context.lineWidth = 3;
    context.strokeRect(x, y, width, height);
    context.drawImage(image, drawX, drawY, drawWidth, drawHeight);
};

const drawCroppedImage = (context, image, x, y, width, height, crop = {}, padding = 4) => {
    const innerX = x + padding;
    const innerY = y + padding;
    const innerWidth = Math.max(1, width - (padding * 2));
    const innerHeight = Math.max(1, height - (padding * 2));
    const scale = Math.max(1, Math.min(3, Number(crop.scale || 1)));
    const offsetX = Math.max(-50, Math.min(50, Number(crop.x || 0))) / 100;
    const offsetY = Math.max(-50, Math.min(50, Number(crop.y || 0))) / 100;
    const ratio = Math.max(innerWidth / image.width, innerHeight / image.height) * scale;
    const drawWidth = image.width * ratio;
    const drawHeight = image.height * ratio;
    const maxX = Math.max(0, (drawWidth - innerWidth) / 2);
    const maxY = Math.max(0, (drawHeight - innerHeight) / 2);
    const drawX = innerX + ((innerWidth - drawWidth) / 2) + (maxX * offsetX * 2);
    const drawY = innerY + ((innerHeight - drawHeight) / 2) + (maxY * offsetY * 2);

    context.fillStyle = '#ffffff';
    context.fillRect(x, y, width, height);
    context.save();
    context.beginPath();
    context.rect(innerX, innerY, innerWidth, innerHeight);
    context.clip();
    context.drawImage(image, drawX, drawY, drawWidth, drawHeight);
    context.restore();
    context.strokeStyle = '#d7e1ef';
    context.lineWidth = 3;
    context.strokeRect(x, y, width, height);
};

const getPhotoCollageSlots = (count, x, y, width, height, gap, layout = 'featured') => {
    const halfWidth = (width - gap) / 2;
    const halfHeight = (height - gap) / 2;
    const thirdWidth = (width - (gap * 2)) / 3;

    if (count <= 1) return [{ x, y, width, height }];

    if (layout === 'featured' && count >= 3) {
        const mainWidth = Math.round(width * 0.58);
        const sideWidth = width - mainWidth - gap;
        const sideRows = Math.ceil((count - 1) / 2);
        const sideHeight = (height - (gap * (sideRows - 1))) / sideRows;

        return [
            { x, y, width: mainWidth, height },
            ...Array.from({ length: count - 1 }, (_, index) => {
                const row = Math.floor(index / 2);
                const column = index % 2;
                const cellWidth = (sideWidth - gap) / 2;

                return {
                    x: x + mainWidth + gap + (column * (cellWidth + gap)),
                    y: y + (row * (sideHeight + gap)),
                    width: cellWidth,
                    height: sideHeight,
                };
            }),
        ];
    }

    if (layout === 'feature_right' && count >= 3) {
        const mainWidth = Math.round(width * 0.58);
        const sideWidth = width - mainWidth - gap;
        const sideRows = Math.ceil((count - 1) / 2);
        const sideHeight = (height - (gap * (sideRows - 1))) / sideRows;

        return [
            ...Array.from({ length: count - 1 }, (_, index) => {
                const row = Math.floor(index / 2);
                const column = index % 2;
                const cellWidth = (sideWidth - gap) / 2;

                return {
                    x: x + (column * (cellWidth + gap)),
                    y: y + (row * (sideHeight + gap)),
                    width: cellWidth,
                    height: sideHeight,
                };
            }),
            { x: x + sideWidth + gap, y, width: mainWidth, height },
        ];
    }

    if (layout === 'stacked' && count >= 4) {
        const topHeight = Math.round(height * 0.56);
        const bottomHeight = height - topHeight - gap;
        const bottomWidth = (width - (gap * (count - 3))) / Math.max(1, count - 2);

        return [
            { x, y, width: halfWidth, height: topHeight },
            { x: x + halfWidth + gap, y, width: halfWidth, height: topHeight },
            ...Array.from({ length: count - 2 }, (_, index) => ({
                x: x + (index * (bottomWidth + gap)),
                y: y + topHeight + gap,
                width: bottomWidth,
                height: bottomHeight,
            })),
        ];
    }

    if (layout === 'magazine' && count >= 3) {
        const heroHeight = Math.round(height * 0.62);
        const stripHeight = height - heroHeight - gap;
        const stripWidth = (width - (gap * (count - 2))) / Math.max(1, count - 1);

        return [
            { x, y, width, height: heroHeight },
            ...Array.from({ length: count - 1 }, (_, index) => ({
                x: x + (index * (stripWidth + gap)),
                y: y + heroHeight + gap,
                width: stripWidth,
                height: stripHeight,
            })),
        ];
    }

    if (layout === 'panorama' && count >= 4) {
        const topHeight = Math.round(height * 0.48);
        const bottomHeight = height - topHeight - gap;
        const topCount = Math.ceil(count / 2);
        const bottomCount = count - topCount;
        const topWidth = (width - (gap * (topCount - 1))) / topCount;
        const bottomWidth = (width - (gap * (bottomCount - 1))) / Math.max(1, bottomCount);

        return [
            ...Array.from({ length: topCount }, (_, index) => ({
                x: x + (index * (topWidth + gap)),
                y,
                width: topWidth,
                height: topHeight,
            })),
            ...Array.from({ length: bottomCount }, (_, index) => ({
                x: x + (index * (bottomWidth + gap)),
                y: y + topHeight + gap,
                width: bottomWidth,
                height: bottomHeight,
            })),
        ];
    }

    if (count === 2) {
        return [
            { x, y, width: halfWidth, height },
            { x: x + halfWidth + gap, y, width: halfWidth, height },
        ];
    }

    if (count === 3) {
        const mainWidth = Math.round(width * 0.6);
        const sideWidth = width - mainWidth - gap;

        return [
            { x, y, width: mainWidth, height },
            { x: x + mainWidth + gap, y, width: sideWidth, height: halfHeight },
            { x: x + mainWidth + gap, y: y + halfHeight + gap, width: sideWidth, height: halfHeight },
        ];
    }

    if (count === 4) {
        return [
            { x, y, width: halfWidth, height: halfHeight },
            { x: x + halfWidth + gap, y, width: halfWidth, height: halfHeight },
            { x, y: y + halfHeight + gap, width: halfWidth, height: halfHeight },
            { x: x + halfWidth + gap, y: y + halfHeight + gap, width: halfWidth, height: halfHeight },
        ];
    }

    return [
        { x, y, width: halfWidth, height: halfHeight },
        { x: x + halfWidth + gap, y, width: halfWidth, height: halfHeight },
        { x, y: y + halfHeight + gap, width: thirdWidth, height: halfHeight },
        { x: x + thirdWidth + gap, y: y + halfHeight + gap, width: thirdWidth, height: halfHeight },
        { x: x + ((thirdWidth + gap) * 2), y: y + halfHeight + gap, width: thirdWidth, height: halfHeight },
    ];
};

const wrapCanvasText = (context, text, x, y, maxWidth, lineHeight, maxLines = 2) => {
    const words = String(text || '').split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';

    words.forEach((word) => {
        const testLine = line ? `${line} ${word}` : word;
        if (context.measureText(testLine).width > maxWidth && line) {
            lines.push(line);
            line = word;
        } else {
            line = testLine;
        }
    });

    if (line) lines.push(line);
    lines.slice(0, maxLines).forEach((item, index) => context.fillText(item, x, y + (index * lineHeight)));
};

const resizePhotoToDataUrl = async (file, maxSide = 1200, quality = 0.78) => {
    const originalDataUrl = await readFileAsDataUrl(file);
    const image = await loadCanvasImage(originalDataUrl);
    const ratio = Math.min(1, maxSide / Math.max(image.width, image.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(image.width * ratio));
    canvas.height = Math.max(1, Math.round(image.height * ratio));
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0, canvas.width, canvas.height);

    return canvas.toDataURL('image/jpeg', quality);
};

const collageDateLabel = (dateValue) => {
    if (!dateValue) return new Date().toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' }).toUpperCase();
    if (/^\d{4}-\d{2}-\d{2}T/.test(String(dateValue))) {
        return new Date(dateValue).toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' }).toUpperCase();
    }
    return String(dateValue);
};

const collageDateParts = (dateValue) => {
    const label = collageDateLabel(dateValue).replace(/\s+/g, ' ').trim();
    const normalized = label.replace(',', '');
    const match = normalized.match(/^([A-Za-z]+)\s+(.+?)\s+(\d{4})$/);

    if (match) {
        return {
            month: match[1].slice(0, 3).toUpperCase(),
            dates: match[2].trim(),
            year: match[3],
        };
    }

    const parts = normalized.split(' ').filter(Boolean);
    if (parts.length >= 3) {
        return {
            month: parts[0].slice(0, 3).toUpperCase(),
            dates: parts.slice(1, -1).join(' '),
            year: parts.at(-1),
        };
    }

    return { month: '', dates: label, year: '' };
};

const defaultPhotoCollageTitle = (index = 0) => `Photo Documentation Collage ${index + 1}`;
const defaultPhotoCollageHeading = (index = 0) => `Photo documentation of LGU response actions/activities${index ? ` (${index + 1})` : ''}.`;
const photoCaptionMaxLength = 220;
const limitPhotoCaption = (value) => String(value || '')
    .split(/\r?\n/)
    .slice(0, 3)
    .join('\n')
    .slice(0, photoCaptionMaxLength);
const buildCaptionFacts = (data = {}) => ({
    reporting_lgu: data.requesting_lgu,
    incident: {
        name: data.incident_name,
        type: data.incident_type,
        specific_details: data.incident_specific_details,
        occurrence_started_at: data.occurrence_started_at || data.incident_date,
        affected_areas: data.affected_barangays || data.affected_areas || data.barangay,
    },
    encoded_lgu_response_actions: compactFactRows(data.response_action_rows),
    encoded_assistance_provided: compactFactRows(data.assistance_rows),
    caption_rule: 'Polish the supplied caption only. Do not claim that a person, item, place, agency, or activity is visible unless it is stated in the draft caption or supported by these encoded facts.',
});

const generatePhotoDocumentationCollage = async (photos = [], index = 0, options = {}) => {
    const canvas = document.createElement('canvas');
    canvas.width = 1600;
    canvas.height = 1100;
    const context = canvas.getContext('2d');
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    const dateParts = collageDateParts(options.date_label || options.generated_at);
    const heading = limitPhotoCaption(options.heading || defaultPhotoCollageHeading(index)).trim();
    const layout = options.layout || 'featured';

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = '#0f4c9a';
    context.fillRect(0, 0, canvas.width, 170);
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, 230, 170);
    context.fillStyle = '#0f4c9a';
    context.textAlign = 'center';
    context.fillStyle = '#0f4c9a';
    context.font = '900 34px Arial';
    context.fillText(dateParts.month, 115, 48);
    context.font = String(dateParts.dates).length > 6 ? '900 36px Arial' : '900 44px Arial';
    wrapCanvasText(context, dateParts.dates, 115, 86, 160, 40, 2);
    context.font = '900 34px Arial';
    context.fillText(dateParts.year, 115, 145);
    context.textAlign = 'start';
    context.fillStyle = '#ffffff';
    context.font = '700 34px Arial';
    wrapCanvasText(
        context,
        heading,
        270,
        48,
        1260,
        38,
        3,
    );

    const images = await Promise.all(photos.map((photo) => loadCanvasImage(photo.data_url)));
    const gap = 10;
    const gridX = 35;
    const gridY = 188;
    const gridWidth = 1530;
    const gridHeight = 792;
    const slots = getPhotoCollageSlots(images.length, gridX, gridY, gridWidth, gridHeight, gap, layout);

    images.forEach((image, photoIndex) => {
        const slot = slots[photoIndex];
        if (!slot) return;

        drawCroppedImage(context, image, slot.x, slot.y, slot.width, slot.height, photos[photoIndex]?.crop);
    });

    const footerY = 1000;
    const footerHeight = 100;
    const blueFooterWidth = Math.round(canvas.width * (2 / 3));
    const footerBottom = footerY + footerHeight;
    const redTopStart = blueFooterWidth - 120;
    const redBottomStart = blueFooterWidth - 155;
    const yellowTopStart = blueFooterWidth - 55;
    const yellowBottomStart = blueFooterWidth - 90;

    context.fillStyle = '#0f4c9a';
    context.beginPath();
    context.moveTo(0, footerY);
    context.lineTo(redTopStart, footerY);
    context.lineTo(redBottomStart, footerBottom);
    context.lineTo(0, footerBottom);
    context.closePath();
    context.fill();

    context.fillStyle = '#dc2626';
    context.beginPath();
    context.moveTo(redTopStart, footerY);
    context.lineTo(yellowTopStart, footerY);
    context.lineTo(yellowBottomStart, footerBottom);
    context.lineTo(redBottomStart, footerBottom);
    context.closePath();
    context.fill();

    context.fillStyle = '#facc15';
    context.beginPath();
    context.moveTo(yellowTopStart, footerY);
    context.lineTo(blueFooterWidth, footerY);
    context.lineTo(blueFooterWidth, footerBottom);
    context.lineTo(yellowBottomStart, footerBottom);
    context.closePath();
    context.fill();

    context.fillStyle = '#ffffff';
    context.fillRect(blueFooterWidth, footerY, canvas.width - blueFooterWidth, footerHeight);

    const logoY = footerY + 24;
    const logoHeight = 52;

    if (options.lgu_logo_url) {
        await drawFooterLogo(context, options.lgu_logo_url, blueFooterWidth + 12, logoY, 60, logoHeight, 'LGU');
    } else {
        drawTemporaryLguLogo(context, blueFooterWidth + 16, logoY, logoHeight);
    }

    await drawFooterLogo(context, '/images/dswd_logo_3.png', blueFooterWidth + 84, logoY, 138, logoHeight, 'DSWD');

    await drawFooterLogo(context, '/images/dromic-logo.png', blueFooterWidth + 236, logoY, 185, logoHeight, 'DROMIC');
    await drawFooterLogo(context, '/images/Bagong_PilipinasTransparent.png', blueFooterWidth + 435, logoY, 58, logoHeight, 'BP');

    // JPEG keeps the generated report legible while avoiding multi-megabyte PNG
    // payloads that can prevent an otherwise valid draft from reaching Laravel.
    return canvas.toDataURL('image/jpeg', 0.88);
};

const requiredLabel = (label) => (
    <>
        {label}<span className="text-rose-600"> *</span>
    </>
);

const formatNumber = (value) => Number(value || 0).toLocaleString();
const formatCurrency = (value) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0));
const formatNarrativeDate = (value) => {
    if (!value) return '';

    const source = String(value);
    const date = new Date(/^\d{4}-\d{2}-\d{2}$/.test(source) ? `${source}T00:00:00` : source);
    if (Number.isNaN(date.getTime())) return source;

    return new Intl.DateTimeFormat('en-PH', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        ...(source.includes('T') ? { hour: 'numeric', minute: '2-digit', hour12: true } : {}),
    }).format(date);
};
const readableFactValue = (key, value) => {
    if (/(?:_at|_date|_started|_ended|_received|_interrupted|_restored|_resumed)$/.test(key)) {
        return formatNarrativeDate(value);
    }
    if (typeof value === 'number' || (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value)))) {
        if (/(?:cost|amount|value)$/.test(key)) return formatCurrency(value);
        return Number(value).toLocaleString('en-PH');
    }
    return value === true ? 'Yes' : value;
};
const readableFactRecord = (record = {}) => Object.fromEntries(
    Object.entries(record).map(([key, value]) => [key, readableFactValue(key, value)]),
);
const compactFactRows = (rows = []) => (rows || [])
    .map((row = {}) => Object.fromEntries(
        Object.entries(row).filter(([key, value]) => (
            !['id', 'client_id', 'psgc_code', 'barangay_code', 'barangay_origin_code', 'disaggregation', 'disaggregation_completed'].includes(key)
            && value !== ''
            && value !== null
            && value !== undefined
            && value !== false
        )).map(([key, value]) => [key, readableFactValue(key, value)]),
    ))
    .filter((row) => Object.keys(row).length > 0);
const dromicHeadClass = 'bg-cyan-200 text-slate-950';
const normalizedOptionText = (option) => {
    if (typeof option === 'string' || typeof option === 'number') return String(option);

    return String(option?.value ?? option?.label ?? option?.name ?? option?.title ?? '').trim();
};
const withOthersOption = (options = []) => [
    ...(options || [])
        .map(normalizedOptionText)
        .filter(Boolean)
        .filter((option, index, list) => list.findIndex((item) => item.toLowerCase() === option.toLowerCase()) === index)
        .filter((option) => option.toLowerCase() !== 'others'),
    'Others',
];

const ageSexDisaggregationTotals = (row) => {
    const ageSex = row?.disaggregation?.age_sex || {};

    return Object.values(ageSex).reduce((totals, values = {}) => ({
        cum: totals.cum + Number(values.male_cum || 0) + Number(values.female_cum || 0),
        now: totals.now + Number(values.male_now || 0) + Number(values.female_now || 0),
    }), { cum: 0, now: 0 });
};

const ageSexFemaleTotals = (row) => {
    const ageSex = row?.disaggregation?.age_sex || {};

    return Object.values(ageSex).reduce((totals, values = {}) => ({
        cum: totals.cum + Number(values.female_cum || 0),
        now: totals.now + Number(values.female_now || 0),
    }), { cum: 0, now: 0 });
};

const childAgeSexTotals = (row) => {
    const ageSex = row?.disaggregation?.age_sex || {};
    const childKeys = ['infant', 'toddler', 'pre_school', 'school_age', 'teenage'];

    return childKeys.reduce((totals, key) => {
        const values = ageSex?.[key] || {};

        return {
            cum: totals.cum + Number(values.male_cum || 0) + Number(values.female_cum || 0),
            now: totals.now + Number(values.male_now || 0) + Number(values.female_now || 0),
        };
    }, { cum: 0, now: 0 });
};

const ageSexTotalsFor = (row, keys = [], sexes = ['male', 'female']) => {
    const ageSex = row?.disaggregation?.age_sex || {};

    return keys.reduce((totals, key) => {
        const values = ageSex?.[key] || {};

        return {
            cum: totals.cum + (sexes.includes('male') ? Number(values.male_cum || 0) : 0) + (sexes.includes('female') ? Number(values.female_cum || 0) : 0),
            now: totals.now + (sexes.includes('male') ? Number(values.male_now || 0) : 0) + (sexes.includes('female') ? Number(values.female_now || 0) : 0),
        };
    }, { cum: 0, now: 0 });
};

const sectoralRowTotals = (values = {}) => ({
    cum: Number(values.male_cum || 0) + Number(values.female_cum || 0),
    now: Number(values.male_now || 0) + Number(values.female_now || 0),
});

const cumNowPairIssue = (cum, now, label) => {
    const hasCum = cum !== '' && cum !== null && cum !== undefined;
    const hasNow = now !== '' && now !== null && now !== undefined;

    if (hasCum !== hasNow) {
        return `${label}: CUM and NOW must both be encoded. Enter 0 when there is no count.`;
    }

    if (!hasCum && !hasNow) {
        return '';
    }

    const cumCount = Number(cum || 0);
    const nowCount = Number(now || 0);

    if (!Number.isInteger(cumCount) || !Number.isInteger(nowCount) || cumCount < 0 || nowCount < 0) {
        return `${label}: CUM and NOW must be whole numbers from 0 and above.`;
    }

    if (cumCount === 0 && nowCount > 0) {
        return `${label}: CUM cannot be blank or 0 when NOW has a count. Encode the matching CUM value in the same row.`;
    }

    if (nowCount > cumCount) {
        return `${label}: NOW cannot be greater than CUM.`;
    }

    return '';
};

const disaggregationValidationIssues = (row) => {
    const disaggregation = row?.disaggregation || emptyDisaggregation();
    const issues = [];

    ageSexRows.forEach(([key, label]) => {
        const values = disaggregation.age_sex?.[key] || {};
        const maleIssue = cumNowPairIssue(values.male_cum, values.male_now, `${label} male`);
        const femaleIssue = cumNowPairIssue(values.female_cum, values.female_now, `${label} female`);

        if (maleIssue) issues.push(maleIssue);
        if (femaleIssue) issues.push(femaleIssue);
    });

    sectoralRows.forEach(([key, label, hasMale]) => {
        const values = disaggregation.sectoral?.[key] || {};

        if (hasMale) {
            const maleIssue = cumNowPairIssue(values.male_cum, values.male_now, `${label} male`);
            if (maleIssue) issues.push(maleIssue);
        }

        const femaleIssue = cumNowPairIssue(values.female_cum, values.female_now, `${label} female`);
        if (femaleIssue) issues.push(femaleIssue);
    });

    const totals = ageSexDisaggregationTotals(row);
    const childTotals = childAgeSexTotals(row);
    const expectedCum = Number(row?.persons_cum || 0);
    const expectedNow = Number(row?.persons_now || 0);

    if (totals.cum !== expectedCum || totals.now !== expectedNow) {
        issues.push(`Age/Sex total CUM and NOW must match the EC Persons CUM (${formatNumber(expectedCum)}) and NOW (${formatNumber(expectedNow)}). Current totals are CUM ${formatNumber(totals.cum)} and NOW ${formatNumber(totals.now)}.`);
    }

    ['pregnant_women', 'lactating_mothers'].forEach((key) => {
        const label = key === 'pregnant_women' ? 'Pregnant Women' : 'Lactating Mothers';
        const values = disaggregation.sectoral?.[key] || {};
        const cum = Number(values.female_cum || 0);
        const now = Number(values.female_now || 0);
        const eligibleFemaleTotals = ageSexTotalsFor(row, ['teenage', 'adult'], ['female']);

        if (cum > 0 && eligibleFemaleTotals.cum === 0) {
            issues.push(`${label}: CUM cannot be encoded without female CUM data under Teenage and/or Adult Age/Sex groups.`);
        }

        if (now > 0 && eligibleFemaleTotals.now === 0) {
            issues.push(`${label}: NOW cannot be encoded without female NOW data under Teenage and/or Adult Age/Sex groups.`);
        }

        if (cum > eligibleFemaleTotals.cum) {
            issues.push(`${label}: CUM cannot exceed the combined Teenage/Adult female CUM (${formatNumber(eligibleFemaleTotals.cum)}).`);
        }

        if (now > eligibleFemaleTotals.now) {
            issues.push(`${label}: NOW cannot exceed the combined Teenage/Adult female NOW (${formatNumber(eligibleFemaleTotals.now)}).`);
        }

        if (cum > 0 && now === 0 && eligibleFemaleTotals.now > 0) {
            issues.push(`${label}: NOW cannot stay 0 while Teenage/Adult female NOW still has a count. Set the sectoral NOW count, or set the related Age/Sex NOW count to 0 if none remain today.`);
        }

    });

    const childHeadedTotals = sectoralRowTotals(disaggregation.sectoral?.child_headed_family || {});
    if (childHeadedTotals.cum > 0 && childTotals.cum === 0) {
        issues.push('Child-Headed Family: CUM cannot be encoded without CUM data under Infant, Toddler, Pre-School, School Age, or Teenage groups.');
    }

    if (childHeadedTotals.now > 0 && childTotals.now === 0) {
        issues.push('Child-Headed Family: NOW cannot be encoded without NOW data under Infant, Toddler, Pre-School, School Age, or Teenage groups.');
    }

    if (childHeadedTotals.cum > 0 && childHeadedTotals.now === 0 && childTotals.now > 0) {
        issues.push('Child-Headed Family: NOW cannot stay 0 while Infant/Toddler/Pre-School/School Age/Teenage Age/Sex NOW still has a count. Set Child-Headed Family NOW, or set the related Age/Sex NOW count to 0 if none remain today.');
    }

    const soloParentTotals = sectoralRowTotals(disaggregation.sectoral?.solo_parent || {});
    const soloParentEligibleTotals = ageSexTotalsFor(row, ['teenage', 'adult', 'elderly']);

    if (soloParentTotals.cum > 0 && soloParentEligibleTotals.cum === 0) {
        issues.push('Solo Parent: CUM cannot be encoded without CUM data under Teenage, Adult, and/or Elderly Age/Sex groups.');
    }

    if (soloParentTotals.now > 0 && soloParentEligibleTotals.now === 0) {
        issues.push('Solo Parent: NOW cannot be encoded without NOW data under Teenage, Adult, and/or Elderly Age/Sex groups.');
    }

    if (soloParentTotals.cum > 0 && soloParentTotals.now === 0 && soloParentEligibleTotals.now > 0) {
        issues.push('Solo Parent: NOW cannot stay 0 while Teenage/Adult/Elderly Age/Sex NOW still has a count. Set Solo Parent NOW, or set the related Age/Sex NOW count to 0 if none remain today.');
    }

    const maleAgeSexTotals = ageSexTotalsFor(row, ageSexRows.map(([key]) => key), ['male']);
    const femaleAgeSexTotals = ageSexTotalsFor(row, ageSexRows.map(([key]) => key), ['female']);
    const stableSectoralGroups = new Set(['pwds', 'child_headed_family', 'single_headed_family', 'solo_parent', 'four_ps', 'indigenous_people']);

    sectoralRows.forEach(([key, label, hasMale]) => {
        const values = disaggregation.sectoral?.[key] || {};
        const sectorTotals = sectoralRowTotals(values);

        if (sectorTotals.cum > expectedCum) {
            issues.push(`${label}: total CUM cannot exceed this EC Persons CUM (${formatNumber(expectedCum)}).`);
        }
        if (sectorTotals.now > expectedNow) {
            issues.push(`${label}: total NOW cannot exceed this EC Persons NOW (${formatNumber(expectedNow)}).`);
        }
        if (hasMale && Number(values.male_cum || 0) > maleAgeSexTotals.cum) {
            issues.push(`${label}: male CUM cannot exceed the male Age/Sex CUM total (${formatNumber(maleAgeSexTotals.cum)}).`);
        }
        if (hasMale && Number(values.male_now || 0) > maleAgeSexTotals.now) {
            issues.push(`${label}: male NOW cannot exceed the male Age/Sex NOW total (${formatNumber(maleAgeSexTotals.now)}).`);
        }
        if (Number(values.female_cum || 0) > femaleAgeSexTotals.cum) {
            issues.push(`${label}: female CUM cannot exceed the female Age/Sex CUM total (${formatNumber(femaleAgeSexTotals.cum)}).`);
        }
        if (Number(values.female_now || 0) > femaleAgeSexTotals.now) {
            issues.push(`${label}: female NOW cannot exceed the female Age/Sex NOW total (${formatNumber(femaleAgeSexTotals.now)}).`);
        }

        if (stableSectoralGroups.has(key) && hasMale
            && maleAgeSexTotals.cum === maleAgeSexTotals.now
            && Number(values.male_cum || 0) > 0
            && Number(values.male_now || 0) !== Number(values.male_cum || 0)) {
            issues.push(`${label}: male NOW must equal male CUM because the EC male Age/Sex population is unchanged at ${formatNumber(maleAgeSexTotals.cum)}.`);
        }

        if (stableSectoralGroups.has(key)
            && femaleAgeSexTotals.cum === femaleAgeSexTotals.now
            && Number(values.female_cum || 0) > 0
            && Number(values.female_now || 0) !== Number(values.female_cum || 0)) {
            issues.push(`${label}: female NOW must equal female CUM because the EC female Age/Sex population is unchanged at ${formatNumber(femaleAgeSexTotals.cum)}.`);
        }
    });

    return issues;
};

const familyPersonPairIssue = (families, persons, label) => {
    const familyCount = Number(families || 0);
    const personCount = Number(persons || 0);

    if (familyCount === 0 && personCount > 0) {
        return `${label}: Families cannot be 0 when persons are greater than 0. Encode the correct number of families, or set persons to 0 if there are no affected/displaced persons.`;
    }

    if (familyCount > 0 && personCount === 0) {
        return `${label}: Persons cannot be 0 when families are greater than 0. Encode the correct number of persons, or set families to 0 if there are no affected/displaced families.`;
    }

    if (familyCount > personCount) {
        return `${label}: Families cannot be greater than persons.`;
    }

    return '';
};

const ageSexRows = [
    ['infant', 'Infant', '0–6 months old'],
    ['toddler', 'Toddler', '7 months–2 years old'],
    ['pre_school', 'Pre-School', '3–5 years old'],
    ['school_age', 'School Age', '6–12 years old'],
    ['teenage', 'Teenage', '13–17 years old'],
    ['adult', 'Adult', '18–59 years old'],
    ['elderly', 'Elderly', '60 years old and above'],
];

const sectoralRows = [
    ['pwds', 'Persons with Disabilities (PWDs)', true],
    ['child_headed_family', 'Child-Headed Family', true],
    ['single_headed_family', 'Single-Headed Family', true],
    ['solo_parent', 'Solo Parent', true],
    ['pregnant_women', 'Pregnant Women', false],
    ['lactating_mothers', 'Lactating Mothers', false],
    ['four_ps', '4Ps Beneficiaries (4Ps)', true],
    ['indigenous_people', 'Indigenous People (IP)', true],
];

const emptyDisaggregation = () => ({
    age_sex: Object.fromEntries(ageSexRows.map(([key]) => [key, { male_cum: '', male_now: '', female_cum: '', female_now: '' }])),
    sectoral: Object.fromEntries(sectoralRows.map(([key, , hasMale]) => [key, {
        male_cum: hasMale ? '' : null,
        male_now: hasMale ? '' : null,
        female_cum: '',
        female_now: '',
    }])),
});

const emptyEvacuationCenterRow = (selectedBarangays = []) => ({
    barangay_address_scope: 'current',
    barangay_address: '',
    barangay_address_code: '',
    barangay_address_province: '',
    barangay_address_province_code: '',
    barangay_address_city: '',
    barangay_address_city_code: '',
    evacuation_center: '',
    families_cum: '',
    families_now: '',
    persons_cum: '',
    persons_now: '',
    barangay_origin_scope: 'current',
    barangay_origin: '',
    barangay_origin_code: '',
    barangay_origin_province: '',
    barangay_origin_province_code: '',
    barangay_origin_city: '',
    barangay_origin_city_code: '',
    classrooms_used: '',
    disaggregation: emptyDisaggregation(),
    disaggregation_completed: false,
});

const zeroNowForCumDisaggregation = (disaggregation = emptyDisaggregation()) => ({
    age_sex: Object.fromEntries(Object.entries(disaggregation.age_sex || {}).map(([key, values = {}]) => [key, {
        ...values,
        male_now: Number(values.male_cum || 0) > 0 ? 0 : values.male_now,
        female_now: Number(values.female_cum || 0) > 0 ? 0 : values.female_now,
    }])),
    sectoral: Object.fromEntries(Object.entries(disaggregation.sectoral || {}).map(([key, values = {}]) => [key, {
        ...values,
        male_now: values.male_cum === null ? null : (Number(values.male_cum || 0) > 0 ? 0 : values.male_now),
        female_now: Number(values.female_cum || 0) > 0 ? 0 : values.female_now,
    }])),
});

const hasDisaggregationCumCounts = (disaggregation = emptyDisaggregation()) => {
    const ageSexHasCum = Object.values(disaggregation.age_sex || {}).some((values = {}) => (
        Number(values.male_cum || 0) > 0 || Number(values.female_cum || 0) > 0
    ));
    const sectoralHasCum = Object.values(disaggregation.sectoral || {}).some((values = {}) => (
        Number(values.male_cum || 0) > 0 || Number(values.female_cum || 0) > 0
    ));

    return ageSexHasCum || sectoralHasCum;
};

const readPreviewFromUrl = () => {
    if (typeof window === 'undefined') {
        return { id: null, mode: 'incident' };
    }

    const params = new URLSearchParams(window.location.search);
    // `focus` is used by workflow notification deep links as an alias for preview.
    const id = Number(params.get('preview') || params.get('focus') || 0) || null;
    const mode = ['incident', 'report', 'request'].includes(params.get('preview_mode') || '')
        ? params.get('preview_mode')
        : (params.get('focus') ? 'request' : 'incident');

    return { id, mode };
};

const syncPreviewInUrl = (id, mode = 'incident') => {
    if (typeof window === 'undefined') return;

    const url = new URL(window.location.href);
    if (id) {
        url.searchParams.set('preview', String(id));
        url.searchParams.set('preview_mode', mode || 'incident');
    } else {
        url.searchParams.delete('preview');
        url.searchParams.delete('preview_mode');
    }

    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
};

export default function Index({ lguProfile, defaultIncidentDate, requests, correctionDraft = null, incidentGroups = [], reliefRequestIncidentOptions = [], reportFilters = {}, monitoringSummary = {}, reportDashboard = {}, requestDashboard = {}, incidentTypes = [], barangayOptions = [], psgcOptions = {}, fniLibraryItems = [] }) {
    const { flash = {}, auth = {} } = usePage().props;
    const initialUrlPreview = readPreviewFromUrl();
    const [open, setOpen] = useState(false);
    const [consolidatedRequestOpen, setConsolidatedRequestOpen] = useState(false);
    const [editingConsolidatedId, setEditingConsolidatedId] = useState(null);
    const consolidatedSaveModeRef = useRef('final');
    const [consolidatedOptionsLoading, setConsolidatedOptionsLoading] = useState(false);
    const [consolidatedAreasViewer, setConsolidatedAreasViewer] = useState(null);
    const [consolidatedLinkedViewer, setConsolidatedLinkedViewer] = useState(null);
    const [editingRequestId, setEditingRequestId] = useState(null);
    const [sourceSeriesSummary, setSourceSeriesSummary] = useState({ drafts_saved: 0, reports_finalized: 0, is_terminal: false });
    const [finalConfirmationOpen, setFinalConfirmationOpen] = useState(false);
    const finalConfirmationAccepted = useRef(false);
    const [closedReportNowWarningOpen, setClosedReportNowWarningOpen] = useState(false);
    const closedReportNowWarningAccepted = useRef(false);
    const [previewReportId, setPreviewReportId] = useState(flash.preview_report_id || initialUrlPreview.id || null);
    const [previewMode, setPreviewMode] = useState(
        flash.preview_report_id || initialUrlPreview.id
            ? (flash.preview_report_id ? (flash.preview_mode || 'incident') : initialUrlPreview.mode)
            : 'report',
    );
    const [historyPreview, setHistoryPreview] = useState(null);
    const [revisionPreview, setRevisionPreview] = useState(null);
    const [combinedPreviewTab, setCombinedPreviewTab] = useState('report');
    const [reportCopyTab, setReportCopyTab] = useState('advance');
    const [previewContentTab, setPreviewContentTab] = useState('narrative');
    const [previewControlsCollapsed, setPreviewControlsCollapsed] = useState(false);
    const [previewSidebarCollapsed, setPreviewSidebarCollapsed] = useState(false);
    const [signedReport, setSignedReport] = useState(null);
    const [signedRequest, setSignedRequest] = useState(null);
    const [signedUploadErrors, setSignedUploadErrors] = useState({});
    const [signedUploadNotice, setSignedUploadNotice] = useState(null);
    const [submissionBusy, setSubmissionBusy] = useState(false);
    const signedReportInputRef = useRef(null);
    const signedRequestInputRef = useRef(null);
    const [aiMessage, setAiMessage] = useState('');
    const [aiBusy, setAiBusy] = useState(false);
    const [advisoryExtractionBusy, setAdvisoryExtractionBusy] = useState(false);
    const [progressOpen, setProgressOpen] = useState(false);
    const [justificationAiMessage, setJustificationAiMessage] = useState('');
    const [justificationAiBusy, setJustificationAiBusy] = useState(false);
    const [validationNotice, setValidationNotice] = useState('');
    const [responseLetterBusyId, setResponseLetterBusyId] = useState(null);
    const [responseLetterNotice, setResponseLetterNotice] = useState('');
    const [responseLetterPreview, setResponseLetterPreview] = useState({ open: false, title: '', subtitle: null, src: null, kind: null, zIndexClass: 'z-[100]' });
    const [draftPreviewOpen, setDraftPreviewOpen] = useState(false);
    const [draftPreviewZoom, setDraftPreviewZoom] = useState(DEFAULT_DOCUMENT_PREVIEW_ZOOM);
    const [draftPreviewTab, setDraftPreviewTab] = useState('narrative');
    const [draftPreviewUrl, setDraftPreviewUrl] = useState('');
    const [draftPreviewBusy, setDraftPreviewBusy] = useState(false);
    const [draftPreviewError, setDraftPreviewError] = useState('');
    const draftPreviewUrlRef = useRef(null);
    const [reportSearch, setReportSearch] = useState(reportFilters.search || '');
    const [amendmentRequestRow, setAmendmentRequestRow] = useState(null);
    const [amendmentRequestTarget, setAmendmentRequestTarget] = useState('report');
    const [amendmentReason, setAmendmentReason] = useState('');
    const [amendmentBusy, setAmendmentBusy] = useState(false);
    const [reopenBusy, setReopenBusy] = useState(false);
    const realtimeReloadTimer = useRef(null);
    const activeReportTab = ['reports', 'requests'].includes(reportFilters.tab) ? reportFilters.tab : 'incidents';
    const summaryFiltersActive = Boolean(reportFilters.search || reportFilters.status || reportFilters.classification || reportFilters.validation || reportFilters.series_key);
    const openReportView = (overrides = {}) => {
        router.get('/lgu/dromic-sitrep', {
            ...reportFilters,
            tab: activeReportTab === 'incidents' ? 'reports' : activeReportTab,
            search: reportSearch,
            ...overrides,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };
    const acknowledgeResponseLetter = async (row, kind) => {
        const letter = row?.dswd_response_letter;
        const fniId = letter?.fni_request_id;
        if (!fniId) return;
        setResponseLetterBusyId(`${row.id}-${kind}`);
        setResponseLetterNotice('');
        try {
            const response = await window.axios.post(`/lgu/response-letters/${fniId}/acknowledge`, { kind }, {
                headers: { Accept: 'application/json' },
                withXSRFToken: true,
            });
            setResponseLetterNotice(response.data?.message || 'Receipt acknowledged.');
            router.reload({ only: ['requests'], preserveScroll: true });
        } catch (error) {
            setResponseLetterNotice(error?.response?.data?.message || 'Unable to acknowledge response letter receipt.');
        } finally {
            setResponseLetterBusyId(null);
        }
    };
    const [pendingDeleteEcIndex, setPendingDeleteEcIndex] = useState(null);
    const [pendingDeleteOutsideEcIndex, setPendingDeleteOutsideEcIndex] = useState(null);
    const [pendingDeleteDamagedHouseIndex, setPendingDeleteDamagedHouseIndex] = useState(null);
    const [pendingDeleteAssistanceIndex, setPendingDeleteAssistanceIndex] = useState(null);
    const [pendingDeleteSupportingRow, setPendingDeleteSupportingRow] = useState(null);
    const [pendingZeroNowEcIndex, setPendingZeroNowEcIndex] = useState(null);
    const form = useForm(emptyPayload(lguProfile, defaultIncidentDate));
    const consolidatedForm = useForm({
        incident_series_keys: [],
        requested_fni_items: [],
        signed_request: null,
        submission_status: 'final',
    });
    const isProvince = Boolean(lguProfile?.is_province);
    const withReliefRequest = Boolean(form.data.has_relief_request);
    const requestedFniItems = Array.isArray(form.data.requested_fni_items) ? form.data.requested_fni_items : [];
    const selectableRequestedFniItems = fniLibraryItems.filter((item) => {
        const category = String(item.item_category || '').trim().toLocaleLowerCase();

        return !category.includes('raw material') && !category.includes('indirect material');
    });
    const uniqueRequestedFniLibraryItems = sortRequestedFniLibraryItems(Array.from(selectableRequestedFniItems.reduce((items, item) => {
        const uniqueName = String(item.item_name || '').trim().toLocaleLowerCase();

        if (uniqueName && !items.has(uniqueName)) {
            items.set(uniqueName, item);
        }

        return items;
    }, new Map()).values()));
    const requestedFniOptions = uniqueRequestedFniLibraryItems.map((item) => ({
        value: String(item.id),
        label: item.item_name,
    }));
    const requestedFniIds = requestedFniItems.map((row) => String(row.fni_library_item_id));
    const setRequestedFniSelection = (selectedIds) => {
        const suggestedQuantity = Number(totalAffectedFamilies || 0) > 0 ? Number(totalAffectedFamilies) : '';
        form.setData('requested_fni_items', selectedIds.map((id) => {
            const existing = requestedFniItems.find((row) => String(row.fni_library_item_id) === String(id));

            return existing || {
                fni_library_item_id: Number(id),
                requested_quantity: suggestedQuantity,
            };
        }));
    };
    const setRequestedFniQuantity = (fniLibraryItemId, requestedQuantity) => {
        const next = coerceWholeQuantity(requestedQuantity, { min: 1 });
        form.setData('requested_fni_items', requestedFniItems.map((row) => (
            String(row.fni_library_item_id) === String(fniLibraryItemId)
                ? { ...row, requested_quantity: next === '' ? '' : next }
                : row
        )));
    };
    const consolidatedRequestedItems = Array.isArray(consolidatedForm.data.requested_fni_items) ? consolidatedForm.data.requested_fni_items : [];
    const consolidatedRequestedIds = consolidatedRequestedItems.map((row) => String(row.fni_library_item_id));
    const selectedConsolidatedIncidents = reliefRequestIncidentOptions.filter((incident) => consolidatedForm.data.incident_series_keys.includes(incident.series_key));
    const selectedConsolidatedType = selectedConsolidatedIncidents[0]?.incident_type || '';
    const selectedEncodedFniCount = sumEncodedFniItems(selectedConsolidatedIncidents).length;
    const selectedMissingEncodedFni = selectedConsolidatedIncidents.length > 0 && selectedEncodedFniCount === 0;
    const reliefOptionsByType = reliefRequestIncidentOptions.reduce((groups, incident) => {
        const type = String(incident.incident_type || 'Other').trim() || 'Other';
        if (!groups[type]) groups[type] = [];
        groups[type].push(incident);
        return groups;
    }, {});
    const toggleConsolidatedIncident = (incident) => {
        const selected = consolidatedForm.data.incident_series_keys.includes(incident.series_key);
        if (!selected && selectedConsolidatedType && String(incident.incident_type).toLocaleLowerCase() !== String(selectedConsolidatedType).toLocaleLowerCase()) {
            window.dispatchEvent(new CustomEvent('dromis:toast', {
                detail: {
                    type: 'error',
                    title: 'Different incident type',
                    message: `This lump request is limited to ${selectedConsolidatedType}. Deselect that type first to switch.`,
                },
            }));
            return;
        }
        const nextKeys = selected
            ? consolidatedForm.data.incident_series_keys.filter((key) => key !== incident.series_key)
            : [...consolidatedForm.data.incident_series_keys, incident.series_key];
        // While revising, keep the pre-filled / manually edited FNI list intact when incidents change.
        if (editingConsolidatedId) {
            consolidatedForm.setData({
                ...consolidatedForm.data,
                incident_series_keys: nextKeys,
            });
            return;
        }
        const nextIncidents = reliefRequestIncidentOptions.filter((option) => nextKeys.includes(option.series_key));
        const summedItems = sumEncodedFniItems(nextIncidents);
        // Keep manually encoded FNI when selected reports have none (forgot-to-encode case).
        consolidatedForm.setData({
            ...consolidatedForm.data,
            incident_series_keys: nextKeys,
            requested_fni_items: summedItems.length
                ? summedItems
                : (nextKeys.length ? consolidatedRequestedItems : []),
        });
    };
    const setConsolidatedFniSelection = (selectedIds) => {
        consolidatedForm.setData('requested_fni_items', selectedIds.map((id) => consolidatedRequestedItems.find((row) => String(row.fni_library_item_id) === String(id)) || {
            fni_library_item_id: Number(id),
            requested_quantity: '',
        }));
    };
    const setConsolidatedFniQuantity = (fniLibraryItemId, value) => {
        const next = coerceWholeQuantity(value, { min: 1 });
        consolidatedForm.setData('requested_fni_items', consolidatedRequestedItems.map((row) => String(row.fni_library_item_id) === String(fniLibraryItemId)
            ? { ...row, requested_quantity: next === '' ? '' : next }
            : row));
    };
    const openConsolidatedRequest = () => {
        consolidatedForm.clearErrors();
        consolidatedForm.reset();
        consolidatedForm.setData({
            incident_series_keys: [],
            requested_fni_items: [],
            signed_request: null,
            submission_status: 'final',
        });
        setEditingConsolidatedId(null);
        setConsolidatedRequestOpen(true);
        setConsolidatedAreasViewer(null);
        setConsolidatedOptionsLoading(true);
        // Refresh eligibility after signed-report uploads without requiring a full page reload.
        router.get('/lgu/dromic-sitrep', { tab: 'requests' }, {
            only: ['reliefRequestIncidentOptions', 'incidentGroups'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onFinish: () => setConsolidatedOptionsLoading(false),
        });
    };
    const openConsolidatedEdit = (row) => {
        const payload = row?.lgu_dromic_payload || {};
        consolidatedForm.clearErrors();
        consolidatedForm.setData({
            incident_series_keys: Array.isArray(payload.linked_incident_series_keys) ? payload.linked_incident_series_keys : [],
            requested_fni_items: (Array.isArray(payload.requested_fni_items) ? payload.requested_fni_items : [])
                .map((item) => ({
                    fni_library_item_id: Number(item.fni_library_item_id),
                    requested_quantity: Number(item.requested_quantity) || '',
                }))
                .filter((item) => item.fni_library_item_id > 0),
            signed_request: null,
            submission_status: 'final',
        });
        consolidatedSaveModeRef.current = 'final';
        setEditingConsolidatedId(row.id);
        setConsolidatedRequestOpen(true);
        setConsolidatedAreasViewer(null);
        setConsolidatedOptionsLoading(true);
        router.get('/lgu/dromic-sitrep', { tab: 'requests', editing_relief_request_id: row.id }, {
            only: ['requests', 'reliefRequestIncidentOptions', 'incidentGroups', 'requestDashboard'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onFinish: () => setConsolidatedOptionsLoading(false),
        });
    };
    const closeConsolidatedRequestModal = () => {
        setConsolidatedRequestOpen(false);
        setEditingConsolidatedId(null);
        setConsolidatedAreasViewer(null);
        consolidatedSaveModeRef.current = 'final';
        // Drop editing_relief_request_id so partial reloads do not keep revise context sticky.
        router.get('/lgu/dromic-sitrep', { tab: 'requests' }, {
            only: ['reliefRequestIncidentOptions', 'incidentGroups'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };
    const submitConsolidatedRequest = (event) => {
        event.preventDefault();
        const submitterMode = event.nativeEvent?.submitter?.dataset?.saveMode;
        const submissionStatus = submitterMode || consolidatedSaveModeRef.current || 'final';
        consolidatedSaveModeRef.current = submissionStatus;
        if (editingConsolidatedId) {
            // Inertia v2 useForm.transform() returns void — do not chain .patch/.post.
            // POST + _method keeps multipart file uploads working on Laravel.
            consolidatedForm.transform((data) => ({
                submission_status: submissionStatus,
                incident_series_keys: data.incident_series_keys,
                requested_fni_items: data.requested_fni_items,
                signed_request: data.signed_request || null,
                _method: 'patch',
            }));
            consolidatedForm.post(`/lgu/dromic-sitrep/${editingConsolidatedId}/relief-request`, {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    setConsolidatedRequestOpen(false);
                    setEditingConsolidatedId(null);
                    consolidatedSaveModeRef.current = 'final';
                    consolidatedForm.transform((data) => data);
                },
                onFinish: () => consolidatedForm.transform((data) => data),
            });
            return;
        }
        consolidatedForm.post('/lgu/dromic-sitrep/relief-requests', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => setConsolidatedRequestOpen(false),
        });
    };
    const finalizeStandaloneReliefForSubmit = (row) => {
        if (!row?.id) return;
        const status = row.lgu_report_status || row.status;
        const retained = retainedSignedRequestFor(row);
        if (!retained) {
            openConsolidatedEdit(row);
            return;
        }

        // Already finalized — submit to DSWD/DRRS immediately.
        if (status === 'final') {
            setSubmissionBusy(true);
            router.post(`/lgu/dromic-sitrep/${row.id}/submit`, {}, {
                preserveScroll: true,
                onFinish: () => setSubmissionBusy(false),
            });
            return;
        }

        const payload = row?.lgu_dromic_payload || {};
        const keys = Array.isArray(payload.linked_incident_series_keys) ? payload.linked_incident_series_keys : [];
        const items = (Array.isArray(payload.requested_fni_items) ? payload.requested_fni_items : [])
            .map((item) => ({
                fni_library_item_id: Number(item.fni_library_item_id),
                requested_quantity: Number(item.requested_quantity) || 0,
            }))
            .filter((item) => item.fni_library_item_id > 0 && item.requested_quantity > 0);
        if (!keys.length || !items.length) {
            openConsolidatedEdit(row);
            return;
        }

        // Draft with signed letter: finalize then submit in one flow.
        setSubmissionBusy(true);
        router.post(`/lgu/dromic-sitrep/${row.id}/relief-request`, {
            _method: 'patch',
            submission_status: 'final',
            incident_series_keys: keys,
            requested_fni_items: items,
        }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                router.post(`/lgu/dromic-sitrep/${row.id}/submit`, {}, {
                    preserveScroll: true,
                    onFinish: () => setSubmissionBusy(false),
                });
            },
            onError: () => setSubmissionBusy(false),
        });
    };
    const previewReport = (requests?.data ?? []).find((row) => Number(row.id) === Number(previewReportId));
    const editingReport = (requests?.data ?? []).find((row) => Number(row.id) === Number(editingRequestId));
    const editingConsolidatedRow = (requests?.data ?? []).find((row) => Number(row.id) === Number(editingConsolidatedId));
    const editingRetainedSignedRequest = retainedSignedRequestFor(editingConsolidatedRow);
    const editingHasRetainedSignedRequest = Boolean(editingRetainedSignedRequest);
    const hasConsolidatedIncidentChoices = reliefRequestIncidentOptions.length > 0 || Boolean(editingConsolidatedId);
    const canSubmitConsolidatedUpdate = Boolean(editingConsolidatedId)
        && consolidatedForm.data.incident_series_keys.length > 0
        && consolidatedRequestedItems.length > 0
        && (Boolean(consolidatedForm.data.signed_request) || editingHasRetainedSignedRequest);
    const signedReportLocked = previewReport?.lgu_dromic_validation_status === 'validated_no_findings'
        && Boolean(previewReport?.lgu_signed_report_path);
    // Signed request-letter upload/preview only when Include request was ticked (or a lump request was created).
    // Encoding FNI needs alone must not show the request-letter uploader.
    const previewIsStandaloneRelief = isStandaloneReliefRequest(previewReport);
    const previewIncludesRequestLetter = Boolean(previewReport?.lgu_dromic_payload?.has_relief_request)
        || Boolean(previewReport?.lgu_relief_request_reference)
        || previewIsStandaloneRelief;
    const signedRequestLocked = previewIncludesRequestLetter
        && previewReport?.lgu_relief_validation_status === 'validated_no_findings';
    const reportReplacementAllowed = !previewIsStandaloneRelief && (
        !previewReport?.lgu_signed_report_path
        || previewReport?.lgu_report_status === 'final'
        || (previewReport?.lgu_dromic_validation_status === 'needs_lgu_action'
            && (previewReport?.lgu_dromic_correction_scope || 'document') === 'document')
        || (previewReport?.lgu_dromic_validation_status === 'validated_no_findings'
            && !previewReport?.lgu_signed_report_path)
    );
    const requestReplacementAllowed = previewIncludesRequestLetter && (
        !previewReport?.lgu_signed_request_path
        || previewReport?.lgu_report_status === 'final'
        || (previewReport?.lgu_relief_validation_status === 'needs_lgu_action'
            && (previewReport?.lgu_relief_correction_scope || 'document') === 'document')
    );
    const bothDocumentsValidated = previewIsStandaloneRelief
        ? signedRequestLocked && Boolean(previewReport?.lgu_signed_request_path)
        : previewIncludesRequestLetter
            && signedReportLocked
            && signedRequestLocked
            && Boolean(previewReport?.lgu_signed_request_path);
    const previewDocumentKind = previewIncludesRequestLetter
        && (previewMode === 'request' || (previewMode === 'incident' && combinedPreviewTab === 'request'))
        ? 'request'
        : 'report';
    const previewValidationNote = previewDocumentKind === 'request'
        ? previewReport?.lgu_relief_review_note
        : previewReport?.lgu_dromic_review_note;
    const previewCanExport = previewDocumentKind === 'request'
        ? signedRequestLocked && Boolean(previewReport?.lgu_signed_request_path)
        : previewReport?.lgu_dromic_validation_status === 'validated_no_findings';
    // Keep signed DROMIC report upload available in Preview (report or incident mode).
    // Only the request-letter uploader stays gated by Include request / lump reference.
    const showSignedCopiesPanel = !isProvince
        && Boolean(previewReport)
        && (previewMode === 'incident' || previewMode === 'report');
    const openDocumentPreview = (row, mode) => {
        const standalone = isStandaloneReliefRequest(row);
        const includesRequest = Boolean(row?.lgu_dromic_payload?.has_relief_request)
            || Boolean(row?.lgu_relief_request_reference)
            || standalone;
        const status = row?.lgu_report_status || row?.status;
        // Eye used to open report-only preview without the signed-copies panel.
        // Prefer the submission workspace whenever signed report upload/submit is still needed.
        // Request-letter upload remains gated by Include request / lump reference inside that panel.
        let nextMode = includesRequest ? mode : (mode === 'request' ? 'incident' : mode);
        if (standalone) {
            nextMode = 'incident';
        } else if (mode === 'report' && (
            status === 'final'
            || status === 'advance_submitted'
            || (status === 'submitted' && !row?.lgu_signed_report_path)
        )) {
            nextMode = 'incident';
        }
        setPreviewContentTab('narrative');
        setPreviewControlsCollapsed(false);
        setPreviewSidebarCollapsed(false);
        setPreviewMode(nextMode);
        setReportCopyTab('advance');
        setCombinedPreviewTab((includesRequest && (mode === 'request' || standalone)) ? 'request' : 'report');
        setSignedReport(null);
        setSignedRequest(null);
        setSignedUploadErrors({});
        setSignedUploadNotice(null);
        setSubmissionBusy(false);
        setPreviewReportId(row.id);
        syncPreviewInUrl(row.id, nextMode);
    };
    const closePdfPreview = () => setResponseLetterPreview({ open: false, title: '', subtitle: null, src: null, kind: null, zIndexClass: 'z-[100]' });
    const openSignedRequestPdfPreview = (retained, { title = 'Signed request letter', zIndexClass = 'z-[100]' } = {}) => {
        if (!retained?.viewUrl) return;
        setResponseLetterPreview({
            open: true,
            title,
            subtitle: retained.name || null,
            src: retained.viewUrl,
            kind: 'signed',
            zIndexClass,
        });
    };
    const openRetainedSignedRequestView = (row) => {
        const retained = retainedSignedRequestFor(row);
        if (!retained) return;
        if (retained.active) {
            openDocumentPreview(row, 'request');
            return;
        }
        openSignedRequestPdfPreview(retained, { title: 'Signed request letter' });
    };
    const openCorrectionWorkspace = (row, kind) => {
        const includesRequest = Boolean(row?.lgu_dromic_payload?.has_relief_request)
            || Boolean(row?.lgu_relief_request_reference);
        setPreviewContentTab('narrative');
        setPreviewControlsCollapsed(false);
        setPreviewSidebarCollapsed(false);
        setPreviewMode('incident');
        setCombinedPreviewTab(kind === 'request' && includesRequest ? 'request' : 'report');
        setReportCopyTab('advance');
        setSignedReport(null);
        if (kind !== 'request' || !includesRequest) setSignedRequest(null);
        setSignedUploadErrors({});
        setSignedUploadNotice(null);
        setSubmissionBusy(false);
        setPreviewReportId(row.id);
        syncPreviewInUrl(row.id, 'incident');
    };
    const closeDocumentPreview = () => {
        setPreviewReportId(null);
        syncPreviewInUrl(null);
    };

    useEffect(() => {
        if (!flash.preview_report_id) return;
        const previewRow = (requests?.data ?? []).find((row) => Number(row.id) === Number(flash.preview_report_id));
        // Standalone relief must use incident mode so the Submit to DSWD panel is visible.
        const mode = isStandaloneReliefRequest(previewRow) ? 'incident' : (flash.preview_mode || 'incident');
        setPreviewMode(mode);
        setCombinedPreviewTab(mode === 'request' || isStandaloneReliefRequest(previewRow) ? 'request' : 'report');
        setReportCopyTab('advance');
        setPreviewReportId(flash.preview_report_id);
        syncPreviewInUrl(flash.preview_report_id, mode);
    }, [flash.preview_report_id, flash.preview_mode]);

    useEffect(() => {
        if (!previewReport || previewIncludesRequestLetter) return;
        if (combinedPreviewTab === 'request') setCombinedPreviewTab('report');
        if (previewMode === 'request') setPreviewMode('incident');
        setSignedRequest(null);
    }, [previewReportId, previewIncludesRequestLetter, combinedPreviewTab, previewMode, previewReport]);

    useEffect(() => {
        if (!draftPreviewOpen) {
            if (draftPreviewUrlRef.current) {
                URL.revokeObjectURL(draftPreviewUrlRef.current);
                draftPreviewUrlRef.current = null;
            }
            setDraftPreviewUrl('');
            setDraftPreviewBusy(false);
            setDraftPreviewError('');
            return undefined;
        }

        if (draftPreviewTab === 'encoded') {
            return undefined;
        }

        let cancelled = false;
        const loadNarrativePreview = async () => {
            setDraftPreviewBusy(true);
            setDraftPreviewError('');
            if (draftPreviewUrlRef.current) {
                URL.revokeObjectURL(draftPreviewUrlRef.current);
                draftPreviewUrlRef.current = null;
            }
            setDraftPreviewUrl('');
            try {
                const response = await window.axios.post('/lgu/dromic-sitrep/preview?pdf=1', {
                    ...form.data,
                    report_number: editingReport?.lgu_dromic_report_number || form.data.report_number || 1,
                }, {
                    responseType: 'blob',
                    headers: { Accept: 'application/pdf' },
                    withXSRFToken: true,
                });
                if (cancelled) {
                    return;
                }
                const url = URL.createObjectURL(response.data);
                draftPreviewUrlRef.current = url;
                setDraftPreviewUrl(url);
            } catch (error) {
                if (cancelled) {
                    return;
                }
                setDraftPreviewUrl('');
                setDraftPreviewError(error?.response?.data?.message || 'Unable to build the LGU narrative report PDF preview.');
            } finally {
                if (!cancelled) {
                    setDraftPreviewBusy(false);
                }
            }
        };

        loadNarrativePreview();

        return () => {
            cancelled = true;
        };
    }, [draftPreviewOpen, draftPreviewTab]);

    const openCreateReport = () => {
        setEditingRequestId(null);
        setSourceSeriesSummary({ drafts_saved: 0, reports_finalized: 0, is_terminal: false });
        form.clearErrors();
        form.setData(emptyPayload(lguProfile, defaultIncidentDate));
        setValidationNotice('');
        setOpen(true);
    };

    const openDraftReport = (row) => {
        const payload = row.lgu_dromic_payload || {};
        setEditingRequestId(row.id);
        setSourceSeriesSummary(row.series_summary || { drafts_saved: 0, reports_finalized: 0, is_terminal: false });
        form.clearErrors();
        form.setData({
            ...emptyPayload(lguProfile, defaultIncidentDate),
            ...payload,
            submission_status: 'draft',
        });
        setValidationNotice('');
        setOpen(true);
    };

    const openIncidentUpdate = (row, classification = 'regular') => {
        const payload = row.lgu_dromic_payload || {};
        const updatePayload = classification === 'terminal'
            ? zeroTerminalNowValues(payload)
            : payload;
        setEditingRequestId(null);
        setSourceSeriesSummary(row.series_summary || { drafts_saved: 0, reports_finalized: 0, is_terminal: false });
        form.clearErrors();
        form.setData({
            ...emptyPayload(lguProfile, defaultIncidentDate),
            ...updatePayload,
            submission_status: 'draft',
            report_series_key: row.lgu_dromic_series_key || payload.report_series_key || '',
            report_classification: classification,
            // Next SitRep must not inherit prior Include-request / FNI needs.
            // Those stay on the earlier report (or lump letter); encode fresh needs here only if required.
            has_relief_request: false,
            requested_fni_items: [],
            relief_requested: '',
        });
        setValidationNotice(classification === 'terminal'
            ? 'Terminal Report selected. All NOW values were initialized to 0. Prior FNI needs / request letter were not carried over. NOW values remain editable if validated current counts still exist.'
            : 'A new unnumbered update has been prepared from the latest report. Prior FNI needs / request letter were not carried over. It will receive the next report number only when finalized.');
        setOpen(true);
    };

    const startCorrectionDraft = (row, target) => {
        router.post(`/lgu/dromic-sitrep/${row.id}/correction-draft`, { target }, {
            preserveScroll: true,
            onSuccess: (page) => {
                const correctionId = page?.props?.flash?.correction_draft_id;
                const correction = (page?.props?.requests?.data ?? []).find((item) => Number(item.id) === Number(correctionId))
                    || (Number(page?.props?.correctionDraft?.id) === Number(correctionId) ? page.props.correctionDraft : null);

                if (correction) {
                    setPreviewReportId(null);
                    openDraftReport(correction);
                }
            },
        });
    };

    const amendmentTargetOf = (row) => row?.lgu_amendment_request_target || (row?.lgu_amendment_request_status ? 'report' : null);

    const canReopenForRevision = (row) => {
        const status = row?.lgu_report_status || row?.status;
        return status === 'final'
            && !row?.lgu_submitted_to_dswd_at
            && !row?.lgu_correction_of_id;
    };

    const canRequestAmendment = (row, target = 'report') => {
        const status = row?.lgu_report_status || row?.status;
        // Amendment is only for documents already submitted to DSWD.
        if (!['advance_submitted', 'submitted'].includes(status)) return false;
        if (row?.lgu_correction_of_id) return false;
        if (row?.lgu_amendment_request_status === 'requested') return false;

        if (target === 'request') {
            const hasRelief = Boolean(row?.lgu_relief_request_reference)
                || Boolean(row?.lgu_dromic_payload?.has_relief_request)
                || Boolean(row?.lgu_dromic_payload?.standalone_relief_request);
            if (!hasRelief) return false;
            if (row?.lgu_relief_validation_status === 'needs_lgu_action') return false;
            if (['validated_no_findings', 'superseded'].includes(row?.lgu_relief_validation_status)) return false;
            return true;
        }

        if (row?.lgu_dromic_payload?.standalone_relief_request) return false;
        if (row?.lgu_dromic_validation_status === 'needs_lgu_action') return false;
        if (['validated_no_findings', 'superseded'].includes(row?.lgu_dromic_validation_status)) return false;
        return true;
    };

    const reopenForRevision = (row) => {
        if (!row?.id || reopenBusy) return;
        setReopenBusy(true);
        router.post(`/lgu/dromic-sitrep/${row.id}/reopen`, {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                const draftId = page?.props?.flash?.reopen_draft_id || row.id;
                const mode = page?.props?.flash?.reopen_draft_mode
                    || (isStandaloneReliefRequest(row) ? 'request' : 'report');
                const refreshed = (page?.props?.requests?.data ?? []).find((item) => Number(item.id) === Number(draftId))
                    || { ...row, lgu_report_status: 'draft', status: 'draft', lgu_submitted_to_dswd_at: null };
                if (mode === 'request' || isStandaloneReliefRequest(refreshed)) {
                    openConsolidatedEdit(refreshed);
                } else {
                    openDraftReport(refreshed);
                }
            },
            onFinish: () => setReopenBusy(false),
        });
    };

    const openAmendmentRequest = (row, target = 'report') => {
        setAmendmentRequestRow(row);
        setAmendmentRequestTarget(target);
        setAmendmentReason('');
    };

    const submitAmendmentRequest = (event) => {
        event.preventDefault();
        if (!amendmentRequestRow?.id || amendmentBusy) return;
        setAmendmentBusy(true);
        router.post(`/lgu/dromic-sitrep/${amendmentRequestRow.id}/amendment-request`, {
            target: amendmentRequestTarget,
            reason: amendmentReason,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setAmendmentRequestRow(null);
                setAmendmentRequestTarget('report');
                setAmendmentReason('');
            },
            onFinish: () => setAmendmentBusy(false),
        });
    };

    useEffect(() => {
        if (!flash.correction_draft_id) return;
        const correction = (requests?.data ?? []).find((row) => Number(row.id) === Number(flash.correction_draft_id))
            || (Number(correctionDraft?.id) === Number(flash.correction_draft_id) ? correctionDraft : null);
        if (correction) openDraftReport(correction);
    }, [flash.correction_draft_id, correctionDraft?.id]);

    useEffect(() => {
        if (!flash.reopen_draft_id) return;
        const draft = (requests?.data ?? []).find((row) => Number(row.id) === Number(flash.reopen_draft_id));
        if (!draft) return;
        if (flash.reopen_draft_mode === 'request' || isStandaloneReliefRequest(draft)) {
            openConsolidatedEdit(draft);
        } else {
            openDraftReport(draft);
        }
    }, [flash.reopen_draft_id, flash.reopen_draft_mode]);

    useEffect(() => {
        const reloadFniProcessing = (payload = {}) => {
            const lguPsgc = String(lguProfile?.psgc_code || '');
            const payloadPsgc = String(payload.lgu_psgc_code || '');

            // Events are user-room scoped to concerned LGU recipients; PSGC is an extra guard.
            if (lguPsgc && payloadPsgc && lguPsgc !== payloadPsgc) {
                return;
            }

            window.clearTimeout(realtimeReloadTimer.current);
            realtimeReloadTimer.current = window.setTimeout(() => {
                router.reload({
                    only: ['requests', 'requestDashboard'],
                    preserveScroll: true,
                    preserveState: true,
                });
            }, 350);
        };

        const stop = listenRealtime('lgu.fni.processing.updated', reloadFniProcessing);

        return () => {
            stop();
            window.clearTimeout(realtimeReloadTimer.current);
        };
    }, [lguProfile?.psgc_code]);

    const clearSignedFileInputs = () => {
        if (signedReportInputRef.current) signedReportInputRef.current.value = '';
        if (signedRequestInputRef.current) signedRequestInputRef.current.value = '';
    };

    const uploadSignedCopies = () => {
        if (!previewReportId || submissionBusy) return;
        const maxBytes = 10 * 1024 * 1024;
        if (!signedReport && !(previewIncludesRequestLetter && signedRequest)) {
            setSignedUploadErrors({
                [previewIsStandaloneRelief ? 'signed_request' : 'signed_report']: 'Choose a PDF file before uploading.',
            });
            setSignedUploadNotice({ type: 'error', message: 'Choose a PDF file before uploading.' });
            return;
        }
        if (!previewIsStandaloneRelief && signedReport && signedReport.size > maxBytes) {
            setSignedUploadErrors({ signed_report: 'Signed report PDF must be 10 MB or smaller.' });
            setSignedUploadNotice({ type: 'error', message: 'Signed report PDF must be 10 MB or smaller.' });
            return;
        }
        if (previewIncludesRequestLetter && signedRequest && signedRequest.size > maxBytes) {
            setSignedUploadErrors({ signed_request: 'Signed request-letter PDF must be 10 MB or smaller.' });
            setSignedUploadNotice({ type: 'error', message: 'Signed request-letter PDF must be 10 MB or smaller.' });
            return;
        }
        if (signedReport && !/\.pdf$/i.test(signedReport.name || '')) {
            setSignedUploadErrors({ signed_report: 'Signed report must be a PDF file.' });
            setSignedUploadNotice({ type: 'error', message: 'Signed report must be a PDF file.' });
            return;
        }
        if (previewIncludesRequestLetter && signedRequest && !/\.pdf$/i.test(signedRequest.name || '')) {
            setSignedUploadErrors({ signed_request: 'Signed request letter must be a PDF file.' });
            setSignedUploadNotice({ type: 'error', message: 'Signed request letter must be a PDF file.' });
            return;
        }

        const payload = {};
        if (signedReport) payload.signed_report = signedReport;
        if (previewIncludesRequestLetter && signedRequest) payload.signed_request = signedRequest;
        const uploadingReportId = previewReportId;

        setSignedUploadErrors({});
        setSignedUploadNotice({ type: 'info', message: 'Uploading signed PDF…' });
        setSubmissionBusy(true);

        // Avoid nesting router.reload() inside onSuccess — that can cancel the visit
        // before onFinish and leave the upload button stuck disabled.
        router.post(`/lgu/dromic-sitrep/${uploadingReportId}/signed-copies`, payload, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            only: ['requests', 'incidentGroups', 'monitoringSummary', 'reliefRequestIncidentOptions', 'flash'],
            onSuccess: (page) => {
                const successMessage = page?.props?.flash?.success
                    || 'Signed copy attachment(s) uploaded successfully.';
                setSignedReport(null);
                setSignedRequest(null);
                setSignedUploadErrors({});
                clearSignedFileInputs();
                setSignedUploadNotice({ type: 'success', message: successMessage });
                window.dispatchEvent(new CustomEvent('dromis:toast', {
                    detail: { type: 'success', title: 'Signed copy uploaded', message: successMessage },
                }));
                window.setTimeout(() => {
                    setPreviewReportId((current) => (Number(current) === Number(uploadingReportId) ? null : current));
                    syncPreviewInUrl(null);
                    setSignedUploadNotice(null);
                }, 1400);
            },
            onError: (errors) => {
                setSignedUploadErrors(errors || {});
                const message = [...new Set(Object.values(errors || {}).flat().filter(Boolean))].slice(0, 2).join(' ')
                    || 'Unable to upload the signed PDF. Please try again.';
                setSignedUploadNotice({ type: 'error', message });
                window.dispatchEvent(new CustomEvent('dromis:toast', {
                    detail: { type: 'error', title: 'Upload failed', message },
                }));
            },
            onCancel: () => {
                setSignedUploadNotice({ type: 'error', message: 'Upload was interrupted. Please try again.' });
            },
            onFinish: () => setSubmissionBusy(false),
        });
    };

    const submitReportToDswd = () => {
        if (!previewReportId) return;
        setSubmissionBusy(true);
        router.post(`/lgu/dromic-sitrep/${previewReportId}/submit`, {}, {
            preserveScroll: true,
            onFinish: () => setSubmissionBusy(false),
        });
    };

    const printReport = () => {
        const popup = window.open(`/lgu/dromic-sitrep/${previewReportId}/pdf?inline=1`, '_blank');
        if (popup) popup.addEventListener('load', () => popup.print(), { once: true });
    };
    const error = (field) => form.errors[field] && <p className="mt-1 text-xs font-bold text-rose-600">{form.errors[field]}</p>;
    const provinceFromProfile = () => {
        if (lguProfile?.province) return lguProfile.province;
        const name = lguProfile?.name || '';
        if (name.includes(',')) return name.split(',').slice(1).join(',').trim();
        return isProvince ? name : '';
    };
    const selectedBarangays = Array.isArray(form.data.affected_barangays) ? form.data.affected_barangays : [];
    const currentDateTimeLimit = currentLocalDateTimeInput();
    const occurrenceTimestamp = Date.parse(form.data.occurrence_started_at || '');
    const informationReceivedTimestamp = Date.parse(form.data.information_received_at || '');
    const incidentEndedTimestamp = Date.parse(form.data.incident_ended_at || '');
    const occurrenceDateTimeIssue = Number.isFinite(occurrenceTimestamp) && occurrenceTimestamp > Date.now()
        ? 'Occurrence cannot be later than the current date and time.'
        : '';
    const informationReceivedDateTimeIssue = Number.isFinite(informationReceivedTimestamp) && informationReceivedTimestamp > Date.now()
        ? 'Information received cannot be later than the current date and time.'
        : (Number.isFinite(occurrenceTimestamp) && Number.isFinite(informationReceivedTimestamp) && informationReceivedTimestamp < occurrenceTimestamp
            ? 'Information received cannot be earlier than occurrence.'
            : '');
    const incidentEndedDateTimeIssue = Number.isFinite(incidentEndedTimestamp) && incidentEndedTimestamp > Date.now()
        ? 'Incident ended cannot be later than the current date and time.'
        : (Number.isFinite(occurrenceTimestamp) && Number.isFinite(incidentEndedTimestamp) && incidentEndedTimestamp < occurrenceTimestamp
            ? 'Incident ended cannot be earlier than occurrence.'
            : '');
    const areaRows = selectedBarangays.length && Array.isArray(form.data.area_rows) ? form.data.area_rows : [];
    const notApplicableSections = Array.isArray(form.data.not_applicable_sections) ? form.data.not_applicable_sections : [];
    const isSectionNotApplicable = (key) => key !== 'response_actions' && notApplicableSections.includes(key);
    const toggleSectionNotApplicable = (key) => {
        const turningOn = !isSectionNotApplicable(key);
        const next = turningOn
            ? [...notApplicableSections, key]
            : notApplicableSections.filter((sectionKey) => sectionKey !== key);

        if (key === 'advisory_screenshots' && turningOn) {
            form.setData((current) => ({
                ...current,
                not_applicable_sections: next,
                official_advisory_rows: [],
            }));
            setAdvisoryExtractionBusy(false);
            return;
        }

        form.setData('not_applicable_sections', next);
    };
    const evacuationCenterRows = Array.isArray(form.data.evacuation_center_rows) ? form.data.evacuation_center_rows : [];
    const assistanceRows = Array.isArray(form.data.assistance_rows) ? form.data.assistance_rows : [];
    const relatedIncidentRows = Array.isArray(form.data.related_incident_rows) ? form.data.related_incident_rows : [];
    const casualtyRows = Array.isArray(form.data.casualty_rows) ? form.data.casualty_rows : [];
    const infrastructureDamageRows = Array.isArray(form.data.infrastructure_damage_rows) ? form.data.infrastructure_damage_rows : [];
    const agricultureDamageRows = Array.isArray(form.data.agriculture_damage_rows) ? form.data.agriculture_damage_rows : [];
    const classSuspensionRows = Array.isArray(form.data.class_suspension_rows) ? form.data.class_suspension_rows : [];
    const workSuspensionRows = Array.isArray(form.data.work_suspension_rows) ? form.data.work_suspension_rows : [];
    const roadBridgeRows = Array.isArray(form.data.road_bridge_rows) ? form.data.road_bridge_rows : [];
    const powerLifelineRows = Array.isArray(form.data.power_lifeline_rows) ? form.data.power_lifeline_rows : [];
    const waterLifelineRows = Array.isArray(form.data.water_lifeline_rows) ? form.data.water_lifeline_rows : [];
    const communicationLifelineRows = Array.isArray(form.data.communication_lifeline_rows) ? form.data.communication_lifeline_rows : [];
    const seaportRows = Array.isArray(form.data.seaport_rows) ? form.data.seaport_rows : [];
    const airportRows = Array.isArray(form.data.airport_rows) ? form.data.airport_rows : [];
    const landTransportTerminalRows = Array.isArray(form.data.land_transport_terminal_rows) ? form.data.land_transport_terminal_rows : [];
    const strandedTransportRows = Array.isArray(form.data.stranded_transport_rows) ? form.data.stranded_transport_rows : [];
    const calamityDeclarationRows = Array.isArray(form.data.calamity_declaration_rows) ? form.data.calamity_declaration_rows : [];
    const preemptiveEvacuationRows = Array.isArray(form.data.preemptive_evacuation_rows) ? form.data.preemptive_evacuation_rows : [];
    const clusterGapRows = Array.isArray(form.data.cluster_gap_rows) ? form.data.cluster_gap_rows : [];
    const responseActionRows = Array.isArray(form.data.response_action_rows) ? form.data.response_action_rows : [];
    const officialAdvisoryRows = Array.isArray(form.data.official_advisory_rows) ? form.data.official_advisory_rows : [];
    const photoDocumentationRows = Array.isArray(form.data.photo_documentation_rows) ? form.data.photo_documentation_rows : [];
    const photoCollageRows = Array.isArray(form.data.photo_collage_rows) ? form.data.photo_collage_rows : [];
    const [disaggregationModalIndex, setDisaggregationModalIndex] = useState(null);
    const barangayOptionMap = new Map((barangayOptions || []).map((option) => [option.value, option]));
    const updateAreaRow = (index, key, value) => {
        form.setData('area_rows', areaRows.map((row, rowIndex) => {
            if (rowIndex !== index) return row;

            return typeof key === 'object' ? { ...row, ...key } : { ...row, [key]: value };
        }));
        setValidationNotice('');
    };
    const updateAreaRows = (updater) => {
        form.setData('area_rows', areaRows.map(updater));
        setValidationNotice('');
    };
    const totalsFromRows = (key) => areaRows.reduce((sum, row) => sum + Number(row?.[key] || 0), 0);
    const needsPopulationJustification = areaRows.some((row) => {
        const psa2024 = Number(row.psa_2024 || 0);
        const persons = Number(row.affected_persons || 0);

        return psa2024 > 0 && persons > psa2024;
    });
    const populationIssues = areaRows
        .map((row, index) => {
            const familiesRaw = String(row.affected_families ?? '').trim();
            const personsRaw = String(row.affected_persons ?? '').trim();
            const hasFamilies = familiesRaw !== '';
            const hasPersons = personsRaw !== '';

            if (!hasFamilies && !hasPersons) {
                return {
                    index,
                    type: 'empty',
                    message: 'Awaiting Entry!',
                };
            }

            if (hasFamilies !== hasPersons) {
                return {
                    index,
                    type: 'paired',
                    message: `Please encode both affected families and affected persons for ${row.area || `Barangay ${index + 1}`}.`,
                };
            }

            const families = Number(familiesRaw || 0);
            const persons = Number(personsRaw || 0);

            const pairIssue = familyPersonPairIssue(families, persons, row.area || `Barangay ${index + 1}`);
            if (pairIssue) {
                return {
                    index,
                    type: 'families',
                    message: pairIssue,
                };
            }

            const psa2024 = Number(row.psa_2024 || 0);
            if (psa2024 > 0 && persons > psa2024 && !String(form.data.population_justification || '').trim()) {
                return {
                    index,
                    type: 'psa_2024',
                    message: `Affected persons for ${row.area || `Barangay ${index + 1}`} exceed the PSA 2024 population. Please provide the justification below.`,
                };
            }

            return null;
        })
        .filter(Boolean);
    const totalAffectedFamilies = totalsFromRows('affected_families');
    const totalAffectedPersons = totalsFromRows('affected_persons');
    const areaLookupKeys = (row = {}) => [
        String(row.psgc_code || '').trim(),
        String(row.area || row.barangay_origin || '').trim(),
    ].filter(Boolean);
    const affectedFamiliesByBarangayCode = new Map();
    const affectedPersonsByBarangayCode = new Map();
    areaRows.forEach((row) => {
        areaLookupKeys(row).forEach((key) => {
            affectedFamiliesByBarangayCode.set(key, Number(row.affected_families || 0));
            affectedPersonsByBarangayCode.set(key, Number(row.affected_persons || 0));
        });
    });
    const includeInsideEcInComputation = !isSectionNotApplicable('inside_ec');
    const includeOutsideEcInComputation = !isSectionNotApplicable('outside_ec');
    const outsideEcByOriginCode = areaRows.reduce((totals, row) => {
        if (!includeOutsideEcInComputation || !row.outside_ec_included) return totals;
        const keys = areaLookupKeys(row);
        if (!keys.length) return totals;

        const summary = {
            families_cum: Number(row.outside_ec_families_cum || 0),
            families_now: Number(row.outside_ec_families_now || 0),
            persons_cum: Number(row.outside_ec_persons_cum || 0),
            persons_now: Number(row.outside_ec_persons_now || 0),
        };

        keys.forEach((key) => totals.set(key, summary));

        return totals;
    }, new Map());
    const insideEcByOriginCode = (includeInsideEcInComputation ? evacuationCenterRows : []).reduce((totals, row) => {
        const keys = [
            String(row.barangay_origin_code || '').trim(),
            String(row.barangay_origin || '').trim(),
        ].filter(Boolean);
        if (!keys.length) return totals;
        const current = keys.reduce((found, key) => found || totals.get(key), null) || { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };
        const summary = {
            families_cum: current.families_cum + Number(row.families_cum || 0),
            families_now: current.families_now + Number(row.families_now || 0),
            persons_cum: current.persons_cum + Number(row.persons_cum || 0),
            persons_now: current.persons_now + Number(row.persons_now || 0),
        };

        keys.forEach((key) => totals.set(key, summary));

        return totals;
    }, new Map());
    const displacementConsistencyIssues = areaRows
        .map((row, index) => {
            const keys = areaLookupKeys(row);
            const inside = keys.reduce((found, key) => found || insideEcByOriginCode.get(key), null)
                || { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };
            const outside = includeOutsideEcInComputation && row.outside_ec_included
                ? {
                    families_cum: Number(row.outside_ec_families_cum || 0),
                    families_now: Number(row.outside_ec_families_now || 0),
                    persons_cum: Number(row.outside_ec_persons_cum || 0),
                    persons_now: Number(row.outside_ec_persons_now || 0),
                }
                : { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };
            const affectedFamilies = Number(row.affected_families || 0);
            const affectedPersons = Number(row.affected_persons || 0);

            for (const [periodKey, periodLabel] of [['cum', 'CUM'], ['now', 'NOW']]) {
                const displacedFamilies = inside[`families_${periodKey}`] + outside[`families_${periodKey}`];
                const displacedPersons = inside[`persons_${periodKey}`] + outside[`persons_${periodKey}`];
                const allFamiliesDisplaced = affectedFamilies > 0 && displacedFamilies === affectedFamilies;
                const allPersonsDisplaced = affectedPersons > 0 && displacedPersons === affectedPersons;

                if (displacedFamilies > affectedFamilies || displacedPersons > affectedPersons) {
                    return {
                        index,
                        key: String(row.psgc_code || row.area || ''),
                        period: periodKey,
                        message: `${row.area || `Barangay ${index + 1}`}: Total displaced ${periodLabel} cannot exceed affected population. Displaced: ${formatNumber(displacedFamilies)} families / ${formatNumber(displacedPersons)} persons; affected: ${formatNumber(affectedFamilies)} families / ${formatNumber(affectedPersons)} persons.`,
                    };
                }

                if (allFamiliesDisplaced !== allPersonsDisplaced) {
                    return {
                        index,
                        key: String(row.psgc_code || row.area || ''),
                        period: periodKey,
                        message: allFamiliesDisplaced
                            ? `${row.area || `Barangay ${index + 1}`}: Total displaced ${periodLabel} families already equal all affected families (${formatNumber(affectedFamilies)}), but displaced persons (${formatNumber(displacedPersons)}) do not equal affected persons (${formatNumber(affectedPersons)}). Correct the Inside/Outside EC counts so families and persons are fully accounted together.`
                            : `${row.area || `Barangay ${index + 1}`}: Total displaced ${periodLabel} persons already equal all affected persons (${formatNumber(affectedPersons)}), but displaced families (${formatNumber(displacedFamilies)}) do not equal affected families (${formatNumber(affectedFamilies)}). Correct the Inside/Outside EC counts so families and persons are fully accounted together.`,
                    };
                }

                const nonIdpFamilies = affectedFamilies - displacedFamilies;
                const nonIdpPersons = affectedPersons - displacedPersons;
                const nonIdpPairIssue = familyPersonPairIssue(nonIdpFamilies, nonIdpPersons, `${row.area || `Barangay ${index + 1}`} Non-IDP ${periodLabel}`);
                if (nonIdpPairIssue) {
                    return {
                        index,
                        key: String(row.psgc_code || row.area || ''),
                        period: periodKey,
                        message: `${nonIdpPairIssue} Computed Non-IDPs are ${formatNumber(nonIdpFamilies)} families / ${formatNumber(nonIdpPersons)} persons. Correct the affected or Inside/Outside EC counts.`,
                    };
                }
            }

            return null;
        })
        .filter(Boolean);
    const setSelectedBarangays = (values) => {
        const existingRows = new Map(areaRows.map((row) => [row.area, row]));
        form.setData({
            ...form.data,
            affected_barangays: values,
            affected_areas: values.join(', '),
            area_rows: values.map((barangay) => ({
                ...(existingRows.get(barangay) || emptyPayload(lguProfile, defaultIncidentDate).area_rows[0]),
                area: barangay,
                psgc_code: barangayOptionMap.get(barangay)?.psgc_code || barangayOptionMap.get(barangay)?.code || '',
                psa_2024: barangayOptionMap.get(barangay)?.psa_2024 || '',
            })),
        });
    };
    const addEvacuationCenter = () => {
        if (!affectedPopulationReady) {
            setValidationNotice('Complete the Status of Affected Population first before encoding displaced population. At least one affected family and one affected person must be encoded for every selected barangay before adding evacuation centers.');
            return;
        }

        if (evacuationIssues.length > 0) {
            setValidationNotice('Please correct the existing evacuation center row/s first before adding another one. Check Families/Persons CUM and NOW, then open “Encode Required Data” if the disaggregated totals need review.');
            return;
        }

        form.setData('evacuation_center_rows', [...evacuationCenterRows, emptyEvacuationCenterRow(selectedBarangays)]);
    };
    const updateEvacuationCenter = (index, key, value) => {
        form.setData('evacuation_center_rows', evacuationCenterRows.map((row, rowIndex) => {
            if (rowIndex !== index) return row;

            return typeof key === 'object' ? { ...row, ...key } : { ...row, [key]: value };
        }));

        if (key === 'persons_now'
            && String(value).trim() === '0'
            && Number(evacuationCenterRows[index]?.persons_cum || 0) > 0
            && hasDisaggregationCumCounts(evacuationCenterRows[index]?.disaggregation || emptyDisaggregation())) {
            setPendingZeroNowEcIndex(index);
        }

        setValidationNotice('');
    };
    const applyZeroNowDisaggregation = (index) => {
        form.setData('evacuation_center_rows', evacuationCenterRows.map((row, rowIndex) => {
            if (rowIndex !== index) return row;

            return {
                ...row,
                disaggregation: zeroNowForCumDisaggregation(row.disaggregation || emptyDisaggregation()),
                disaggregation_completed: true,
            };
        }));
        setPendingZeroNowEcIndex(null);
        setValidationNotice('');
    };
    const removeEvacuationCenter = (index) => {
        form.setData('evacuation_center_rows', evacuationCenterRows.filter((_, rowIndex) => rowIndex !== index));
        setValidationNotice('');
    };
    const removeOutsideEcRow = (rowIndex) => {
        updateAreaRow(rowIndex, {
            outside_ec_included: false,
            outside_ec_families_cum: '',
            outside_ec_families_now: '',
            outside_ec_persons_cum: '',
            outside_ec_persons_now: '',
        });
        setPendingDeleteOutsideEcIndex(null);
    };
    const removeDamagedHouseRow = (rowIndex) => {
        updateAreaRow(rowIndex, {
            damaged_houses_included: false,
            damaged_houses_totally: '',
            damaged_houses_partially: '',
            damaged_houses_estimated_cost: '',
        });
        setPendingDeleteDamagedHouseIndex(null);
    };
    const addAssistanceRow = (areaRow = {}) => {
        form.setData('assistance_rows', [...assistanceRows, emptyAssistanceRow(areaRow)]);
        setValidationNotice('');
    };
    const updateAssistanceRow = (index, key, value) => {
        form.setData('assistance_rows', assistanceRows.map((row, rowIndex) => (rowIndex === index ? { ...row, [key]: value } : row)));
        setValidationNotice('');
    };
    const removeAssistanceRow = (index) => {
        form.setData('assistance_rows', assistanceRows.filter((_, rowIndex) => rowIndex !== index));
        setPendingDeleteAssistanceIndex(null);
        setValidationNotice('');
    };
    const rowsFor = (key) => (Array.isArray(form.data[key]) ? form.data[key] : []);
    const addSupportingRow = (key, row) => {
        form.setData(key, [...rowsFor(key), row]);
        setValidationNotice('');
    };
    const updateSupportingRow = (key, index, field, value) => {
        form.setData(key, rowsFor(key).map((row, rowIndex) => {
            if (rowIndex !== index) return row;

            return typeof field === 'object' && field !== null
                ? { ...row, ...field }
                : { ...row, [field]: value };
        }));
        setValidationNotice('');
    };
    const removeSupportingRow = ({ key, index }) => {
        form.setData(key, rowsFor(key).filter((_, rowIndex) => rowIndex !== index));
        setPendingDeleteSupportingRow(null);
        setValidationNotice('');
    };
    const updateDisaggregation = (index, group, key, field, value) => {
        const numericValue = value === '' ? '' : Math.max(0, Number(value || 0));

        form.setData('evacuation_center_rows', evacuationCenterRows.map((row, rowIndex) => {
            if (rowIndex !== index) return row;

            return {
                ...row,
                disaggregation_completed: true,
                disaggregation: {
                    ...(row.disaggregation || emptyDisaggregation()),
                    [group]: {
                        ...((row.disaggregation || emptyDisaggregation())[group] || {}),
                        [key]: {
                            ...(((row.disaggregation || emptyDisaggregation())[group] || {})[key] || {}),
                            [field]: numericValue,
                        },
                    },
                },
            };
        }));
        setValidationNotice('');
    };
    const evacuationGrandTotals = evacuationCenterRows.reduce((totals, row) => ({
        families_cum: totals.families_cum + Number(row.families_cum || 0),
        families_now: totals.families_now + Number(row.families_now || 0),
        persons_cum: totals.persons_cum + Number(row.persons_cum || 0),
        persons_now: totals.persons_now + Number(row.persons_now || 0),
        classrooms_used: totals.classrooms_used + Number(row.classrooms_used || 0),
    }), {
        families_cum: 0,
        families_now: 0,
        persons_cum: 0,
        persons_now: 0,
        classrooms_used: 0,
    });
    const evacuationRowIssues = evacuationCenterRows
        .map((row, index) => {
            const requiredFields = [
                ['barangay_address', 'barangay address of EC'],
                ['evacuation_center', 'evacuation center name'],
                ['families_cum', 'families CUM'],
                ['families_now', 'families NOW'],
                ['persons_cum', 'persons CUM'],
                ['persons_now', 'persons NOW'],
                ['barangay_origin', 'barangay of origin of IDPs'],
                ['classrooms_used', 'number of classrooms used'],
            ];
            const missing = requiredFields.find(([key]) => String(row[key] ?? '').trim() === '');

            if (missing) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: Please encode ${missing[1]}.`,
                };
            }

            const cumPairIssue = familyPersonPairIssue(row.families_cum, row.persons_cum, `Evacuation Center ${index + 1} CUM`);
            if (cumPairIssue) {
                return {
                    index,
                    message: cumPairIssue,
                };
            }

            const nowPairIssue = familyPersonPairIssue(row.families_now, row.persons_now, `Evacuation Center ${index + 1} NOW`);
            if (nowPairIssue) {
                return {
                    index,
                    message: nowPairIssue,
                };
            }

            if (Number(row.families_now || 0) > Number(row.families_cum || 0) || Number(row.persons_now || 0) > Number(row.persons_cum || 0)) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: NOW counts cannot be greater than CUM counts.`,
                };
            }

            if (Number(row.families_cum || 0) > 0
                && Number(row.families_now || 0) === Number(row.families_cum || 0)
                && Number(row.persons_now || 0) < Number(row.persons_cum || 0)) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: Persons NOW cannot be incomplete while Families NOW is complete. Since Families NOW equals Families CUM (${formatNumber(row.families_cum)}), Persons NOW must also equal Persons CUM (${formatNumber(row.persons_cum)}), or correct Families NOW.`,
                };
            }

            const affectedPersonsForOrigin = affectedPersonsByBarangayCode.get(String(row.barangay_origin_code || row.barangay_origin || ''));
            const affectedFamiliesForOrigin = affectedFamiliesByBarangayCode.get(String(row.barangay_origin_code || row.barangay_origin || ''));
            const outsideForOrigin = outsideEcByOriginCode.get(String(row.barangay_origin_code || row.barangay_origin || '')) || { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };
            if (affectedPersonsForOrigin !== undefined && Number(row.persons_cum || 0) > affectedPersonsForOrigin) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: Persons CUM is greater than the affected persons encoded for ${row.barangay_origin}. Check the affected population row and correct either the EC Persons CUM or the affected persons count.`,
                };
            }

            if (affectedFamiliesForOrigin > 0 && affectedPersonsForOrigin > 0
                && outsideForOrigin.families_cum === affectedFamiliesForOrigin
                && outsideForOrigin.persons_cum === affectedPersonsForOrigin
                && (Number(row.families_cum || 0) > 0 || Number(row.persons_cum || 0) > 0)) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: ${row.barangay_origin} is already fully encoded in Outside EC CUM. Keep the count in either Inside EC or Outside EC, not both, so the same affected population is not counted twice.`,
                };
            }

            if (affectedFamiliesForOrigin > 0 && affectedPersonsForOrigin > 0
                && outsideForOrigin.families_now === affectedFamiliesForOrigin
                && outsideForOrigin.persons_now === affectedPersonsForOrigin
                && (Number(row.families_now || 0) > 0 || Number(row.persons_now || 0) > 0)) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: ${row.barangay_origin} is already fully encoded in Outside EC NOW. Keep the current displaced count in either Inside EC or Outside EC, not both.`,
                };
            }

            if (Number(row.persons_cum || 0) > totalAffectedPersons || Number(row.families_cum || 0) > totalAffectedFamilies) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: displaced CUM counts are greater than the total affected population. Please validate or correct the entry.`,
                };
            }

            if (!row.disaggregation_completed) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: Open “Encode Required Data” and review the age/sex and sectoral tables. Leave blanks for now if the report type allows it later; this section is currently flagged for your attention.`,
                };
            }

            const disaggregationIssues = disaggregationValidationIssues(row);
            if (disaggregationIssues.length > 0) {
                return {
                    index,
                    message: `Evacuation Center ${index + 1}: ${disaggregationIssues[0]} Click “Review Totals”, then encode CUM and NOW as pairs in the same age/sex or sectoral row until Age/Sex totals equal Persons CUM ${formatNumber(row.persons_cum)} and NOW ${formatNumber(row.persons_now)}.`,
                };
            }

            return null;
        })
        .filter(Boolean);
    const evacuationTotalIssues = [];

    if (evacuationCenterRows.length > 0 && (evacuationGrandTotals.families_cum > totalAffectedFamilies || evacuationGrandTotals.families_now > totalAffectedFamilies)) {
        evacuationTotalIssues.push({
            type: 'grand-total-families',
            message: `Inside EC grand total families (${formatNumber(Math.max(evacuationGrandTotals.families_cum, evacuationGrandTotals.families_now))}) cannot be greater than the total affected families (${formatNumber(totalAffectedFamilies)}). Review affected-population totals or correct the EC family counts.`,
        });
    }

    if (evacuationCenterRows.length > 0 && (evacuationGrandTotals.persons_cum > totalAffectedPersons || evacuationGrandTotals.persons_now > totalAffectedPersons)) {
        evacuationTotalIssues.push({
            type: 'grand-total-persons',
            message: `Inside EC grand total persons (${formatNumber(Math.max(evacuationGrandTotals.persons_cum, evacuationGrandTotals.persons_now))}) cannot be greater than the total affected persons (${formatNumber(totalAffectedPersons)}). Review affected-population totals or correct the EC persons counts.`,
        });
    }

    const evacuationConsistencyIssues = displacementConsistencyIssues.flatMap((issue) => {
        const areaKeys = new Set(areaLookupKeys(areaRows[issue.index] || {}));

        return evacuationCenterRows
            .map((row, index) => ({
                index,
                message: issue.message,
                matches: [String(row.barangay_origin_code || '').trim(), String(row.barangay_origin || '').trim()]
                    .some((key) => key && areaKeys.has(key)),
            }))
            .filter((item) => item.matches)
            .map(({ matches, ...item }) => item);
    });
    const evacuationIssues = [...evacuationRowIssues, ...evacuationTotalIssues, ...evacuationConsistencyIssues];
    const outsideEcRowIssues = areaRows
        .map((row, index) => {
            if (!row.outside_ec_included) {
                return null;
            }

            const fields = [
                ['outside_ec_families_cum', 'Families CUM'],
                ['outside_ec_families_now', 'Families NOW'],
                ['outside_ec_persons_cum', 'Persons CUM'],
                ['outside_ec_persons_now', 'Persons NOW'],
            ];
            const missing = fields.find(([key]) => String(row[key] ?? '').trim() === '');

            if (missing) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Please encode Outside EC ${missing[1]}. Enter 0 if there are no displaced families/persons outside evacuation centers.`,
                };
            }

            const cumPairIssue = familyPersonPairIssue(row.outside_ec_families_cum, row.outside_ec_persons_cum, `${row.area || `Barangay ${index + 1}`} Outside EC CUM`);
            if (cumPairIssue) {
                return { index, message: cumPairIssue };
            }

            const nowPairIssue = familyPersonPairIssue(row.outside_ec_families_now, row.outside_ec_persons_now, `${row.area || `Barangay ${index + 1}`} Outside EC NOW`);
            if (nowPairIssue) {
                return { index, message: nowPairIssue };
            }

            if (Number(row.outside_ec_families_now || 0) > Number(row.outside_ec_families_cum || 0)
                || Number(row.outside_ec_persons_now || 0) > Number(row.outside_ec_persons_cum || 0)) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Outside EC NOW counts cannot be greater than Outside EC CUM counts.`,
                };
            }

            const key = String(row.psgc_code || row.area || '');
            const inside = insideEcByOriginCode.get(key) || { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };
            const affectedFamilies = Number(row.affected_families || 0);
            const affectedPersons = Number(row.affected_persons || 0);
            const totalDisplacedFamiliesCum = inside.families_cum + Number(row.outside_ec_families_cum || 0);
            const totalDisplacedFamiliesNow = inside.families_now + Number(row.outside_ec_families_now || 0);
            const totalDisplacedPersonsCum = inside.persons_cum + Number(row.outside_ec_persons_cum || 0);
            const totalDisplacedPersonsNow = inside.persons_now + Number(row.outside_ec_persons_now || 0);

            if (totalDisplacedFamiliesCum > affectedFamilies || totalDisplacedFamiliesNow > affectedFamilies) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Inside EC + Outside EC displaced families cannot be greater than affected families (${formatNumber(affectedFamilies)}).`,
                };
            }

            if (totalDisplacedPersonsCum > affectedPersons || totalDisplacedPersonsNow > affectedPersons) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Inside EC + Outside EC displaced persons cannot be greater than affected persons (${formatNumber(affectedPersons)}).`,
                };
            }

            if (affectedFamilies > 0 && affectedPersons > 0
                && inside.families_cum === affectedFamilies
                && inside.persons_cum === affectedPersons
                && (Number(row.outside_ec_families_cum || 0) > 0 || Number(row.outside_ec_persons_cum || 0) > 0)) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: This barangay is already fully encoded in Inside EC CUM. Step 1: confirm whether the displaced population is inside an EC or outside EC. Step 2: remove the Outside EC row or set Outside EC CUM values to 0 to avoid double-counting.`,
                };
            }

            if (affectedFamilies > 0 && affectedPersons > 0
                && inside.families_now === affectedFamilies
                && inside.persons_now === affectedPersons
                && (Number(row.outside_ec_families_now || 0) > 0 || Number(row.outside_ec_persons_now || 0) > 0)) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: This barangay is already fully encoded in Inside EC NOW. Step 1: confirm where the current displaced population is staying. Step 2: remove the Outside EC row or set Outside EC NOW values to 0 before submitting.`,
                };
            }

            return null;
        })
        .filter(Boolean);
    const affectedPopulationReady = selectedBarangays.length > 0
        && areaRows.length > 0
        && areaRows.every((row) => Number(row.affected_families || 0) > 0 && Number(row.affected_persons || 0) > 0)
        && populationIssues.length === 0;
    const canAddEvacuationCenter = affectedPopulationReady && evacuationIssues.length === 0;
    const insideSummaryForAreaRow = (row) => insideEcByOriginCode.get(String(row.psgc_code || '').trim())
        || insideEcByOriginCode.get(String(row.area || '').trim())
        || { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };
    const displacedPopulationRows = areaRows.map((row, areaIndex) => {
        const inside = insideSummaryForAreaRow(row);
        const outside = includeOutsideEcInComputation && row.outside_ec_included
            ? {
                families_cum: Number(row.outside_ec_families_cum || 0),
                families_now: Number(row.outside_ec_families_now || 0),
                persons_cum: Number(row.outside_ec_persons_cum || 0),
                persons_now: Number(row.outside_ec_persons_now || 0),
            }
            : { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };

        return {
            barangay: row.area || '-',
            families_cum: inside.families_cum + outside.families_cum,
            families_now: inside.families_now + outside.families_now,
            persons_cum: inside.persons_cum + outside.persons_cum,
            persons_now: inside.persons_now + outside.persons_now,
            validation_issue: displacementConsistencyIssues.find((issue) => issue.index === areaIndex)?.message || '',
        };
    }).filter((row) => Number(row.families_cum || 0) > 0 || Number(row.persons_cum || 0) > 0);
    const nonIdpRows = areaRows.map((row, areaIndex) => {
        const inside = insideSummaryForAreaRow(row);
        const outside = includeOutsideEcInComputation && row.outside_ec_included
            ? {
                families_cum: Number(row.outside_ec_families_cum || 0),
                families_now: Number(row.outside_ec_families_now || 0),
                persons_cum: Number(row.outside_ec_persons_cum || 0),
                persons_now: Number(row.outside_ec_persons_now || 0),
            }
            : { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };
        const displaced = {
            families_cum: inside.families_cum + outside.families_cum,
            families_now: inside.families_now + outside.families_now,
            persons_cum: inside.persons_cum + outside.persons_cum,
            persons_now: inside.persons_now + outside.persons_now,
        };

        return {
            barangay: row.area || '-',
            families_cum: Math.max(0, Number(row.affected_families || 0) - displaced.families_cum),
            families_now: Math.max(0, Number(row.affected_families || 0) - displaced.families_now),
            persons_cum: Math.max(0, Number(row.affected_persons || 0) - displaced.persons_cum),
            persons_now: Math.max(0, Number(row.affected_persons || 0) - displaced.persons_now),
            validation_issue: displacementConsistencyIssues.find((issue) => issue.index === areaIndex)?.message || '',
        };
    }).filter((row) => Number(row.families_cum || 0) > 0 || Number(row.persons_cum || 0) > 0);
    const damagedHouseIssues = areaRows
        .map((row, index) => {
            if (!row.damaged_houses_included) return null;

            const totallyBlank = String(row.damaged_houses_totally ?? '').trim() === '';
            const partiallyBlank = String(row.damaged_houses_partially ?? '').trim() === '';

            if (totallyBlank || partiallyBlank) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Encode both Totally Damaged and Partially Damaged houses. Enter 0 if none.`,
                };
            }

            const totalDamaged = Number(row.damaged_houses_totally || 0) + Number(row.damaged_houses_partially || 0);
            const affectedFamilies = Number(row.affected_families || 0);
            const estimatedCostBlank = String(row.damaged_houses_estimated_cost ?? '').trim() === '';
            const estimatedCost = Number(row.damaged_houses_estimated_cost || 0);

            if (estimatedCost < 0) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Estimated cost of damage cannot be negative.`,
                };
            }

            if (totalDamaged === 0 && estimatedCost > 0) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Estimated cost of damage was encoded but total damaged houses is 0. Step 1: encode Totally or Partially Damaged houses if there is damage. Step 2: otherwise clear or set the estimated cost to 0.`,
                };
            }

            if (totalDamaged > 0 && (estimatedCostBlank || estimatedCost <= 0)) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Total damaged houses is ${formatNumber(totalDamaged)}, so estimated cost of damage is required. Enter the best available LGU estimate.`,
                };
            }

            if (totalDamaged > affectedFamilies) {
                return {
                    index,
                    message: `${row.area || `Barangay ${index + 1}`}: Total damaged houses (${formatNumber(totalDamaged)}) cannot be greater than affected families (${formatNumber(affectedFamilies)}). Check the affected families and correct the totally/partially damaged houses entries.`,
                };
            }

            return null;
        })
        .filter(Boolean);
    const affectedAreaByKey = new Map();
    areaRows.forEach((row) => {
        areaLookupKeys(row).forEach((key) => affectedAreaByKey.set(key, row));
    });
    const assistanceIssues = assistanceRows
        .map((row, index) => {
            const rowHasAnyValue = ['barangay', 'barangay_code', 'source', 'source_details', 'quantity', 'unit', 'item_type', 'particular', 'cost_per_unit', 'families_served']
                .some((field) => String(row[field] ?? '').trim() !== '');

            if (!rowHasAnyValue) {
                return {
                    index,
                    message: `Assistance Row ${index + 1}: Complete the row or remove it if no assistance item will be encoded.`,
                };
            }

            const barangayKey = String(row.barangay_code || row.barangay || '').trim();
            const affectedArea = affectedAreaByKey.get(barangayKey);

            if (!String(row.barangay || '').trim()) {
                return {
                    index,
                    message: `Assistance Row ${index + 1}: Select the affected barangay where this assistance was provided.`,
                };
            }

            if (!affectedArea) {
                return {
                    index,
                    message: `Assistance Row ${index + 1}: ${row.barangay || 'Selected barangay'} is not in the affected barangay list. Add it to Status of Affected Population first or remove this assistance row.`,
                };
            }

            const requiredFields = [
                ['source', 'Source'],
                ['quantity', 'Quantity'],
                ['unit', 'Unit of measurement'],
                ['item_type', 'Type of item'],
                ['particular', 'Particular'],
                ['cost_per_unit', 'Cost per unit'],
                ['families_served', 'No. of families served'],
            ];
            const missing = requiredFields.find(([field]) => String(row[field] ?? '').trim() === '');

            if (missing) {
                return {
                    index,
                    message: `Assistance Row ${index + 1}: ${missing[1]} is required. Enter the assistance details as reflected in the distribution record.`,
                };
            }

            if (Number(row.quantity || 0) <= 0) {
                return {
                    index,
                    message: `Assistance Row ${index + 1}: Quantity must be greater than 0.`,
                };
            }

            if (Number(row.cost_per_unit || 0) < 0) {
                return {
                    index,
                    message: `Assistance Row ${index + 1}: Cost per unit cannot be negative.`,
                };
            }

            const barangayAffectedFamilies = Number(affectedArea?.affected_families || 0);
            if (Number(row.families_served || 0) > barangayAffectedFamilies) {
                return {
                    index,
                    message: `Assistance Row ${index + 1}: Families served (${formatNumber(row.families_served)}) cannot be greater than affected families for ${affectedArea.area || row.barangay} (${formatNumber(barangayAffectedFamilies)}).`,
                };
            }

            return null;
        })
        .filter(Boolean);
    const rowHasAnyValue = (row = {}, fields = []) => fields.some((field) => String(row[field] ?? '').trim() !== '');
    const missingFieldIssue = (row, fields = [], prefix) => {
        const missing = fields.find(([field]) => String(row[field] ?? '').trim() === '');

        return missing ? `${prefix}: ${missing[1]} is required once this row is started. If the exact detail is not yet known, encode the best available estimate. Use N/A for text fields that are not applicable.` : '';
    };
    const validateOptionalRows = (rows, fields, requiredFields, label, extraValidator = null) => rows
        .map((row, index) => {
            if (!rowHasAnyValue(row, fields)) return null;

            const prefix = `${label} Row ${index + 1}`;
            const missingIssue = missingFieldIssue(row, requiredFields, prefix);
            if (missingIssue) return { index, message: missingIssue };

            const extraIssue = extraValidator ? extraValidator(row, index, prefix) : '';
            return extraIssue ? { index, message: extraIssue } : null;
        })
        .filter(Boolean);
    const requireOtherValue = (row, prefix, pairs = []) => {
        const missing = pairs.find(([field, otherField, label]) => String(row[field] || '').trim() === 'Others' && !String(row[otherField] || '').trim());

        return missing ? `${prefix}: Please specify the other ${missing[2]}.` : '';
    };
    const relatedIncidentIssues = validateOptionalRows(
        relatedIncidentRows,
        ['barangay', 'incident_type', 'incident_type_other', 'occurrence_at', 'description', 'actions_taken', 'status'],
        [['barangay', 'Barangay'], ['incident_type', 'Type of incident'], ['occurrence_at', 'Date and time of occurrence'], ['description', 'Description'], ['actions_taken', 'Actions taken'], ['status', 'Status']],
        'Related Incident',
        (row, index, prefix) => {
            if (String(row.incident_type || '').trim() === 'Others' && !String(row.incident_type_other || '').trim()) {
                return `${prefix}: Please specify the other type of incident.`;
            }

            return '';
        },
    );
    const casualtyIssues = validateOptionalRows(
        casualtyRows,
        ['casualty_status', 'last_name', 'first_name', 'middle_name', 'age', 'sex', 'address', 'cause', 'remarks', 'source_of_data'],
        [['casualty_status', 'Casualty status'], ['last_name', 'Last name'], ['first_name', 'First name'], ['middle_name', 'Middle name'], ['age', 'Age'], ['sex', 'Sex'], ['address', 'Address barangay'], ['cause', 'Cause'], ['remarks', 'Remarks'], ['source_of_data', 'Source of data']],
        'Casualty',
        (row, index, prefix) => {
            if (Number(row.age || 0) < 0) return `${prefix}: Age cannot be negative.`;
            return '';
        },
    );
    const infrastructureDamageIssues = validateOptionalRows(
        infrastructureDamageRows,
        ['barangay', 'structure_type', 'structure_type_other', 'damage_description', 'length_meters', 'estimated_cost', 'remarks'],
        [['barangay', 'Barangay'], ['structure_type', 'Type of structure'], ['damage_description', 'Damage description'], ['length_meters', 'Length'], ['estimated_cost', 'Estimated cost of damage'], ['remarks', 'Remarks']],
        'Infrastructure Damage',
        (row, index, prefix) => {
            const otherIssue = requireOtherValue(row, prefix, [['structure_type', 'structure_type_other', 'type of structure']]);
            if (otherIssue) return otherIssue;
            if (Number(row.length_meters || 0) < 0) return `${prefix}: Length cannot be negative.`;
            if (Number(row.estimated_cost || 0) < 0) return `${prefix}: Estimated cost of damage cannot be negative.`;
            return '';
        },
    );
    const agricultureDamageIssues = validateOptionalRows(
        agricultureDamageRows,
        ['barangay', 'classification', 'classification_other', 'type', 'type_other', 'affected_farmers_fisherfolks', 'area_no_chance_recovery', 'area_with_chance_recovery', 'infrastructure_totally_damaged', 'infrastructure_partially_damaged', 'production_loss_heads', 'production_loss_cost_per_head', 'production_loss_volume_mt', 'production_loss_value'],
        [['barangay', 'Barangay'], ['classification', 'Classification'], ['type', 'Type'], ['affected_farmers_fisherfolks', 'No. of farmers / fisherfolks affected'], ['area_no_chance_recovery', 'Area affected with no chance of recovery'], ['area_with_chance_recovery', 'Area affected with chance of recovery'], ['infrastructure_totally_damaged', 'Totally damaged infrastructure/machineries/equipment'], ['infrastructure_partially_damaged', 'Partially damaged infrastructure/machineries/equipment'], ['production_loss_heads', 'Production loss no. of heads'], ['production_loss_cost_per_head', 'Production loss cost per head'], ['production_loss_volume_mt', 'Production loss in volume'], ['production_loss_value', 'Production loss / cost of damage in value']],
        'Agriculture Damage/Loss',
        (row, index, prefix) => {
            const otherIssue = requireOtherValue(row, prefix, [
                ['classification', 'classification_other', 'classification'],
                ['type', 'type_other', 'type'],
            ]);
            if (otherIssue) return otherIssue;
            const numericFields = ['affected_farmers_fisherfolks', 'area_no_chance_recovery', 'area_with_chance_recovery', 'infrastructure_totally_damaged', 'infrastructure_partially_damaged', 'production_loss_heads', 'production_loss_cost_per_head', 'production_loss_volume_mt', 'production_loss_value'];
            if (numericFields.some((field) => Number(row[field] || 0) < 0)) return `${prefix}: Numeric values cannot be negative.`;
            return '';
        },
    );
    const classSuspensionIssues = validateOptionalRows(
        classSuspensionRows,
        ['coverage', 'barangay', 'level_from', 'level_from_other', 'level_to', 'level_to_other', 'type', 'type_other', 'suspension_at', 'resumed_at', 'remarks'],
        [['coverage', 'Coverage'], ['level_from', 'Level from'], ['level_to', 'Level to'], ['type', 'Type'], ['suspension_at', 'Date and time of suspension'], ['remarks', 'Remarks']],
        'Class Suspension',
        (row, index, prefix) => {
            if (row.coverage === 'Selected barangay' && !String(row.barangay || '').trim()) {
                return `${prefix}: Barangay is required when coverage is selected barangay.`;
            }

            return requireOtherValue(row, prefix, [
                ['level_from', 'level_from_other', 'level from'],
                ['level_to', 'level_to_other', 'level to'],
                ['type', 'type_other', 'type'],
            ]);
        },
    );
    const workSuspensionIssues = validateOptionalRows(
        workSuspensionRows,
        ['coverage', 'barangay', 'type', 'type_other', 'suspension_at', 'resumed_at', 'remarks'],
        [['coverage', 'Coverage'], ['type', 'Type'], ['suspension_at', 'Date and time of suspension'], ['remarks', 'Remarks']],
        'Work Suspension',
        (row, index, prefix) => {
            if (row.coverage === 'Selected barangay' && !String(row.barangay || '').trim()) {
                return `${prefix}: Barangay is required when coverage is selected barangay.`;
            }

            return requireOtherValue(row, prefix, [['type', 'type_other', 'type']]);
        },
    );
    const roadBridgeIssues = validateOptionalRows(
        roadBridgeRows,
        ['barangay', 'type', 'type_other', 'classification', 'classification_other', 'road_section', 'status', 'reported_not_passable_at', 'reported_passable_at', 'remarks'],
        [['barangay', 'Barangay'], ['type', 'Type'], ['classification', 'Classification'], ['road_section', 'Road section'], ['status', 'Status'], ['reported_not_passable_at', 'Date and time reported not passable'], ['remarks', 'Remarks']],
        'Road/Bridge Lifeline',
        (row, index, prefix) => requireOtherValue(row, prefix, [
            ['type', 'type_other', 'type'],
            ['classification', 'classification_other', 'classification'],
        ]),
    );
    const utilityLifelineFields = ['coverage', 'barangay', 'type', 'type_other', 'service_provider', 'interrupted_at', 'restored_at', 'remarks_status'];
    const utilityLifelineRequired = [['coverage', 'Coverage'], ['type', 'Type'], ['service_provider', 'Service provider'], ['interrupted_at', 'Date and time of interruption'], ['remarks_status', 'Remarks / status']];
    const utilityCoverageValidator = (row, index, prefix) => {
        if (row.coverage === 'Selected barangay' && !String(row.barangay || '').trim()) {
            return `${prefix}: Barangay is required when coverage is selected barangay.`;
        }

        return requireOtherValue(row, prefix, [['type', 'type_other', 'type']]);
    };
    const powerLifelineIssues = validateOptionalRows(
        powerLifelineRows,
        utilityLifelineFields,
        utilityLifelineRequired,
        'Power Lifeline',
        utilityCoverageValidator,
    );
    const waterLifelineIssues = validateOptionalRows(
        waterLifelineRows,
        utilityLifelineFields,
        utilityLifelineRequired,
        'Water Lifeline',
        utilityCoverageValidator,
    );
    const communicationLifelineIssues = validateOptionalRows(
        communicationLifelineRows,
        ['coverage', 'barangay', 'communication_status', 'communication_status_other', 'service_provider', 'interrupted_at', 'restored_at', 'remarks'],
        [['coverage', 'Coverage'], ['communication_status', 'Status of communication'], ['service_provider', 'Service provider'], ['interrupted_at', 'Date and time of interruption'], ['remarks', 'Remarks']],
        'Communication Lifeline',
        (row, index, prefix) => {
            if (row.coverage === 'Selected barangay' && !String(row.barangay || '').trim()) {
                return `${prefix}: Barangay is required when coverage is selected barangay.`;
            }

            return requireOtherValue(row, prefix, [['communication_status', 'communication_status_other', 'status of communication']]);
        },
    );
    const portStatusValidator = (row, index, prefix) => {
        const otherIssue = requireOtherValue(row, prefix, [['status', 'status_other', 'status']]);
        if (otherIssue) return otherIssue;
        if (Number(row.stranded_passengers || 0) < 0) return `${prefix}: No. of stranded passengers cannot be negative.`;
        return '';
    };
    const seaportIssues = validateOptionalRows(
        seaportRows,
        ['name', 'status', 'status_other', 'stranded_passengers', 'reported_non_operational_at', 'reported_operational_at', 'remarks'],
        [['name', 'Name of port'], ['status', 'Status'], ['stranded_passengers', 'No. of stranded passengers'], ['reported_non_operational_at', 'Date and time reported non-operational / cancelled trips'], ['remarks', 'Remarks']],
        'Seaport Status',
        portStatusValidator,
    );
    const airportIssues = validateOptionalRows(
        airportRows,
        ['name', 'status', 'status_other', 'stranded_passengers', 'reported_non_operational_at', 'reported_operational_at', 'remarks'],
        [['name', 'Name of airport'], ['status', 'Status'], ['stranded_passengers', 'No. of stranded passengers'], ['reported_non_operational_at', 'Date and time reported non-operational / cancelled trips'], ['remarks', 'Remarks']],
        'Airport Status',
        portStatusValidator,
    );
    const landTransportTerminalIssues = validateOptionalRows(
        landTransportTerminalRows,
        ['name', 'status', 'status_other', 'stranded_passengers', 'reported_non_operational_at', 'reported_operational_at', 'remarks'],
        [['name', 'Name of land transport terminal'], ['status', 'Status'], ['stranded_passengers', 'No. of stranded passengers'], ['reported_non_operational_at', 'Date and time reported non-operational / cancelled trips'], ['remarks', 'Remarks']],
        'Land Transport Terminal Status',
        portStatusValidator,
    );
    const strandedTransportIssues = validateOptionalRows(
        strandedTransportRows,
        ['barangay', 'station', 'port_terminal', 'passengers', 'rolling_cargoes', 'vessel_bus_liner', 'mbca', 'remarks'],
        [['barangay', 'Barangay'], ['station', 'Station'], ['port_terminal', 'Port / terminal'], ['passengers', 'Passenger count'], ['rolling_cargoes', 'Rolling cargoes'], ['vessel_bus_liner', 'Vessel / bus liner'], ['mbca', 'MBCA'], ['remarks', 'Remarks']],
        'Stranded Passenger / Cargo',
        (row, index, prefix) => ['passengers', 'rolling_cargoes', 'mbca'].some((field) => Number(row[field] || 0) < 0) ? `${prefix}: Numeric values cannot be negative.` : '',
    );
    const calamityDeclarationIssues = validateOptionalRows(
        calamityDeclarationRows,
        ['location', 'type', 'type_other', 'resolution_number', 'resolution_date', 'remarks'],
        [['location', 'City / municipality / barangay'], ['type', 'Type'], ['resolution_number', 'Resolution number'], ['resolution_date', 'Resolution date'], ['remarks', 'Remarks']],
        'Declaration of State of Calamity',
        (row, index, prefix) => requireOtherValue(row, prefix, [['type', 'type_other', 'type']]),
    );
    const preemptiveEvacuationIssues = validateOptionalRows(
        preemptiveEvacuationRows,
        ['barangay', 'families', 'male', 'female', 'remarks'],
        [['barangay', 'Barangay'], ['families', 'Families'], ['male', 'Male'], ['female', 'Female'], ['remarks', 'Remarks']],
        'Pre-Emptive Evacuation',
        (row, index, prefix) => ['families', 'male', 'female'].some((field) => Number(row[field] || 0) < 0) ? `${prefix}: Numeric values cannot be negative.` : '',
    );
    const clusterGapIssues = validateOptionalRows(
        clusterGapRows,
        ['cluster', 'cluster_other', 'areas_of_concern', 'actions_undertaken', 'status_remarks'],
        [['cluster', 'Type / cluster'], ['areas_of_concern', 'Areas of concern'], ['actions_undertaken', 'Actions undertaken'], ['status_remarks', 'Status / remarks']],
        'Gap / Challenge',
        (row, index, prefix) => requireOtherValue(row, prefix, [['cluster', 'cluster_other', 'cluster']]),
    );
    const populatedResponseActionRows = responseActionRows.filter(
        (row) => ['acted_by_office', 'acted_by_office_other', 'action_intervention'].some((field) => String(row?.[field] || '').trim() !== ''),
    );
    const responseActionIssues = populatedResponseActionRows.length
        ? validateOptionalRows(
            populatedResponseActionRows,
            ['acted_by_office', 'acted_by_office_other', 'action_intervention'],
            [['acted_by_office', 'Acted by'], ['action_intervention', 'Response action / intervention']],
            'Response Action / Intervention',
            (row, index, prefix) => requireOtherValue(row, prefix, [['acted_by_office', 'acted_by_office_other', 'office / unit']]),
        )
        : [{ message: 'Response Actions and Interventions is required. Add at least one recent LGU action or intervention.' }];
    const officialAdvisoryIssues = officialAdvisoryRows
        .map((row, index) => {
            const missing = [
                ['agency', 'agency'],
                ['advisory_title', 'advisory title'],
            ].find(([field]) => !String(row?.[field] || '').trim());

            if (missing) {
                return { index, message: `Official advisory ${index + 1}: Complete the ${missing[1]} or remove this source.` };
            }
            if (!String(row?.source_url || '').trim() && !String(row?.screenshot_data_url || '').trim() && !String(row?.pasted_text || '').trim()) {
                return { index, message: `Official advisory ${index + 1}: Paste an advisory screenshot or copied post text.` };
            }
            if (String(row?.screenshot_data_url || '').trim() && (
                row.content_status !== 'extracted_for_review'
                || !String(row?.summary || '').trim()
            )) {
                return {
                    index,
                    message: row.content_status === 'processing'
                        ? `Advisory screenshot ${index + 1}: Reading is still in progress.`
                        : `Advisory screenshot ${index + 1}: Remove it or paste a clearer copy before using the Situation Overview AI.`,
                };
            }

            return null;
        })
        .filter(Boolean);
    const outsideEcIssues = [
        ...outsideEcRowIssues,
        ...displacementConsistencyIssues.filter((issue) => areaRows[issue.index]?.outside_ec_included),
    ];
    const photoDocumentationIssues = [
        ...validateOptionalRows(
            photoDocumentationRows,
            ['data_url'],
            [['data_url', 'Photo']],
            'Photo Documentation',
        ),
        ...(photoDocumentationRows.length > 10 ? [{ message: 'Photo Documentation: Upload up to 10 photos only.' }] : []),
        ...(photoDocumentationRows.length > 0 && photoCollageRows.length === 0 ? [{ message: 'Photo Documentation: Generate at least one collage before saving as final, or mark this section N/A if no photos are available.' }] : []),
        ...(photoCollageRows.length > 2 ? [{ message: 'Photo Documentation: Generate at most 2 collages only.' }] : []),
        ...photoCollageRows.flatMap((collage, index) => (
            String(collage?.heading || '').trim()
                ? []
                : [{ collageIndex: index, message: `Photo Documentation Collage ${index + 1}: Caption is required.` }]
        )),
    ];
    const unresolvedSectionIssues = [
        [isSectionNotApplicable('inside_ec'), evacuationCenterRows.length > 0, 'inside_ec', 'Inside Evacuation Centers'],
        [isSectionNotApplicable('outside_ec'), areaRows.some((row) => row.outside_ec_included), 'outside_ec', 'Outside Evacuation Centers'],
        [isSectionNotApplicable('damaged_houses'), areaRows.some((row) => row.damaged_houses_included), 'damaged_houses', 'Damaged Houses'],
        [isSectionNotApplicable('assistance'), assistanceRows.length > 0, 'assistance', 'Status of Assistance Provided'],
        [isSectionNotApplicable('related_incidents'), relatedIncidentRows.length > 0, 'related_incidents', 'Related Incidents'],
        [isSectionNotApplicable('casualties'), casualtyRows.length > 0, 'casualties', 'Casualties'],
        [isSectionNotApplicable('infrastructure_damage'), infrastructureDamageRows.length > 0, 'infrastructure_damage', 'Damage to Infrastructure'],
        [isSectionNotApplicable('agriculture_damage'), agricultureDamageRows.length > 0, 'agriculture_damage', 'Damage and Losses to Agriculture'],
        [isSectionNotApplicable('class_suspension'), classSuspensionRows.length > 0, 'class_suspension', 'Class Suspension'],
        [isSectionNotApplicable('work_suspension'), workSuspensionRows.length > 0, 'work_suspension', 'Work Suspension'],
        [isSectionNotApplicable('roads_bridges'), roadBridgeRows.length > 0, 'roads_bridges', 'Status of Roads and Bridges'],
        [isSectionNotApplicable('power_lifelines'), powerLifelineRows.length > 0, 'power_lifelines', 'Status of Power Supply'],
        [isSectionNotApplicable('water_lifelines'), waterLifelineRows.length > 0, 'water_lifelines', 'Status of Water Supply'],
        [isSectionNotApplicable('communication_lifelines'), communicationLifelineRows.length > 0, 'communication_lifelines', 'Status of Communication Lines'],
        [isSectionNotApplicable('seaports'), seaportRows.length > 0, 'seaports', 'Status of Seaports'],
        [isSectionNotApplicable('airports'), airportRows.length > 0, 'airports', 'Status of Airports'],
        [isSectionNotApplicable('land_transport_terminals'), landTransportTerminalRows.length > 0, 'land_transport_terminals', 'Status of Land Transportation Terminals'],
        [isSectionNotApplicable('stranded_transport'), strandedTransportRows.length > 0, 'stranded_transport', 'Stranded Passengers and Transport'],
        [isSectionNotApplicable('calamity_declaration'), calamityDeclarationRows.length > 0, 'calamity_declaration', 'Declaration of State of Calamity'],
        [isSectionNotApplicable('preemptive_evacuation'), preemptiveEvacuationRows.length > 0, 'preemptive_evacuation', 'Pre-emptive Evacuation'],
        [isSectionNotApplicable('cluster_gaps'), clusterGapRows.length > 0, 'cluster_gaps', 'Gaps / Challenges'],
        [isSectionNotApplicable('photo_documentation'), photoDocumentationRows.length > 0 || photoCollageRows.length > 0, 'photo_documentation', 'Photo Documentation'],
    ]
        .filter(([isNa, hasContent]) => !isNa && !hasContent)
        .map(([, , key, label]) => ({
            key,
            message: `${label}: Add at least one entry or mark this section N/A before saving as final.`,
        }));
    const supportingSectionIssues = [
        ...unresolvedSectionIssues,
        ...(isSectionNotApplicable('related_incidents') ? [] : relatedIncidentIssues),
        ...(isSectionNotApplicable('casualties') ? [] : casualtyIssues),
        ...(isSectionNotApplicable('infrastructure_damage') ? [] : infrastructureDamageIssues),
        ...(isSectionNotApplicable('agriculture_damage') ? [] : agricultureDamageIssues),
        ...(isSectionNotApplicable('class_suspension') ? [] : classSuspensionIssues),
        ...(isSectionNotApplicable('work_suspension') ? [] : workSuspensionIssues),
        ...(isSectionNotApplicable('roads_bridges') ? [] : roadBridgeIssues),
        ...(isSectionNotApplicable('power_lifelines') ? [] : powerLifelineIssues),
        ...(isSectionNotApplicable('water_lifelines') ? [] : waterLifelineIssues),
        ...(isSectionNotApplicable('communication_lifelines') ? [] : communicationLifelineIssues),
        ...(isSectionNotApplicable('seaports') ? [] : seaportIssues),
        ...(isSectionNotApplicable('airports') ? [] : airportIssues),
        ...(isSectionNotApplicable('land_transport_terminals') ? [] : landTransportTerminalIssues),
        ...(isSectionNotApplicable('stranded_transport') ? [] : strandedTransportIssues),
        ...(isSectionNotApplicable('calamity_declaration') ? [] : calamityDeclarationIssues),
        ...(isSectionNotApplicable('preemptive_evacuation') ? [] : preemptiveEvacuationIssues),
        ...(isSectionNotApplicable('cluster_gaps') ? [] : clusterGapIssues),
        ...responseActionIssues,
        ...officialAdvisoryIssues,
        ...(isSectionNotApplicable('photo_documentation') ? [] : photoDocumentationIssues),
    ];
    const incidentInfoReady = Boolean(
        form.data.incident_type
        && selectedBarangays.length > 0
        && form.data.occurrence_started_at
        && form.data.incident_status
        && (form.data.incident_status !== 'Ended' || form.data.incident_ended_at)
        && form.data.information_received_at
        && form.data.dromic_reporter,
    );
    const progressStatus = (key, status) => isSectionNotApplicable(key) ? 'na' : status;
    const progressIgnoredFields = ['province_city_municipality', 'city_municipality', 'casualty_status'];
    const progressRowHasUserValue = (row = {}) => Object.entries(row)
        .some(([field, value]) => !progressIgnoredFields.includes(field) && String(value ?? '').trim() !== '');
    const optionalStatus = (key, rows, issuesList = []) => {
        if (isSectionNotApplicable(key)) return 'na';
        if (!affectedPopulationReady) return 'locked';
        if (issuesList.length) return 'needs';
        if (!rows.length) return 'needs';
        return rows.every(progressRowHasUserValue) ? 'complete' : 'needs';
    };
    const optionalSummary = (key, count, populatedSummary, emptySummary) => (
        isSectionNotApplicable(key) ? 'Marked not applicable by the encoder.' : (count ? populatedSummary : emptySummary)
    );
    const insideEcNa = isSectionNotApplicable('inside_ec');
    const outsideEcNa = isSectionNotApplicable('outside_ec');
    const displacedNa = insideEcNa && outsideEcNa;
    const suspensionNa = isSectionNotApplicable('class_suspension') && isSectionNotApplicable('work_suspension');
    const coreLifelineGroups = [
        ['roads_bridges', roadBridgeRows, roadBridgeIssues, 'ROADS AND BRIDGES', 'road/bridge'],
        ['power_lifelines', powerLifelineRows, powerLifelineIssues, 'POWER', 'power'],
        ['water_lifelines', waterLifelineRows, waterLifelineIssues, 'WATER', 'water'],
        ['communication_lifelines', communicationLifelineRows, communicationLifelineIssues, 'COMMUNICATION LINES', 'communication'],
    ];
    const portsTerminalGroups = [
        ['seaports', seaportRows, seaportIssues, 'STATUS OF SEAPORTS', 'seaport'],
        ['airports', airportRows, airportIssues, 'STATUS OF AIRPORTS', 'airport'],
        ['land_transport_terminals', landTransportTerminalRows, landTransportTerminalIssues, 'STATUS OF LAND TRANSPORT TERMINALS', 'land transport terminal'],
        ['stranded_transport', strandedTransportRows, strandedTransportIssues, 'STRANDED PASSENGERS, ROLLING CARGOES, VESSELS, MBCAS', 'stranded transport'],
    ];
    const postLifelineGroups = [
        ['calamity_declaration', calamityDeclarationRows, calamityDeclarationIssues, 'DECLARATION OF STATE OF CALAMITY', 'state of calamity'],
        ['preemptive_evacuation', preemptiveEvacuationRows, preemptiveEvacuationIssues, 'PRE-EMPTIVE EVACUATION', 'pre-emptive evacuation'],
        ['cluster_gaps', clusterGapRows, clusterGapIssues, 'GAPS / CHALLENGES AND STATUS / ACTIONS UNDERTAKEN', 'cluster action'],
        ['response_actions', responseActionRows, responseActionIssues, 'RESPONSE ACTIONS AND INTERVENTIONS', 'response action'],
        ['photo_documentation', photoDocumentationRows, photoDocumentationIssues, 'PHOTO DOCUMENTATION', 'photo documentation'],
    ];
    const lifelineGroups = coreLifelineGroups;
    const lifelinesNa = lifelineGroups.every(([key]) => isSectionNotApplicable(key));
    const lifelineRowsStarted = lifelineGroups.reduce((total, [, rows]) => total + rows.length, 0);
    const lifelineHasIssues = lifelineGroups.some(([key, , itemIssues]) => !isSectionNotApplicable(key) && itemIssues.length > 0);
    const progressAnchorFor = (key) => ({
        incident_information: 'dromic-section-incident-information',
        affected_population: 'dromic-section-affected-population',
        displaced_population: 'dromic-section-displaced-population',
        inside_ec: 'dromic-section-inside-ec',
        outside_ec: 'dromic-section-outside-ec',
        damaged_houses: 'dromic-section-damaged-houses',
        assistance: 'dromic-section-assistance',
        related_incidents: 'dromic-section-related-incidents',
        casualties: 'dromic-section-casualties',
        infrastructure_damage: 'dromic-section-infrastructure-damage',
        agriculture_damage: 'dromic-section-agriculture-damage',
        suspension: 'dromic-section-suspension',
        class_suspension: 'dromic-section-class-suspension',
        work_suspension: 'dromic-section-work-suspension',
        lifelines: 'dromic-section-lifelines',
        roads_bridges: 'dromic-section-roads-bridges',
        power_lifelines: 'dromic-section-power',
        water_lifelines: 'dromic-section-water',
        communication_lifelines: 'dromic-section-communication-lines',
        ports_terminals: 'dromic-section-ports-terminals',
        seaports: 'dromic-section-seaports',
        airports: 'dromic-section-airports',
        land_transport_terminals: 'dromic-section-land-transport-terminals',
        stranded_transport: 'dromic-section-stranded-transport',
        calamity_declaration: 'dromic-section-calamity-declaration',
        preemptive_evacuation: 'dromic-section-preemptive-evacuation',
        cluster_gaps: 'dromic-section-cluster-gaps',
        response_actions: 'dromic-section-response-actions',
        photo_documentation: 'dromic-section-photo-documentation',
        relief_augmentation: 'dromic-section-relief-request',
        advisory_screenshots: 'dromic-section-official-advisories',
        situation_overview: 'dromic-section-situation-overview',
    }[key] || '');
    const progressGroupItem = ([key, rows, itemIssues, label, rowLabel]) => ({
        label,
        anchor: progressAnchorFor(key),
        status: optionalStatus(key, rows, itemIssues),
        summary: optionalSummary(key, rows.length, `${formatNumber(rows.length)} ${rowLabel} row/s started.`, `No ${rowLabel} row started.`),
        required: key === 'response_actions',
    });
    const progressGroupStatus = (groups) => groups.every(([key, rows, itemIssues]) => {
        const status = optionalStatus(key, rows, itemIssues);
        return status === 'complete' || status === 'na';
    }) ? 'complete' : 'needs';
    const progressGroupRowsStarted = (groups) => groups.reduce((total, [, rows]) => total + rows.length, 0);
    const fniNeedsComplete = requestedFniItems.length > 0
        && requestedFniItems.every((item) => Number(item.requested_quantity || 0) >= 1);
    const reliefAugmentationReady = !withReliefRequest || fniNeedsComplete;
    const advisoryScreenshotRows = officialAdvisoryRows.filter((row) => String(row?.screenshot_data_url || '').trim());
    const advisoryIncidentText = [
        form.data.incident_type,
        form.data.incident_name,
        form.data.incident_specific_details,
    ].filter(Boolean).join(' ').toLocaleLowerCase();
    const advisoryScreenshotsApplicable = /(typhoon|tropical|storm|weather|rain|flood|thunder|monsoon|low pressure|lpa|earthquake|seismic|volcan|tsunami)/i.test(advisoryIncidentText);
    const advisoryScreenshotsNa = isSectionNotApplicable('advisory_screenshots');
    const advisoryScreenshotStatus = advisoryScreenshotsNa || !advisoryScreenshotsApplicable
        ? 'na'
        : advisoryExtractionBusy
            ? 'locked'
            : (officialAdvisoryIssues.length === 0 ? 'complete' : 'needs');
    const sectionProgressItems = [
        {
            label: 'INCIDENT INFORMATION',
            anchor: progressAnchorFor('incident_information'),
            status: incidentInfoReady ? 'complete' : 'needs',
            summary: incidentInfoReady ? 'Required incident details are encoded.' : 'Complete disaster type, dates, affected barangays, and reporter.',
            required: true,
        },
        {
            label: 'STATUS OF AFFECTED POPULATION',
            anchor: progressAnchorFor('affected_population'),
            status: affectedPopulationReady ? 'complete' : populationIssues.length ? 'needs' : 'needs',
            summary: affectedPopulationReady ? `${formatNumber(areaRows.length)} affected barangay/ies ready.` : 'Encode affected families and persons for every selected barangay.',
            required: true,
        },
        {
            label: 'STATUS OF DISPLACED POPULATION',
            anchor: progressAnchorFor('displaced_population'),
            status: displacedNa ? 'na' : (!affectedPopulationReady ? 'locked' : ((!insideEcNa && evacuationIssues.length) || (!outsideEcNa && outsideEcIssues.length) ? 'needs' : ((insideEcNa || evacuationCenterRows.length) && (outsideEcNa || areaRows.some((row) => row.outside_ec_included)) ? 'complete' : 'needs'))),
            summary: displacedNa
                ? 'Marked not applicable by the encoder.'
                : !affectedPopulationReady
                ? 'Locked until affected population is complete.'
                : (evacuationCenterRows.length || areaRows.some((row) => row.outside_ec_included))
                    ? `${formatNumber(evacuationCenterRows.length)} inside EC row/s, ${formatNumber(areaRows.filter((row) => row.outside_ec_included).length)} outside EC row/s.`
                    : 'No displaced-population data encoded. Mark N/A if this section does not apply.',
            required: true,
            children: [
                {
                    label: 'INSIDE EVACUATION CENTERS',
                    anchor: progressAnchorFor('inside_ec'),
                    status: insideEcNa ? 'na' : (!affectedPopulationReady ? 'locked' : (evacuationIssues.length ? 'needs' : (evacuationCenterRows.length ? 'complete' : 'needs'))),
                    summary: insideEcNa ? 'Marked not applicable by the encoder.' : (evacuationCenterRows.length ? `${formatNumber(evacuationCenterRows.length)} EC row/s encoded.` : 'No inside EC rows encoded.'),
                },
                {
                    label: 'OUTSIDE EVACUATION CENTERS',
                    anchor: progressAnchorFor('outside_ec'),
                    status: outsideEcNa ? 'na' : (!affectedPopulationReady ? 'locked' : (outsideEcIssues.length ? 'needs' : (areaRows.some((row) => row.outside_ec_included) ? 'complete' : 'needs'))),
                    summary: outsideEcNa ? 'Marked not applicable by the encoder.' : (areaRows.some((row) => row.outside_ec_included) ? `${formatNumber(areaRows.filter((row) => row.outside_ec_included).length)} barangay row/s encoded.` : 'No outside EC rows encoded.'),
                },
            ],
        },
        {
            label: 'STATUS OF DAMAGED HOUSES',
            anchor: progressAnchorFor('damaged_houses'),
            status: progressStatus('damaged_houses', !affectedPopulationReady ? 'locked' : (damagedHouseIssues.length ? 'needs' : (areaRows.some((row) => row.damaged_houses_included) ? 'complete' : 'needs'))),
            summary: optionalSummary('damaged_houses', areaRows.filter((row) => row.damaged_houses_included).length, `${formatNumber(areaRows.filter((row) => row.damaged_houses_included).length)} barangay row/s encoded.`, 'No damaged-house row encoded. Mark N/A if this section does not apply.'),
            required: true,
        },
        {
            label: 'COST OF ASSISTANCE PROVIDED',
            anchor: progressAnchorFor('assistance'),
            status: optionalStatus('assistance', assistanceRows, assistanceIssues),
            summary: optionalSummary('assistance', assistanceRows.length, `${formatNumber(assistanceRows.length)} assistance item/s encoded.`, 'No assistance item encoded. Mark N/A if no assistance was provided.'),
            required: false,
        },
        {
            label: 'RELATED INCIDENTS',
            anchor: progressAnchorFor('related_incidents'),
            status: optionalStatus('related_incidents', relatedIncidentRows, relatedIncidentIssues),
            summary: optionalSummary('related_incidents', relatedIncidentRows.length, `${formatNumber(relatedIncidentRows.length)} row/s started.`, 'No rows started. Mark N/A if this section does not apply.'),
            required: false,
        },
        {
            label: 'CASUALTIES',
            anchor: progressAnchorFor('casualties'),
            status: optionalStatus('casualties', casualtyRows, casualtyIssues),
            summary: optionalSummary('casualties', casualtyRows.length, `${formatNumber(casualtyRows.length)} casualty row/s started.`, 'No casualty row started. Mark N/A if no casualties were reported.'),
            required: false,
        },
        {
            label: 'DAMAGES TO INFRASTRUCTURE',
            anchor: progressAnchorFor('infrastructure_damage'),
            status: optionalStatus('infrastructure_damage', infrastructureDamageRows, infrastructureDamageIssues),
            summary: optionalSummary('infrastructure_damage', infrastructureDamageRows.length, `${formatNumber(infrastructureDamageRows.length)} infrastructure row/s started.`, 'No infrastructure row started. Mark N/A if this section does not apply.'),
            required: false,
        },
        {
            label: 'DAMAGE AND LOSSES TO AGRICULTURE',
            anchor: progressAnchorFor('agriculture_damage'),
            status: optionalStatus('agriculture_damage', agricultureDamageRows, agricultureDamageIssues),
            summary: optionalSummary('agriculture_damage', agricultureDamageRows.length, `${formatNumber(agricultureDamageRows.length)} agriculture row/s started.`, 'No agriculture row started. Mark N/A if this section does not apply.'),
            required: false,
        },
        {
            label: 'SUSPENSION',
            anchor: progressAnchorFor('suspension'),
            status: suspensionNa ? 'na' : (!affectedPopulationReady ? 'locked' : ([optionalStatus('class_suspension', classSuspensionRows, classSuspensionIssues), optionalStatus('work_suspension', workSuspensionRows, workSuspensionIssues)].every((status) => status === 'complete' || status === 'na') ? 'complete' : 'needs')),
            summary: suspensionNa ? 'Marked not applicable by the encoder.' : (classSuspensionRows.length || workSuspensionRows.length ? `${formatNumber(classSuspensionRows.length + workSuspensionRows.length)} suspension row/s started.` : 'No suspension rows started. Mark N/A if this section does not apply.'),
            required: false,
            children: [
                {
                    label: 'CLASS SUSPENSION',
                    anchor: progressAnchorFor('class_suspension'),
                    status: suspensionNa ? 'na' : optionalStatus('class_suspension', classSuspensionRows, classSuspensionIssues),
                    summary: optionalSummary('class_suspension', classSuspensionRows.length, `${formatNumber(classSuspensionRows.length)} row/s started.`, 'No class suspension row started.'),
                },
                {
                    label: 'WORK SUSPENSION',
                    anchor: progressAnchorFor('work_suspension'),
                    status: suspensionNa ? 'na' : optionalStatus('work_suspension', workSuspensionRows, workSuspensionIssues),
                    summary: optionalSummary('work_suspension', workSuspensionRows.length, `${formatNumber(workSuspensionRows.length)} row/s started.`, 'No work suspension row started.'),
                },
            ],
        },
        {
            label: 'STATUS OF LIFELINES',
            anchor: progressAnchorFor('lifelines'),
            status: lifelinesNa ? 'na' : (!affectedPopulationReady ? 'locked' : progressGroupStatus(lifelineGroups)),
            summary: lifelinesNa ? 'Marked not applicable by the encoder.' : (lifelineRowsStarted ? `${formatNumber(lifelineRowsStarted)} lifeline row/s started.` : 'No lifeline row started. Mark N/A on subsections that do not apply.'),
            required: false,
            children: coreLifelineGroups.map(progressGroupItem),
        },
        {
            label: 'STATUS OF PORTS / TERMINALS',
            anchor: progressAnchorFor('ports_terminals'),
            status: portsTerminalGroups.every(([key]) => isSectionNotApplicable(key))
                ? 'na'
                : (!affectedPopulationReady ? 'locked' : progressGroupStatus(portsTerminalGroups)),
            summary: portsTerminalGroups.every(([key]) => isSectionNotApplicable(key))
                ? 'Marked not applicable by the encoder.'
                : (progressGroupRowsStarted(portsTerminalGroups) ? `${formatNumber(progressGroupRowsStarted(portsTerminalGroups))} port/terminal row/s started.` : 'No port or terminal row started. Mark N/A on subsections that do not apply.'),
            required: false,
            children: portsTerminalGroups.map(progressGroupItem),
        },
        ...postLifelineGroups.map(progressGroupItem),
        {
            label: 'FNI NEEDS FOR THIS INCIDENT',
            anchor: progressAnchorFor('relief_augmentation'),
            status: withReliefRequest
                ? (fniNeedsComplete ? 'complete' : 'needs')
                : (requestedFniItems.length ? (fniNeedsComplete ? 'complete' : 'needs') : 'na'),
            summary: withReliefRequest
                ? (reliefAugmentationReady
                    ? `${formatNumber(requestedFniItems.length)} requested FNI item/s with quantities encoded.`
                    : 'Select at least one FNI item and complete every requested quantity.')
                : (requestedFniItems.length
                    ? `${formatNumber(requestedFniItems.length)} FNI need/s encoded. Include request is off; a later lump request can total these.`
                    : 'Optional. Encode FNI needs for affected families here even if Include request stays off.'),
            required: withReliefRequest,
        },
        {
            label: 'ADVISORY SCREENSHOTS',
            anchor: progressAnchorFor('advisory_screenshots'),
            status: advisoryScreenshotStatus,
            summary: advisoryScreenshotsNa
                ? 'Marked not applicable by the encoder.'
                : !advisoryScreenshotsApplicable
                    ? 'Not required for this incident type. Mark N/A to persist that choice for Situation Overview AI.'
                    : (advisoryScreenshotRows.length
                        ? `${formatNumber(advisoryScreenshotRows.length)} advisory screenshot/text source/s encoded.`
                        : 'Optional for weather/earthquake reports. Paste screenshots when available, or mark N/A if none apply.'),
            required: false,
        },
        {
            label: 'SITUATION OVERVIEW',
            anchor: progressAnchorFor('situation_overview'),
            status: String(form.data.narrative || '').trim() ? 'complete' : 'needs',
            summary: String(form.data.narrative || '').trim() ? 'Narrative is encoded.' : 'Draft or auto-generate the situation overview.',
            required: true,
        },
    ];
    const flattenProgressItems = (items = []) => items.flatMap((item) => [item, ...flattenProgressItems(item.children || [])]);
    const progressEntries = flattenProgressItems(sectionProgressItems).filter((item) => item.status !== 'na');
    const completedProgress = progressEntries.filter((item) => item.status === 'complete').length;
    const progressPercent = progressEntries.length ? Math.round((completedProgress / progressEntries.length) * 100) : 0;
    const aiPrerequisiteEntries = flattenProgressItems(sectionProgressItems)
        .filter((item) => item.label !== 'SITUATION OVERVIEW');
    const advisoryScreenshotsReady = advisoryScreenshotsNa || (!advisoryExtractionBusy && officialAdvisoryIssues.length === 0);
    const situationOverviewAiReady = aiPrerequisiteEntries.length > 0
        && aiPrerequisiteEntries.every((item) => item.status === 'complete' || item.status === 'na')
        && advisoryScreenshotsReady;
    const pendingAiSections = aiPrerequisiteEntries
        .filter((item) => item.status !== 'complete' && item.status !== 'na')
        .map((item) => item.label)
        .concat(advisoryScreenshotsReady ? [] : ['ADVISORY SCREENSHOTS']);
    const jumpToDromicSection = (anchor) => {
        if (!anchor || typeof document === 'undefined') return;

        const target = document.getElementById(anchor);
        if (!target) return;

        target.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });
        target.classList.remove('dromic-section-focus');
        window.requestAnimationFrame(() => target.classList.add('dromic-section-focus'));
        window.setTimeout(() => target.classList.remove('dromic-section-focus'), 2200);
    };

    const facts = () => {
        const compactNarrativeRows = (rows = []) => compactFactRows(rows.map((row) => Object.fromEntries(
            Object.entries(row || {}).filter(([field]) => !field.toLowerCase().includes('barangay')),
        )));
        const displacedTotals = displacedPopulationRows.reduce((totals, row) => ({
            families_cumulative: totals.families_cumulative + Number(row.families_cum || 0),
            families_current: totals.families_current + Number(row.families_now || 0),
            persons_cumulative: totals.persons_cumulative + Number(row.persons_cum || 0),
            persons_current: totals.persons_current + Number(row.persons_now || 0),
        }), {
            families_cumulative: 0,
            families_current: 0,
            persons_cumulative: 0,
            persons_current: 0,
        });
        const damagedHouseTotals = areaRows.reduce((totals, row) => ({
            totally_damaged: totals.totally_damaged + Number(row.damaged_houses_totally || 0),
            partially_damaged: totals.partially_damaged + Number(row.damaged_houses_partially || 0),
            estimated_cost: totals.estimated_cost + Number(row.damaged_houses_estimated_cost || 0),
        }), { totally_damaged: 0, partially_damaged: 0, estimated_cost: 0 });
        const casualtyTotals = casualtyRows.reduce((totals, row) => {
            const status = String(row.casualty_status || '').toLowerCase();
            if (['dead', 'missing', 'injured'].includes(status)) totals[status] += 1;
            return totals;
        }, { dead: 0, missing: 0, injured: 0 });

        return {
            reporting_lgu: form.data.requesting_lgu || lguProfile?.name,
            report_classification: form.data.report_classification,
            reporting_phase: ['terminal', 'first_and_final'].includes(form.data.report_classification)
                ? 'Closed report: describe all response actions as completed and use past tense.'
                : 'Active report: distinguish completed actions from actions that are explicitly ongoing.',
            incident: {
                name: form.data.incident_name,
                type: form.data.incident_type,
                other_type: form.data.incident_type_other,
                specific_details: form.data.incident_specific_details,
                summary: form.data.incident_summary,
                location: [form.data.municipality, form.data.province].filter(Boolean).join(', '),
                affected_barangays: selectedBarangays,
                affected_barangay_count: selectedBarangays.length,
                occurrence_datetime: formatNarrativeDate(form.data.occurrence_started_at || form.data.incident_date),
                occurrence_date: formatNarrativeDate(String(form.data.occurrence_started_at || form.data.incident_date || '').slice(0, 10)),
                status: form.data.incident_status,
                ended_date: form.data.incident_status === 'Ended'
                    ? formatNarrativeDate(String(form.data.incident_ended_at || '').slice(0, 10))
                    : null,
                ended_datetime: form.data.incident_status === 'Ended'
                    ? formatNarrativeDate(form.data.incident_ended_at)
                    : null,
                ...(String(form.data.incident_type || '').toLowerCase().includes('fire') && form.data.incident_status === 'Ended' && form.data.incident_ended_at
                    ? { fireout: formatNarrativeDate(form.data.incident_ended_at) }
                    : {}),
            },
            affected_population_totals: {
                families: formatNumber(totalAffectedFamilies || form.data.affected_families),
                persons: formatNumber(totalAffectedPersons || form.data.affected_persons),
            },
            displaced_population_totals: displacedPopulationRows.length ? readableFactRecord(displacedTotals) : null,
            displaced_population_details: compactFactRows(displacedPopulationRows.map((row) => Object.fromEntries(
                Object.entries(row).filter(([field]) => !field.toLowerCase().includes('barangay')),
            ))),
            evacuation_center_totals: evacuationCenterRows.length ? readableFactRecord(evacuationGrandTotals) : null,
            evacuation_centers: compactNarrativeRows(evacuationCenterRows),
            damaged_house_totals: areaRows.some((row) => row.damaged_houses_included) ? readableFactRecord(damagedHouseTotals) : null,
            damaged_house_details: compactFactRows(areaRows
                .filter((row) => row.damaged_houses_included)
                .map((row) => ({
                    totally_damaged: row.damaged_houses_totally,
                    partially_damaged: row.damaged_houses_partially,
                    estimated_cost: row.damaged_houses_estimated_cost,
                }))),
            casualty_totals: casualtyRows.length ? readableFactRecord(casualtyTotals) : null,
            related_incidents: compactNarrativeRows(relatedIncidentRows),
            infrastructure_damage: compactNarrativeRows(infrastructureDamageRows),
            agriculture_damage_and_losses: compactNarrativeRows(agricultureDamageRows),
            class_suspensions: compactNarrativeRows(classSuspensionRows),
            work_suspensions: compactNarrativeRows(workSuspensionRows),
            roads_and_bridges: compactNarrativeRows(roadBridgeRows),
            power_lifelines: compactNarrativeRows(powerLifelineRows),
            water_lifelines: compactNarrativeRows(waterLifelineRows),
            communication_lifelines: compactNarrativeRows(communicationLifelineRows),
            seaports: compactNarrativeRows(seaportRows),
            airports: compactNarrativeRows(airportRows),
            land_transport_terminals: compactNarrativeRows(landTransportTerminalRows),
            stranded_passengers_and_transport: compactNarrativeRows(strandedTransportRows),
            calamity_declarations: compactNarrativeRows(calamityDeclarationRows),
            preemptive_evacuation: compactNarrativeRows(preemptiveEvacuationRows),
            lgu_response_actions: compactNarrativeRows(responseActionRows),
            official_agency_advisories_not_applicable: advisoryScreenshotsNa
                || (!advisoryScreenshotsApplicable && officialAdvisoryRows.length === 0),
            official_agency_advisories_status: (advisoryScreenshotsNa || (!advisoryScreenshotsApplicable && officialAdvisoryRows.length === 0))
                ? 'not_applicable'
                : (officialAdvisoryRows.length > 0 ? 'supplied' : 'none_supplied'),
            official_agency_advisories: (advisoryScreenshotsNa || (!advisoryScreenshotsApplicable && officialAdvisoryRows.length === 0))
                ? []
                : compactFactRows(officialAdvisoryRows.map((advisory) => Object.fromEntries(
                    Object.entries(advisory).filter(([field]) => !['screenshot_data_url', 'screenshot_name', 'pasted_text'].includes(field)),
                ))),
            cluster_gaps_and_actions: compactNarrativeRows(clusterGapRows),
            assistance_provided: compactNarrativeRows(assistanceRows),
            identified_needs: form.data.needs,
        };
    };

    const populationJustificationFacts = () => ({
        requesting_lgu: form.data.requesting_lgu,
        incident_type: form.data.incident_type,
        incident_specific_details: form.data.incident_specific_details,
        affected_barangays: areaRows.map((row) => ({
            barangay: row.area,
            psa_2024_population: row.psa_2024,
            affected_families: row.affected_families,
            affected_persons: row.affected_persons,
            exceeds_psa_2024: Number(row.affected_persons || 0) > Number(row.psa_2024 || 0),
        })),
        total_affected_families: totalAffectedFamilies,
        total_affected_persons: totalAffectedPersons,
    });

    const enhanceNarrative = async (mode) => {
        if (!situationOverviewAiReady) {
            setAiMessage(`Complete or mark N/A the remaining report sections first: ${pendingAiSections.slice(0, 4).join(', ')}${pendingAiSections.length > 4 ? ', and others' : ''}.`);
            return;
        }
        if (mode === 'polish') {
            const issue = situationOverviewIssue(form.data.narrative);
            if (issue) {
                setAiMessage(issue);
                return;
            }
        }
        setAiBusy(true);
        setAiMessage('');
        try {
            const { data } = await window.axios.post('/lgu/dromic-sitrep/polish', {
                mode,
                text: form.data.narrative,
                facts: facts(),
            });
            const previousNarrative = String(form.data.narrative || '').trim();
            const polished = String(data.polished || '').trim();
            form.setData('narrative', polished);
            setAiMessage(mode === 'generate'
                ? `Generated with ${data.provider || 'AI'}. Please review before submitting.`
                : polished === previousNarrative
                    ? 'Polish kept the current wording. Edit a sentence, then try Polish again.'
                    : `Polished with ${data.provider || 'AI'}. Please review before submitting.`);
        } catch (error) {
            setAiMessage(error?.response?.data?.message || 'AI helper is unavailable. You can still encode and submit manually.');
        } finally {
            setAiBusy(false);
        }
    };

    const polishPopulationJustification = async () => {
        const draft = String(form.data.population_justification || '').trim();

        setJustificationAiMessage('');

        if (draft.length < 12) {
            setJustificationAiMessage('Please type the actual reason first. AI Roger can polish your idea, but it will not invent the justification.');
            return;
        }

        setJustificationAiBusy(true);
        try {
            const { data } = await window.axios.post('/lgu/dromic-sitrep/polish', {
                mode: 'justification',
                text: draft,
                facts: populationJustificationFacts(),
            });

            form.setData('population_justification', data.polished || draft);
            setJustificationAiMessage(`Justification checked and polished with ${data.provider || 'AI'}. Please review before submitting.`);
        } catch (error) {
            setJustificationAiMessage(error?.response?.data?.message || 'AI could not check the justification right now. You can still encode it manually.');
        } finally {
            setJustificationAiBusy(false);
        }
    };

    const submit = (event) => {
        event.preventDefault();
        const submissionStatus = event.nativeEvent?.submitter?.value === 'draft' ? 'draft' : 'final';
        const isDraft = submissionStatus === 'draft';
        const incidentTypeText = String(form.data.incident_type || '').trim();

        if (!isDraft) {
            const occurrenceAt = Date.parse(form.data.occurrence_started_at || '');
            const informationReceivedAt = Date.parse(form.data.information_received_at || '');
            const incidentEndedAt = Date.parse(form.data.incident_ended_at || '');

            if (Number.isFinite(occurrenceAt) && occurrenceAt > Date.now()) {
                setValidationNotice('Date and Time of Occurrence cannot be later than the current date and time.');
                jumpToDromicSection('dromic-section-incident-information');
                return;
            }

            if (Number.isFinite(occurrenceAt) && Number.isFinite(informationReceivedAt) && informationReceivedAt < occurrenceAt) {
                setValidationNotice('Date and Time Information Was Received / Gathered cannot be earlier than the Date and Time of Occurrence.');
                jumpToDromicSection('dromic-section-incident-information');
                return;
            }

            if (form.data.incident_status === 'Ended'
                && Number.isFinite(occurrenceAt)
                && Number.isFinite(incidentEndedAt)
                && incidentEndedAt < occurrenceAt) {
                setValidationNotice('Date and Time the Disaster / Incident Ended cannot be earlier than the Date and Time of Occurrence.');
                jumpToDromicSection('dromic-section-incident-information');
                return;
            }

            if (Number.isFinite(informationReceivedAt) && informationReceivedAt > Date.now()) {
                setValidationNotice('Date and Time Information Was Received / Gathered cannot be later than the current date and time.');
                jumpToDromicSection('dromic-section-incident-information');
                return;
            }

            if (form.data.incident_status === 'Ended'
                && Number.isFinite(incidentEndedAt)
                && incidentEndedAt > Date.now()) {
                setValidationNotice('Date and Time the Disaster / Incident Ended cannot be later than the current date and time.');
                jumpToDromicSection('dromic-section-incident-information');
                return;
            }

            const narrativeIssue = situationOverviewIssue(form.data.narrative);
            if (narrativeIssue) {
                setValidationNotice(narrativeIssue);
                jumpToDromicSection('dromic-section-situation-overview');
                return;
            }

            if (requestedFniItems.length > 0 && requestedFniItems.some((row) => Number(row.requested_quantity || 0) < 1)) {
                setValidationNotice('Every selected FNI need must have a quantity of 1 or more. These items can be encoded without ticking Include request.');
                jumpToDromicSection('dromic-section-relief-request');
                return;
            }

            if (withReliefRequest && (
                requestedFniItems.length === 0
                || requestedFniItems.some((row) => Number(row.requested_quantity || 0) < 1)
            )) {
                setValidationNotice('Select at least one requested FNI item and enter a requested quantity of 1 or more for every selected item.');
                jumpToDromicSection('dromic-section-relief-request');
                return;
            }

            if (!incidentTypeText) {
                setValidationNotice('Please select the Type of Disaster / Incident before saving the final DROMIC / Situational Report.');
                return;
            }

            if (!selectedBarangays.length) {
                setValidationNotice('Please select at least one affected barangay so DROMIS can prepare the Status of Affected Population table.');
                return;
            }

            if (populationIssues.length) {
                setValidationNotice('Please review the highlighted row/s. Encode affected families and persons together, and provide justification if the count exceeds PSA 2024 population.');
                return;
            }

            if (!affectedPopulationReady) {
                setValidationNotice('Please complete the Status of Affected Population first. Encode affected families and affected persons for every selected barangay before continuing to displaced-population sections.');
                return;
            }

            if (!isSectionNotApplicable('inside_ec') && evacuationIssues.length) {
                setValidationNotice('Please review the Status of Displaced Population section. Complete each evacuation center and its disaggregated data. Enter 0 if there is no count.');
                return;
            }

            if (!isSectionNotApplicable('outside_ec') && outsideEcIssues.length) {
                setValidationNotice('Please review the Outside Evacuation Centers section. Encode CUM and NOW values for every affected barangay. Enter 0 if no displaced families/persons are outside evacuation centers.');
                return;
            }

            if (!isSectionNotApplicable('damaged_houses') && damagedHouseIssues.length) {
                setValidationNotice('Please review the Status of Damaged Houses section. Encode totally and partially damaged houses together, and make sure the total is not greater than affected families.');
                return;
            }

            if (!isSectionNotApplicable('assistance') && assistanceIssues.length) {
                setValidationNotice('Please review the Cost of Assistance Provided section. Complete each assistance item or remove unused rows.');
                return;
            }

            if (responseActionIssues.length) {
                setValidationNotice("Response Actions and Interventions is required. Encode at least one recent action or intervention undertaken by the reporting LGU.");
                jumpToDromicSection(progressAnchorFor('response_actions'));
                return;
            }

            if (!isSectionNotApplicable('advisory_screenshots') && officialAdvisoryIssues.length) {
                setValidationNotice('Review the selected official agency source. Complete its agency, advisory title, verified excerpt, and official URL, remove the incomplete source, or mark PAGASA / PHIVOLCS Advisory Screenshots as N/A.');
                return;
            }

            if (supportingSectionIssues.length) {
                const firstIssue = supportingSectionIssues[0];
                setValidationNotice(firstIssue?.message || 'Complete every applicable section or mark it N/A before saving as final.');
                if (firstIssue?.key) jumpToDromicSection(progressAnchorFor(firstIssue.key));
                return;
            }
        } else {
            setValidationNotice('Saving the current entries as a DROMIS draft…');
        }

        const isClosedReport = ['terminal', 'first_and_final'].includes(form.data.report_classification);
        if (!isDraft && isClosedReport && hasNonZeroNowValue(form.data) && !closedReportNowWarningAccepted.current) {
            setClosedReportNowWarningOpen(true);
            return;
        }

        if (!isDraft && !finalConfirmationAccepted.current) {
            setFinalConfirmationOpen(true);
            return;
        }
        finalConfirmationAccepted.current = false;
        closedReportNowWarningAccepted.current = false;

        const incidentName = [incidentTypeText, form.data.incident_specific_details].filter(Boolean).join(' - ') || form.data.incident_name;
        const affectedAreas = selectedBarangays.join(', ') || form.data.affected_areas || areaRows.map((row) => row.area).filter(Boolean).join(', ');
        const generatedNarrative = [
            `The ${form.data.requesting_lgu || 'LGU'} reports ${incidentName || 'an incident'} affecting ${affectedAreas || 'identified areas'}.`,
            form.data.occurrence_started_at ? `Date and time of occurrence: ${form.data.occurrence_started_at}.` : null,
            form.data.incident_status ? `Status of incident: ${form.data.incident_status}.` : null,
            form.data.incident_status === 'Ended' && form.data.incident_ended_at ? `Date and time the disaster/incident ended: ${form.data.incident_ended_at}.` : null,
            form.data.information_received_at ? `Information was received/gathered by the LGU on ${form.data.information_received_at}.` : null,
            `Reported by: ${form.data.dromic_reporter || form.data.requester_name || 'LGU DROMIC reporter'}.`,
        ].filter(Boolean).join(' ');

        form.transform((data) => ({
                ...data,
                requester_name: data.dromic_reporter || data.requester_name,
                incident_type: incidentTypeText,
                incident_types: incidentTypeText ? [incidentTypeText] : [],
                incident_name: incidentName,
                incident_date: (data.occurrence_started_at || data.incident_date || defaultIncidentDate || '').slice(0, 10),
                incident_ended_at: data.incident_status === 'Ongoing' ? null : data.incident_ended_at,
                submission_status: submissionStatus,
                province: data.province || provinceFromProfile(),
                barangay: affectedAreas,
                affected_families: totalsFromRows('affected_families') || data.affected_families,
                affected_persons: totalsFromRows('affected_persons') || data.affected_persons,
                narrative: data.narrative || generatedNarrative,
                requested_fni_items: data.requested_fni_items || [],
                evacuation_center_rows: isSectionNotApplicable('inside_ec') ? [] : data.evacuation_center_rows,
                assistance_rows: isSectionNotApplicable('assistance') ? [] : data.assistance_rows,
                related_incident_rows: isSectionNotApplicable('related_incidents') ? [] : data.related_incident_rows,
                casualty_rows: isSectionNotApplicable('casualties') ? [] : data.casualty_rows,
                infrastructure_damage_rows: isSectionNotApplicable('infrastructure_damage') ? [] : data.infrastructure_damage_rows,
                agriculture_damage_rows: isSectionNotApplicable('agriculture_damage') ? [] : data.agriculture_damage_rows,
                class_suspension_rows: isSectionNotApplicable('class_suspension') ? [] : data.class_suspension_rows,
                work_suspension_rows: isSectionNotApplicable('work_suspension') ? [] : data.work_suspension_rows,
                road_bridge_rows: isSectionNotApplicable('roads_bridges') ? [] : data.road_bridge_rows,
                power_lifeline_rows: isSectionNotApplicable('power_lifelines') ? [] : data.power_lifeline_rows,
                water_lifeline_rows: isSectionNotApplicable('water_lifelines') ? [] : data.water_lifeline_rows,
                communication_lifeline_rows: isSectionNotApplicable('communication_lifelines') ? [] : data.communication_lifeline_rows,
                seaport_rows: isSectionNotApplicable('seaports') ? [] : data.seaport_rows,
                airport_rows: isSectionNotApplicable('airports') ? [] : data.airport_rows,
                land_transport_terminal_rows: isSectionNotApplicable('land_transport_terminals') ? [] : data.land_transport_terminal_rows,
                stranded_transport_rows: isSectionNotApplicable('stranded_transport') ? [] : data.stranded_transport_rows,
                calamity_declaration_rows: isSectionNotApplicable('calamity_declaration') ? [] : data.calamity_declaration_rows,
                preemptive_evacuation_rows: isSectionNotApplicable('preemptive_evacuation') ? [] : data.preemptive_evacuation_rows,
                cluster_gap_rows: isSectionNotApplicable('cluster_gaps') ? [] : data.cluster_gap_rows,
                response_action_rows: (data.response_action_rows || []).filter(
                    (row) => String(row?.action_intervention || '').trim() !== '',
                ),
                photo_documentation_rows: isSectionNotApplicable('photo_documentation') ? [] : data.photo_documentation_rows,
                photo_collage_rows: isSectionNotApplicable('photo_documentation') ? [] : data.photo_collage_rows,
                area_rows: (data.area_rows || []).map((row) => ({
                    ...row,
                    ...(isSectionNotApplicable('outside_ec') ? {
                        outside_ec_included: false,
                        outside_ec_families_cum: '',
                        outside_ec_families_now: '',
                        outside_ec_persons_cum: '',
                        outside_ec_persons_now: '',
                    } : {}),
                    ...(isSectionNotApplicable('damaged_houses') ? {
                        damaged_houses_included: false,
                        damaged_houses_totally: '',
                        damaged_houses_partially: '',
                        damaged_houses_estimated_cost: '',
                    } : {}),
                })),
            }));
        const submitOptions = {
            preserveScroll: true,
            onSuccess: (page) => {
                const finalizedReportId = page?.props?.flash?.preview_report_id;
                form.reset();
                form.setData(emptyPayload(lguProfile, defaultIncidentDate));
                setOpen(false);
                setEditingRequestId(null);
                if (!isDraft && finalizedReportId) {
                    setPreviewMode('incident');
                    setCombinedPreviewTab('report');
                    setReportCopyTab('advance');
                    setPreviewReportId(finalizedReportId);
                    syncPreviewInUrl(finalizedReportId, 'incident');
                }
                router.reload({
                    only: ['requests', 'incidentGroups', 'monitoringSummary', 'reliefRequestIncidentOptions'],
                    preserveScroll: true,
                    preserveState: true,
                });
            },
            onError: (errors) => {
                const messages = [...new Set(Object.values(errors || {}).filter(Boolean))].slice(0, 3);
                const details = messages.length ? ` ${messages.join(' ')}` : '';
                setValidationNotice(isDraft
                    ? `The draft was not saved.${details}`
                    : `The final report was not saved.${details}`);
            },
            onException: () => {
                setValidationNotice(isDraft
                    ? 'The draft was not saved because DROMIS could not complete the request. Please try again.'
                    : 'The final report was not saved because DROMIS could not complete the request. Please try again.');
            },
        };

        if (editingRequestId) {
            form.patch(`/lgu/dromic-sitrep/${editingRequestId}`, submitOptions);
        } else {
            form.post('/lgu/dromic-sitrep', submitOptions);
        }
    };

    return (
        <AppLayout title="DROMIC / SitRep">
            <Head title={isProvince ? 'PLGU DROMIC Monitoring' : 'LGU DROMIC Reports'} />
            <div className="space-y-6">
                <Card className="overflow-hidden">
                    <div className="flex flex-col gap-4 bg-gradient-to-br from-slate-950 via-slate-900 to-teal-950 p-5 text-white lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0 flex-1">
                            <p className="text-xs font-black uppercase tracking-wide text-cyan-200">{isProvince ? 'PLGU Monitoring View' : 'LGU DROMIC / Situational Reporting'}</p>
                            <h1 className="mt-1 text-2xl font-black">{isProvince ? 'Monitor City / Municipal DROMIC / SitRep' : 'DROMIC / Situational Reports'}</h1>
                            <p className="mt-1 text-sm text-cyan-100">
                                {isProvince
                                    ? 'View consolidated and individual city/municipal reports in your province. Relief request processing details are intentionally kept out of this monitoring view.'
                                    : 'Encode template-style incident facts. For one incident, tick Include request and encode FNI here. For several incidents, leave Include request unchecked, encode per-incident FNI needs if known, then create one lump request — DROMIS totals those needs.'}
                            </p>
                            <p className="mt-2 text-sm font-bold text-white/80">Signed LGU: {lguProfile?.name || 'LGU account'} {lguProfile?.psgc_code ? `(${lguProfile.psgc_code})` : ''}</p>
                        </div>
                        {!isProvince && (
                            <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                <button type="button" onClick={openConsolidatedRequest} className="inline-flex h-10 items-center justify-center gap-2 whitespace-nowrap rounded-md border border-violet-300 bg-violet-50 px-4 text-sm font-black text-violet-800 shadow-sm hover:bg-violet-100">
                                    <FilePlus2 className="h-4 w-4 shrink-0" /> Create Relief Request
                                </button>
                                <button type="button" onClick={openCreateReport} className="inline-flex h-10 items-center justify-center gap-2 whitespace-nowrap rounded-md bg-emerald-600 px-4 text-sm font-black text-white shadow-sm hover:bg-emerald-700">
                                    <FilePlus2 className="h-4 w-4 shrink-0" /> Create Report
                                </button>
                            </div>
                        )}
                    </div>
                </Card>

                {isProvince && (
                    <div className="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <SummaryCard title="Reports monitored" value={monitoringSummary.reports} />
                        <SummaryCard title="Cities / municipalities" value={monitoringSummary.cities_municipalities} />
                        <SummaryCard title="Affected families" value={monitoringSummary.affected_families} />
                        <SummaryCard title="Reports with relief request" value={monitoringSummary.with_requests} />
                    </div>
                )}

                {summaryFiltersActive && <div className="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-900"><Search className="h-4 w-4 shrink-0" />Filters are active. Summary cards reflect only the filtered table results.</div>}

                {activeReportTab === 'incidents' && <div className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-4">
                    <ReportMetricCard icon={ListChecks} label="Incidents reported" value={reportDashboard.incident_count} tone="indigo" />
                    <ReportMetricCard icon={Send} label="Submitted reports" value={reportDashboard.submitted} tone="blue" breakdown={[['Advance', Number(reportDashboard.submitted || 0) - Number(reportDashboard.signed_submitted || 0)], ['Signed', reportDashboard.signed_submitted]]} />
                    <ReportMetricCard icon={FilePlus2} label="Submitted requests" value={requestDashboard.submitted_total} tone="violet" breakdown={[['Advance', Number(requestDashboard.submitted_total || 0) - Number(requestDashboard.signed || 0)], ['Signed', requestDashboard.signed]]} />
                    <ReportMetricCard icon={AlertTriangle} label="Needs LGU Action" value={Number(reportDashboard.with_findings || 0) + Number(requestDashboard.with_findings || 0)} tone="rose" breakdown={[['Reports', reportDashboard.with_findings], ['Requests', requestDashboard.with_findings]]} />
                    <ReportMetricCard icon={UploadCloud} label="Pending signed copies" value={Number(reportDashboard.pending_signed_copies || 0) + Number(requestDashboard.pending_signed_copies || 0)} tone="amber" breakdown={[['Reports', reportDashboard.pending_signed_copies], ['Requests', requestDashboard.pending_signed_copies]]} />
                </div>}

                {activeReportTab === 'reports' && <div className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-4">
                    <ReportMetricCard icon={Send} label="Submitted reports" value={reportDashboard.submitted} tone="blue" />
                    <ReportMetricCard icon={Edit3} label="Current drafts" value={reportDashboard.drafts} tone="amber" />
                    <ReportMetricCard icon={FileCheck2} label="Finalized reports" value={reportDashboard.finalized} tone="indigo" />
                    <ReportMetricCard icon={CheckCircle2} label="Validated — no findings" value={reportDashboard.validated_no_findings} tone="emerald" />
                    <ReportMetricCard icon={AlertTriangle} label="Needs LGU Action" value={reportDashboard.with_findings} tone="rose" />
                    <ReportMetricCard icon={UploadCloud} label="Pending signed copies" value={reportDashboard.pending_signed_copies} tone="violet" />
                </div>}

                {activeReportTab === 'requests' && (
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3">
                        <ReportMetricCard icon={FilePlus2} label="Request letters" value={requestDashboard.total} tone="violet" compact />
                        <ReportMetricCard icon={UploadCloud} label="Pending signed copy" value={requestDashboard.pending_signed_copies} tone="amber" compact />
                        <ReportMetricCard icon={Search} label="Awaiting DRRS review" value={requestDashboard.awaiting_review} tone="blue" compact />
                        <ReportMetricCard icon={CheckCircle2} label="Validated — no findings" value={requestDashboard.validated_no_findings} tone="emerald" compact />
                        <ReportMetricCard icon={AlertTriangle} label="Needs LGU Action" value={requestDashboard.with_findings} tone="rose" compact />
                        <ReportMetricCard icon={Send} label="Routed to DRRS" value={requestDashboard.routed} tone="indigo" compact />
                    </div>
                )}

                <Card className="p-0">
                    <div className="px-5 pt-5">
                        <h2 className="font-black">{isProvince ? 'City / Municipal LGU DROMIC reports' : 'Status of DROMIC / Situational Reports'}</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            {isProvince
                                ? 'Open individual reports or use the figures above for quick provincial monitoring.'
                                : 'Review incidents first, then open their complete reporting history. DROMIC / SitRep validation and request-letter processing remain separate.'}
                        </p>
                    </div>
                    <SectionTabs
                        label="DROMIC Report Views"
                        appearance="framed"
                        className="mt-4"
                        contentClassName="px-5"
                        value={activeReportTab}
                        ariaLabel="DROMIC report views"
                        tabs={[
                            { id: 'incidents', label: 'All Incidents', icon: ListChecks, href: '/lgu/dromic-sitrep?tab=incidents', preserveState: true },
                            { id: 'reports', label: 'All Reports', icon: FileCheck2, title: 'View all reports', onClick: () => openReportView({ tab: 'reports', series_key: '', request_letter: '' }) },
                            { id: 'requests', label: 'Requests', icon: FilePlus2, title: 'View request letters for relief augmentation', onClick: () => router.get('/lgu/dromic-sitrep', { tab: 'requests' }, { preserveScroll: true, preserveState: true }) },
                        ]}
                    />
                    {activeReportTab === 'incidents' && (
                        <>
                            <form onSubmit={(event) => { event.preventDefault(); router.get('/lgu/dromic-sitrep', { tab: 'incidents', search: reportSearch }, { preserveScroll: true, preserveState: true, replace: true }); }} className="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-4 md:flex-row dark:border-zinc-800 dark:bg-zinc-950/40">
                                <div className="relative min-w-0 flex-1">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                    <input value={reportSearch} onChange={(event) => setReportSearch(event.target.value)} placeholder="Search document code, incident, LGU, or affected area..." className="h-10 w-full rounded-md border-slate-300 pl-9 text-sm" />
                                </div>
                                <button type="submit" title="Search incidents" aria-label="Search incidents" className="inline-flex h-10 w-10 items-center justify-center rounded-md bg-emerald-700 text-white"><Search className="h-4 w-4" /></button>
                                <button type="button" title="Clear incident search" aria-label="Clear incident search" onClick={() => { setReportSearch(''); router.get('/lgu/dromic-sitrep', { tab: 'incidents' }, { preserveScroll: true, replace: true }); }} className="inline-flex h-10 w-10 items-center justify-center rounded-md border bg-white dark:bg-zinc-900"><X className="h-4 w-4" /></button>
                            </form>
                            <StatusLegend kind="incident" />
                            <DataTable columns={isProvince
                                ? ['Incident', 'Submitting LGU', 'Occurrence', 'Affected Barangays', 'Reports', 'Relief Request', 'Advance Copy', 'Signed Copy', 'DROMIC Validation', 'Last Reporter', 'Last Updated', 'Action']
                                : ['Incident', 'Occurrence', 'Affected Barangays', 'Reports', 'Relief Request', 'Advance Copy', 'Signed Copy', 'DROMIC Validation', 'Last Reporter', 'Last Updated', 'Action']} rows={incidentGroups.map((incident) => (
                                <tr key={incident.series_key}>
                                    <td className="min-w-[260px] px-4 py-3"><p className="whitespace-normal font-black leading-5">{incident.incident_name}</p><p className="mt-1 font-mono text-[11px] font-bold text-emerald-700">{incident.incident_code}</p><p className="mt-1 text-xs text-slate-500">{incidentDetailLabel(incident)}</p></td>
                                    {isProvince && <td className="min-w-[180px] px-4 py-3 font-bold">{incident.requesting_agency || '-'}</td>}
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(incident.occurrence_started_at)}</td>
                                    <td className="w-[320px] max-w-[320px] px-4 py-3"><p title={affectedAreaSummary(incident, barangayOptions)} className="line-clamp-2 whitespace-normal leading-5">{affectedAreaSummary(incident, barangayOptions)}</p></td>
                                    <td className="whitespace-nowrap px-4 py-3"><p className="font-black">{incident.report_count} total</p><p className="text-xs text-slate-500">{incident.draft_count || 0} draft · {incident.finalized_count || 0} finalized</p><p className="text-xs text-slate-500">{incident.advance_count || 0} advance · {incident.signed_count || 0} signed</p></td>
                                    <td className="w-24 px-3 py-3 text-center"><ReliefRequestMark included={incident.has_relief_request} /></td>
                                    <td className="w-24 px-3 py-3 text-center"><DromicAdvanceCopyMark submissionStatus={incident.latest_report_status || 'draft'} /></td>
                                    <td className="w-24 px-3 py-3 text-center"><DromicSubmissionMark submissionStatus={incident.latest_report_status || 'draft'} /></td>
                                    <td className="w-24 px-3 py-3 text-center"><DromicValidationMark submissionStatus={incident.latest_report_status || 'draft'} validationStatus={incident.latest_validation_status} hasSignedReport={Boolean(incident.latest_has_signed_report)} /></td>
                                    <td className="px-4 py-3 font-semibold">{incident.last_reporter}</td>
                                    <td className="whitespace-nowrap px-4 py-3">{formatDateTime(incident.updated_at)}</td>
                                    <td className="px-4 py-3"><button type="button" title="View all reports for this incident" aria-label="View all reports for this incident" onClick={() => openReportView({ tab: 'reports', series_key: incident.series_key, search: '', status: '', classification: '', validation: '', request_letter: '' })} className="inline-flex h-8 w-8 items-center justify-center rounded-md bg-brand-700 text-white"><ListChecks className="h-4 w-4" /></button></td>
                                </tr>
                            ))} />
                            {incidentGroups.length === 0 && <div className="p-10 text-center text-sm text-slate-500">No incident reporting history is available yet.</div>}
                        </>
                    )}
                    {activeReportTab !== 'incidents' && (
                    <>
                    <form onSubmit={(event) => { event.preventDefault(); openReportView({ search: reportSearch, page: 1 }); }} className="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-4 md:flex-row md:flex-wrap dark:border-zinc-800 dark:bg-zinc-950/40">
                        <input value={reportSearch} onChange={(event) => setReportSearch(event.target.value)} placeholder="Search document code, report, incident, or LGU..." className="h-10 min-w-[280px] flex-1 rounded-md border-slate-300 text-sm" />
                        {[
                            ['status', 'All submission statuses', [['draft', 'Draft'], ['final', 'Final'], ['advance_submitted', 'Advance submitted'], ['submitted', 'Submitted']]],
                            ...(activeReportTab === 'reports' ? [['classification', 'All report types', [['regular', 'Regular'], ['terminal', 'Terminal'], ['first_and_final', 'First and Final']]]] : []),
                            ['validation', activeReportTab === 'requests' ? 'All request-letter validation' : 'All DROMIC / SitRep validation', [['pending_review', 'Awaiting review'], ['under_review', 'Under review'], ['needs_lgu_action', 'Needs LGU action'], ['validated_no_findings', 'Validated — no findings']]],
                        ].map(([key, placeholder, options]) => (
                            <select key={key} value={reportFilters[key] || ''} onChange={(event) => openReportView({ [key]: event.target.value, page: 1 })} className="h-10 min-w-[200px] rounded-md border-slate-300 bg-white text-sm font-bold dark:bg-zinc-900">
                                <option value="">{placeholder}</option>
                                {options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        ))}
                        <div className="flex shrink-0 gap-2">
                            <button title={`Search ${activeReportTab}`} aria-label={`Search ${activeReportTab}`} className="inline-flex h-10 w-10 items-center justify-center rounded-md bg-emerald-700 text-white"><Search className="h-4 w-4" /></button>
                            <button type="button" title="Clear filters" aria-label="Clear filters" onClick={() => { setReportSearch(''); router.get('/lgu/dromic-sitrep', { tab: activeReportTab }, { preserveScroll: true, replace: true }); }} className="inline-flex h-10 w-10 items-center justify-center rounded-md border bg-white dark:bg-zinc-900"><X className="h-4 w-4" /></button>
                            {reportFilters.series_key && <button type="button" onClick={() => openReportView({ series_key: '' })} className="rounded-md border border-brand-200 bg-brand-50 px-4 py-2 text-xs font-black text-brand-800">Show all incidents</button>}
                        </div>
                    </form>
                    <StatusLegend kind={activeReportTab === 'requests' ? 'request' : 'report'} />
                    {responseLetterNotice && activeReportTab === 'requests' && (
                        <p className="border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-bold text-emerald-900">{responseLetterNotice}</p>
                    )}
                    <DataTable columns={isProvince
                        ? [activeReportTab === 'requests' ? 'Request Code' : 'Report Title', 'Submitting LGU', 'Occurrence', 'Affected Areas', 'Last Reporter', ...(activeReportTab === 'reports' ? ['Advance Copy'] : []), activeReportTab === 'requests' ? 'Signed Request' : 'Signed Copy', activeReportTab === 'requests' ? 'DRRS Validation' : 'DROMIC Validation', ...(activeReportTab === 'requests' ? ['FNI Processing'] : []), 'Corrected Version', 'Last Updated', ...(activeReportTab === 'requests' ? ['DSWD Response Letter'] : []), 'PDF']
                        : [activeReportTab === 'requests' ? 'Request Code' : 'Report Title', 'Occurrence', 'Affected Areas', 'Last Reporter', ...(activeReportTab === 'reports' ? ['Advance Copy'] : []), activeReportTab === 'requests' ? 'Signed Request' : 'Signed Copy', activeReportTab === 'requests' ? 'DRRS Validation' : 'DROMIC Validation', ...(activeReportTab === 'requests' ? ['FNI Processing'] : []), 'Corrected Version', 'Last Updated', ...(activeReportTab === 'requests' ? ['DSWD Response Letter'] : []), 'Action']} rows={(requests?.data ?? []).map((row) => (
                        <tr key={row.id} className={(activeReportTab === 'requests' ? row.lgu_relief_validation_status : row.lgu_dromic_validation_status) === 'needs_lgu_action' ? 'bg-rose-50 dark:bg-rose-950/20' : (activeReportTab === 'requests' ? row.lgu_relief_validation_status : row.lgu_dromic_validation_status) === 'validated_no_findings' ? 'bg-emerald-50/40 dark:bg-emerald-950/10' : ''}>
                            <td className="w-[430px] min-w-[380px] max-w-[430px] px-4 py-3">
                                <p className="whitespace-normal break-words font-black leading-5">{activeReportTab === 'requests' ? row.lgu_relief_request_reference : row.report_title}</p>
                                {row.lgu_correction_of_id && <span className="mt-1 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-black uppercase text-blue-700 ring-1 ring-blue-200">Current revision {Number(row.lgu_dromic_revision_number || 0)}</span>}
                                {activeReportTab === 'reports' && row.lgu_amendment_request_status === 'requested' && amendmentTargetOf(row) !== 'request' && <span className="mt-1 inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-black uppercase text-amber-800 ring-1 ring-amber-200">Amendment requested</span>}
                                {activeReportTab === 'reports' && row.lgu_amendment_request_status === 'approved' && amendmentTargetOf(row) !== 'request' && <span className="mt-1 inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase text-emerald-800 ring-1 ring-emerald-200">Amendment approved</span>}
                                {activeReportTab === 'reports' && row.lgu_amendment_request_status === 'denied' && amendmentTargetOf(row) !== 'request' && <span className="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase text-slate-700 ring-1 ring-slate-200">Amendment denied</span>}
                                {activeReportTab === 'requests' && row.lgu_amendment_request_status === 'requested' && amendmentTargetOf(row) === 'request' && <span className="mt-1 inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-black uppercase text-amber-800 ring-1 ring-amber-200">Amendment requested</span>}
                                {activeReportTab === 'requests' && row.lgu_amendment_request_status === 'approved' && amendmentTargetOf(row) === 'request' && <span className="mt-1 inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase text-emerald-800 ring-1 ring-emerald-200">Amendment approved</span>}
                                {activeReportTab === 'requests' && row.lgu_amendment_request_status === 'denied' && amendmentTargetOf(row) === 'request' && <span className="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase text-slate-700 ring-1 ring-slate-200">Amendment denied</span>}
                                {activeReportTab === 'requests' && <p className="mt-1 whitespace-normal break-words text-xs font-bold leading-4 text-slate-700 dark:text-zinc-200">{row.report_title}</p>}
                                <p className="mt-1 whitespace-normal text-xs text-slate-500">{row.reference_number} · {formatDateTime(row.lgu_submitted_to_dswd_at || row.updated_at)}</p>
                                {activeReportTab === 'requests' && isStandaloneReliefRequest(row) ? (
                                    <LinkedIncidentsSummary
                                        incidents={linkedIncidentsForRow(row)}
                                        onViewAll={(incidents) => setConsolidatedLinkedViewer({ row, incidents })}
                                    />
                                ) : (
                                    <p className="mt-1 font-mono text-[11px] font-bold text-emerald-700">{row.incident_code}</p>
                                )}
                            </td>
                            {isProvince && <td className="w-[220px] max-w-[220px] px-4 py-3 font-bold">{lguAndProvince(row)}</td>}
                            <td className="whitespace-nowrap px-4 py-3">{formatDate(row.lgu_dromic_payload?.occurrence_started_at || row.incident?.incident_date)}</td>
                            <td className="w-[280px] max-w-[280px] px-4 py-3">
                                <AffectedAreasSummaryCell
                                    areas={activeReportTab === 'requests' && isStandaloneReliefRequest(row)
                                        ? linkedAffectedAreasForRow(row)
                                        : null}
                                    fallbackTitle={affectedAreaSummary(row, barangayOptions)}
                                    onViewAll={(areas) => setConsolidatedAreasViewer({
                                        incident_name: row.lgu_relief_request_reference || row.report_title,
                                        municipality: row.municipality,
                                        province: row.province,
                                        incident_code: row.incident_code,
                                        affected_barangays: areas,
                                    })}
                                />
                            </td>
                            <td className="min-w-[170px] px-4 py-3 font-semibold">{row.series_summary?.last_reporter || row.requester || '-'}</td>
                            {activeReportTab === 'reports' && <td className="w-24 px-3 py-3 text-center"><DromicAdvanceCopyMark submissionStatus={row.lgu_report_status || row.status || 'draft'} /></td>}
                            <td className="w-24 px-3 py-3 text-center">{activeReportTab === 'requests'
                                ? <RequestSubmissionMark hasSignedRequest={Boolean(row.lgu_signed_request_path)} />
                                : <DromicSubmissionMark submissionStatus={row.lgu_report_status || row.status || 'draft'} />}</td>
                            <td className="w-24 px-3 py-3 text-center">{activeReportTab === 'requests'
                                ? <RequestValidationMark hasSignedRequest={Boolean(row.lgu_signed_request_path)} validationStatus={row.lgu_relief_validation_status} />
                                : <DromicValidationMark submissionStatus={row.lgu_report_status || row.status || 'draft'} validationStatus={row.lgu_dromic_validation_status} hasSignedReport={Boolean(row.lgu_signed_report_path)} />}</td>
                            {activeReportTab === 'requests' && <td className="min-w-[160px] px-4 py-3">{row.relief_augmentation_request
                                ? <div><p className="font-black text-emerald-800">{row.relief_augmentation_request.reference_number}</p><p className="mt-1 text-xs font-bold text-slate-500">{fniProcessingLabel(row)}</p></div>
                                : <span className="text-xs text-slate-400">Starts after DRRS validation</span>}</td>}
                            <td className="w-24 px-3 py-3 text-center"><CorrectedVersionMark corrected={Boolean(row.lgu_correction_of_id) && (activeReportTab === 'requests' ? row.lgu_correction_target === 'request' && Boolean(row.lgu_signed_request_path) : row.lgu_correction_target !== 'request' && ['advance_submitted', 'submitted'].includes(row.lgu_report_status || row.status))} kind={activeReportTab === 'requests' ? 'request' : 'report'} validationStatus={activeReportTab === 'requests' ? row.lgu_relief_validation_status : row.lgu_dromic_validation_status} /></td>
                            <td className="whitespace-nowrap px-4 py-3">{formatDateTime(row.updated_at)}</td>
                            {activeReportTab === 'requests' && (
                                <td className="min-w-[220px] px-4 py-3">
                                    <DswdResponseLetterCell
                                        row={row}
                                        busyId={responseLetterBusyId}
                                        onAcknowledge={acknowledgeResponseLetter}
                                        onView={(kind, letter) => {
                                            const src = kind === 'advance' ? letter?.advance?.view_url : letter?.signed?.view_url;
                                            if (!src) return;
                                            setResponseLetterPreview({
                                                open: true,
                                                title: kind === 'advance' ? 'Advance Response Letter' : 'Signed Response Letter',
                                                subtitle: letter?.reference_number || row.reference_number || row.request_code,
                                                src,
                                                kind: kind === 'signed' ? 'signed' : null,
                                                zIndexClass: 'z-[100]',
                                            });
                                        }}
                                    />
                                </td>
                            )}
                            <td className="whitespace-nowrap px-4 py-3">
                                {isProvince ? (
                                    <a title="Open report PDF" aria-label="Open report PDF" href={`/lgu/dromic-sitrep/${row.id}/pdf?inline=1`} target="_blank" rel="noreferrer" className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-brand-200 bg-brand-50 text-brand-700"><Download className="h-4 w-4" /></a>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        {(row.lgu_report_status || row.status) === 'draft' && row.series_summary?.is_terminal && !isStandaloneReliefRequest(row) ? (
                                            <span className="rounded-md bg-slate-100 px-2.5 py-1.5 text-xs font-black text-slate-600">Reporting closed</span>
                                        ) : (row.lgu_report_status || row.status) === 'draft' ? (
                                            <div className="flex flex-wrap gap-2">
                                                {isStandaloneReliefRequest(row) ? (
                                                    <button type="button" title="Continue editing this draft relief request" aria-label="Continue editing draft relief request" onClick={() => openConsolidatedEdit(row)} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-amber-200 bg-amber-50 text-amber-800"><Edit3 className="h-4 w-4" /></button>
                                                ) : (
                                                    <button type="button" title="Continue and finish this draft report" aria-label="Continue and finish this draft report" onClick={() => openDraftReport(row)} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-amber-200 bg-amber-50 text-amber-800"><Edit3 className="h-4 w-4" /></button>
                                                )}
                                                {activeReportTab === 'requests' && retainedSignedRequestFor(row) && (
                                                    <button type="button" title="View signed request letter" aria-label="View signed request letter" onClick={() => openRetainedSignedRequestView(row)} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-brand-200 bg-brand-50 text-brand-800"><Eye className="h-4 w-4" /></button>
                                                )}
                                                {activeReportTab === 'requests' && isStandaloneReliefRequest(row) && retainedSignedRequestFor(row) && (
                                                    <button type="button" title="Finalize and submit this relief request to DSWD" aria-label="Finalize and submit relief request to DSWD" onClick={() => finalizeStandaloneReliefForSubmit(row)} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-800"><Send className="h-4 w-4" /></button>
                                                )}
                                            </div>
                                        ) : (
                                            <>
                                                {canReopenForRevision(row) && (
                                                    <button
                                                        type="button"
                                                        title={isStandaloneReliefRequest(row) || activeReportTab === 'requests'
                                                            ? 'Revise this finalized request before submitting to DSWD'
                                                            : 'Revise this finalized report before submitting to DSWD'}
                                                        aria-label="Revise before submit"
                                                        disabled={reopenBusy}
                                                        onClick={() => reopenForRevision(row)}
                                                        className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-amber-200 bg-amber-50 text-amber-800 disabled:opacity-50"
                                                    >
                                                        <Edit3 className="h-4 w-4" />
                                                    </button>
                                                )}
                                                {activeReportTab === 'requests' && retainedSignedRequestFor(row) && row.lgu_relief_validation_status !== 'needs_lgu_action' && (
                                                    <button type="button" title="View signed request letter" aria-label="View signed request letter" onClick={() => openRetainedSignedRequestView(row)} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-brand-200 bg-brand-50 text-brand-800"><Eye className="h-4 w-4" /></button>
                                                )}
                                                {activeReportTab === 'reports'
                                                    && (row.lgu_report_status || row.status) !== 'final'
                                                    && (row.lgu_report_status || row.status) !== 'draft'
                                                    && row.lgu_dromic_validation_status !== 'needs_lgu_action'
                                                    && <button type="button" title="Preview DROMIC / SitRep" aria-label="Preview DROMIC / SitRep" onClick={() => openDocumentPreview(row, 'report')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-brand-200 bg-brand-50 text-brand-800"><Eye className="h-4 w-4" /></button>}
                                                {activeReportTab === 'requests' && row.lgu_relief_validation_status === 'needs_lgu_action' && <button type="button" title="Preview and correct this request letter" aria-label="Preview and correct request letter" onClick={() => openCorrectionWorkspace(row, 'request')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-rose-800"><Edit3 className="h-4 w-4" /></button>}
                                                {activeReportTab === 'requests' && !row.lgu_signed_request_path && ['final', 'advance_submitted', 'submitted'].includes(row.lgu_report_status || row.status) && <button type="button" title="Upload the pending signed request-letter PDF" aria-label="Upload pending signed request letter" onClick={() => openCorrectionWorkspace(row, 'request')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-violet-200 bg-violet-50 text-violet-800"><UploadCloud className="h-4 w-4" /></button>}
                                                {activeReportTab === 'reports' && !row.lgu_signed_report_path && ['final', 'advance_submitted', 'submitted'].includes(row.lgu_report_status || row.status) && <button type="button" title="Upload the pending signed DROMIC report PDF" aria-label="Upload pending signed DROMIC report" onClick={() => openDocumentPreview(row, 'incident')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-violet-200 bg-violet-50 text-violet-800"><UploadCloud className="h-4 w-4" /></button>}
                                                {activeReportTab === 'reports' && row.lgu_dromic_validation_status === 'needs_lgu_action' && <button type="button" title="Preview and correct this DROMIC / SitRep" aria-label="Preview and correct DROMIC report" onClick={() => openCorrectionWorkspace(row, 'report')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-rose-800"><Edit3 className="h-4 w-4" /></button>}
                                                {activeReportTab === 'reports' && canRequestAmendment(row, 'report') && <button type="button" title="Request DRIMS permission to amend this submitted report" aria-label="Request permission to amend report" onClick={() => openAmendmentRequest(row, 'report')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-amber-200 bg-amber-50 text-amber-800"><Undo2 className="h-4 w-4" /></button>}
                                                {activeReportTab === 'requests' && canRequestAmendment(row, 'request') && <button type="button" title="Request DRRS permission to amend this submitted relief request" aria-label="Request permission to amend relief request" onClick={() => openAmendmentRequest(row, 'request')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-amber-200 bg-amber-50 text-amber-800"><Undo2 className="h-4 w-4" /></button>}
                                                {(row.revision_history || []).length > 1 && <button type="button" title={`View ${activeReportTab === 'requests' ? 'request-letter' : 'report'} revision history`} aria-label="View revision history" onClick={() => setRevisionPreview({ row, kind: activeReportTab === 'requests' ? 'request' : 'report' })} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-indigo-200 bg-indigo-50 text-indigo-800"><History className="h-4 w-4" /></button>}
                                                {(row.signed_document_versions || []).some((version) => version.kind === (activeReportTab === 'requests' ? 'request' : 'report')) && <button type="button" title={`View previous uploaded signed ${activeReportTab === 'requests' ? 'request letters' : 'reports'}`} aria-label="View previous uploaded signed documents" onClick={() => setHistoryPreview({ row, kind: activeReportTab === 'requests' ? 'request' : 'report' })} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-700"><Images className="h-4 w-4" /></button>}
                                                {activeReportTab === 'requests' && (row.lgu_report_status || row.status) === 'final' && (
                                                    <button
                                                        type="button"
                                                        title="Submit this relief request to DSWD"
                                                        aria-label="Submit relief request to DSWD"
                                                        disabled={submissionBusy}
                                                        onClick={() => (isStandaloneReliefRequest(row)
                                                            ? finalizeStandaloneReliefForSubmit(row)
                                                            : openDocumentPreview(row, 'incident'))}
                                                        className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-800 disabled:opacity-50"
                                                    >
                                                        <Send className="h-4 w-4" />
                                                    </button>
                                                )}
                                                {activeReportTab === 'reports' && (row.lgu_report_status || row.status) === 'final' && (
                                                    <button type="button" title="Finish and submit this finalized report" aria-label="Finish and submit this finalized report" onClick={() => openDocumentPreview(row, 'incident')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-800"><Send className="h-4 w-4" /></button>
                                                )}
                                                {activeReportTab === 'reports' && Number(row.series_summary?.latest_request_id) === Number(row.id)
                                                    && !row.series_summary?.is_terminal
                                                    && !['terminal', 'first_and_final'].includes(row.lgu_dromic_report_classification)
                                                    && ['advance_submitted', 'submitted'].includes(row.lgu_report_status || row.status) && (
                                                    <button type="button" title="Create updated report" aria-label="Create updated report" onClick={() => openIncidentUpdate(row, 'regular')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-emerald-200 bg-emerald-50 text-emerald-800"><Plus className="h-4 w-4" /></button>
                                                )}
                                                {activeReportTab === 'reports' && Number(row.series_summary?.latest_request_id) === Number(row.id)
                                                    && !row.series_summary?.is_terminal
                                                    && !['terminal', 'first_and_final'].includes(row.lgu_dromic_report_classification)
                                                    && ['advance_submitted', 'submitted'].includes(row.lgu_report_status || row.status)
                                                    && row.lgu_dromic_payload?.incident_status === 'Ended'
                                                    && Number(row.series_summary?.reports_finalized || 0) > 0 && (
                                                    <button type="button" title="Create terminal report" aria-label="Create terminal report" onClick={() => openIncidentUpdate(row, 'terminal')} className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-rose-800"><FileCheck2 className="h-4 w-4" /></button>
                                                )}
                                            </>
                                        )}
                                    </div>
                                )}
                            </td>
                        </tr>
                    ))} />
                    {(requests?.data ?? []).length === 0 && <div className="p-10 text-center text-sm text-slate-500">{isProvince ? 'No city/municipal LGU DROMIC reports are available for this province yet.' : 'No DROMIC / Situational Reports yet.'}</div>}
                    {requests?.links?.length > 3 && <div className="flex flex-wrap gap-1 border-t border-slate-200 p-4 dark:border-zinc-800">{requests.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded border px-3 py-1 text-xs font-bold ${link.active ? 'bg-brand-600 text-white' : 'bg-white dark:bg-zinc-900'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded border px-3 py-1 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                    </>
                    )}
                </Card>
            </div>

            {consolidatedRequestOpen && (
                <div className="fixed inset-0 z-[230] flex items-start justify-center overflow-y-auto bg-slate-950/70 p-2 backdrop-blur-sm sm:p-5" role="dialog" aria-modal="true" aria-labelledby="consolidated-relief-title">
                    <form onSubmit={submitConsolidatedRequest} className="my-auto flex max-h-[calc(100dvh-1rem)] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-950 sm:max-h-[calc(100dvh-2.5rem)]">
                        <div className="flex shrink-0 items-start justify-between gap-4 border-b border-violet-200 bg-gradient-to-r from-violet-50 to-emerald-50 p-4 sm:p-5 dark:border-violet-900 dark:from-violet-950/40 dark:to-emerald-950/30">
                            <div>
                                <p className="text-xs font-black uppercase tracking-wide text-violet-700">Standalone consolidated request</p>
                                <h2 id="consolidated-relief-title" className="mt-1 text-xl font-black">
                                    {editingConsolidatedId ? 'Revise Relief Augmentation Request' : 'Create Relief Augmentation Request'}
                                </h2>
                                <p className="mt-1 text-sm text-slate-600 dark:text-zinc-300">
                                    {editingConsolidatedId
                                        ? 'This request is pre-filled from the previous encoding. Add or remove incidents of the same type, adjust FNI quantities, and keep or replace the signed request letter before saving as final.'
                                        : 'Select one or more existing incidents of the same type. FNI quantities encoded on those reports are totaled here; if none were encoded, add them in step 2 before submitting.'}
                                </p>
                            </div>
                            <button type="button" onClick={closeConsolidatedRequestModal} className="shrink-0 rounded-md border bg-white p-2 text-slate-600" aria-label="Close consolidated request"><X className="h-5 w-5" /></button>
                        </div>
                        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-4 sm:p-5">
                            <section className="rounded-lg border border-slate-200 p-4 dark:border-zinc-800">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h3 className="font-black">1. Select incidents</h3>
                                        <p className="text-xs text-slate-500">
                                            {editingConsolidatedId
                                                ? 'Previously linked incidents stay selected. You can remove them or add other eligible incidents of the same type.'
                                                : 'Only finalized incidents with an uploaded signed DROMIC report and no existing relief request are listed. One lump request may include only one incident type.'}
                                        </p>
                                    </div>
                                    {selectedConsolidatedType && <span className="rounded-full bg-violet-100 px-3 py-1 text-xs font-black text-violet-800">Locked type: {selectedConsolidatedType}</span>}
                                </div>
                                {selectedConsolidatedType && Object.keys(reliefOptionsByType).length > 1 && (
                                    <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold leading-5 text-amber-950">
                                        Other incident types are dimmed and cannot be mixed into this request. Clear the current selection to switch type.
                                    </p>
                                )}
                                {consolidatedOptionsLoading ? (
                                    <p className="mt-3 rounded-md bg-sky-50 p-4 text-sm font-bold text-sky-900">Refreshing eligible incidents…</p>
                                ) : reliefRequestIncidentOptions.length ? (
                                    <div className="mt-3 space-y-4">
                                        {Object.entries(reliefOptionsByType).map(([type, incidents]) => {
                                            const typeLockedOut = Boolean(selectedConsolidatedType)
                                                && String(type).toLocaleLowerCase() !== String(selectedConsolidatedType).toLocaleLowerCase();
                                            return (
                                                <div key={type} className={typeLockedOut ? 'opacity-55' : ''}>
                                                    <div className="mb-2 flex flex-wrap items-center gap-2">
                                                        <p className="text-xs font-black uppercase tracking-wide text-violet-700">{type}</p>
                                                        {typeLockedOut && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">Different type — unavailable</span>}
                                                    </div>
                                                    <div className="grid gap-2 md:grid-cols-2">
                                                        {incidents.map((incident) => {
                                                            const selected = consolidatedForm.data.incident_series_keys.includes(incident.series_key);
                                                            const disabled = !selected && Boolean(selectedConsolidatedType)
                                                                && String(incident.incident_type).toLocaleLowerCase() !== String(selectedConsolidatedType).toLocaleLowerCase();
                                                            const areas = normalizeAffectedAreas(incident);
                                                            const previewAreas = areas.slice(0, AFFECTED_AREA_PREVIEW_LIMIT);
                                                            const hiddenAreaCount = Math.max(areas.length - previewAreas.length, 0);
                                                            const fniCount = Number(incident.fni_item_count ?? (incident.requested_fni_items || []).length);
                                                            const locationBits = [incident.municipality, incident.province].filter(Boolean).join(', ');
                                                            return (
                                                                <label
                                                                    key={incident.series_key}
                                                                    className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition ${selected ? 'border-violet-400 bg-violet-50 ring-2 ring-violet-100' : disabled ? 'cursor-not-allowed border-slate-100 bg-slate-50 opacity-45' : 'border-slate-200 hover:border-violet-300'}`}
                                                                >
                                                                    <input type="checkbox" className="mt-1" checked={selected} disabled={disabled} onChange={() => toggleConsolidatedIncident(incident)} />
                                                                    <span className="min-w-0 flex-1">
                                                                        <span className="block font-black">{incident.incident_name}</span>
                                                                        <span className="mt-1 block text-xs font-bold text-violet-700">{incident.incident_type}</span>
                                                                        <span className="mt-1 block text-xs text-slate-500">
                                                                            {incident.incident_code} · {formatDate(incident.occurrence_started_at)} · {Number(incident.affected_families || 0).toLocaleString()} affected families
                                                                            {locationBits ? ` · ${locationBits}` : ''}
                                                                        </span>
                                                                        <span className="mt-2 flex flex-wrap items-start gap-x-2 gap-y-1 text-xs text-slate-600">
                                                                            <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-violet-600" />
                                                                            <span className="min-w-0">
                                                                                <span className="font-bold text-slate-700">Affected area/s: </span>
                                                                                {areas.length === 0
                                                                                    ? <span className="text-slate-500">Not encoded</span>
                                                                                    : (
                                                                                        <>
                                                                                            <span>{previewAreas.join(', ')}</span>
                                                                                            {hiddenAreaCount > 0 && (
                                                                                                <button
                                                                                                    type="button"
                                                                                                    className="ml-1 font-black text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900"
                                                                                                    onClick={(event) => {
                                                                                                        event.preventDefault();
                                                                                                        event.stopPropagation();
                                                                                                        setConsolidatedAreasViewer(incident);
                                                                                                    }}
                                                                                                >
                                                                                                    View all {areas.length}
                                                                                                </button>
                                                                                            )}
                                                                                        </>
                                                                                    )}
                                                                            </span>
                                                                        </span>
                                                                        <span className={`mt-2 inline-flex rounded-full px-2 py-0.5 text-[11px] font-black ${fniCount > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'}`}>
                                                                            {fniCount > 0 ? `${fniCount} FNI item${fniCount === 1 ? '' : 's'} encoded` : 'No FNI encoded — add in step 2'}
                                                                        </span>
                                                                    </span>
                                                                </label>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : <p className="mt-3 rounded-md bg-amber-50 p-4 text-sm font-bold text-amber-800">No finalized incident with an uploaded signed report is currently available.</p>}
                                {consolidatedForm.errors.incident_series_keys && <p className="mt-2 text-sm font-bold text-rose-700">{consolidatedForm.errors.incident_series_keys}</p>}
                            </section>

                            <section className={`rounded-lg border border-slate-200 p-4 dark:border-zinc-800 ${!hasConsolidatedIncidentChoices ? 'pointer-events-none opacity-45' : ''}`} aria-disabled={!hasConsolidatedIncidentChoices}>
                                <h3 className="font-black">2. Requested relief items</h3>
                                <p className="mt-1 text-xs text-slate-500">{!hasConsolidatedIncidentChoices
                                    ? 'Select an eligible incident first. This section unlocks when at least one finalized incident with a signed DROMIC report is available.'
                                    : !selectedConsolidatedIncidents.length
                                        ? 'Select one or more incidents above. Encoded FNI totals will appear here when available.'
                                        : selectedEncodedFniCount
                                            ? 'These quantities were totaled from FNI encoded on the selected reports. Edit the combined totals if needed.'
                                            : editingConsolidatedId
                                                ? 'Adjust the pre-filled request items, or add more FNI before saving.'
                                                : 'No FNI needs were encoded on the selected reports. Add the combined request items here before submitting.'}</p>
                                {selectedMissingEncodedFni && !editingConsolidatedId && (
                                    <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs font-semibold leading-5 text-amber-950">
                                        <p className="font-black">Forgot to encode FNI on the SitRep?</p>
                                        <p className="mt-1">You can still create this lump request. Use the FNI picker below to add items and quantities now. They will be stored with this relief augmentation request only and will not rewrite the signed DROMIC reports.</p>
                                    </div>
                                )}
                                <div className="mt-3"><LookerMultiSelect label="Food and Non-Food Items (FNI)" options={requestedFniOptions} value={consolidatedRequestedIds} onApply={setConsolidatedFniSelection} placeholder="Search FNI item name..." allLabel="Select one or more FNI items" disabled={!hasConsolidatedIncidentChoices} /></div>
                                {consolidatedForm.errors.requested_fni_items && <p className="mt-2 text-sm font-bold text-rose-700">{consolidatedForm.errors.requested_fni_items}</p>}
                                {consolidatedRequestedItems.length > 0 && <div className="mt-3 divide-y overflow-hidden rounded-md border">
                                    {consolidatedRequestedItems.map((row, index) => {
                                        const item = fniLibraryItems.find((candidate) => String(candidate.id) === String(row.fni_library_item_id));
                                        return <div key={row.fni_library_item_id} className="grid items-center gap-3 p-3 sm:grid-cols-[1fr_220px]"><div><p className="font-black">{item?.item_name}</p><p className="text-xs text-slate-500">{item?.unit_of_measure ? `Unit: ${item.unit_of_measure}` : item?.item_category}</p></div><div><input type="text" inputMode="numeric" pattern="[0-9]*" className="w-full" disabled={!hasConsolidatedIncidentChoices} value={wholeQuantityInputValue(row.requested_quantity)} onChange={(event) => setConsolidatedFniQuantity(row.fni_library_item_id, event.target.value)} placeholder="Requested quantity" />{consolidatedForm.errors[`requested_fni_items.${index}.requested_quantity`] && <p className="mt-1 text-xs font-bold text-rose-700">{consolidatedForm.errors[`requested_fni_items.${index}.requested_quantity`]}</p>}</div></div>;
                                    })}
                                </div>}
                                {selectedConsolidatedIncidents.length > 0 && consolidatedRequestedItems.length === 0 && (
                                    <p className="mt-3 text-xs font-bold text-rose-700">Add at least one FNI item with quantity to continue.</p>
                                )}
                            </section>

                            <section className={`rounded-lg border border-slate-200 p-4 dark:border-zinc-800 ${!hasConsolidatedIncidentChoices ? 'pointer-events-none opacity-45' : ''}`} aria-disabled={!hasConsolidatedIncidentChoices}>
                                <h3 className="font-black">3. Upload signed LGU request</h3>
                                <p className="mt-1 text-sm text-slate-500">{!hasConsolidatedIncidentChoices
                                    ? 'Select an eligible incident first. The signed request-letter upload unlocks with section 1.'
                                    : editingConsolidatedId
                                        ? (editingHasRetainedSignedRequest
                                            ? 'The current signed request letter is kept. Replace it only if the PDF needs to change.'
                                            : 'Attach the signed relief augmentation request letter covering the selected incidents. PDF only, up to 10 MB. Required when saving as final.')
                                        : 'Attach the signed relief augmentation request letter covering all selected incidents. PDF only, up to 10 MB.'}</p>
                                {editingConsolidatedId && editingRetainedSignedRequest && (
                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-3 dark:border-emerald-900 dark:bg-emerald-950/30">
                                        <div className="min-w-0">
                                            <p className="text-xs font-black uppercase tracking-wide text-emerald-800">Current signed letter</p>
                                            <p className="mt-1 truncate text-sm font-bold text-emerald-950 dark:text-emerald-100">{editingRetainedSignedRequest.name}</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => openSignedRequestPdfPreview(editingRetainedSignedRequest, {
                                                title: 'Signed request letter',
                                                zIndexClass: 'z-[250]',
                                            })}
                                            className="inline-flex items-center gap-1.5 rounded-md border border-emerald-300 bg-white px-3 py-1.5 text-xs font-black text-emerald-800"
                                        >
                                            <Eye className="h-3.5 w-3.5" /> View
                                        </button>
                                    </div>
                                )}
                                <label className={`mt-3 flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-violet-300 bg-violet-50 px-4 py-7 text-center transition dark:border-violet-800 dark:bg-violet-950/30 ${hasConsolidatedIncidentChoices ? 'cursor-pointer hover:bg-violet-100' : 'cursor-not-allowed'}`}>
                                    <UploadCloud className="h-8 w-8 text-violet-700" />
                                    <span className="mt-2 font-black text-violet-900 dark:text-violet-100">{consolidatedForm.data.signed_request?.name || (editingHasRetainedSignedRequest ? 'Replace signed request-letter PDF (optional)' : 'Choose signed request-letter PDF')}</span>
                                    <span className="mt-1 text-xs text-violet-700 dark:text-violet-300">
                                        {editingHasRetainedSignedRequest
                                            ? 'Leave unchanged to keep the current signed letter.'
                                            : 'The document will be stored with this consolidated request.'}
                                    </span>
                                    <input type="file" accept="application/pdf,.pdf" className="sr-only" disabled={!hasConsolidatedIncidentChoices} onChange={(event) => consolidatedForm.setData('signed_request', event.target.files?.[0] || null)} />
                                </label>
                                {consolidatedForm.errors.signed_request && <p className="mt-2 text-sm font-bold text-rose-700">{consolidatedForm.errors.signed_request}</p>}
                            </section>
                        </div>
                        <div className="flex shrink-0 flex-wrap justify-end gap-3 border-t bg-slate-50 p-4 dark:bg-zinc-900">
                            <button type="button" onClick={closeConsolidatedRequestModal} className="rounded-md border bg-white px-4 py-2 text-sm font-black">Cancel</button>
                            {editingConsolidatedId ? (
                                <>
                                    <button
                                        type="submit"
                                        data-save-mode="final"
                                        disabled={consolidatedForm.processing || !canSubmitConsolidatedUpdate}
                                        onClick={() => { consolidatedSaveModeRef.current = 'final'; }}
                                        className="rounded-md bg-violet-700 px-5 py-2 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {consolidatedForm.processing && consolidatedSaveModeRef.current === 'final' ? 'Saving...' : 'Update Request'}
                                    </button>
                                    <button
                                        type="submit"
                                        data-save-mode="draft"
                                        disabled={consolidatedForm.processing || !consolidatedForm.data.incident_series_keys.length || !consolidatedRequestedItems.length}
                                        onClick={() => { consolidatedSaveModeRef.current = 'draft'; }}
                                        className="rounded-md border border-amber-300 bg-amber-50 px-5 py-2 text-sm font-black text-amber-900 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {consolidatedForm.processing && consolidatedSaveModeRef.current === 'draft' ? 'Saving...' : 'Save draft'}
                                    </button>
                                </>
                            ) : (
                                <button type="submit" disabled={consolidatedForm.processing || !consolidatedForm.data.incident_series_keys.length || !consolidatedRequestedItems.length || !consolidatedForm.data.signed_request} className="rounded-md bg-violet-700 px-5 py-2 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-50">{consolidatedForm.processing ? 'Creating...' : 'Create Request'}</button>
                            )}
                        </div>
                    </form>
                </div>
            )}

            {consolidatedAreasViewer && (
                <div className="fixed inset-0 z-[240] flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="consolidated-areas-title">
                    <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-2xl dark:bg-zinc-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-black uppercase tracking-wide text-violet-700">Affected area/s</p>
                                <h3 id="consolidated-areas-title" className="mt-1 text-lg font-black">{consolidatedAreasViewer.incident_name || 'Selected incident'}</h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    {[consolidatedAreasViewer.municipality, consolidatedAreasViewer.province].filter(Boolean).join(', ') || consolidatedAreasViewer.incident_code}
                                </p>
                            </div>
                            <button type="button" onClick={() => setConsolidatedAreasViewer(null)} className="rounded-md border p-2" aria-label="Close affected areas"><X className="h-4 w-4" /></button>
                        </div>
                        <ul className="mt-4 max-h-72 space-y-2 overflow-y-auto">
                            {normalizeAffectedAreas(consolidatedAreasViewer).map((area) => (
                                <li key={area} className="flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100">
                                    <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-violet-600" />
                                    <span>{area}</span>
                                </li>
                            ))}
                        </ul>
                        <button type="button" onClick={() => setConsolidatedAreasViewer(null)} className="mt-4 w-full rounded-md bg-violet-700 px-4 py-2 text-sm font-black text-white">Close</button>
                    </div>
                </div>
            )}

            {consolidatedLinkedViewer && (
                <div className="fixed inset-0 z-[240] flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="consolidated-linked-title">
                    <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl dark:bg-zinc-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-black uppercase tracking-wide text-violet-700">Linked incidents</p>
                                <h3 id="consolidated-linked-title" className="mt-1 text-lg font-black">
                                    {consolidatedLinkedViewer.row?.lgu_relief_request_reference || 'Consolidated request'}
                                </h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    {consolidatedLinkedViewer.incidents.length} incident{consolidatedLinkedViewer.incidents.length === 1 ? '' : 's'} covered by this relief request
                                </p>
                            </div>
                            <button type="button" onClick={() => setConsolidatedLinkedViewer(null)} className="rounded-md border p-2" aria-label="Close linked incidents"><X className="h-4 w-4" /></button>
                        </div>
                        <ul className="mt-4 max-h-80 space-y-2 overflow-y-auto">
                            {consolidatedLinkedViewer.incidents.map((incident) => {
                                const areas = normalizeAffectedAreas(incident);
                                return (
                                    <li key={incident.series_key || incident.incident_code} className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-zinc-700 dark:bg-zinc-950">
                                        <p className="font-black text-slate-900 dark:text-zinc-100">{incident.incident_name || incident.incident_type || 'Incident'}</p>
                                        <p className="mt-1 font-mono text-[11px] font-bold text-emerald-700">{incident.incident_code}</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {areas.length ? areas.join(', ') : 'No affected areas encoded'}
                                            {incident.affected_families != null ? ` · ${Number(incident.affected_families || 0).toLocaleString()} families` : ''}
                                        </p>
                                    </li>
                                );
                            })}
                        </ul>
                        <button type="button" onClick={() => setConsolidatedLinkedViewer(null)} className="mt-4 w-full rounded-md bg-violet-700 px-4 py-2 text-sm font-black text-white">Close</button>
                    </div>
                </div>
            )}

            {open && (
                <div className="fixed inset-0 z-[80] flex overflow-hidden bg-slate-950/60 backdrop-blur-sm">
                    <form id="lgu-dromic-report-form" onSubmit={submit} className="dromic-fit-modal flex h-dvh min-w-0 w-screen max-w-none flex-col overflow-hidden bg-white shadow-2xl dark:bg-zinc-900">
                        <style>{`
                            .dromic-fit-modal,
                            .dromic-fit-modal * {
                                box-sizing: border-box;
                            }
                            .dromic-fit-modal {
                                inline-size: 100vw !important;
                                width: 100vw !important;
                                max-inline-size: none !important;
                                max-width: none !important;
                                overflow-x: clip;
                                contain: inline-size;
                            }
                            .dromic-fit-modal > *,
                            .dromic-fit-modal section,
                            .dromic-fit-modal form,
                            .dromic-fit-modal article,
                            .dromic-fit-modal header,
                            .dromic-fit-modal footer,
                            .dromic-fit-modal .grid,
                            .dromic-fit-modal .flex,
                            .dromic-fit-modal [class*="space-y-"],
                            .dromic-fit-modal .rounded-lg,
                            .dromic-fit-modal .rounded-md {
                                min-width: 0;
                                max-width: 100% !important;
                                max-inline-size: 100% !important;
                            }
                            .dromic-fit-modal table {
                                width: 100%;
                                max-width: 100%;
                                table-layout: fixed;
                            }
                            .dromic-fit-modal table:not(.dromic-wide-table) {
                                min-width: 0 !important;
                            }
                            .dromic-fit-modal .dromic-wide-table {
                                width: max-content;
                                min-width: 100%;
                                max-width: none;
                                table-layout: auto;
                            }
                            .dromic-fit-modal .dromic-table-scroll {
                                width: 100%;
                                min-width: 0;
                                max-width: 100%;
                                max-inline-size: 100%;
                                display: block;
                                overflow-x: auto;
                                overflow-y: visible;
                                overscroll-behavior-x: contain;
                                contain: inline-size;
                            }
                            .dromic-fit-modal .dromic-table-scroll > table {
                                margin: 0;
                            }
                            .dromic-fit-modal .dromic-modal-body {
                                inline-size: 100%;
                                max-inline-size: 100%;
                                overflow-x: clip;
                                contain: inline-size;
                            }
                            .dromic-fit-modal .dromic-table-fit {
                                width: 100%;
                                min-width: 0;
                                max-width: 100%;
                                max-inline-size: 100%;
                                overflow-x: hidden;
                                overflow-y: visible;
                                contain: inline-size;
                            }
                            .dromic-fit-modal .dromic-table-fit table {
                                width: 100% !important;
                                min-width: 0 !important;
                                max-width: 100% !important;
                                table-layout: fixed;
                            }
                            .dromic-fit-modal .dromic-table-fit th,
                            .dromic-fit-modal .dromic-table-fit td {
                                max-width: 1px;
                            }
                            .dromic-fit-modal .dromic-control-cell {
                                overflow: visible;
                                min-width: 10rem;
                                width: auto;
                            }
                            .dromic-fit-modal .dromic-table-fit td.dromic-control-cell {
                                max-width: none;
                            }
                            .dromic-fit-modal .dromic-control-cell,
                            .dromic-fit-modal .dromic-control-cell * {
                                max-width: 100%;
                            }
                            .dromic-fit-modal .dromic-control-cell .dromic-select-control,
                            .dromic-fit-modal .dromic-control-cell input,
                            .dromic-fit-modal .dromic-control-cell select {
                                min-width: 9rem;
                            }
                            .dromic-fit-modal .dromic-control-cell .dromic-select-control {
                                width: 100%;
                                min-width: 100%;
                            }
                            .dromic-fit-modal .dromic-control-cell textarea {
                                min-width: 12rem;
                            }
                            .dromic-fit-modal .dromic-select-control {
                                min-width: 0 !important;
                                max-width: 100% !important;
                                overflow: hidden;
                            }
                            .dromic-fit-modal .dromic-select-value {
                                min-width: 0 !important;
                                max-width: 100% !important;
                                flex: 1 1 auto;
                                overflow: hidden !important;
                                text-overflow: ellipsis !important;
                                white-space: nowrap !important;
                                word-break: normal !important;
                                overflow-wrap: normal !important;
                            }
                            .dromic-fit-modal select,
                            .dromic-fit-modal .dromic-native-select {
                                overflow: hidden !important;
                                text-overflow: ellipsis !important;
                                white-space: nowrap !important;
                            }
                            .dromic-fit-modal .dromic-table-fit input,
                            .dromic-fit-modal .dromic-table-fit textarea,
                            .dromic-fit-modal .dromic-table-fit select {
                                min-width: 0 !important;
                                width: 100%;
                            }
                            .dromic-fit-modal .dromic-table-fit button {
                                min-width: 0 !important;
                            }
                            .dromic-fit-modal .dromic-table-fit th:first-child,
                            .dromic-fit-modal .dromic-table-fit td:first-child {
                                box-sizing: border-box;
                                width: 3rem !important;
                                min-width: 3rem !important;
                                max-width: 3rem !important;
                                padding-left: .25rem !important;
                                padding-right: .25rem !important;
                                white-space: normal;
                            }
                            .dromic-fit-modal button {
                                white-space: normal;
                                overflow-wrap: anywhere;
                                overflow: hidden;
                                line-height: 1.15;
                            }
                            .dromic-fit-modal button > span,
                            .dromic-fit-modal .inline-flex > span {
                                min-width: 0;
                                max-width: 100%;
                                overflow-wrap: anywhere;
                            }
                            .dromic-fit-modal th,
                            .dromic-fit-modal td {
                                min-width: 0;
                                overflow-wrap: normal;
                                word-break: keep-all;
                                hyphens: none;
                            }
                            .dromic-fit-modal .break-words {
                                overflow-wrap: normal !important;
                                word-break: keep-all !important;
                            }
                            .dromic-fit-modal th {
                                white-space: normal;
                                line-height: 1.15;
                                font-size: clamp(0.64rem, 0.72vw, 0.82rem);
                            }
                            .dromic-fit-modal td {
                                white-space: normal;
                                line-height: 1.2;
                            }
                            .dromic-fit-modal .dromic-nowrap {
                                white-space: nowrap !important;
                                word-break: keep-all !important;
                                overflow-wrap: normal !important;
                            }
                            .dromic-fit-modal .dromic-footer-label {
                                white-space: nowrap !important;
                                word-break: keep-all !important;
                                overflow-wrap: normal !important;
                                font-size: clamp(0.62rem, 0.7vw, 0.78rem);
                                letter-spacing: -0.01em;
                            }
                            .dromic-fit-modal input,
                            .dromic-fit-modal textarea,
                            .dromic-fit-modal select,
                            .dromic-fit-modal button {
                                min-width: 0;
                                max-width: 100%;
                            }
                            .dromic-fit-modal input,
                            .dromic-fit-modal textarea,
                            .dromic-fit-modal select {
                                width: 100%;
                            }
                            .dromic-fit-modal input[type="checkbox"],
                            .dromic-fit-modal input[type="radio"] {
                                width: auto !important;
                                min-width: 0 !important;
                                max-width: none !important;
                            }
                            .dromic-fit-modal th:last-child,
                            .dromic-fit-modal .nowrap-cell {
                                white-space: nowrap;
                                word-break: normal;
                                overflow-wrap: normal;
                            }
                            .dromic-fit-modal .dromic-section-anchor {
                                scroll-margin-top: 1rem;
                                transition: outline-color .2s ease, box-shadow .2s ease, background-color .2s ease;
                            }
                            .dromic-fit-modal .dromic-section-focus {
                                outline: 3px solid rgba(16, 185, 129, .85);
                                box-shadow: 0 0 0 8px rgba(16, 185, 129, .16);
                                background-color: rgba(236, 253, 245, .75);
                            }
                            @media (max-width: 1366px) {
                                .dromic-fit-modal th,
                                .dromic-fit-modal td {
                                    padding-left: 0.35rem !important;
                                    padding-right: 0.35rem !important;
                                }
                            }
                        `}</style>
                        <div className="sticky top-0 z-10 min-w-0 max-w-full border-b border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                            <div className="flex min-w-0 flex-wrap items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <h2 className="text-xl font-black">{editingReport?.lgu_correction_of_id ? 'Correct Returned Submission' : editingRequestId ? 'Edit Draft DROMIC / Situational Report' : 'Create Report'}</h2>
                                    {editingReport?.lgu_correction_of_id && <p className="mt-1 text-xs font-bold text-rose-700">Correction draft · {editingReport.lgu_correction_target === 'request' ? 'Only encoded request/FNI entries may be changed.' : 'Only DROMIC / SitRep entries may be changed.'} The previously submitted version remains preserved.</p>}
                                    <p className="mt-1 text-sm text-slate-500">Encode the initial incident facts, affected barangays, evacuation center figures, displacement, and damaged houses. More DROMIC sections can be added later.</p>
                                </div>
                                <button type="button" onClick={() => setOpen(false)} className="shrink-0 rounded-md border px-3 py-2 text-sm font-bold">Close</button>
                            </div>
                        </div>
                        <div className="dromic-modal-body min-h-0 min-w-0 max-w-full flex-1 overflow-x-hidden overflow-y-auto p-3 sm:p-5">
                            <div id="dromic-section-incident-information" className="dromic-section-anchor min-w-0 rounded-lg border border-slate-200 p-4 dark:border-zinc-800">
                                <p className="mb-3 text-lg font-black uppercase tracking-wide text-emerald-700">Incident Information</p>
                                <div className="grid min-w-0 gap-4 lg:grid-cols-3">
                                    <LookerMultiSelect
                                        single
                                        disabled={Number(sourceSeriesSummary?.reports_finalized || 0) > 0}
                                        label={requiredLabel('Type of Disaster / Incident')}
                                        value={form.data.incident_type ? [form.data.incident_type] : []}
                                        onApply={(values) => form.setData({
                                            ...form.data,
                                            incident_type: values.at(-1) || '',
                                            incident_type_other: '',
                                            incident_types: values.at(-1) ? [values.at(-1)] : [],
                                        })}
                                        options={(incidentTypes || []).filter((value, index, list) => value && list.indexOf(value) === index).map((value) => ({ value, label: value }))}
                                        placeholder="Search disaster / incident type..."
                                        allLabel="Select disaster / incident type"
                                    />
                                    <Input label="Specify Disaster / Incident" value={form.data.incident_specific_details} onChange={(value) => form.setData('incident_specific_details', value)} disabled={Number(sourceSeriesSummary?.reports_finalized || 0) > 0} placeholder="e.g. Tropical Depression Auring, LPA east of Mindanao" />
                                    <LookerMultiSelect label={requiredLabel('Affected Areas / Barangays')} value={selectedBarangays} onApply={setSelectedBarangays} options={barangayOptions} placeholder="Search affected barangay..." allLabel="Select affected barangays" />
                                    <Input label="Date and Time of Occurrence" type="datetime-local" max={currentDateTimeLimit} value={form.data.occurrence_started_at} onChange={(value) => form.setData('occurrence_started_at', value)} error={error('occurrence_started_at') || error('incident_date') || occurrenceDateTimeIssue} hint={Number(sourceSeriesSummary?.reports_finalized || 0) > 0 ? 'Retained from the first report for this incident series.' : 'Must not be later than the current date and time.'} disabled={Number(sourceSeriesSummary?.reports_finalized || 0) > 0} required />
                                    <Select
                                        label="Status of Incident"
                                        value={form.data.incident_status}
                                        onChange={(value) => form.setData({
                                            ...form.data,
                                            incident_status: value,
                                            incident_ended_at: value === 'Ongoing' ? '' : form.data.incident_ended_at,
                                            report_classification: value === 'Ongoing' ? 'regular' : form.data.report_classification,
                                        })}
                                        options={['Ongoing', 'Ended']}
                                        required
                                    />
                                    {form.data.incident_status !== 'Ongoing' && (
                                        <Input label="Date and Time the Disaster / Incident Ended" type="datetime-local" min={form.data.occurrence_started_at || undefined} max={currentDateTimeLimit} value={form.data.incident_ended_at} onChange={(value) => form.setData('incident_ended_at', value)} error={error('incident_ended_at') || incidentEndedDateTimeIssue} hint={Number(sourceSeriesSummary?.reports_finalized || 0) > 0 && form.data.incident_ended_at ? 'Retained from the report where the incident was first marked ended.' : 'Must be on or after occurrence and not later than the current time.'} disabled={Number(sourceSeriesSummary?.reports_finalized || 0) > 0 && Boolean(form.data.incident_ended_at)} required={form.data.incident_status === 'Ended'} />
                                    )}
                                    <Input
                                        label="Date and Time Information Was Received / Gathered"
                                        type="datetime-local"
                                        min={form.data.occurrence_started_at || undefined}
                                        max={currentDateTimeLimit}
                                        value={form.data.information_received_at}
                                        onChange={(value) => form.setData('information_received_at', value)}
                                        error={error('information_received_at') || informationReceivedDateTimeIssue}
                                        hint={Number(sourceSeriesSummary?.reports_finalized || 0) > 0
                                            ? 'Retained from the first report for this incident series.'
                                            : 'Must be on or after occurrence and not later than the current time.'}
                                        disabled={Number(sourceSeriesSummary?.reports_finalized || 0) > 0}
                                        required
                                    />
                                    <Input label="DROMIC Reporter / Reported By" value={form.data.dromic_reporter} onChange={(value) => form.setData('dromic_reporter', value)} error={error('requester_name')} required />
                                </div>
                                {form.data.incident_status === 'Ended' && (
                                    <div className="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                                        <p className="text-sm font-black text-blue-950 dark:text-blue-100">Reporting Completion</p>
                                        <p className="mt-1 text-xs leading-5 text-blue-800 dark:text-blue-200">
                                            Choose how this finalized report relates to the incident series. Drafts remain unnumbered and editable.
                                        </p>
                                        <div className="mt-3 grid gap-3 lg:grid-cols-3">
                                            <ReportingChoice
                                                checked={form.data.report_classification === 'regular'}
                                                onChange={() => form.setData('report_classification', 'regular')}
                                                title={sourceSeriesSummary.reports_finalized > 0 ? 'Continuing Report' : 'Initial Report'}
                                                description="Finalize this report while keeping the incident series open for later updates."
                                            />
                                            <ReportingChoice
                                                checked={form.data.report_classification === 'first_and_final'}
                                                disabled={sourceSeriesSummary.reports_finalized > 0}
                                                onChange={() => form.setData('report_classification', 'first_and_final')}
                                                title="First and Final Report"
                                                description="Use when this is the first report and the incident has already ended. This closes the series."
                                            />
                                            <ReportingChoice
                                                checked={form.data.report_classification === 'terminal'}
                                                disabled={sourceSeriesSummary.reports_finalized < 1}
                                                onChange={() => form.setData({
                                                    ...zeroTerminalNowValues(form.data),
                                                    report_classification: 'terminal',
                                                })}
                                                title="Terminal Report"
                                                description="Use after at least one finalized report to formally end further reporting for this incident."
                                            />
                                        </div>
                                        {error('report_classification')}
                                    </div>
                                )}
                            </div>
                            <div className="mt-4 grid min-w-0 max-w-full gap-4">
                                <div id="dromic-section-affected-population" className="dromic-section-anchor min-w-0 max-w-full rounded-lg border border-slate-200 p-4 dark:border-zinc-800">
                                    <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="text-lg font-black uppercase tracking-wide text-emerald-700">Status of Affected Population</p>
                                            <p className="mt-1 text-xs text-slate-500">Every selected barangay appears here. Encode the number of affected families and persons per barangay.</p>
                                        </div>
                                    </div>
                                    <AffectedPopulationTable rows={areaRows} updateAreaRow={updateAreaRow} issues={populationIssues} />
                                    <DromicIssueNotice issues={populationIssues} tableName="Status of Affected Population table" />
                                    {(needsPopulationJustification || form.data.population_justification) && (
                                        <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                <div>
                                                    <p className="text-sm font-black text-amber-950 dark:text-amber-100">Justification for Exceeding PSA 2024 Population <span className="text-rose-600">*</span></p>
                                                    <p className="mt-1 text-xs font-semibold text-amber-800 dark:text-amber-200">
                                                        Type the real LGU-verified reason first. AI Roger will only polish it if the text already explains why the reported affected persons exceeded the PSA 2024 population.
                                                    </p>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={polishPopulationJustification}
                                                    disabled={justificationAiBusy}
                                                    className="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-amber-700 px-3 py-2 text-xs font-black text-white hover:bg-amber-800 disabled:opacity-60"
                                                >
                                                    <Sparkles className="h-4 w-4" />
                                                    {justificationAiBusy ? 'Checking...' : 'Check & Polish'}
                                                </button>
                                            </div>
                                            <textarea
                                                rows={4}
                                                className="mt-3 w-full rounded-md border-2 border-amber-300 bg-white px-3 py-2 font-bold text-slate-950 placeholder:text-slate-400 focus:border-amber-600 focus:ring-2 focus:ring-amber-300 dark:bg-zinc-950 dark:text-white"
                                                value={form.data.population_justification ?? ''}
                                                onChange={(event) => {
                                                    form.setData('population_justification', event.target.value);
                                                    setValidationNotice('');
                                                    setJustificationAiMessage('');
                                                }}
                                                placeholder="Example: The affected count includes verified boarders, renters, visitors, or displaced persons staying in the barangay during the incident..."
                                                required={needsPopulationJustification}
                                            />
                                            {error('population_justification')}
                                            {justificationAiMessage && (
                                                <p className={`mt-3 rounded-md border p-3 text-sm font-bold ${justificationAiMessage.toLowerCase().includes('polished') ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-300 bg-white text-amber-900'}`}>
                                                    {justificationAiMessage}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </div>

                                <div id="dromic-section-displaced-population" className="dromic-section-anchor min-w-0 max-w-full rounded-lg border border-slate-200 p-4 dark:border-zinc-800">
                                    <div id="dromic-section-inside-ec" className="dromic-section-anchor mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="text-lg font-black uppercase tracking-wide text-emerald-700">Status of Displaced Population</p>
                                            <h3 className="mt-1 text-base font-black uppercase tracking-wide">Inside Evacuation Centers</h3>
                                            <p className="mt-1 text-sm text-slate-500">
                                                Add each activated evacuation center. Encode 0 if there is no count. Age/sex and sectoral data are required because they help DRMD quickly identify urgent needs of children, older persons, pregnant or lactating women, PWDs, and other vulnerable groups.
                                            </p>
                                        </div>
                                        <div className="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2">
                                            {!isSectionNotApplicable('inside_ec') && (
                                                <button type="button" disabled={!canAddEvacuationCenter} onClick={addEvacuationCenter} className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-xs font-black text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600">
                                                    <Plus className="h-4 w-4" /> Add Evacuation Center
                                                </button>
                                            )}
                                            <NotApplicableToggle checked={isSectionNotApplicable('inside_ec')} onChange={() => toggleSectionNotApplicable('inside_ec')} />
                                        </div>
                                    </div>
                                    {isSectionNotApplicable('inside_ec') ? (
                                        <NotApplicableNotice section="Inside Evacuation Centers" />
                                    ) : !affectedPopulationReady ? (
                                        <div className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                            Complete the Status of Affected Population first. DROMIS will unlock Inside Evacuation Centers only after every selected barangay has actual affected families and affected persons encoded. All-zero affected population cannot proceed to EC encoding.
                                        </div>
                                    ) : (
                                        <>
                                            <InsideEvacuationCentersTable
                                                rows={evacuationCenterRows}
                                                barangayOptions={barangayOptions}
                                                affectedOriginOptions={areaRows.map((row) => ({
                                                    value: row.area,
                                                    label: row.area,
                                                    code: row.psgc_code,
                                                }))}
                                                currentLguName={lguProfile?.name || 'Current LGU'}
                                                psgcOptions={psgcOptions}
                                                updateRow={updateEvacuationCenter}
                                                requestRemoveRow={setPendingDeleteEcIndex}
                                                openDisaggregation={setDisaggregationModalIndex}
                                                issues={evacuationIssues}
                                            />
                                        </>
                                    )}
                                    {affectedPopulationReady && !isSectionNotApplicable('inside_ec') && <DromicIssueNotice issues={evacuationIssues} tableName="Inside Evacuation Centers table" />}

                                    <div id="dromic-section-outside-ec" className="dromic-section-anchor mt-6 border-t border-slate-200 pt-5 dark:border-zinc-800">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <h3 className="text-base font-black uppercase tracking-wide">Outside Evacuation Centers</h3>
                                                <p className="mt-1 text-sm text-slate-500">
                                                    Encode displaced families/persons staying outside evacuation centers. Only selected affected barangays are listed here. Enter 0 if none.
                                                </p>
                                            </div>
                                            <NotApplicableToggle checked={isSectionNotApplicable('outside_ec')} onChange={() => toggleSectionNotApplicable('outside_ec')} />
                                        </div>
                                        {isSectionNotApplicable('outside_ec') ? (
                                            <NotApplicableNotice section="Outside Evacuation Centers" />
                                        ) : !affectedPopulationReady ? (
                                            <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                                Complete the Status of Affected Population first before encoding Outside EC displaced population.
                                            </div>
                                        ) : (
                                            <OutsideEvacuationCentersTable
                                                rows={areaRows}
                                                insideEcByOriginCode={insideEcByOriginCode}
                                                updateAreaRow={updateAreaRow}
                                                updateAreaRows={updateAreaRows}
                                                requestRemoveRow={setPendingDeleteOutsideEcIndex}
                                                issues={outsideEcIssues}
                                            />
                                        )}
                                        {affectedPopulationReady && !isSectionNotApplicable('outside_ec') && <DromicIssueNotice issues={outsideEcIssues} tableName="Outside Evacuation Centers table" />}
                                    </div>

                                    {affectedPopulationReady && !displacedNa && (
                                        <div className="mt-6 grid gap-5 border-t border-slate-200 pt-5 dark:border-zinc-800">
                                            <ReadonlyDisplacementSummaryTable
                                                title="Total Displaced Population (Inside + Outside ECs)"
                                                description="Automatically computed from Inside Evacuation Centers plus Outside Evacuation Centers per barangay."
                                                rows={displacedPopulationRows}
                                                emptyMessage="No displaced population has been encoded yet. This is acceptable if the affected barangays have affected families/persons but no IDPs inside or outside evacuation centers."
                                            />
                                            <ReadonlyDisplacementSummaryTable
                                                title="Non-IDPs (Affected - Total Displaced Population)"
                                                description="Automatically computed by subtracting total displaced population from affected population per barangay."
                                                rows={nonIdpRows}
                                                emptyMessage="No Non-IDPs to display. Based on the current entries, the affected population has already been fully accounted for as displaced population."
                                            />
                                        </div>
                                    )}
                                </div>

                                <div id="dromic-section-damaged-houses" className="dromic-section-anchor min-w-0 max-w-full rounded-lg border border-slate-200 p-4 dark:border-zinc-800">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p className="text-lg font-black uppercase tracking-wide text-emerald-700">Status of Damaged Houses</p>
                                            <p className="mt-1 text-sm text-slate-500">
                                                Add only affected barangays with reported damaged houses. Encode 0 if the barangay was checked but no totally or partially damaged houses were validated.
                                            </p>
                                        </div>
                                        <NotApplicableToggle checked={isSectionNotApplicable('damaged_houses')} onChange={() => toggleSectionNotApplicable('damaged_houses')} />
                                    </div>
                                    {isSectionNotApplicable('damaged_houses') ? (
                                        <NotApplicableNotice section="Status of Damaged Houses" />
                                    ) : !affectedPopulationReady ? (
                                        <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                            Complete the Status of Affected Population first before encoding damaged houses.
                                        </div>
                                    ) : (
                                        <DamagedHousesTable
                                            rows={areaRows}
                                            updateAreaRow={updateAreaRow}
                                            updateAreaRows={updateAreaRows}
                                            requestRemoveRow={setPendingDeleteDamagedHouseIndex}
                                            issues={damagedHouseIssues}
                                        />
                                    )}
                                    {affectedPopulationReady && !isSectionNotApplicable('damaged_houses') && <DromicIssueNotice issues={damagedHouseIssues} tableName="Status of Damaged Houses table" />}
                                </div>

                                <div id="dromic-section-assistance" className="dromic-section-anchor min-w-0 max-w-full rounded-lg border border-slate-200 p-4 dark:border-zinc-800">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="text-lg font-black uppercase tracking-wide text-emerald-700">Cost of Assistance Provided</p>
                                            <p className="mt-1 text-sm text-slate-500">
                                                Encode assistance already provided per affected barangay. Add one or more assistance items under each barangay so DROMIS can total assistance by location and prevent over-counting served families.
                                            </p>
                                        </div>
                                        <NotApplicableToggle checked={isSectionNotApplicable('assistance')} onChange={() => toggleSectionNotApplicable('assistance')} />
                                    </div>
                                    {isSectionNotApplicable('assistance') ? (
                                        <NotApplicableNotice section="Cost of Assistance Provided" />
                                    ) : !affectedPopulationReady ? (
                                        <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                            Complete the Status of Affected Population first before encoding assistance provided.
                                        </div>
                                    ) : (
                                        <BarangayAssistanceProvidedTable
                                            rows={assistanceRows}
                                            areaRows={areaRows}
                                            addRow={addAssistanceRow}
                                            updateRow={updateAssistanceRow}
                                            requestRemoveRow={setPendingDeleteAssistanceIndex}
                                            issues={assistanceIssues}
                                        />
                                    )}
                                    {affectedPopulationReady && !isSectionNotApplicable('assistance') && <DromicIssueNotice issues={assistanceIssues} tableName="Cost of Assistance Provided table" />}
                                </div>

                                <AdditionalDromicSections
                                    form={form}
                                    lguProfile={lguProfile}
                                    areaRows={areaRows}
                                    barangayOptions={barangayOptions}
                                    incidentTypes={incidentTypes}
                                    affectedPopulationReady={affectedPopulationReady}
                                    relatedIncidentRows={relatedIncidentRows}
                                    casualtyRows={casualtyRows}
                                    infrastructureDamageRows={infrastructureDamageRows}
                                    agricultureDamageRows={agricultureDamageRows}
                                    classSuspensionRows={classSuspensionRows}
                                    workSuspensionRows={workSuspensionRows}
                                    roadBridgeRows={roadBridgeRows}
                                    powerLifelineRows={powerLifelineRows}
                                    waterLifelineRows={waterLifelineRows}
                                    communicationLifelineRows={communicationLifelineRows}
                                    seaportRows={seaportRows}
                                    airportRows={airportRows}
                                    landTransportTerminalRows={landTransportTerminalRows}
                                    strandedTransportRows={strandedTransportRows}
                                    calamityDeclarationRows={calamityDeclarationRows}
                                    preemptiveEvacuationRows={preemptiveEvacuationRows}
                                    clusterGapRows={clusterGapRows}
                                    responseActionRows={responseActionRows}
                                    photoDocumentationRows={photoDocumentationRows}
                                    photoCollageRows={photoCollageRows}
                                    addSupportingRow={addSupportingRow}
                                    updateSupportingRow={updateSupportingRow}
                                    requestRemoveRow={setPendingDeleteSupportingRow}
                                    isSectionNotApplicable={isSectionNotApplicable}
                                    toggleSectionNotApplicable={toggleSectionNotApplicable}
                                    issues={{
                                        relatedIncidentIssues,
                                        casualtyIssues,
                                        infrastructureDamageIssues,
                                        agricultureDamageIssues,
                                        classSuspensionIssues,
                                        workSuspensionIssues,
                                        roadBridgeIssues,
                                        powerLifelineIssues,
                                        waterLifelineIssues,
                                        communicationLifelineIssues,
                                        seaportIssues,
                                        airportIssues,
                                        landTransportTerminalIssues,
                                        strandedTransportIssues,
                                        calamityDeclarationIssues,
                                        preemptiveEvacuationIssues,
                                        clusterGapIssues,
                                        responseActionIssues,
                                        photoDocumentationIssues,
                                    }}
                                />

                                <DromicSectionCard id="dromic-section-relief-request">
                                    <div className="min-w-0">
                                        <p className="text-lg font-black uppercase tracking-wide text-emerald-700">FNI needs for this incident</p>
                                        <p className="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                                            Encode the Food and Non-Food Items this incident needs for its affected families. You can do this without filing a request letter. If you later create a lump request, these quantities are totaled automatically.
                                        </p>
                                    </div>
                                    <div className="mt-4 space-y-4">
                                            <div className="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold leading-6 text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">
                                                {Number(totalAffectedFamilies || 0) > 0
                                                    ? `New item quantities start from ${Number(totalAffectedFamilies).toLocaleString()} affected families. Change a quantity if this incident needs more or less.`
                                                    : 'Encode affected families first if you want quantities to start from that count. You can still select items and type quantities now.'}
                                            </div>
                                            <LookerMultiSelect
                                                label="Food and Non-Food Items (FNI) needed"
                                                options={requestedFniOptions}
                                                value={requestedFniIds}
                                                onApply={setRequestedFniSelection}
                                                placeholder="Search FNI item name..."
                                                allLabel="Select one or more FNI items"
                                            />
                                            {error('requested_fni_items')}
                                            {requestedFniItems.length > 0 && (
                                                <div className="overflow-hidden rounded-md border border-slate-200 dark:border-zinc-800">
                                                    <div className="grid grid-cols-[minmax(0,1fr)_minmax(150px,220px)] gap-3 bg-slate-50 px-4 py-2 text-xs font-black uppercase tracking-wide text-slate-600 dark:bg-zinc-900 dark:text-zinc-300">
                                                        <span>Selected FNI item</span>
                                                        <span>Needed quantity</span>
                                                    </div>
                                                    <div className="divide-y divide-slate-200 dark:divide-zinc-800">
                                                        {requestedFniItems.map((row, index) => {
                                                            const item = fniLibraryItems.find((candidate) => String(candidate.id) === String(row.fni_library_item_id));

                                                            return (
                                                                <div key={row.fni_library_item_id} className="grid grid-cols-[minmax(0,1fr)_minmax(150px,220px)] items-center gap-3 px-4 py-3">
                                                                    <div className="min-w-0">
                                                                        <p className="font-black text-slate-900 dark:text-zinc-100">{item?.item_name || `FNI item ${row.fni_library_item_id}`}</p>
                                                                        <p className="mt-0.5 text-xs text-slate-500">{[item?.item_category, item?.brand_description, item?.unit_of_measure ? `Unit: ${item.unit_of_measure}` : null].filter(Boolean).join(' · ') || '-'}</p>
                                                                    </div>
                                                                    <div>
                                                                        <input
                                                                            type="text"
                                                                            min="1"
                                                                            inputMode="numeric"
                                                                            pattern="[0-9]*"
                                                                            className="w-full"
                                                                            value={wholeQuantityInputValue(row.requested_quantity)}
                                                                            onInput={(event) => setRequestedFniQuantity(row.fni_library_item_id, event.target.value)}
                                                                            onChange={(event) => setRequestedFniQuantity(row.fni_library_item_id, event.target.value)}
                                                                            placeholder={item?.unit_of_measure ? `No. of ${item.unit_of_measure}` : 'Number of items'}
                                                                        />
                                                                        {error(`requested_fni_items.${index}.requested_quantity`)}
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            )}
                                        <div className="rounded-md border border-slate-200 bg-slate-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                                            <label className="flex cursor-pointer items-start gap-3 text-sm font-black text-slate-800 dark:text-zinc-100">
                                                <input
                                                    type="checkbox"
                                                    className="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                                                    checked={withReliefRequest}
                                                    onChange={(event) => form.setData('has_relief_request', event.target.checked)}
                                                />
                                                <span>
                                                    File a single-incident request with this report
                                                    <span className="mt-1 block text-sm font-semibold text-slate-500 dark:text-zinc-400">
                                                        Leave this off for DROMIC encoding and for later lump requests. Tick it only if you will submit a formal request letter to DRMD for this incident only.
                                                    </span>
                                                </span>
                                            </label>
                                            {withReliefRequest && (
                                                <p className="mt-3 text-sm font-semibold leading-6 text-sky-900 dark:text-sky-100">
                                                    This incident will not appear in Create Relief Request. Upload a signed request letter with this report after finalizing.
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </DromicSectionCard>

                                <OfficialAdvisorySection
                                    form={form}
                                    incidentType={form.data.incident_type}
                                    incidentName={form.data.incident_name}
                                    incidentDetails={form.data.incident_specific_details}
                                    incidentDate={form.data.occurrence_started_at || form.data.incident_date || defaultIncidentDate}
                                    province={form.data.province || provinceFromProfile()}
                                    municipality={form.data.municipality || lguProfile?.name}
                                    isNa={isSectionNotApplicable('advisory_screenshots')}
                                    toggleNa={() => toggleSectionNotApplicable('advisory_screenshots')}
                                    onExtractionStateChange={setAdvisoryExtractionBusy}
                                />

                                <DromicSectionCard>
                                    <div className="rounded-md border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p className="text-lg font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-100">Situation Overview</p>
                                                <p className="text-sm text-emerald-800 dark:text-emerald-200">Groq writes as the reporting LGU employee, using only relevant incident conditions, verified effects, and response actions encoded in this form.</p>
                                            </div>
                                            <div className="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2">
                                                <button type="button" disabled={aiBusy || !situationOverviewAiReady} onClick={() => enhanceNarrative('generate')} className="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-xs font-black text-white disabled:cursor-not-allowed disabled:opacity-45"><Bot className="h-4 w-4" /> Auto-generate</button>
                                                <button type="button" disabled={aiBusy || !situationOverviewAiReady} onClick={() => enhanceNarrative('polish')} className="inline-flex items-center gap-2 rounded-md bg-brand-600 px-3 py-2 text-xs font-black text-white disabled:cursor-not-allowed disabled:opacity-45"><Sparkles className="h-4 w-4" /> Polish</button>
                                            </div>
                                        </div>
                                        {!situationOverviewAiReady && (
                                            <p className="mt-3 text-sm font-bold text-amber-800">
                                                AI becomes available after every preceding section is completed or marked N/A. Pending: {pendingAiSections.slice(0, 5).join(', ')}{pendingAiSections.length > 5 ? ', and others' : ''}.
                                            </p>
                                        )}
                                        {aiMessage && <p className="mt-3 text-sm font-bold text-emerald-900 dark:text-emerald-100">{aiMessage}</p>}
                                    </div>
                                    <div className="mt-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-300">
                                        <p className="font-black text-slate-900 dark:text-white">Expected narrative flow</p>
                                        <p className="mt-1 leading-6">
                                            Describe the current local situation first, then explain its verified effects and the LGU's response. Groq will produce at least two distinct paragraphs and add a third only when the available data supports a separate, useful topic. Reporting timestamps and administrative form details are omitted.
                                        </p>
                                    </div>
                                    <div className="mt-4 grid min-w-0 max-w-full gap-4">
                                        <Textarea label="Situation Overview" rows={16} value={form.data.narrative} onChange={(value) => form.setData('narrative', value)} error={error('narrative')} required />
                                    </div>
                                </DromicSectionCard>
                            </div>
                        </div>
                        <div className="sticky bottom-0 flex min-w-0 max-w-full flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                            <div className="min-w-0 flex-1 flex-wrap items-center gap-3 sm:flex">
                                <button type="button" onClick={() => setProgressOpen((value) => !value)} className="inline-flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-black uppercase tracking-wide text-emerald-800 shadow-sm hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100">
                                    <ListChecks className="h-4 w-4" />
                                    Report Progress
                                    <span className="rounded-full bg-emerald-700 px-2 py-0.5 text-xs text-white">{progressPercent}%</span>
                                    {progressOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronUp className="h-4 w-4" />}
                                </button>
                                {validationNotice && (
                                    <div className="mt-2 max-w-full rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-100 sm:mt-0 sm:max-w-xl">
                                        {validationNotice}
                                    </div>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={() => {
                                    setDraftPreviewZoom(1);
                                    setDraftPreviewTab('narrative');
                                    setDraftPreviewOpen(true);
                                }}
                                className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-black text-slate-700"
                                title="Preview the LGU narrative report from the values currently in this form"
                            >
                                <Eye className="h-4 w-4" /> Preview Report
                            </button>
                            <button type="button" onClick={() => setOpen(false)} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button>
                            <button type="submit" name="submission_status" value="draft" disabled={form.processing} className="rounded-md border border-amber-200 bg-amber-50 px-5 py-2 text-sm font-black text-amber-900 hover:bg-amber-100 disabled:opacity-60 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                {form.processing ? 'Saving...' : 'Save as Draft'}
                            </button>
                            <button id="save-final-report-button" type="submit" name="submission_status" value="final" disabled={form.processing || populationIssues.length > 0 || (!isSectionNotApplicable('inside_ec') && evacuationIssues.length > 0) || (!isSectionNotApplicable('outside_ec') && outsideEcIssues.length > 0) || (!isSectionNotApplicable('damaged_houses') && damagedHouseIssues.length > 0) || (!isSectionNotApplicable('assistance') && assistanceIssues.length > 0) || supportingSectionIssues.length > 0} className="rounded-md bg-emerald-700 px-5 py-2 text-sm font-black text-white disabled:opacity-60">
                                {form.processing
                                    ? 'Saving...'
                                    : form.data.report_classification === 'terminal'
                                        ? 'Finalize Terminal Report'
                                        : form.data.report_classification === 'first_and_final'
                                            ? 'Finalize First and Final Report'
                                            : 'Save as Final'}
                            </button>
                        </div>
                    </form>
                    <FloatingDromicProgressCard
                        open={progressOpen}
                        onToggle={() => setProgressOpen((value) => !value)}
                        percent={progressPercent}
                        items={sectionProgressItems}
                        onJump={jumpToDromicSection}
                    />
                    {disaggregationModalIndex !== null && evacuationCenterRows[disaggregationModalIndex] && (
                        <DisaggregationModal
                            row={evacuationCenterRows[disaggregationModalIndex]}
                            index={disaggregationModalIndex}
                            onClose={() => setDisaggregationModalIndex(null)}
                            updateDisaggregation={updateDisaggregation}
                        />
                    )}
                    {pendingDeleteEcIndex !== null && evacuationCenterRows[pendingDeleteEcIndex] && (
                        <ConfirmDeleteModal
                            title="Remove Evacuation Center Row?"
                            message={`This will remove ${evacuationCenterRows[pendingDeleteEcIndex].evacuation_center || `Evacuation Center ${pendingDeleteEcIndex + 1}`} and its encoded disaggregated data.`}
                            onCancel={() => setPendingDeleteEcIndex(null)}
                            onConfirm={() => {
                                removeEvacuationCenter(pendingDeleteEcIndex);
                                setPendingDeleteEcIndex(null);
                            }}
                        />
                    )}
                    {pendingDeleteOutsideEcIndex !== null && areaRows[pendingDeleteOutsideEcIndex] && (
                        <ConfirmDeleteModal
                            title="Remove Outside EC Row?"
                            message={`This will remove the Outside EC entry for ${areaRows[pendingDeleteOutsideEcIndex].area || `Barangay ${pendingDeleteOutsideEcIndex + 1}`}.`}
                            onCancel={() => setPendingDeleteOutsideEcIndex(null)}
                            onConfirm={() => removeOutsideEcRow(pendingDeleteOutsideEcIndex)}
                        />
                    )}
                    {pendingDeleteDamagedHouseIndex !== null && areaRows[pendingDeleteDamagedHouseIndex] && (
                        <ConfirmDeleteModal
                            title="Remove Damaged Houses Row?"
                            message={`This will remove the damaged houses entry for ${areaRows[pendingDeleteDamagedHouseIndex].area || `Barangay ${pendingDeleteDamagedHouseIndex + 1}`}.`}
                            onCancel={() => setPendingDeleteDamagedHouseIndex(null)}
                            onConfirm={() => removeDamagedHouseRow(pendingDeleteDamagedHouseIndex)}
                        />
                    )}
                    {pendingDeleteAssistanceIndex !== null && assistanceRows[pendingDeleteAssistanceIndex] && (
                        <ConfirmDeleteModal
                            title="Remove Assistance Row?"
                            message={`This will remove ${assistanceRows[pendingDeleteAssistanceIndex].particular || `Assistance Row ${pendingDeleteAssistanceIndex + 1}`} from ${assistanceRows[pendingDeleteAssistanceIndex].barangay || 'Cost of Assistance Provided'}.`}
                            onCancel={() => setPendingDeleteAssistanceIndex(null)}
                            onConfirm={() => removeAssistanceRow(pendingDeleteAssistanceIndex)}
                        />
                    )}
                    {pendingDeleteSupportingRow !== null && (
                        <ConfirmDeleteModal
                            title="Remove DROMIC/SitRep Row?"
                            message={`This will remove ${pendingDeleteSupportingRow.label || 'this additional DROMIC/SitRep detail row'}.`}
                            onCancel={() => setPendingDeleteSupportingRow(null)}
                            onConfirm={() => removeSupportingRow(pendingDeleteSupportingRow)}
                        />
                    )}
                    {pendingZeroNowEcIndex !== null && evacuationCenterRows[pendingZeroNowEcIndex] && (
                        <ConfirmZeroNowModal
                            evacuationCenter={evacuationCenterRows[pendingZeroNowEcIndex].evacuation_center || `Evacuation Center ${pendingZeroNowEcIndex + 1}`}
                            onCancel={() => setPendingZeroNowEcIndex(null)}
                            onConfirm={() => applyZeroNowDisaggregation(pendingZeroNowEcIndex)}
                        />
                    )}
                    <DocumentPreviewModal
                        open={draftPreviewOpen}
                        onClose={() => setDraftPreviewOpen(false)}
                        zIndexClass="z-[195]"
                        eyebrow="Document preview"
                        badge="Draft / local preview"
                        title="LGU Narrative Report"
                        subtitle={draftPreviewTab === 'encoded'
                            ? 'Encoded worksheet structure from live form values'
                            : 'Official DomPDF layout of the LGU DROMIC / Situational Report (not a signed PDF)'}
                        notice={draftPreviewTab === 'encoded'
                            ? 'Encoded data view only — switch to Narrative Report for the official PDF layout.'
                            : 'Draft PDF preview only — finalize to generate the official advance PDF.'}
                        tabs={[
                            { id: 'narrative', label: 'Narrative Report', icon: FileCheck2 },
                            { id: 'encoded', label: 'Encoded Data', icon: ListChecks },
                        ]}
                        activeTab={draftPreviewTab}
                        onTabChange={setDraftPreviewTab}
                        zoom={draftPreviewZoom}
                        onZoomChange={setDraftPreviewZoom}
                        paperWidth="210mm"
                        usePaperCanvas={draftPreviewTab === 'encoded'}
                    >
                        {draftPreviewTab === 'encoded' ? (
                            <DromicEncodedReportBody
                                report={{
                                    reference_number: editingReport?.reference_number || 'Draft DROMIC / SitRep',
                                    requesting_agency: form.data.requesting_lgu || lguProfile?.name,
                                    municipality: form.data.municipality || lguProfile?.name,
                                    province: form.data.province || lguProfile?.province,
                                    requester: form.data.requester_name || form.data.dromic_reporter,
                                    affected_families: form.data.affected_families,
                                    affected_persons: form.data.affected_persons,
                                    lgu_dromic_report_number: editingReport?.lgu_dromic_report_number || 1,
                                    lgu_dromic_payload: form.data,
                                }}
                            />
                        ) : draftPreviewBusy ? (
                            <div className="flex min-h-[60vh] items-center justify-center p-8 text-sm font-bold text-slate-600">Building narrative report PDF…</div>
                        ) : draftPreviewError ? (
                            <div className="flex min-h-[60vh] items-center justify-center p-8 text-center text-sm font-bold text-rose-700">{draftPreviewError}</div>
                        ) : draftPreviewUrl ? (
                            <iframe
                                title="LGU narrative report PDF preview"
                                src={`${draftPreviewUrl}#toolbar=1&navpanes=0`}
                                className="h-full min-h-[60vh] w-full bg-slate-200"
                            />
                        ) : null}
                    </DocumentPreviewModal>
                </div>
            )}
            {closedReportNowWarningOpen && (
                <div className="fixed inset-0 z-[125] flex items-center justify-center bg-slate-950/70 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl dark:bg-zinc-900">
                        <div className="flex items-start gap-3">
                            <div className="rounded-full bg-amber-100 p-2 text-amber-700"><FileCheck2 className="h-5 w-5" /></div>
                            <div>
                                <h3 className="text-lg font-black">Review nonzero NOW data</h3>
                                <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-zinc-300">
                                    This is a {form.data.report_classification === 'terminal' ? 'Terminal Report' : 'First and Final Report'}, but one or more NOW values are greater than zero.
                                </p>
                            </div>
                        </div>
                        <p className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm font-bold leading-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                            Nonzero NOW values are allowed and do not by themselves constitute a reporting error. Continue only if these are validated current counts that should remain in this closed report.
                        </p>
                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => {
                                    setClosedReportNowWarningOpen(false);
                                    closedReportNowWarningAccepted.current = false;
                                }}
                                className="rounded-md border px-4 py-2 text-sm font-bold"
                            >
                                Review NOW Data
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setClosedReportNowWarningOpen(false);
                                    closedReportNowWarningAccepted.current = true;
                                    document.getElementById('lgu-dromic-report-form')?.requestSubmit(document.getElementById('save-final-report-button'));
                                }}
                                className="rounded-md bg-amber-600 px-4 py-2 text-sm font-black text-white"
                            >
                                Values Are Valid — Continue
                            </button>
                        </div>
                    </div>
                </div>
            )}
            {finalConfirmationOpen && (
                <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/70 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl dark:bg-zinc-900">
                        <div className="flex items-start gap-3">
                            <div className="rounded-full bg-amber-100 p-2 text-amber-700"><FileCheck2 className="h-5 w-5" /></div>
                            <div>
                                <h3 className="text-lg font-black">
                                    {form.data.report_classification === 'terminal'
                                        ? 'Finalize this Terminal Report?'
                                        : form.data.report_classification === 'first_and_final'
                                            ? 'Finalize this First and Final Report?'
                                            : 'Save this report as final?'}
                                </h3>
                                <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-zinc-300">
                                    Finalizing permanently locks the encoded report. You will be taken to a PDF-style preview where you may print or download it, upload the signed report and—when relief augmentation is requested—the signed request letter, then submit it to DSWD.
                                </p>
                            </div>
                        </div>
                        <p className="mt-4 rounded-md bg-slate-50 p-3 text-sm font-bold leading-6 text-slate-700 dark:bg-zinc-800 dark:text-zinc-200">
                            {form.data.report_classification === 'terminal' || form.data.report_classification === 'first_and_final'
                                ? 'This completion type closes the incident series. No additional reports can be created afterward.'
                                : 'This report receives the next sequence number while keeping the incident series open for later updates.'}
                        </p>
                        <div className="mt-6 flex justify-end gap-3">
                            <button type="button" onClick={() => {
                                setFinalConfirmationOpen(false);
                                closedReportNowWarningAccepted.current = false;
                            }} className="rounded-md border px-4 py-2 text-sm font-bold">Continue Editing</button>
                            <button
                                type="button"
                                onClick={() => {
                                    setFinalConfirmationOpen(false);
                                    finalConfirmationAccepted.current = true;
                                    document.getElementById('lgu-dromic-report-form')?.requestSubmit(document.getElementById('save-final-report-button'));
                                }}
                                className="rounded-md bg-emerald-700 px-4 py-2 text-sm font-black text-white"
                            >
                                Confirm and Finalize
                            </button>
                        </div>
                    </div>
                </div>
            )}
            {previewReportId && (
                <div className="fixed inset-0 z-[110] flex flex-col bg-slate-950/75 p-2 backdrop-blur-sm sm:p-4">
                    <div className="mx-auto flex h-full w-full max-w-7xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-900">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                            <div>
                                <p className="text-xs font-black uppercase tracking-wide text-emerald-700">{previewMode === 'request' && previewIncludesRequestLetter ? 'Request Letter Preview' : previewMode === 'incident' && bothDocumentsValidated ? 'Combined Incident Documents Preview' : previewMode === 'incident' ? 'Incident Document Preview' : 'DROMIC / SitRep Preview'}</p>
                                <h3 className="font-black">{previewMode === 'request' && previewIncludesRequestLetter ? (previewReport?.lgu_relief_request_reference || 'Request Letter') : previewReport?.reference_number || 'DROMIC / Situational Report'}</h3>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {previewDocumentKind === 'report' && <button type="button" title={previewControlsCollapsed ? 'Show preview controls' : 'Collapse preview controls for more document space'} aria-label={previewControlsCollapsed ? 'Show preview controls' : 'Collapse preview controls'} onClick={() => setPreviewControlsCollapsed((value) => !value)} className="rounded-md border p-2 text-slate-700">{previewControlsCollapsed ? <ChevronDown className="h-4 w-4" /> : <ChevronUp className="h-4 w-4" />}</button>}
                                {showSignedCopiesPanel && <button type="button" title={previewSidebarCollapsed ? 'Show submission and validation panel' : 'Collapse side panel for a wider document view'} aria-label={previewSidebarCollapsed ? 'Show side panel' : 'Collapse side panel'} onClick={() => setPreviewSidebarCollapsed((value) => !value)} className="rounded-md border p-2 text-slate-700">{previewSidebarCollapsed ? <ChevronLeft className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}</button>}
                                {previewCanExport && previewDocumentKind === 'report' && <button type="button" onClick={printReport} className="inline-flex items-center gap-1.5 rounded-md border px-3 py-2 text-xs font-black"><Printer className="h-4 w-4" /> Print</button>}
                                {previewCanExport && <a href={(previewMode === 'request' || (previewMode === 'incident' && combinedPreviewTab === 'request')) && previewIncludesRequestLetter
                                    ? `/lgu/dromic-sitrep/${previewReportId}/signed-copy/request`
                                    : reportCopyTab === 'signed' && previewReport?.lgu_signed_report_path
                                        ? `/lgu/dromic-sitrep/${previewReportId}/signed-copy/report`
                                        : `/lgu/dromic-sitrep/${previewReportId}/pdf`} className="inline-flex items-center gap-1.5 rounded-md border px-3 py-2 text-xs font-black"><Download className="h-4 w-4" /> Download PDF</a>}
                                <button type="button" onClick={closeDocumentPreview} className="rounded-md border p-2"><X className="h-4 w-4" /></button>
                            </div>
                        </div>
                        {previewDocumentKind === 'report' && !previewControlsCollapsed && <div className="shrink-0 border-b bg-white px-4 py-3 dark:bg-zinc-900">
                            <SectionTabs
                                appearance="plain"
                                value={previewContentTab}
                                onChange={(tab) => {
                                    setPreviewContentTab(tab);
                                    if (tab === 'narrative') setReportCopyTab('advance');
                                }}
                                ariaLabel="Report preview format"
                                tabs={[
                                    { id: 'narrative', label: 'Narrative Report', icon: FileCheck2 },
                                    { id: 'encoded', label: 'Encoded Data', icon: ListChecks },
                                ]}
                            />
                        </div>}
                        <div className={`grid min-h-0 flex-1 ${showSignedCopiesPanel && !previewSidebarCollapsed ? 'lg:grid-cols-[1fr_360px]' : previewMode === 'request' || (previewMode !== 'incident' && previewMode !== 'report' && previewValidationNote) ? 'lg:grid-cols-[1fr_320px]' : 'grid-cols-1'}`}>
                            {previewDocumentKind === 'report' && previewContentTab === 'encoded' ? (
                                <ReadonlyDromicReportModal report={previewReport} embedded />
                            ) : previewMode === 'request' && previewIncludesRequestLetter ? (
                                previewReport?.lgu_signed_request_path ? (
                                    <SignedPdfPreview
                                        src={`/lgu/dromic-sitrep/${previewReportId}/signed-copy/request`}
                                        filename={previewReport?.lgu_signed_request_name || previewReport?.lgu_relief_request_reference || 'Signed request letter.pdf'}
                                        title="Signed request letter preview"
                                    />
                                ) : <div className="flex min-h-[60vh] items-center justify-center p-8 text-center"><div><UploadCloud className="mx-auto h-10 w-10 text-amber-500" /><p className="mt-3 font-black">Signed request letter not yet uploaded</p><p className="mt-1 text-sm text-slate-500">Upload it from the incident preview before opening the request document.</p></div></div>
                            ) : previewMode === 'incident' && previewIncludesRequestLetter && !bothDocumentsValidated && combinedPreviewTab === 'request' ? (
                                previewReport?.lgu_signed_request_path
                                    ? <SignedPdfPreview
                                        src={`/lgu/dromic-sitrep/${previewReportId}/signed-copy/request`}
                                        filename={previewReport?.lgu_signed_request_name || previewReport?.lgu_relief_request_reference || 'Signed request letter.pdf'}
                                        title="Request letter correction preview"
                                    />
                                    : <div className="flex min-h-[60vh] items-center justify-center bg-slate-100 p-8 text-center"><div><UploadCloud className="mx-auto h-10 w-10 text-violet-500" /><p className="mt-3 font-black">Signed request letter pending</p><p className="mt-1 text-sm text-slate-500">Choose the signed request-letter PDF using the upload field on the right.</p></div></div>
                            ) : previewMode === 'incident' && bothDocumentsValidated ? (
                                <div className="flex min-h-0 flex-col bg-slate-100">
                                    {!previewControlsCollapsed && <div className="shrink-0 border-b bg-white p-3 dark:bg-zinc-900">
                                        <SectionTabs
                                            appearance="plain"
                                            value={combinedPreviewTab}
                                            onChange={setCombinedPreviewTab}
                                            ariaLabel="Incident document preview"
                                            tabs={[
                                                { id: 'request', label: 'Request Letter', icon: FilePlus2 },
                                                { id: 'report', label: 'DROMIC / SitRep', icon: FileCheck2 },
                                            ]}
                                        />
                                    </div>}
                                    {combinedPreviewTab === 'request'
                                        ? <SignedPdfPreview
                                            src={`/lgu/dromic-sitrep/${previewReportId}/signed-copy/request`}
                                            filename={previewReport?.lgu_signed_request_name || previewReport?.lgu_relief_request_reference || 'Signed request letter.pdf'}
                                            title="Validated request letter preview"
                                        />
                                        : reportCopyTab === 'signed' && previewReport?.lgu_signed_report_path
                                            ? <SignedPdfPreview
                                                src={`/lgu/dromic-sitrep/${previewReportId}/signed-copy/report`}
                                                filename={previewReport?.lgu_signed_report_name || previewReport?.reference_number || 'Signed DROMIC report.pdf'}
                                                title="Signed DROMIC report preview"
                                            />
                                            : <iframe title="Advance DROMIC report preview" src={`/lgu/dromic-sitrep/${previewReportId}/pdf?inline=1`} className="h-full min-h-[60vh] w-full flex-1" />}
                                </div>
                            ) : <div className="flex min-h-0 flex-col bg-slate-100">
                                {previewReport?.lgu_report_status === 'submitted' && previewReport?.lgu_signed_report_path && !previewControlsCollapsed && (
                                    <div className="flex shrink-0 flex-wrap items-center gap-2 border-b bg-white p-3 dark:bg-zinc-900">
                                        <SectionTabs
                                            label="Document copy"
                                            appearance="plain"
                                            value={reportCopyTab}
                                            onChange={setReportCopyTab}
                                            ariaLabel="Document copy"
                                            tabs={[
                                                { id: 'advance', label: 'Advance Copy', icon: FileCheck2, title: 'View the advance copy generated from the encoded report data' },
                                                { id: 'signed', label: 'Signed Copy', icon: UploadCloud, title: 'View the signed PDF submitted by the LGU' },
                                            ]}
                                        />
                                    </div>
                                )}
                                {reportCopyTab === 'signed' && previewReport?.lgu_signed_report_path
                                    ? <SignedPdfPreview
                                        src={`/lgu/dromic-sitrep/${previewReportId}/signed-copy/report`}
                                        filename={previewReport?.lgu_signed_report_name || previewReport?.reference_number || 'Signed DROMIC report.pdf'}
                                        title="Signed DROMIC report preview"
                                    />
                                    : <iframe title="Advance DROMIC report preview" src={`/lgu/dromic-sitrep/${previewReportId}/pdf?inline=1`} className="h-full min-h-[60vh] w-full flex-1" />}
                            </div>}
                            {showSignedCopiesPanel && !previewSidebarCollapsed && (
                                <aside className="overflow-y-auto border-l p-4">
                                    <StatusBadge row={previewReport} />
                                    <ValidationNoteCard row={previewReport} kind={previewDocumentKind} />
                                    {previewDocumentKind === 'report' && previewReport.lgu_dromic_validation_status === 'needs_lgu_action' && ['encoding', 'both'].includes(previewReport.lgu_dromic_correction_scope) && <button type="button" onClick={() => startCorrectionDraft(previewReport, 'report')} className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md bg-rose-700 px-3 py-2 text-xs font-black text-white"><Edit3 className="h-4 w-4" /> Correct Encoded Report Entries</button>}
                                    {previewDocumentKind === 'request' && previewReport.lgu_relief_validation_status === 'needs_lgu_action' && ['encoding', 'both'].includes(previewReport.lgu_relief_correction_scope) && <button type="button" onClick={() => startCorrectionDraft(previewReport, 'request')} className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md bg-rose-700 px-3 py-2 text-xs font-black text-white"><Edit3 className="h-4 w-4" /> Correct Encoded Request Entries</button>}
                                    {canReopenForRevision(previewReport) && (
                                        <button type="button" disabled={reopenBusy} onClick={() => reopenForRevision(previewReport)} className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-black text-amber-900 disabled:opacity-50">
                                            <Edit3 className="h-4 w-4" /> Revise before submit
                                        </button>
                                    )}
                                    {canRequestAmendment(previewReport, previewDocumentKind === 'request' ? 'request' : 'report') && (
                                        <button type="button" onClick={() => openAmendmentRequest(previewReport, previewDocumentKind === 'request' ? 'request' : 'report')} className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-black text-amber-900">
                                            <Undo2 className="h-4 w-4" /> Request permission to amend
                                        </button>
                                    )}
                                    {previewReport.lgu_amendment_request_status === 'requested' && amendmentTargetOf(previewReport) === (previewDocumentKind === 'request' ? 'request' : 'report') && (
                                        <p className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs font-semibold leading-5 text-amber-950">
                                            Amendment request is pending {previewDocumentKind === 'request' ? 'DRRS' : 'DRIMS'} review. Encoding stays locked until approved.
                                        </p>
                                    )}
                                    {previewReport.lgu_amendment_request_status === 'denied' && amendmentTargetOf(previewReport) === (previewDocumentKind === 'request' ? 'request' : 'report') && (
                                        <p className="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs font-semibold leading-5 text-slate-700">Amendment request was denied{previewReport.lgu_amendment_review_note ? `: ${previewReport.lgu_amendment_review_note}` : '.'}</p>
                                    )}
                                    <h4 className="mt-5 font-black">Signed copies</h4>
                                    <p className="mt-1 text-xs leading-5 text-slate-500">
                                        {previewIsStandaloneRelief
                                            ? 'This consolidated relief request only requires the signed LGU relief augmentation request letter.'
                                            : previewIncludesRequestLetter
                                                ? 'A signed report and a signed LGU relief augmentation request letter are both required for a complete submission.'
                                                : 'A signed report is required for a complete submission. Encoding FNI needs alone does not require a signed request letter.'}
                                    </p>
                                    {signedUploadNotice && (
                                        <p className={`mt-3 rounded-md border px-3 py-2 text-xs font-bold leading-5 ${
                                            signedUploadNotice.type === 'success'
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                                : signedUploadNotice.type === 'info'
                                                    ? 'border-sky-200 bg-sky-50 text-sky-950'
                                                    : 'border-rose-200 bg-rose-50 text-rose-900'
                                        }`}>
                                            {signedUploadNotice.message}
                                        </p>
                                    )}
                                    {!previewIsStandaloneRelief && (
                                        <>
                                            <label className="mt-4 block text-xs font-black">DROMIC signed report (PDF only)</label>
                                            {signedReportLocked
                                                ? <p className="mt-1 rounded-md border border-emerald-200 bg-emerald-50 p-2 text-xs font-bold text-emerald-800"><CheckCircle2 className="mr-1 inline h-3.5 w-3.5" />Locked after signed PDF validation — no findings</p>
                                                : reportReplacementAllowed
                                                    ? <input ref={signedReportInputRef} type="file" accept="application/pdf,.pdf" onChange={(event) => { setSignedUploadErrors((prev) => ({ ...prev, signed_report: undefined })); setSignedUploadNotice(null); setSignedReport(event.target.files?.[0] || null); }} className="mt-1 block w-full text-xs" />
                                                    : <p className="mt-1 rounded-md border border-slate-200 bg-slate-50 p-2 text-xs font-bold text-slate-600">Replacement becomes available only if DSWD marks this report as Needs LGU Action.</p>}
                                            {signedUploadErrors.signed_report && <p className="mt-1 text-xs font-bold text-rose-700">{Array.isArray(signedUploadErrors.signed_report) ? signedUploadErrors.signed_report[0] : signedUploadErrors.signed_report}</p>}
                                            {previewReport.lgu_signed_report_name && <a target="_blank" rel="noreferrer" href={`/lgu/dromic-sitrep/${previewReport.id}/signed-copy/report`} className="mt-1 block text-xs font-bold text-emerald-700 underline">Uploaded: {previewReport.lgu_signed_report_name}</a>}
                                        </>
                                    )}
                                    {previewIncludesRequestLetter && (
                                        <>
                                            <label className="mt-4 block text-xs font-black">Request for Relief Augmentation — PDF only</label>
                                            {signedRequestLocked
                                                ? <p className="mt-1 rounded-md border border-emerald-200 bg-emerald-50 p-2 text-xs font-bold text-emerald-800"><CheckCircle2 className="mr-1 inline h-3.5 w-3.5" />Locked after DRRS validation — no findings</p>
                                                : requestReplacementAllowed
                                                    ? <input ref={signedRequestInputRef} type="file" accept="application/pdf,.pdf" onChange={(event) => { setSignedUploadErrors((prev) => ({ ...prev, signed_request: undefined })); setSignedUploadNotice(null); setSignedRequest(event.target.files?.[0] || null); }} className="mt-1 block w-full text-xs" />
                                                    : <p className="mt-1 rounded-md border border-slate-200 bg-slate-50 p-2 text-xs font-bold text-slate-600">Replacement becomes available only if DRRS marks this request letter as Needs LGU Action.</p>}
                                            {signedUploadErrors.signed_request && <p className="mt-1 text-xs font-bold text-rose-700">{Array.isArray(signedUploadErrors.signed_request) ? signedUploadErrors.signed_request[0] : signedUploadErrors.signed_request}</p>}
                                            {previewReport.lgu_signed_request_name && <a target="_blank" rel="noreferrer" href={`/lgu/dromic-sitrep/${previewReport.id}/signed-copy/request`} className="mt-1 block text-xs font-bold text-emerald-700 underline">Uploaded: {previewReport.lgu_signed_request_name}</a>}
                                        </>
                                    )}
                                    <button
                                        type="button"
                                        disabled={submissionBusy || (previewIsStandaloneRelief ? !signedRequest : (!signedReport && !(previewIncludesRequestLetter && signedRequest))) || (signedReportLocked && Boolean(signedReport)) || (signedRequestLocked && Boolean(signedRequest))}
                                        onClick={uploadSignedCopies}
                                        className={`mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md px-3 py-2 text-xs font-black disabled:cursor-not-allowed disabled:opacity-50 ${
                                            !submissionBusy && (previewIsStandaloneRelief ? Boolean(signedRequest) : (signedReport || (previewIncludesRequestLetter && signedRequest)))
                                                ? 'bg-emerald-700 text-white hover:bg-emerald-800'
                                                : 'border border-emerald-200 bg-emerald-50 text-emerald-800'
                                        }`}
                                    >
                                        <UploadCloud className="h-4 w-4" /> {submissionBusy ? 'Uploading…' : 'Upload Selected PDF'}
                                    </button>
                                    {previewReport.lgu_report_status === 'final' && (
                                        <div className="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                            <p className="text-xs leading-5 text-amber-900">
                                                {previewIsStandaloneRelief
                                                    ? 'You may submit this consolidated relief request to DSWD now. The signed request letter is already attached.'
                                                    : 'You may submit now. If required signed copies are missing, DSWD will receive an advance copy and the system will continue reminding the LGU until the signed requirements are complete.'}
                                            </p>
                                            <button type="button" disabled={submissionBusy || (previewIsStandaloneRelief && !previewReport.lgu_signed_request_path)} onClick={submitReportToDswd} className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white disabled:opacity-50"><Send className="h-4 w-4" /> Submit to DSWD</button>
                                        </div>
                                    )}
                                    {previewReport.lgu_report_status === 'advance_submitted' && (
                                        <div className="mt-6 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sky-950">
                                            <p className="text-xs font-black uppercase tracking-wide">Advance copy already sent</p>
                                            <p className="mt-1 text-xs leading-5">
                                                This report has already been received by DSWD and OCD Caraga for advance reporting. Upload the remaining signed {previewIncludesRequestLetter ? 'report and request letter' : 'report'} above. DROMIS will automatically mark the submission complete when all required signed copies are attached.
                                            </p>
                                        </div>
                                    )}
                                    {previewReport.lgu_report_status === 'submitted' && (
                                        <div className="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-emerald-950">
                                            <p className="text-xs font-black uppercase tracking-wide">Submission complete</p>
                                            <p className="mt-1 text-xs leading-5">DSWD and OCD Caraga have received the report with all required signed copies.</p>
                                        </div>
                                    )}
                                </aside>
                            )}
                            {previewMode === 'request' && previewIncludesRequestLetter && previewReport && <RequestedFniPanel rows={previewReport.lgu_dromic_payload?.requested_fni_items} row={previewReport} />}
                            {previewMode === 'report' && !showSignedCopiesPanel && previewReport && previewValidationNote && <aside className="overflow-y-auto border-l bg-white p-4 dark:bg-zinc-900"><ValidationNoteCard row={previewReport} kind="report" /></aside>}
                        </div>
                    </div>
                </div>
            )}
            {historyPreview && <SignedDocumentHistoryModal row={historyPreview.row} kind={historyPreview.kind} onClose={() => setHistoryPreview(null)} />}
            {revisionPreview && <RevisionHistoryModal row={revisionPreview.row} kind={revisionPreview.kind} onClose={() => setRevisionPreview(null)} />}
            {amendmentRequestRow && (
                <div className="fixed inset-0 z-[130] flex items-center justify-center bg-slate-950/70 p-4">
                    <form onSubmit={submitAmendmentRequest} className="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl dark:bg-zinc-900">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-black uppercase tracking-wide text-amber-700">
                                    {amendmentRequestTarget === 'request' ? 'Same request letter' : 'Same report number'}
                                </p>
                                <h3 className="mt-1 text-lg font-black">Request permission to amend</h3>
                                <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-zinc-300">
                                    {amendmentRequestTarget === 'request'
                                        ? `Ask DRRS to allow updates on ${amendmentRequestRow.lgu_relief_request_reference || amendmentRequestRow.reference_number || 'this relief request'} after it was already submitted to DSWD. Use this when omitted FNI or request details need revision and DRRS has not already returned the request for correction.`
                                        : `Ask DRIMS to allow encoding updates on ${amendmentRequestRow.reference_number || amendmentRequestRow.report_title} after it was already submitted to DSWD, without creating the next SitRep.`}
                                </p>
                            </div>
                            <button type="button" onClick={() => { setAmendmentRequestRow(null); setAmendmentRequestTarget('report'); }} className="rounded-md border p-2" aria-label="Close amendment request"><X className="h-4 w-4" /></button>
                        </div>
                        <label className="mt-4 block text-sm font-black">
                            Reason
                            <textarea
                                required
                                minLength={20}
                                maxLength={2000}
                                rows={5}
                                value={amendmentReason}
                                onChange={(event) => setAmendmentReason(event.target.value)}
                                className="mt-1 w-full rounded-md border-slate-300 text-sm"
                                placeholder={amendmentRequestTarget === 'request'
                                    ? 'Describe the omitted request/FNI details and why a new relief request is not appropriate.'
                                    : 'Describe the omitted data and why creating the next report is not reasonable.'}
                            />
                        </label>
                        <p className="mt-2 text-xs text-slate-500">
                            Minimum 20 characters. {amendmentRequestTarget === 'request' ? 'DRRS' : 'DRIMS'} will approve or deny this request.
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button type="button" onClick={() => { setAmendmentRequestRow(null); setAmendmentRequestTarget('report'); }} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button>
                            <button type="submit" disabled={amendmentBusy || amendmentReason.trim().length < 20} className="rounded-md bg-amber-700 px-4 py-2 text-sm font-black text-white disabled:opacity-50">
                                {amendmentBusy ? 'Sending...' : (amendmentRequestTarget === 'request' ? 'Send to DRRS' : 'Send to DRIMS')}
                            </button>
                        </div>
                    </form>
                </div>
            )}
            <PdfPreviewModal
                open={responseLetterPreview.open}
                title={responseLetterPreview.title}
                subtitle={responseLetterPreview.subtitle}
                src={responseLetterPreview.src}
                kind={responseLetterPreview.kind}
                zIndexClass={responseLetterPreview.zIndexClass || 'z-[100]'}
                onClose={closePdfPreview}
            />
        </AppLayout>
    );
}

function RevisionHistoryModal({ row, kind, onClose }) {
    const versions = row.revision_history || [];
    const [selectedId, setSelectedId] = useState(versions[0]?.id || null);
    const selected = versions.find((version) => Number(version.id) === Number(selectedId));
    const isRequest = kind === 'request';
    const signedSrc = selected?.has_signed_document
        ? `/lgu/dromic-sitrep/${selected.id}/signed-copy/${isRequest ? 'request' : 'report'}`
        : null;
    const advanceSrc = selected && !isRequest
        ? `/lgu/dromic-sitrep/${selected.id}/pdf?inline=1`
        : null;

    return <div className="fixed inset-0 z-[125] flex items-center justify-center bg-slate-950/75 p-3 backdrop-blur-sm">
        <div className="flex h-[calc(100vh-1.5rem)] w-full max-w-7xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-900">
            <div className="flex items-start justify-between border-b p-4">
                <div><p className="text-xs font-black uppercase tracking-wide text-indigo-700">Read-only revision history</p><h2 className="mt-1 font-black">{isRequest ? row.lgu_relief_request_reference : row.report_title}</h2><p className="mt-1 text-xs text-slate-500">The table shows only the current version. Earlier entries remain here for audit and comparison.</p></div>
                <button type="button" title="Close revision history" aria-label="Close revision history" onClick={onClose} className="rounded-md border p-2"><X className="h-4 w-4" /></button>
            </div>
            <div className="grid min-h-0 flex-1 lg:grid-cols-[320px_1fr]">
                <aside className="overflow-y-auto border-r p-3">
                    {versions.map((version, index) => <button key={version.id} type="button" onClick={() => setSelectedId(version.id)} className={`mb-2 w-full rounded-lg border p-3 text-left ${Number(selectedId) === Number(version.id) ? 'border-indigo-300 bg-indigo-50 text-indigo-950' : 'border-slate-200 bg-white text-slate-700'}`}>
                        <div className="flex items-center justify-between gap-2"><p className="text-xs font-black">{version.revision_number > 0 ? `Revision ${version.revision_number}` : 'Original submission'}</p>{index === 0 && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-black uppercase text-emerald-800">Current</span>}</div>
                        <p className="mt-1 break-all text-[11px] font-semibold">{isRequest ? version.request_reference : version.reference_number}</p>
                        <p className="mt-1 text-[10px] uppercase text-slate-500">{String(version.status || 'draft').replaceAll('_', ' ')} · {String(version.validation_status || 'not reviewed').replaceAll('_', ' ')}</p>
                        <p className="mt-1 text-[10px] text-slate-500">{formatDateTime(version.updated_at || version.created_at)}</p>
                        {version.validation_note && <p className="mt-2 line-clamp-3 text-xs leading-4">{version.validation_note}</p>}
                    </button>)}
                </aside>
                <div className="min-h-0 bg-slate-100">
                    {signedSrc
                        ? <SignedPdfPreview
                            src={signedSrc}
                            filename={isRequest ? (selected.request_reference || 'Signed request letter.pdf') : (selected.reference_number || 'Signed DROMIC report.pdf')}
                            title="Read-only report revision preview"
                            iframeClassName="h-full min-h-[70vh] w-full flex-1 bg-slate-200"
                        />
                        : advanceSrc
                            ? <iframe title="Read-only report revision preview" src={advanceSrc} className="h-full min-h-[70vh] w-full" />
                            : <div className="flex h-full items-center justify-center p-8 text-center text-sm text-slate-500">No signed request PDF exists for this revision.</div>}
                </div>
            </div>
        </div>
    </div>;
}

function SignedDocumentHistoryModal({ row, kind, onClose }) {
    const versions = (row.signed_document_versions || []).filter((version) => version.kind === kind);
    const [selectedId, setSelectedId] = useState(versions[0]?.id || null);
    const selected = versions.find((version) => Number(version.id) === Number(selectedId));

    return <div className="fixed inset-0 z-[130] flex items-center justify-center bg-slate-950/75 p-3 backdrop-blur-sm">
        <div className="flex h-[calc(100vh-1.5rem)] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-zinc-900">
            <div className="flex items-start justify-between border-b p-4">
                <div><p className="text-xs font-black uppercase tracking-wide text-slate-500">Read-only signed document history</p><h2 className="mt-1 font-black">{kind === 'request' ? row.lgu_relief_request_reference : row.reference_number}</h2><p className="mt-1 text-xs text-slate-500">Historical files can be previewed only. Download and print controls are available only for the current signed copy after validation with no findings.</p></div>
                <button type="button" title="Close document history" aria-label="Close document history" onClick={onClose} className="rounded-md border p-2"><X className="h-4 w-4" /></button>
            </div>
            <div className="grid min-h-0 flex-1 lg:grid-cols-[280px_1fr]">
                <aside className="overflow-y-auto border-r p-3">
                    {versions.map((version, index) => <button key={version.id} type="button" onClick={() => setSelectedId(version.id)} className={`mb-2 w-full rounded-lg border p-3 text-left ${Number(selectedId) === Number(version.id) ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700'}`}><p className="text-xs font-black">Previous version {versions.length - index}</p><p className="mt-1 break-all text-[11px]">{version.original_name || `${kind}.pdf`}</p><p className="mt-1 text-[10px] text-slate-500">{formatDateTime(version.uploaded_at || version.created_at)}</p></button>)}
                </aside>
                <div className="min-h-0 bg-slate-100">
                    {selected
                        ? <SignedPdfPreview
                            src={`/lgu/dromic-sitrep/signed-history/${selected.id}`}
                            filename={selected.original_name || `${kind}.pdf`}
                            title="Historical signed document preview"
                            iframeClassName="h-full min-h-[70vh] w-full flex-1 bg-slate-200"
                        />
                        : <div className="flex h-full items-center justify-center text-sm text-slate-500">No previous signed versions are available.</div>}
                </div>
            </div>
        </div>
    </div>;
}

function OfficialAdvisorySection({
    form,
    incidentType = '',
    incidentName = '',
    incidentDetails = '',
    incidentDate = '',
    province = '',
    municipality = '',
    isNa = false,
    toggleNa = null,
    onExtractionStateChange = () => {},
}) {
    const [busy, setBusy] = useState(false);
    const [importing, setImporting] = useState(false);
    const [extractingScreenshot, setExtractingScreenshot] = useState(false);
    const [sourceLink, setSourceLink] = useState('');
    const [result, setResult] = useState(null);
    const [message, setMessage] = useState('');
    const showLegacySourceTools = false;
    const rows = Array.isArray(form.data.official_advisory_rows) ? form.data.official_advisory_rows : [];
    const patchRows = (updater) => form.setData((current) => ({
        ...current,
        official_advisory_rows: updater(Array.isArray(current.official_advisory_rows) ? current.official_advisory_rows : []),
    }));
    const addSource = (source = {}) => {
        if (rows.length >= 5) {
            setMessage('You may include up to five verified official agency sources.');
            return;
        }

        const normalized = {
            agency: source.agency || '',
            advisory_title: source.advisory_title || source.product || '',
            issued_at: source.issued_at || '',
            covered_location: source.covered_location || [municipality, province].filter(Boolean).join(', '),
            summary: source.summary || '',
            source_url: source.source_url || source.url || '',
            match_basis: source.match_basis || source.reason || '',
            content_status: source.content_status || (source.summary ? 'extracted_for_review' : 'reference_only'),
            source_kind: source.source_kind || (source.screenshot_data_url ? 'screenshot' : 'link'),
            screenshot_name: source.screenshot_name || '',
            screenshot_data_url: source.screenshot_data_url || '',
            pasted_text: source.pasted_text || '',
            client_id: source.client_id || '',
        };
        const duplicate = rows.some((row) => (
            String(row.source_url || '') === String(normalized.source_url || '')
            && String(row.advisory_title || '') === String(normalized.advisory_title || '')
        ));

        if (duplicate && normalized.source_url) {
            setMessage('That official source is already included below.');
            return;
        }

        patchRows((current) => [...current, normalized]);
        setMessage('Source added for LGU review. Verify and edit the excerpt before generating the Situation Overview.');
    };
    const updateSource = (index, field, value) => patchRows((current) => current.map((row, rowIndex) => (
        rowIndex === index
            ? { ...row, ...(typeof field === 'object' ? field : { [field]: value }) }
            : row
    )));
    const removeSource = (index) => patchRows((current) => current.filter((_, rowIndex) => rowIndex !== index));
    const lookup = async () => {
        if (!String(incidentType || '').trim()) {
            setMessage('Select the Type of Disaster / Incident first.');
            return;
        }
        if (!incidentDate) {
            setMessage('Encode the incident date before looking up official sources.');
            return;
        }

        setBusy(true);
        setMessage('Checking the appropriate official warning agency and available source candidates...');

        try {
            const { data } = await window.axios.post('/lgu/dromic-sitrep/official-advisories', {
                incident_type: incidentType,
                incident_name: incidentName,
                incident_details: incidentDetails,
                incident_date: String(incidentDate).slice(0, 10),
                province,
                municipality,
            });
            setResult(data);
            setMessage(data.notice || 'Official source recommendations are ready for review.');
        } catch (error) {
            setResult(null);
            setMessage(error.response?.data?.message || 'Official sources could not be checked right now. You may still add a verified source manually.');
        } finally {
            setBusy(false);
        }
    };
    const importLink = async () => {
        if (!String(sourceLink || '').trim()) {
            setMessage('Paste the PAGASA, DOST-PHIVOLCS, or Facebook post link first.');
            return;
        }

        setImporting(true);
        setMessage('Opening the supplied source and checking for readable official advisory text...');

        try {
            const { data } = await window.axios.post('/lgu/dromic-sitrep/official-advisories/import', {
                source_url: sourceLink.trim(),
                incident_type: incidentType,
                incident_date: incidentDate ? String(incidentDate).slice(0, 10) : null,
                province,
                municipality,
            });
            addSource(data.source || {});
            setSourceLink('');
            setMessage(data.notice || 'Source imported for LGU verification.');
        } catch (error) {
            setMessage(error.response?.data?.message || 'The source could not be imported. Confirm that it is a public PAGASA, DOST-PHIVOLCS, or Facebook post link.');
        } finally {
            setImporting(false);
        }
    };
    const pasteScreenshot = async (event) => {
        if (extractingScreenshot) {
            setMessage('Please wait until the current screenshot extraction finishes before pasting another.');
            return;
        }

        const imageItems = Array.from(event.clipboardData?.items || []).filter((item) => item.type?.startsWith('image/'));
        if (rows.length >= 5) {
            setMessage('You may include up to five verified official agency sources.');
            return;
        }

        if (!imageItems.length) {
            const copiedText = String(event.clipboardData?.getData('text/plain') || '').replace(/\s+/g, ' ').trim();
            if (copiedText.length < 20) {
                setMessage('No screenshot or readable advisory text was found. Copy the post image or select and copy its advisory text, then press Ctrl + V here.');
                return;
            }

            event.preventDefault();
            const earthquake = /earthquake|seismic|intensity|magnitude|phivolcs/i.test(`${incidentType} ${copiedText}`);
            addSource({
                agency: earthquake ? 'DOST-PHIVOLCS' : 'PAGASA',
                advisory_title: earthquake ? 'Copied PHIVOLCS earthquake information' : 'Copied PAGASA weather advisory',
                covered_location: [municipality, province].filter(Boolean).join(', '),
                summary: copiedText.slice(0, 3000),
                source_url: '',
                match_basis: 'Advisory text copied and pasted by the LGU. The copied text is used as supplied and must be checked against the official post before generating the Situation Overview.',
                content_status: 'extracted_for_review',
                source_kind: 'clipboard_text',
                pasted_text: copiedText.slice(0, 5000),
            });
            setMessage('Copied advisory text added. Groq will use the supplied text as the first-paragraph incident context.');
            return;
        }

        event.preventDefault();
        setExtractingScreenshot(true);
        onExtractionStateChange(true);
        const itemsToProcess = imageItems.slice(0, Math.max(0, 5 - rows.length));
        setMessage(`${itemsToProcess.length} screenshot${itemsToProcess.length === 1 ? '' : 's'} pasted. Extracting only the visible PAGASA/PHIVOLCS text...`);

        try {
            for (const [itemIndex, imageItem] of itemsToProcess.entries()) {
                const file = imageItem.getAsFile();
                if (!file) continue;

                const screenshotDataUrl = await resizePhotoToDataUrl(file, 1600, 0.82);
                const screenshotName = `warning-agency-screenshot-${Date.now()}-${itemIndex + 1}.jpg`;
                const clientId = `advisory-${Date.now()}-${itemIndex + 1}-${Math.random().toString(36).slice(2, 8)}`;
                const pendingSource = {
                    client_id: clientId,
                    agency: 'Official warning agency',
                    advisory_title: `Advisory screenshot ${rows.length + itemIndex + 1}`,
                    covered_location: [municipality, province].filter(Boolean).join(', '),
                    summary: '',
                    source_url: '',
                    match_basis: '',
                    content_status: 'processing',
                    source_kind: 'screenshot',
                    screenshot_name: screenshotName,
                    screenshot_data_url: screenshotDataUrl,
                    pasted_text: '',
                };
                patchRows((current) => [...current, pendingSource]);

                try {
                    const extractionPayload = {
                        screenshot_data_url: screenshotDataUrl,
                        screenshot_name: screenshotName,
                        incident_type: incidentType,
                        incident_date: incidentDate ? String(incidentDate).slice(0, 10) : null,
                        province,
                        municipality,
                    };
                    let extractionResponse;
                    try {
                        extractionResponse = await window.axios.post('/lgu/dromic-sitrep/official-advisories/extract-screenshot', extractionPayload);
                    } catch (firstError) {
                        if (firstError.response?.status !== 429) throw firstError;
                        setMessage('The advisory reader is busy. This screenshot is queued for one automatic retry…');
                        await new Promise((resolve) => window.setTimeout(resolve, 5000));
                        extractionResponse = await window.axios.post('/lgu/dromic-sitrep/official-advisories/extract-screenshot', extractionPayload);
                    }
                    const { data } = extractionResponse;
                    patchRows((current) => current.map((row) => row.client_id === clientId ? {
                        ...pendingSource,
                        ...(data.source || {}),
                        client_id: clientId,
                        screenshot_name: screenshotName,
                        screenshot_data_url: screenshotDataUrl,
                        source_kind: 'screenshot',
                    } : row));
                    setMessage(data.notice || 'Screenshot pasted and extracted for the Situation Overview.');
                } catch (error) {
                    patchRows((current) => current.map((row) => row.client_id === clientId ? {
                        ...pendingSource,
                        agency: 'Official warning agency',
                        advisory_title: 'Advisory screenshot requiring another paste',
                        content_status: 'extraction_failed',
                    } : row));
                    setMessage(error.response?.data?.message || 'This screenshot could not be processed. Remove it and paste a clearer copy before generating the Situation Overview.');
                }
            }
        } catch (error) {
            setMessage('The clipboard screenshot could not be processed. Copy the advisory image again and retry.');
        } finally {
            setExtractingScreenshot(false);
            onExtractionStateChange(false);
        }
    };

    return (
        <DromicSectionCard id="dromic-section-official-advisories">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p className="text-lg font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-100">PAGASA / PHIVOLCS Advisory Screenshots</p>
                    <p className="mt-1 max-w-4xl text-sm leading-6 text-slate-600 dark:text-zinc-300">
                        Paste up to five screenshots or copied advisory texts. Groq will use the readable incident information for the first paragraph of the Situation Overview. Mark N/A when no PAGASA/PHIVOLCS advisory applies.
                    </p>
                </div>
                <div className="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2">
                    {showLegacySourceTools && !isNa && (
                        <>
                            <button type="button" onClick={lookup} disabled={busy} className="inline-flex items-center gap-2 rounded-md bg-brand-700 px-3 py-2 text-xs font-black text-white disabled:opacity-60">
                                <Search className={`h-4 w-4 ${busy ? 'animate-pulse' : ''}`} />
                                {busy ? 'Checking...' : 'Find official sources'}
                            </button>
                            <button type="button" onClick={() => addSource()} className="inline-flex items-center gap-2 rounded-md border border-emerald-300 bg-white px-3 py-2 text-xs font-black text-emerald-800 dark:border-emerald-900 dark:bg-zinc-950 dark:text-emerald-100">
                                <Plus className="h-4 w-4" /> Add verified source
                            </button>
                        </>
                    )}
                    {toggleNa && <NotApplicableToggle checked={isNa} onChange={toggleNa} />}
                </div>
            </div>

            {isNa ? <NotApplicableNotice section="PAGASA / PHIVOLCS Advisory Screenshots" /> : null}

            {!isNa && showLegacySourceTools && <div className="mt-4 rounded-lg border border-brand-200 bg-brand-50/50 p-4 dark:border-brand-900 dark:bg-brand-950/20">
                <label className="text-xs font-black uppercase tracking-wide text-brand-800 dark:text-brand-100">Import advisory from link</label>
                <div className="mt-2 flex flex-col gap-2 md:flex-row">
                    <input
                        type="url"
                        value={sourceLink}
                        onChange={(event) => setSourceLink(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                importLink();
                            }
                        }}
                        placeholder="Paste a PAGASA, DOST-PHIVOLCS, or public Facebook post URL"
                        className="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200 dark:border-zinc-700 dark:bg-zinc-950 dark:text-white"
                    />
                    <button type="button" onClick={importLink} disabled={importing} className="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-xs font-black text-white disabled:opacity-60">
                        <ExternalLink className={`h-4 w-4 ${importing ? 'animate-pulse' : ''}`} />
                        {importing ? 'Importing...' : 'Import link'}
                    </button>
                </div>
                <p className="mt-2 text-xs leading-5 text-slate-600 dark:text-zinc-300">
                    Official webpages are read automatically when accessible. Facebook may expose only a link or preview; when its post text is blocked, the link is retained as a reference and Groq will not invent its contents.
                </p>
            </div>}

            {!isNa && <>
            <div
                role="button"
                tabIndex={0}
                onPaste={pasteScreenshot}
                className="mt-3 flex min-h-32 cursor-text flex-col items-center justify-center rounded-lg border-2 border-dashed border-violet-300 bg-violet-50 p-5 text-center outline-none transition hover:border-violet-500 focus:border-violet-600 focus:ring-4 focus:ring-violet-200 dark:border-violet-900 dark:bg-violet-950/20"
                aria-label="Paste PAGASA or PHIVOLCS screenshot"
            >
                <ClipboardPaste className={`h-8 w-8 text-violet-700 ${extractingScreenshot ? 'animate-pulse' : ''}`} />
                <p className="mt-2 text-sm font-black text-violet-950 dark:text-violet-100">
                    {extractingScreenshot ? 'Reading pasted screenshot...' : 'Click here, then press Ctrl + V to paste advisory screenshot(s) or copied post text'}
                </p>
                <p className="mt-1 max-w-3xl text-xs leading-5 text-violet-800 dark:text-violet-200">
                    You may copy an advisory image using Ctrl + C or “Copy image,” then paste it here. If the browser copies only text, select the advisory text, press Ctrl + C, and paste it here instead. Screenshots are saved automatically and do not require additional encoding.
                </p>
            </div>

            {message && <p className="mt-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-bold leading-6 text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">{message}</p>}

            {showLegacySourceTools && result?.recommendations?.length > 0 && (
                <div className="mt-4">
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500">Recommended official source</p>
                    <div className="mt-2 grid gap-3 lg:grid-cols-2">
                        {result.recommendations.map((item) => (
                            <div key={`${item.agency}-${item.product}`} className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-zinc-800 dark:bg-zinc-950">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-black text-slate-900 dark:text-white">{item.agency}</p>
                                        <p className="mt-1 text-xs font-bold text-brand-700 dark:text-brand-200">{item.product}</p>
                                    </div>
                                    <a href={item.url} target="_blank" rel="noreferrer" className="inline-flex shrink-0 items-center gap-1 text-xs font-black text-brand-700 underline">
                                        Open <ExternalLink className="h-3.5 w-3.5" />
                                    </a>
                                </div>
                                <p className="mt-2 text-xs leading-5 text-slate-600 dark:text-zinc-300">{item.reason}</p>
                                <button type="button" onClick={() => addSource(item)} className="mt-3 rounded-md border border-emerald-300 bg-white px-3 py-1.5 text-xs font-black text-emerald-800 dark:border-emerald-900 dark:bg-zinc-900 dark:text-emerald-100">
                                    Add for verification
                                </button>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {showLegacySourceTools && result?.candidates?.length > 0 && (
                <div className="mt-4">
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500">Automatically matched candidates</p>
                    <div className="mt-2 space-y-3">
                        {result.candidates.map((candidate, index) => (
                            <div key={`${candidate.source_url}-${index}`} className="rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/30">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p className="font-black text-amber-950 dark:text-amber-100">{candidate.agency} · {candidate.advisory_title}</p>
                                        <p className="mt-1 text-xs font-bold text-amber-800 dark:text-amber-200">{candidate.issued_at || '-'} · {candidate.covered_location || '-'}</p>
                                        <p className="mt-2 text-sm leading-6 text-amber-950 dark:text-amber-100">{candidate.summary}</p>
                                        <p className="mt-2 text-xs text-amber-800 dark:text-amber-200">{candidate.match_basis}</p>
                                    </div>
                                    <div className="flex shrink-0 gap-2">
                                        <a href={candidate.source_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-white px-3 py-2 text-xs font-black text-amber-900">
                                            Verify <ExternalLink className="h-3.5 w-3.5" />
                                        </a>
                                        <button type="button" onClick={() => addSource(candidate)} className="rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white">
                                            Use candidate
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {showLegacySourceTools && result && result.candidates?.length === 0 && (
                <p className="mt-4 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-300">
                    No safe automatic match was found. Open the recommended official source and add the applicable bulletin or verified agency report manually.
                </p>
            )}

            {rows.length > 0 && (
                <div className="mt-5">
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-xs font-black uppercase tracking-wide text-slate-500">Pasted overview sources</p>
                        <p className="text-xs font-bold text-slate-500">{rows.length} of 5 added</p>
                    </div>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {rows.map((row, index) => (
                            <div key={`${row.screenshot_name || row.advisory_title}-${index}`} className="overflow-hidden rounded-lg border border-violet-200 bg-white shadow-sm dark:border-violet-900 dark:bg-zinc-950">
                                {row.screenshot_data_url ? (
                                    <a href={row.screenshot_data_url} target="_blank" rel="noreferrer" className="block bg-slate-100">
                                        <img src={row.screenshot_data_url} alt={row.screenshot_name || `Advisory screenshot ${index + 1}`} className="h-52 w-full object-contain" />
                                    </a>
                                ) : (
                                    <div className="flex h-32 flex-col items-center justify-center bg-violet-50 p-4 text-center dark:bg-violet-950/30">
                                        <ClipboardPaste className="h-7 w-7 text-violet-700" />
                                        <p className="mt-2 text-xs font-black uppercase text-violet-800">
                                            {row.source_kind === 'clipboard_text' ? 'Copied advisory text' : 'Saved advisory reference'}
                                        </p>
                                    </div>
                                )}
                                <div className="p-3">
                                    <p className="truncate text-sm font-black text-slate-950 dark:text-white">{row.advisory_title || `Advisory source ${index + 1}`}</p>
                                    <p className="mt-1 text-xs font-bold text-violet-700">{row.agency || 'Warning agency'}</p>
                                    <p className={`mt-2 text-xs font-black ${row.content_status === 'extracted_for_review' ? 'text-emerald-700' : 'text-amber-700'}`}>
                                        {row.content_status === 'processing'
                                            ? 'Reading screenshot…'
                                            : row.content_status === 'extracted_for_review'
                                                ? 'Ready for Situation Overview'
                                                : 'Remove or paste a clearer copy'}
                                    </p>
                                    <button type="button" onClick={() => removeSource(index)} className="mt-3 inline-flex items-center gap-1 rounded-md border border-rose-200 px-3 py-1.5 text-xs font-black text-rose-700">
                                        <Trash2 className="h-3.5 w-3.5" /> Remove
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {showLegacySourceTools && rows.length > 0 && (
                <div className="mt-5 space-y-4">
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500">Sources selected for this LGU report</p>
                    {rows.map((row, index) => {
                        const complete = ['agency', 'advisory_title'].every((field) => String(row[field] || '').trim())
                            && Boolean(String(row.source_url || '').trim() || String(row.screenshot_data_url || '').trim());
                        const hasUsableExcerpt = String(row.summary || '').trim() && row.content_status !== 'reference_only';

                        return (
                            <div key={index} className={`rounded-lg border p-4 ${complete ? 'border-emerald-200 bg-emerald-50/40 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-rose-200 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/20'}`}>
                                {row.screenshot_data_url && (
                                    <div className="mb-4 grid gap-3 rounded-lg border border-violet-200 bg-white p-3 md:grid-cols-[minmax(0,320px)_1fr] dark:border-violet-900 dark:bg-zinc-950">
                                        <a href={row.screenshot_data_url} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-md border border-slate-200 bg-slate-100">
                                            <img src={row.screenshot_data_url} alt={row.screenshot_name || 'Pasted warning-agency screenshot'} className="max-h-64 w-full object-contain" />
                                        </a>
                                        <div className="flex flex-col justify-center">
                                            <p className="text-xs font-black uppercase tracking-wide text-violet-700">Pasted source screenshot</p>
                                            <p className="mt-1 text-sm font-bold text-slate-900 dark:text-white">{row.screenshot_name || 'Clipboard screenshot'}</p>
                                            <p className="mt-2 text-xs leading-5 text-slate-600 dark:text-zinc-300">Open the preview and compare it with the extracted text below. Only the reviewed text is supplied to the Situation Overview generator.</p>
                                            <button
                                                type="button"
                                                onClick={() => updateSource(index, {
                                                    screenshot_name: '',
                                                    screenshot_data_url: '',
                                                    source_kind: row.source_url ? 'link' : 'screenshot',
                                                    content_status: row.source_url && row.summary ? 'extracted_for_review' : 'reference_only',
                                                })}
                                                className="mt-3 w-fit rounded-md border border-rose-200 px-3 py-1.5 text-xs font-black text-rose-700"
                                            >
                                                Remove screenshot
                                            </button>
                                        </div>
                                    </div>
                                )}
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                    <Input label="Agency" value={row.agency} onChange={(value) => updateSource(index, 'agency', value)} required />
                                    <Input label="Advisory / Report Title" value={row.advisory_title} onChange={(value) => updateSource(index, 'advisory_title', value)} required />
                                    <Input label="Issued Date / Time" value={row.issued_at} onChange={(value) => updateSource(index, 'issued_at', value)} />
                                    <Input label="Covered Location" value={row.covered_location} onChange={(value) => updateSource(index, 'covered_location', value)} />
                                </div>
                                <div className="mt-3 grid gap-3 lg:grid-cols-[1fr_2fr]">
                                    <Input
                                        label="Official Source URL (optional when a screenshot is pasted)"
                                        type="url"
                                        value={row.source_url}
                                        onChange={(value) => updateSource(index, {
                                            source_url: value,
                                            content_status: value === row.source_url ? row.content_status : 'reference_only',
                                        })}
                                    />
                                    <Textarea
                                        label="Verified Relevant Excerpt / Summary (optional for reference-only links)"
                                        rows={4}
                                        value={row.summary}
                                        onChange={(value) => updateSource(index, {
                                            summary: value,
                                            content_status: String(value || '').trim() ? 'extracted_for_review' : 'reference_only',
                                        })}
                                    />
                                </div>
                                <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                                    <p className={`text-xs font-black ${complete ? 'text-emerald-700' : 'text-rose-700'}`}>
                                        {!complete
                                            ? 'Complete the agency and title, then provide an official URL or pasted screenshot.'
                                            : (hasUsableExcerpt
                                                ? 'Excerpt ready for use in the Situation Overview after LGU review.'
                                                : 'Reference link saved. Groq will not use its contents until readable or verified text is available.')}
                                    </p>
                                    <button type="button" onClick={() => removeSource(index)} className="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-white px-3 py-1.5 text-xs font-black text-rose-700">
                                        <Trash2 className="h-3.5 w-3.5" /> Remove source
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
            </>}
        </DromicSectionCard>
    );
}

function SummaryCard({ title, value }) {
    return (
        <Card>
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500">{title}</p>
                    <p className="mt-2 text-3xl font-black">{Number(value || 0).toLocaleString()}</p>
                </div>
                <div className="flex h-11 w-11 items-center justify-center rounded-md bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-100">
                    <BarChart3 className="h-5 w-5" />
                </div>
            </div>
        </Card>
    );
}

function LinkedIncidentsSummary({ incidents = [], onViewAll }) {
    const rows = Array.isArray(incidents) ? incidents : [];
    if (!rows.length) {
        return <p className="mt-1 text-xs font-bold text-slate-500">No linked incidents</p>;
    }
    const preview = rows.slice(0, 2);
    const hidden = Math.max(rows.length - preview.length, 0);
    return (
        <div className="mt-1 space-y-1">
            {preview.map((incident) => (
                <p key={incident.series_key || incident.incident_code} className="font-mono text-[11px] font-bold text-emerald-700">
                    {incident.incident_code}
                    {incident.incident_name ? <span className="font-sans font-semibold text-slate-500"> · {incident.incident_name}</span> : null}
                </p>
            ))}
            {hidden > 0 && (
                <button
                    type="button"
                    onClick={() => onViewAll?.(rows)}
                    className="text-[11px] font-black text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900"
                >
                    View all {rows.length} linked incidents
                </button>
            )}
            {rows.length > 1 && hidden === 0 && (
                <p className="text-[11px] font-bold text-slate-500">{rows.length} incidents linked</p>
            )}
        </div>
    );
}

function AffectedAreasSummaryCell({ areas = null, fallbackTitle = '-', onViewAll }) {
    if (!Array.isArray(areas)) {
        return <p title={fallbackTitle} className="line-clamp-2 whitespace-normal">{fallbackTitle}</p>;
    }
    if (!areas.length) {
        return <p className="text-sm text-slate-500">Not encoded</p>;
    }
    const preview = areas.slice(0, AFFECTED_AREA_PREVIEW_LIMIT);
    const hidden = Math.max(areas.length - preview.length, 0);
    return (
        <div>
            <p title={areas.join(', ')} className="line-clamp-2 whitespace-normal">{preview.join(', ')}</p>
            {hidden > 0 && (
                <button
                    type="button"
                    onClick={() => onViewAll?.(areas)}
                    className="mt-1 text-[11px] font-black text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-900"
                >
                    View all {areas.length} areas
                </button>
            )}
        </div>
    );
}

function ReportMetricCard({ icon: Icon, label, value = 0, tone = 'blue', compact = false, breakdown = [] }) {
    const tones = {
        blue: 'border-sky-200 bg-gradient-to-br from-sky-50 to-white text-sky-800 dark:border-sky-900 dark:from-sky-950/50 dark:to-zinc-950 dark:text-sky-200',
        amber: 'border-amber-200 bg-gradient-to-br from-amber-50 to-white text-amber-800 dark:border-amber-900 dark:from-amber-950/50 dark:to-zinc-950 dark:text-amber-200',
        indigo: 'border-indigo-200 bg-gradient-to-br from-indigo-50 to-white text-indigo-800 dark:border-indigo-900 dark:from-indigo-950/50 dark:to-zinc-950 dark:text-indigo-200',
        emerald: 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white text-emerald-800 dark:border-emerald-900 dark:from-emerald-950/50 dark:to-zinc-950 dark:text-emerald-200',
        rose: 'border-rose-200 bg-gradient-to-br from-rose-50 to-white text-rose-800 dark:border-rose-900 dark:from-rose-950/50 dark:to-zinc-950 dark:text-rose-200',
        violet: 'border-violet-200 bg-gradient-to-br from-violet-50 to-white text-violet-800 dark:border-violet-900 dark:from-violet-950/50 dark:to-zinc-950 dark:text-violet-200',
    };

    return (
        <div className={`relative h-[88px] overflow-hidden rounded-xl border p-3 shadow-sm ${tones[tone] || tones.blue}`}>
            <div className="absolute -right-5 -top-5 h-16 w-16 rounded-full bg-current opacity-[0.06]" />
            <div className="relative flex h-full items-center justify-between gap-3">
                <div>
                    <p className="text-[10px] font-black uppercase tracking-wide opacity-80">{label}</p>
                    <p className="mt-1 text-2xl font-black">{Number(value || 0).toLocaleString()}</p>
                    {breakdown.length > 0 && <p className="mt-0.5 whitespace-nowrap text-[9px] font-bold opacity-80">{breakdown.map(([name, count]) => `${name}: ${Number(count || 0).toLocaleString()}`).join(' · ')}</p>}
                </div>
                <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-current/10">
                    <Icon className="h-5 w-5" />
                </span>
            </div>
        </div>
    );
}

function ValidationNoteCard({ row, kind }) {
    const isRequest = kind === 'request';
    const note = isRequest ? row?.lgu_relief_review_note : row?.lgu_dromic_review_note;
    const screenshots = isRequest ? row?.lgu_relief_review_screenshots : row?.lgu_dromic_review_screenshots;
    const history = isRequest ? row?.lgu_relief_review_history : row?.lgu_dromic_review_history;
    if (!note && (!Array.isArray(screenshots) || screenshots.length === 0) && (!Array.isArray(history) || history.length === 0)) return null;

    const reviewer = isRequest ? row?.lgu_relief_reviewer : row?.lgu_dromic_reviewer;
    const reviewedAt = isRequest ? row?.lgu_relief_reviewed_at : row?.lgu_dromic_reviewed_at;
    const needsAction = (isRequest ? row?.lgu_relief_validation_status : row?.lgu_dromic_validation_status) === 'needs_lgu_action';

    return <div className={`mt-4 rounded-lg border p-3 ${needsAction ? 'border-rose-200 bg-rose-50 text-rose-950 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100' : 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100'}`}>
        <p className="flex items-center gap-1.5 text-xs font-black uppercase tracking-wide">
            {needsAction ? <AlertTriangle className="h-4 w-4 shrink-0" /> : <ClipboardPaste className="h-4 w-4 shrink-0" />}
            DRMD validation note
        </p>
        {note && <p className="mt-2 whitespace-pre-wrap text-sm font-semibold leading-6">{note}</p>}
        {Array.isArray(screenshots) && screenshots.length > 0 && <div className="mt-3">
            <p className="mb-2 text-[10px] font-black uppercase tracking-wide opacity-75">Attached screenshots</p>
            <div className="grid grid-cols-2 gap-2">
                {screenshots.map((screenshot, index) => <a
                    key={`${screenshot.path || screenshot.name}-${index}`}
                    href={`/lgu/dromic-sitrep/${row.id}/validation-screenshot/${isRequest ? 'request' : 'report'}/${index}`}
                    target="_blank"
                    rel="noreferrer"
                    title={`Open ${screenshot.name || `screenshot ${index + 1}`} in full size`}
                    className="group overflow-hidden rounded-md border border-current/20 bg-white/70"
                >
                    <img src={`/lgu/dromic-sitrep/${row.id}/validation-screenshot/${isRequest ? 'request' : 'report'}/${index}`} alt={screenshot.name || `Validation screenshot ${index + 1}`} className="h-28 w-full object-cover transition group-hover:scale-[1.02]" />
                    <p className="truncate bg-white px-2 py-1.5 text-[10px] font-bold text-slate-600">{screenshot.name || `Screenshot ${index + 1}`}</p>
                </a>)}
            </div>
        </div>}
        {(reviewer?.name || reviewedAt) && <p className="mt-3 border-t border-current/15 pt-2 text-[11px] font-bold opacity-75">
            {[reviewer?.name, reviewedAt ? formatDateTime(reviewedAt) : null].filter(Boolean).join(' · ')}
        </p>}
        <LguValidationHistoryList row={row} kind={kind} history={history} />
    </div>;
}

function LguValidationHistoryList({ row, kind, history = [] }) {
    const entries = Array.isArray(history)
        ? history.map((entry, index) => ({ entry, index })).reverse()
        : [];
    if (entries.length === 0) return null;

    return <details className="mt-3 rounded-lg border border-current/15 bg-white/70 text-slate-800">
        <summary className="flex cursor-pointer list-none items-center justify-between p-3 text-xs font-black uppercase tracking-wide">
            <span className="inline-flex items-center gap-1.5"><History className="h-4 w-4" />Validation notes history</span>
            <span className="rounded-full bg-slate-100 px-2 py-0.5">{entries.length}</span>
        </summary>
        <div className="max-h-72 space-y-2 overflow-y-auto border-t border-slate-200 p-3">
            {entries.map(({ entry, index }) => <div key={`${entry.reviewed_at}-${index}`} className="rounded-md border border-slate-200 bg-white p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className={`rounded-full px-2 py-1 text-[10px] font-black uppercase ${entry.validation_status === 'validated_no_findings' ? 'bg-emerald-100 text-emerald-800' : entry.validation_status === 'needs_lgu_action' ? 'bg-rose-100 text-rose-800' : 'bg-blue-100 text-blue-800'}`}>{String(entry.validation_status || 'under_review').replaceAll('_', ' ')}</span>
                    <span className="text-[10px] font-semibold text-slate-500">{formatDateTime(entry.reviewed_at)}</span>
                </div>
                {entry.review_note && <p className="mt-2 whitespace-pre-wrap text-xs font-semibold leading-5">{entry.review_note}</p>}
                <p className="mt-2 text-[10px] text-slate-500">{entry.reviewer?.name || 'DSWD reviewer'}{entry.reviewer?.office ? ` · ${entry.reviewer.office}` : ''}</p>
                {Array.isArray(entry.screenshots) && entry.screenshots.length > 0 && <div className="mt-2 grid grid-cols-2 gap-2">
                    {entry.screenshots.map((screenshot, screenshotIndex) => <a key={`${screenshot.path}-${screenshotIndex}`} href={`/lgu/dromic-sitrep/${row.id}/validation-history-screenshot/${kind}/${index}/${screenshotIndex}`} target="_blank" rel="noreferrer" title="Open validation screenshot in full size" className="overflow-hidden rounded border bg-slate-50"><img src={`/lgu/dromic-sitrep/${row.id}/validation-history-screenshot/${kind}/${index}/${screenshotIndex}`} alt={screenshot.name || `Validation screenshot ${screenshotIndex + 1}`} className="h-24 w-full object-cover" /></a>)}
                </div>}
            </div>)}
        </div>
    </details>;
}

function RequestedFniPanel({ rows = [], row = null }) {
    const items = Array.isArray(rows) ? rows : [];
    return <aside className="overflow-y-auto border-l bg-white p-4 dark:bg-zinc-900">
        <p className="text-xs font-black uppercase tracking-wide text-violet-700">Requested FNIs</p>
        <h4 className="mt-1 font-black">LGU Request Summary</h4>
        <ValidationNoteCard row={row} kind="request" />
        <div className="mt-4 space-y-2">
            {items.length ? items.map((item, index) => <div key={`${item.fni_library_item_id || item.item_name}-${index}`} className="rounded-lg border border-violet-100 bg-violet-50 p-3 dark:border-violet-900 dark:bg-violet-950/20">
                <p className="font-black">{item.item_name || item.name || 'Requested FNI'}</p>
                {item.brand_description && <p className="mt-1 text-xs text-slate-500">{item.brand_description}</p>}
                <p className="mt-2 text-sm font-black text-violet-800">{formatWholeQuantity(item.requested_quantity, '0')} {item.unit_of_measure || item.unit || ''}</p>
            </div>) : <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">No requested FNI line items were encoded.</p>}
        </div>
    </aside>;
}

function affectedAreaSummary(row, barangayOptions = []) {
    const selected = [...new Set((row.lgu_dromic_payload?.affected_barangays || row.affected_barangays || [])
        .map((name) => String(name || '').trim())
        .filter(Boolean))];
    if (!selected.length) return [row.barangay, row.municipality, row.province].filter(Boolean).join(', ') || '-';

    const available = [...new Set((barangayOptions || [])
        .map((option) => String(option?.label || option?.value || option?.name || '').trim())
        .filter(Boolean))];
    if (!available.length || selected.length > available.length) return selected.join(', ');

    const selectedKeys = new Set(selected.map((name) => name.toLocaleLowerCase()));
    const excluded = available.filter((name) => !selectedKeys.has(name.toLocaleLowerCase()));
    if (excluded.length === 0 && selected.length === available.length) return 'All';
    if (excluded.length > 0 && excluded.length <= 2 && selected.length + excluded.length === available.length) {
        return `All except ${excluded.join(' and ')}`;
    }
    return selected.join(', ');
}

function incidentDetailLabel(incident) {
    const name = String(incident.incident_name || '').trim().toLocaleLowerCase();
    const type = String(incident.incident_type || '').trim();
    const distinctType = type && type.toLocaleLowerCase() !== name ? type : '';
    const reportingState = incident.is_closed ? 'Reporting closed' : 'Active reporting';
    return [distinctType, reportingState].filter(Boolean).join(' · ');
}

function lguAndProvince(row) {
    const formatPlace = (value) => String(value || '').trim().toLocaleLowerCase()
        .replace(/(^|[\s.-])\p{L}/gu, (letter) => letter.toLocaleUpperCase())
        .replace(/\b(Mlgu|Plgu|Lgu)\b/g, (word) => word.toLocaleUpperCase());
    const name = formatPlace(row.requesting_agency || row.municipality || '-');
    const province = formatPlace(row.province || '');
    if (!province || name.toLocaleLowerCase().includes(province.toLocaleLowerCase())) return name;
    return `${name}, ${province}`;
}

function IncidentLatestStatus({ incident }) {
    return <div className="space-y-1.5">
        <ReportStatusTracks row={{
            lgu_report_status: incident.latest_report_status || 'draft',
            lgu_dromic_validation_status: incident.latest_validation_status,
            lgu_dromic_review_note: incident.latest_validation_status === 'needs_lgu_action' ? incident.report_action_note : null,
        }} />
        {incident.report_needs_lgu_action && incident.latest_validation_status !== 'needs_lgu_action' && <DocumentActionNotice label="DROMIC / SitRep" note={incident.report_action_note} />}
        {incident.request_needs_lgu_action && <DocumentActionNotice label="Request Letter" note={incident.request_action_note} />}
    </div>;
}

function DocumentActionNotice({ label, note }) {
    return <div className="max-w-[320px] rounded-lg border border-rose-200 bg-rose-50 p-2 text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100">
        <span className="inline-flex items-center gap-1 text-xs font-black uppercase"><AlertTriangle className="h-3.5 w-3.5 shrink-0" />{label} · Needs LGU Action</span>
        {note && <p className="mt-1 whitespace-normal text-xs font-semibold leading-4">{note}</p>}
    </div>;
}

function fniProcessingLabel(row) {
    const letter = row?.dswd_response_letter;
    if (letter?.processing_label) return letter.processing_label;
    if (row?.relief_augmentation_request?.processing_label) return row.relief_augmentation_request.processing_label;

    const hasAdvance = Boolean(letter?.advance?.available);
    const hasSigned = Boolean(letter?.signed?.available);
    if (hasSigned) return 'Acted';
    if (hasAdvance) return 'Awaiting signed response letter';

    const status = String(row?.relief_augmentation_request?.status || '').trim();
    if (!status) return 'Pending';
    return status.replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function DswdResponseLetterCell({ row, busyId, onAcknowledge, onView }) {
    const letter = row?.dswd_response_letter;
    if (!letter?.advance?.available && !letter?.signed?.available) {
        return <span className="text-xs text-slate-400">Awaiting DSWD release</span>;
    }

    return (
        <div className="flex min-w-[200px] flex-col gap-2">
            {letter.advance?.available && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-2 dark:border-amber-900 dark:bg-amber-950/30">
                    <p className="text-[10px] font-black uppercase tracking-wide text-amber-800">Advance copy</p>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            title="View advance response letter"
                            onClick={() => onView?.('advance', letter)}
                            className="inline-flex h-7 items-center gap-1 rounded border border-amber-300 bg-white px-2 text-[11px] font-black text-amber-900"
                        >
                            <Eye className="h-3.5 w-3.5" /> View
                        </button>
                        {letter.advance.acked_at ? (
                            <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700"><CheckCircle2 className="h-3.5 w-3.5" />Acked {formatDateTime(letter.advance.acked_at)}</span>
                        ) : (
                            <button
                                type="button"
                                disabled={busyId === `${row.id}-advance`}
                                onClick={() => onAcknowledge(row, 'advance')}
                                className="inline-flex h-7 items-center gap-1 rounded bg-amber-600 px-2 text-[11px] font-black text-white disabled:opacity-60"
                            >
                                Acknowledge
                            </button>
                        )}
                    </div>
                </div>
            )}
            {letter.signed?.available && (
                <div className="rounded-md border border-emerald-200 bg-emerald-50 p-2 dark:border-emerald-900 dark:bg-emerald-950/30">
                    <p className="text-[10px] font-black uppercase tracking-wide text-emerald-800">Signed copy</p>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            title="View signed response letter"
                            onClick={() => onView?.('signed', letter)}
                            className="inline-flex h-7 items-center gap-1 rounded border border-emerald-300 bg-white px-2 text-[11px] font-black text-emerald-900"
                        >
                            <Eye className="h-3.5 w-3.5" /> View
                        </button>
                        {letter.signed.acked_at ? (
                            <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700"><CheckCircle2 className="h-3.5 w-3.5" />Acked {formatDateTime(letter.signed.acked_at)}</span>
                        ) : (
                            <button
                                type="button"
                                disabled={busyId === `${row.id}-signed`}
                                onClick={() => onAcknowledge(row, 'signed')}
                                className="inline-flex h-7 items-center gap-1 rounded bg-emerald-700 px-2 text-[11px] font-black text-white disabled:opacity-60"
                            >
                                Acknowledge
                            </button>
                        )}
                    </div>
                </div>
            )}
            {!letter.signed?.available && letter.advance?.available && (
                <p className="text-[10px] font-semibold text-slate-500">Signed copy will follow after e-PIRMA routing.</p>
            )}
        </div>
    );
}

function ReportStatusTracks({ row }) {
    return <DromicReportStatus
        submissionStatus={row.lgu_report_status || row.status || 'draft'}
        classification={row.lgu_dromic_report_classification}
        validationStatus={row.lgu_dromic_validation_status}
        correctionScope={row.lgu_dromic_correction_scope}
        seenAt={row.lgu_dromic_seen_at}
        viewerName={row.lgu_dromic_viewer?.name}
        ackedAt={row.lgu_dromic_acked_at}
        ackerName={row.lgu_dromic_acker?.name}
        reviewNote={row.lgu_dromic_review_note}
        reviewerName={row.lgu_dromic_reviewer?.name}
        reviewedAt={row.lgu_dromic_reviewed_at}
        isCorrectedVersion={Boolean(row.lgu_correction_of_id) && row.lgu_correction_target !== 'request'}
    />;
    /*
    const status = row.lgu_report_status || row.status || 'draft';
    const submission = {
        draft: ['Draft · Editable', 'bg-amber-50 text-amber-800'],
        final: ['Final · Awaiting submission', 'bg-blue-50 text-blue-800'],
        advance_submitted: ['Submitted · Advance copy', 'bg-orange-50 text-orange-800'],
        submitted: ['Submitted · Signed copies complete', 'bg-emerald-50 text-emerald-800'],
    }[status] || [String(status).replaceAll('_', ' '), 'bg-slate-100 text-slate-700'];
    const validationStatus = row.lgu_dromic_validation_status || (['advance_submitted', 'submitted'].includes(status) ? 'pending_review' : 'not_submitted');
    const validation = {
        not_submitted: ['Validation starts after submission', 'bg-slate-100 text-slate-600', null],
        pending_review: ['DROMIC document · Awaiting review', 'bg-slate-100 text-slate-700', null],
        under_review: ['DROMIC document · Under review', 'bg-blue-100 text-blue-800', null],
        needs_lgu_action: ['DROMIC document · Needs LGU action', 'bg-rose-100 text-rose-800', AlertTriangle],
        validated_no_findings: ['DROMIC document · Validated — no findings', 'bg-emerald-100 text-emerald-800', CheckCircle2],
    }[validationStatus];
    const hasReliefRequest = Boolean(row.lgu_dromic_payload?.has_relief_request);
    const reliefValidationStatus = row.lgu_relief_validation_status || (['advance_submitted', 'submitted'].includes(status) ? 'pending_review' : 'not_submitted');
    const augmentation = !hasReliefRequest
        ? ['Request letter · Not included', 'bg-slate-100 text-slate-600']
        : status === 'advance_submitted' && !row.lgu_signed_request_path
            ? ['Request letter · PDF required', 'bg-rose-100 text-rose-800']
        : ({
            not_submitted: ['Request letter · Not submitted', 'bg-slate-100 text-slate-600'],
            pending_review: ['Request letter · Awaiting DRRS review', 'bg-slate-100 text-slate-700'],
            under_review: ['Request letter · Under DRRS review', 'bg-blue-100 text-blue-800'],
            needs_lgu_action: ['Request letter · Needs LGU action', 'bg-rose-100 text-rose-800'],
            validated_no_findings: ['Request letter · Validated — no findings', 'bg-emerald-100 text-emerald-800'],
        }[reliefValidationStatus] || ['Request letter · Awaiting DRRS review', 'bg-slate-100 text-slate-700']);
    const reliefWorkflow = {
        for_drmd_aa_review: 'Processing: DRMD review',
        for_drmd_chief_directive: 'Processing: Chief directive',
        for_drmd_aa_routing: 'Processing: approved for routing',
        routed_to_drrs: 'Processing: routed to DRRS',
    }[row.lgu_routing_status];
    const ValidationIcon = validation[2];
    const lifecycleLabel = status !== 'draft' && row.lgu_dromic_report_classification === 'terminal'
        ? 'Terminal Report'
        : status !== 'draft' && row.lgu_dromic_report_classification === 'first_and_final'
            ? 'First and Final Report'
            : null;

    return (
        <div className="flex min-w-[250px] flex-col items-start gap-1.5">
            {lifecycleLabel && <span className="rounded-full bg-violet-50 px-2 py-1 text-xs font-black uppercase text-violet-800">{lifecycleLabel}</span>}
            <span className={`rounded-full px-2 py-1 text-xs font-black uppercase ${submission[1]}`}>Submission · {submission[0]}</span>
            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${validation[1]}`}>{ValidationIcon && <ValidationIcon className="h-3.5 w-3.5" />}{validation[0]}</span>
            {row.lgu_dromic_seen_at && <p className="inline-flex items-center gap-1 text-[11px] font-bold text-sky-700"><Eye className="h-3.5 w-3.5" />Seen by {row.lgu_dromic_viewer?.name || 'DSWD recipient'} · {formatDate(row.lgu_dromic_seen_at)}</p>}
            {false && <span className={`rounded-full px-2 py-1 text-xs font-black uppercase ${augmentation[1]}`}>{augmentation[0]}</span>}
            {row.lgu_dromic_review_note && <p title={row.lgu_dromic_review_note} className="mt-1 line-clamp-2 max-w-[280px] text-xs font-semibold leading-5 text-slate-700 dark:text-zinc-200">{row.lgu_dromic_review_note}</p>}
            {row.lgu_dromic_reviewer?.name && <p className="text-[11px] text-slate-500">Reviewed by {row.lgu_dromic_reviewer.name} · {formatDate(row.lgu_dromic_reviewed_at)}</p>}
            {false && hasReliefRequest && reliefWorkflow && <p className="text-[11px] font-bold text-violet-700">{reliefWorkflow}</p>}
            {false && row.lgu_relief_review_note && <p title={row.lgu_relief_review_note} className="line-clamp-2 max-w-[280px] text-xs font-semibold leading-5 text-slate-700 dark:text-zinc-200">{row.lgu_relief_review_note}</p>}
        </div>
    );
    */
}

function RequestStatusTrack({ row }) {
    const reportStatus = row.lgu_report_status || row.status || 'draft';
    const hasSignedRequest = Boolean(row.lgu_signed_request_path);
    const validationStatus = hasSignedRequest
        ? (row.lgu_relief_validation_status || (['advance_submitted', 'submitted'].includes(reportStatus) ? 'pending_review' : 'not_submitted'))
        : 'not_submitted';
    const validation = {
        not_submitted: ['Not submitted', 'bg-slate-100 text-slate-600', Clock3],
        pending_review: ['Awaiting DRRS review', 'bg-slate-100 text-slate-700', Clock3],
        under_review: ['Under DRRS review', 'bg-blue-100 text-blue-800', Eye],
        needs_lgu_action: ['Needs LGU Action', 'bg-rose-100 text-rose-800', AlertTriangle],
        validated_no_findings: ['Validated — no findings', 'bg-emerald-100 text-emerald-800', CheckCircle2],
        superseded: ['Superseded by corrected submission', 'bg-slate-100 text-slate-700', CheckCircle2],
    }[validationStatus] || ['Awaiting DRRS review', 'bg-slate-100 text-slate-700', Clock3];
    const routing = {
        for_drmd_aa_review: 'DRMD AA review',
        for_drmd_chief_directive: 'Chief directive',
        for_drmd_aa_routing: 'Approved for routing',
        routed_to_drrs: 'Routed to DRRS',
    }[row.lgu_routing_status] || 'Awaiting routing';
    const Icon = validation[2];

    return <div className="flex min-w-[250px] flex-col items-start gap-1.5">
        {row.lgu_correction_of_id && row.lgu_correction_target === 'request' && hasSignedRequest && <span className="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-1 text-xs font-black uppercase text-sky-800 ring-1 ring-sky-200"><CheckCircle2 className="h-3.5 w-3.5" />Corrected version submitted</span>}
        <span className={`rounded-full px-2 py-1 text-xs font-black uppercase ${hasSignedRequest ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>{hasSignedRequest ? 'Submitted Signed PDF' : 'Signed PDF pending'}</span>
        {validationStatus !== 'not_submitted' && <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-black uppercase ${validation[1]}`}><Icon className="h-3.5 w-3.5" />{validation[0]}</span>}
        {validationStatus === 'needs_lgu_action' && row.lgu_relief_correction_scope && <span className="rounded-full bg-rose-50 px-2 py-1 text-[10px] font-black uppercase text-rose-700 ring-1 ring-rose-200">Correction: {row.lgu_relief_correction_scope === 'both' ? 'Encoding + PDF' : row.lgu_relief_correction_scope === 'encoding' ? 'Encoded request entries' : 'PDF document'}</span>}
        {validationStatus === 'needs_lgu_action' && row.lgu_relief_review_note && <div className="mt-0.5 w-full max-w-[320px] rounded-lg border border-rose-200 bg-rose-50 p-2.5 text-rose-900 shadow-sm dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100"><p className="flex items-center gap-1 text-[10px] font-black uppercase tracking-wide"><AlertTriangle className="h-3.5 w-3.5 shrink-0" />Required LGU correction</p><p className="mt-1 whitespace-normal text-xs font-semibold leading-5">{row.lgu_relief_review_note}</p></div>}
        {row.lgu_relief_acked_at
            ? <p className="flex w-full items-center gap-1 pt-0.5 text-[11px] font-bold text-emerald-700"><CheckCircle2 className="h-3.5 w-3.5 shrink-0" />Acknowledged by {row.lgu_relief_acker?.name || row.lgu_relief_viewer?.name || 'DRRS recipient'} · {formatDateTime(row.lgu_relief_acked_at)}</p>
            : (row.lgu_relief_seen_at && <p className="flex w-full items-center gap-1 pt-0.5 text-[11px] font-bold text-sky-700"><Eye className="h-3.5 w-3.5 shrink-0" />Seen by {row.lgu_relief_viewer?.name || 'DRRS recipient'} · {formatDateTime(row.lgu_relief_seen_at)}</p>)}
        {hasSignedRequest && <span className="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-1 text-xs font-black uppercase text-violet-800"><Send className="h-3.5 w-3.5" />{routing}</span>}
        {validationStatus !== 'needs_lgu_action' && row.lgu_relief_review_note && <p title={row.lgu_relief_review_note} className="line-clamp-2 max-w-[280px] text-xs font-semibold leading-5 text-slate-700 dark:text-zinc-200">{row.lgu_relief_review_note}</p>}
    </div>;
}

function StatusBadge({ row }) {
    const status = row.lgu_report_status || row.status || 'draft';
    const labels = {
        draft: 'Draft · Editable',
        final: 'Final · Awaiting submission',
        advance_submitted: 'Submitted · Advance copy',
        submitted: 'Submitted · Signed copies complete',
    };
    const styles = {
        draft: 'bg-amber-50 text-amber-800',
        final: 'bg-blue-50 text-blue-800',
        advance_submitted: 'bg-orange-50 text-orange-800',
        submitted: 'bg-emerald-50 text-emerald-800',
    };
    const lifecycleLabel = status !== 'draft' && row.lgu_dromic_report_classification === 'terminal'
        ? 'Terminal Report'
        : status !== 'draft' && row.lgu_dromic_report_classification === 'first_and_final'
            ? 'First and Final Report'
            : null;
    const label = labels[status] || String(status).replaceAll('_', ' ');
    const classes = styles[status] || 'bg-slate-100 text-slate-700';

    return (
        <div className="flex flex-col items-start gap-1.5">
            {lifecycleLabel && <span className="rounded-full bg-violet-50 px-2 py-1 text-xs font-black uppercase text-violet-800">{lifecycleLabel}</span>}
            <span className={`rounded-full px-2 py-1 text-xs font-black uppercase ${classes}`}>{label}</span>
        </div>
    );
}

function reportSequenceLabel(row) {
    if ((row.lgu_report_status || row.status) === 'draft') return 'Draft · Unnumbered';
    if (row.lgu_dromic_report_classification === 'first_and_final') return 'First and Final DROMIC / Situational Report';
    if (row.lgu_dromic_report_classification === 'terminal') return 'Terminal DROMIC / Situational Report';

    const number = Number(row.lgu_dromic_report_number || 1);
    return `DROMIC / Situational Report No. ${number}`;
}

function ReportingChoice({ checked, disabled = false, onChange, title, description }) {
    return (
        <label className={`flex items-start gap-3 rounded-lg border p-3 transition ${disabled ? 'cursor-not-allowed border-slate-200 bg-slate-100 opacity-60' : checked ? 'cursor-pointer border-blue-500 bg-white shadow-sm' : 'cursor-pointer border-blue-200 bg-white/70 hover:border-blue-400'}`}>
            <input type="radio" name="report_classification_choice" checked={checked} disabled={disabled} onChange={onChange} className="mt-1" />
            <span>
                <span className="block text-sm font-black text-slate-900">{title}</span>
                <span className="mt-1 block text-xs leading-5 text-slate-600">{description}</span>
            </span>
        </label>
    );
}

function FloatingDromicProgressCard({ open, onToggle, percent = 0, items = [], onJump = null }) {
    if (!open) {
        return null;
    }

    const statusStyle = {
        complete: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        needs: 'border-rose-200 bg-rose-50 text-rose-700',
        locked: 'border-amber-200 bg-amber-50 text-amber-800',
        na: 'border-slate-200 bg-slate-100 text-slate-600',
    };
    const statusLabel = {
        complete: 'DONE',
        needs: 'NEEDS INPUT',
        locked: 'LOCKED',
        na: 'N/A',
    };
    const jumpPropsFor = (anchor) => {
        if (!anchor || !onJump) return {};

        return {
            role: 'button',
            tabIndex: 0,
            onClick: (event) => {
                event.stopPropagation();
                onJump(anchor);
            },
            onKeyDown: (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    event.stopPropagation();
                    onJump(anchor);
                }
            },
        };
    };
    const renderProgressChildren = (children = [], depth = 0) => (
        <div className={`mt-2 space-y-1 border-l-2 border-emerald-200 ${depth ? 'pl-2' : 'pl-3'}`}>
            {children.map((child) => (
                <div
                    key={child.label}
                    className={`rounded-lg p-1 text-xs transition ${child.anchor ? 'cursor-pointer hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-400 dark:hover:bg-emerald-950/30' : ''}`}
                    {...jumpPropsFor(child.anchor)}
                >
                    <div className="flex items-start justify-between gap-2">
                        <span>
                            <span className={`block font-black uppercase ${depth ? 'text-[11px]' : ''} text-slate-800 dark:text-zinc-100`}>{child.label}</span>
                            <span className="text-slate-500">{child.summary}</span>
                        </span>
                        <span className={`shrink-0 rounded-full border px-2 py-0.5 text-[9px] font-black uppercase ${statusStyle[child.status] || statusStyle.needs}`}>
                            {statusLabel[child.status] || 'CHECK'}
                        </span>
                    </div>
                    {child.children?.length > 0 && renderProgressChildren(child.children, depth + 1)}
                </div>
            ))}
        </div>
    );

    return (
        <div className="pointer-events-none fixed bottom-[6.75rem] left-5 top-[8rem] z-[95] w-[min(92vw,390px)]">
            <div className="pointer-events-auto flex h-full min-h-0 flex-col overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-2xl dark:border-emerald-900 dark:bg-zinc-950">
                <div className="flex w-full items-center justify-between gap-3 bg-gradient-to-r from-emerald-700 to-cyan-700 px-4 py-3 text-left text-white">
                    <span className="flex items-center gap-3">
                        <span className="flex h-10 w-10 items-center justify-center rounded-full bg-white/15">
                            <ListChecks className="h-5 w-5" />
                        </span>
                        <span>
                            <span className="block text-sm font-black uppercase tracking-wide">REPORT PROGRESS</span>
                            <span className="text-xs font-semibold text-emerald-50">{percent}% of visible sections ready</span>
                        </span>
                    </span>
                    <button type="button" onClick={onToggle} className="rounded-full bg-white/15 p-1.5 hover:bg-white/25" aria-label="Hide report progress">
                        <ChevronDown className="h-5 w-5" />
                    </button>
                </div>
                <div className="h-2 bg-slate-100">
                    <div className="h-full bg-emerald-500 transition-all" style={{ width: `${Math.min(100, Math.max(0, percent))}%` }} />
                </div>
                <div className="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                    {items.map((item) => (
                        <div
                            key={item.label}
                            className={`rounded-xl border border-slate-200 bg-slate-50 p-3 transition dark:border-zinc-800 dark:bg-zinc-900 ${item.anchor ? 'cursor-pointer hover:border-emerald-300 hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-400 dark:hover:bg-emerald-950/30' : ''}`}
                            {...jumpPropsFor(item.anchor)}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-xs font-black uppercase tracking-wide text-slate-950 dark:text-white">{item.label}</p>
                                    <p className="mt-1 text-xs font-semibold text-slate-500">{item.summary}</p>
                                </div>
                                <span className={`shrink-0 rounded-full border px-2 py-1 text-[10px] font-black uppercase ${statusStyle[item.status] || statusStyle.needs}`}>
                                    {statusLabel[item.status] || 'CHECK'}
                                </span>
                            </div>
                            {item.children?.length > 0 && renderProgressChildren(item.children)}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function DromicIssueNotice({ issues = [], tableName }) {
    if (!issues.length) return null;

    const generalIssues = issues.filter((issue) => issue.index === undefined || issue.index === null);

    return (
        <div className="mt-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
            <p className="font-black">
                Error detected! Please check your entries in the <span className="underline decoration-rose-300 underline-offset-2">{tableName}</span>.
            </p>
            {generalIssues.length > 0 && (
                <ul className="mt-2 list-inside list-disc space-y-1">
                    {generalIssues.map((issue) => <li key={`${issue.type || 'general'}-${issue.message}`}>{issue.message}</li>)}
                </ul>
            )}
        </div>
    );
}

function AdditionalDromicSections({
    form,
    lguProfile,
    areaRows = [],
    barangayOptions = [],
    incidentTypes = [],
    affectedPopulationReady = false,
    relatedIncidentRows = [],
    casualtyRows = [],
    infrastructureDamageRows = [],
    agricultureDamageRows = [],
    classSuspensionRows = [],
    workSuspensionRows = [],
    roadBridgeRows = [],
    powerLifelineRows = [],
    waterLifelineRows = [],
    communicationLifelineRows = [],
    seaportRows = [],
    airportRows = [],
    landTransportTerminalRows = [],
    strandedTransportRows = [],
    calamityDeclarationRows = [],
    preemptiveEvacuationRows = [],
    clusterGapRows = [],
    responseActionRows = [],
    photoDocumentationRows = [],
    photoCollageRows = [],
    addSupportingRow,
    updateSupportingRow,
    requestRemoveRow,
    isSectionNotApplicable = () => false,
    toggleSectionNotApplicable = () => {},
    issues = {},
}) {
    const affectedBarangayOptions = (areaRows || [])
        .map((row) => ({ value: row.area || row.psgc_code, label: row.area || row.psgc_code }))
        .filter((option) => option.value && option.label);
    const affectedBarangayLabels = affectedBarangayOptions.map((option) => option.label);
    const allAffectedBarangayOption = { value: 'All affected barangays', label: 'All affected barangays' };
    const affectedOrAllBarangayOptions = affectedBarangayOptions.length ? [allAffectedBarangayOption, ...affectedBarangayOptions] : affectedBarangayOptions;
    const lguName = lguProfile?.name || '';
    const issueFor = (list = [], index) => list.find((issue) => issue.index === index);
    const rowHasValue = (row = {}, fields = []) => fields.some((field) => String(row[field] ?? '').trim() !== '');
    const rowComplete = (row = {}, fields = []) => fields.every((field) => String(row[field] ?? '').trim() !== '');
    const validRemark = (issue, row = {}, valueFields = [], requiredFields = []) => {
        if (issue) return issue.message;
        if (!rowHasValue(row, valueFields)) return 'Awaiting Entry!';
        if (!rowComplete(row, requiredFields)) return 'Complete started row';
        return 'VALID DATA!';
    };
    const rowClass = (issue) => issue ? 'bg-rose-50 dark:bg-rose-950/30' : '';
    const set = (key, index, field) => (value) => updateSupportingRow(key, index, field, value);
    const askRemove = (key, index, label) => requestRemoveRow({ key, index, label });
    const uniqueOptions = (options = []) => [...options].filter((value, index, list) => value && list.indexOf(value) === index);
    const incidentTypeOptions = withOthersOption(incidentTypes);
    const sortRowsByFields = (rows = [], fields = []) => rows
        .map((row, originalIndex) => ({ row, originalIndex }))
        .sort((a, b) => fields
            .map((field) => String(a.row[field] || '').localeCompare(String(b.row[field] || ''), undefined, { sensitivity: 'base' }))
            .find((result) => result !== 0) || a.originalIndex - b.originalIndex);
    const sortedRelatedIncidentRows = sortRowsByFields(relatedIncidentRows, ['incident_type', 'barangay']);
    const sortedCasualtyRows = casualtyRows
        .map((row, originalIndex) => ({ row, originalIndex }))
        .sort((a, b) => ['Injured', 'Missing', 'Dead'].indexOf(a.row.casualty_status) - ['Injured', 'Missing', 'Dead'].indexOf(b.row.casualty_status));
    const sortedInfrastructureDamageRows = sortRowsByFields(infrastructureDamageRows, ['structure_type', 'barangay']);
    const sortedAgricultureDamageRows = sortRowsByFields(agricultureDamageRows, ['classification', 'type', 'barangay']);
    const sortedRoadBridgeRows = sortRowsByFields(roadBridgeRows, ['type', 'classification', 'barangay']);
    const agricultureTotals = agricultureDamageRows.reduce((totals, row) => {
        const areaNoChance = Number(row.area_no_chance_recovery || 0);
        const areaWithChance = Number(row.area_with_chance_recovery || 0);
        const infraTotally = Number(row.infrastructure_totally_damaged || 0);
        const infraPartially = Number(row.infrastructure_partially_damaged || 0);
        const productionHeads = Number(row.production_loss_heads || 0);
        const productionCostPerHead = Number(row.production_loss_cost_per_head || 0);
        const areaTotal = Number(row.area_no_chance_recovery || 0) + Number(row.area_with_chance_recovery || 0);
        const infraTotal = Number(row.infrastructure_totally_damaged || 0) + Number(row.infrastructure_partially_damaged || 0);
        const productionTotal = Number(row.production_loss_heads || 0) * Number(row.production_loss_cost_per_head || 0);

        return {
            rows: totals.rows + 1,
            farmers: totals.farmers + Number(row.affected_farmers_fisherfolks || 0),
            area_no_chance: totals.area_no_chance + areaNoChance,
            area_with_chance: totals.area_with_chance + areaWithChance,
            area: totals.area + areaTotal,
            infra_totally: totals.infra_totally + infraTotally,
            infra_partially: totals.infra_partially + infraPartially,
            infra: totals.infra + infraTotal,
            production_heads: totals.production_heads + productionHeads,
            production_cost_per_head: totals.production_cost_per_head + productionCostPerHead,
            production: totals.production + productionTotal,
            volume: totals.volume + Number(row.production_loss_volume_mt || 0),
            value: totals.value + Number(row.production_loss_value || 0),
        };
    }, {
        rows: 0,
        farmers: 0,
        area_no_chance: 0,
        area_with_chance: 0,
        area: 0,
        infra_totally: 0,
        infra_partially: 0,
        infra: 0,
        production_heads: 0,
        production_cost_per_head: 0,
        production: 0,
        volume: 0,
        value: 0,
    });
    const sumRows = (rows = [], fields = []) => rows.reduce((totals, row) => fields.reduce((carry, field) => ({
        ...carry,
        [field]: Number(carry[field] || 0) + Number(row[field] || 0),
    }), totals), Object.fromEntries(fields.map((field) => [field, 0])));
    const relatedIncidentTotals = { rows: relatedIncidentRows.length };
    const casualtyTotals = sumRows(casualtyRows, ['age']);
    const infrastructureTotals = sumRows(infrastructureDamageRows, ['length_meters', 'estimated_cost']);
    const classSuspensionTotals = { rows: classSuspensionRows.length };
    const workSuspensionTotals = { rows: workSuspensionRows.length };
    const roadBridgeTotals = { rows: roadBridgeRows.length };
    const relatedIncidentValueFields = ['barangay', 'incident_type', 'incident_type_other', 'occurrence_at', 'description', 'actions_taken', 'status'];
    const relatedIncidentRequiredFields = ['barangay', 'incident_type', 'occurrence_at', 'description', 'actions_taken', 'status'];
    const casualtyValueFields = ['casualty_status', 'last_name', 'first_name', 'middle_name', 'age', 'sex', 'address', 'cause', 'remarks', 'source_of_data'];
    const casualtyRequiredFields = ['casualty_status', 'last_name', 'first_name', 'middle_name', 'age', 'sex', 'address', 'cause', 'remarks', 'source_of_data'];
    const infrastructureValueFields = ['barangay', 'structure_type', 'structure_type_other', 'damage_description', 'length_meters', 'estimated_cost', 'remarks'];
    const infrastructureRequiredFields = ['barangay', 'structure_type', 'damage_description', 'length_meters', 'estimated_cost', 'remarks'];
    const agricultureValueFields = ['barangay', 'classification', 'classification_other', 'type', 'type_other', 'affected_farmers_fisherfolks', 'area_no_chance_recovery', 'area_with_chance_recovery', 'infrastructure_totally_damaged', 'infrastructure_partially_damaged', 'production_loss_heads', 'production_loss_cost_per_head', 'production_loss_volume_mt', 'production_loss_value'];
    const agricultureRequiredFields = ['barangay', 'classification', 'type', 'affected_farmers_fisherfolks', 'area_no_chance_recovery', 'area_with_chance_recovery', 'infrastructure_totally_damaged', 'infrastructure_partially_damaged', 'production_loss_heads', 'production_loss_cost_per_head', 'production_loss_volume_mt', 'production_loss_value'];
    const roadBridgeValueFields = ['barangay', 'type', 'type_other', 'classification', 'classification_other', 'road_section', 'status', 'reported_not_passable_at', 'reported_passable_at', 'remarks'];
    const roadBridgeRequiredFields = ['barangay', 'type', 'classification', 'road_section', 'status', 'reported_not_passable_at', 'remarks'];
    const lockedNotice = 'Complete the Status of Affected Population first before encoding this section. DROMIS will use the selected affected barangays as the official location options here.';
    const utilityTypeOptions = withOthersOption(['Outage', 'Interruption', 'Low pressure', 'Service disruption', 'Restored']);
    const communicationStatusOptions = withOthersOption(['Non-operational', 'Intermittent', 'No signal', 'Restored', 'Operational']);
    const portStatusOptions = withOthersOption(['Non-operational', 'Operational', 'Cancelled trips', 'Resumed trips', 'Limited operations']);
    const calamityTypeOptions = withOthersOption(['Province-wide', 'City-wide', 'Municipality-wide', 'Barangay']);
    const clusterOptions = withOthersOption(['Standby funds', 'Food and NFIs', 'IDP Protection', 'CCCM', 'MHPSS', 'WASH', 'Education', 'Emergency Telecom (ETC)', 'Logistics', 'Law and Order', 'Search, Rescue and Retrieval (SRR)', 'MDM', 'Shelter', 'Early Recovery']);
    const responseOfficeOptions = withOthersOption(responseActionOfficeOptions);
    const lifelineCoverageColumns = (rowsKey, barangayOptions) => ([
        {
            label: 'Coverage',
            field: 'coverage',
            render: (row, index) => (
                <SelectorCell
                    value={row.coverage}
                    onChange={(value) => updateSupportingRow(rowsKey, index, {
                        coverage: value,
                        barangay: value === 'Selected barangay' ? row.barangay : '',
                    })}
                    options={lifelineCoverageOptions}
                    placeholder="Search coverage..."
                    allLabel="Coverage"
                />
            ),
        },
        {
            label: 'Barangay',
            field: 'barangay',
            required: false,
            render: (row, index) => (
                row.coverage === 'Selected barangay'
                    ? (
                        <SelectorCell
                            value={row.barangay}
                            onChange={(value) => updateSupportingRow(rowsKey, index, 'barangay', value)}
                            options={barangayOptions}
                            placeholder="Search barangay..."
                            allLabel="Barangay"
                        />
                    )
                    : <td className="nowrap-cell border border-slate-300 px-3 py-2 text-center text-sm font-black text-slate-700">{row.coverage || 'Select coverage first'}</td>
            ),
        },
    ]);

    return (
        <div className="space-y-5">
            <DromicSectionCard id="dromic-section-related-incidents">
                <AdditionalSectionHeader
                    title="Related Incidents"
                    description="Record related incidents such as flooding, landslide, fire, road blockage, or other cascading hazards."
                    onAdd={affectedPopulationReady && !isSectionNotApplicable('related_incidents') ? () => addSupportingRow('related_incident_rows', emptyRelatedIncidentRow(lguProfile)) : null}
                    button="Add related incident"
                    naControl={<NotApplicableToggle checked={isSectionNotApplicable('related_incidents')} onChange={() => toggleSectionNotApplicable('related_incidents')} />}
                />
                <SupplementarySectionInstruction />
                {isSectionNotApplicable('related_incidents') ? <NotApplicableNotice section="Related Incidents" /> : !affectedPopulationReady ? <LockedSectionNotice>{lockedNotice}</LockedSectionNotice> : <div className="dromic-table-scroll rounded-md border border-slate-200 dark:border-zinc-800">
                    <table className="dromic-wide-table w-full min-w-[1500px] table-auto border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr className={dromicHeadClass}>
                                {['No.', 'Barangay', 'Type of Incident', 'Date and Time of Occurrence', 'Description', 'Actions Taken', 'Status', 'Validation Remarks', 'Action'].map((head) => (
                                    <th key={head} className="border border-slate-700 px-3 py-2 font-black uppercase">{head}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {sortedRelatedIncidentRows.length ? sortedRelatedIncidentRows.map(({ row, originalIndex }, displayIndex) => {
                                const issue = issueFor(issues.relatedIncidentIssues, originalIndex);

                                return (
                                    <tr key={originalIndex} className={rowClass(issue)}>
                                        <NumberCell value={displayIndex + 1} />
                                        <SelectorCell value={row.barangay} onChange={set('related_incident_rows', originalIndex, 'barangay')} options={affectedBarangayOptions} placeholder="Search barangay..." allLabel="Barangay" />
                                        <RelatedIncidentTypeCell
                                            value={row.incident_type}
                                            otherValue={row.incident_type_other}
                                            onChange={(value) => updateSupportingRow('related_incident_rows', originalIndex, {
                                                incident_type: value,
                                                incident_type_other: value === 'Others' ? row.incident_type_other : '',
                                            })}
                                            onOtherChange={set('related_incident_rows', originalIndex, 'incident_type_other')}
                                            options={incidentTypeOptions}
                                        />
                                        <EditableCell type="datetime-local" value={row.occurrence_at} onChange={set('related_incident_rows', originalIndex, 'occurrence_at')} />
                                        <TextareaCell value={row.description} onChange={set('related_incident_rows', originalIndex, 'description')} placeholder="Brief description" />
                                        <TextareaCell value={row.actions_taken} onChange={set('related_incident_rows', originalIndex, 'actions_taken')} placeholder="Actions taken" />
                                        <TextareaCell value={row.status} onChange={set('related_incident_rows', originalIndex, 'status')} placeholder="Current status" />
                                        <RemarkCell text={validRemark(issue, row, relatedIncidentValueFields, relatedIncidentRequiredFields)} issue={issue} />
                                        <DeleteCell onClick={() => askRemove('related_incident_rows', originalIndex, `Related Incident Row ${displayIndex + 1}`)} />
                                    </tr>
                                );
                            }) : <EmptyRows colSpan={9} message="No related incident encoded." />}
                        </tbody>
                        {relatedIncidentRows.length > 0 && (
                            <tfoot>
                                <tr className="bg-cyan-100 font-black text-slate-950">
                                    <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                                    <td colSpan="6" className="border border-slate-700 px-3 py-2 text-center">{formatNumber(relatedIncidentTotals.rows)} related incident row/s</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">Validate above</td>
                                    <td className="border border-slate-700 px-3 py-2" />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>}
                {affectedPopulationReady && !isSectionNotApplicable('related_incidents') && <DromicIssueNotice issues={issues.relatedIncidentIssues} tableName="Related Incidents table" />}
            </DromicSectionCard>

            <DromicSectionCard id="dromic-section-casualties">
                <AdditionalSectionHeader
                    title="Casualties"
                    description="Encode injured, missing, and dead persons separately. Keep source of data clear for validation."
                    onAdd={affectedPopulationReady && !isSectionNotApplicable('casualties') ? () => addSupportingRow('casualty_rows', emptyCasualtyRow('Injured', lguProfile)) : null}
                    secondaryActions={affectedPopulationReady && !isSectionNotApplicable('casualties') ? [
                        ['Add missing', () => addSupportingRow('casualty_rows', emptyCasualtyRow('Missing', lguProfile))],
                        ['Add dead', () => addSupportingRow('casualty_rows', emptyCasualtyRow('Dead', lguProfile))],
                    ] : []}
                    button="Add injured"
                    naControl={<NotApplicableToggle checked={isSectionNotApplicable('casualties')} onChange={() => toggleSectionNotApplicable('casualties')} />}
                />
                <SupplementarySectionInstruction />
                {isSectionNotApplicable('casualties') ? <NotApplicableNotice section="Casualties" /> : !affectedPopulationReady ? <LockedSectionNotice>{lockedNotice}</LockedSectionNotice> : <div className="dromic-table-scroll rounded-md border border-slate-200 dark:border-zinc-800">
                    <table className="dromic-wide-table w-full min-w-[1380px] table-auto border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr className={dromicHeadClass}>
                                {['No.', 'Status', 'Last Name', 'First Name', 'Middle Name', 'Age', 'Sex', 'Address Barangay', 'Cause', 'Remarks', 'Source of Data', 'Validation Remarks', 'Action'].map((head) => (
                                    <th key={head} className="border border-slate-700 px-3 py-2 font-black uppercase">{head}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {sortedCasualtyRows.length ? sortedCasualtyRows.map(({ row, originalIndex }, displayIndex) => {
                                const issue = issueFor(issues.casualtyIssues, originalIndex);

                                return (
                                    <tr key={originalIndex} className={rowClass(issue)}>
                                        <NumberCell value={displayIndex + 1} />
                                        <SelectorCell value={row.casualty_status} onChange={set('casualty_rows', originalIndex, 'casualty_status')} options={['Injured', 'Missing', 'Dead'].map((value) => ({ value, label: value }))} placeholder="Search status..." allLabel="Status" />
                                        <EditableCell value={row.last_name} onChange={set('casualty_rows', originalIndex, 'last_name')} />
                                        <EditableCell value={row.first_name} onChange={set('casualty_rows', originalIndex, 'first_name')} />
                                        <EditableCell value={row.middle_name} onChange={set('casualty_rows', originalIndex, 'middle_name')} />
                                        <EditableCell type="number" value={row.age} onChange={set('casualty_rows', originalIndex, 'age')} />
                                        <SelectorCell value={row.sex} onChange={set('casualty_rows', originalIndex, 'sex')} options={['M', 'F'].map((value) => ({ value, label: value }))} placeholder="Search sex..." allLabel="Sex" />
                                        <SelectorCell value={row.address} onChange={set('casualty_rows', originalIndex, 'address')} options={affectedBarangayOptions} placeholder="Search barangay..." allLabel="Barangay" />
                                        <TextareaCell value={row.cause} onChange={set('casualty_rows', originalIndex, 'cause')} />
                                        <TextareaCell value={row.remarks} onChange={set('casualty_rows', originalIndex, 'remarks')} />
                                        <EditableCell value={row.source_of_data} onChange={set('casualty_rows', originalIndex, 'source_of_data')} placeholder="e.g. CDRRMO / DOH" />
                                        <RemarkCell text={validRemark(issue, row, casualtyValueFields, casualtyRequiredFields)} issue={issue} />
                                        <DeleteCell onClick={() => askRemove('casualty_rows', originalIndex, `Casualty Row ${displayIndex + 1}`)} />
                                    </tr>
                                );
                            }) : <EmptyRows colSpan={13} message="No casualty encoded." />}
                        </tbody>
                        {casualtyRows.length > 0 && (
                            <tfoot>
                                <tr className="bg-cyan-100 font-black text-slate-950">
                                    <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                                    <td colSpan="4" className="border border-slate-700 px-3 py-2 text-center">{formatNumber(casualtyRows.length)} casualty row/s</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">{formatNumber(casualtyTotals.age)}</td>
                                    <td colSpan="5" className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">Validate above</td>
                                    <td className="border border-slate-700 px-3 py-2" />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>}
                {affectedPopulationReady && !isSectionNotApplicable('casualties') && <DromicIssueNotice issues={issues.casualtyIssues} tableName="Casualties table" />}
            </DromicSectionCard>

            <DromicSectionCard id="dromic-section-infrastructure-damage">
                <AdditionalSectionHeader
                    title="Damages to Infrastructure"
                    description="Record damaged public infrastructure, structures, road protection works, and other LGU-validated facilities."
                    onAdd={affectedPopulationReady && !isSectionNotApplicable('infrastructure_damage') ? () => addSupportingRow('infrastructure_damage_rows', emptyInfrastructureDamageRow(lguProfile)) : null}
                    button="Add infrastructure damage"
                    naControl={<NotApplicableToggle checked={isSectionNotApplicable('infrastructure_damage')} onChange={() => toggleSectionNotApplicable('infrastructure_damage')} />}
                />
                <SupplementarySectionInstruction />
                {isSectionNotApplicable('infrastructure_damage') ? <NotApplicableNotice section="Damages to Infrastructure" /> : !affectedPopulationReady ? <LockedSectionNotice>{lockedNotice}</LockedSectionNotice> : <div className="dromic-table-scroll rounded-md border border-slate-200 dark:border-zinc-800">
                    <table className="dromic-wide-table w-full min-w-[1450px] table-auto border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr className={dromicHeadClass}>
                                {['No.', 'Barangay', 'Type of Structure', 'Damage Description', 'Length (m)', 'Estimated Cost of Damage', 'Remarks', 'Validation Remarks', 'Action'].map((head) => (
                                    <th key={head} className="border border-slate-700 px-3 py-2 font-black uppercase">{head}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {sortedInfrastructureDamageRows.length ? sortedInfrastructureDamageRows.map(({ row, originalIndex }, displayIndex) => {
                                const issue = issueFor(issues.infrastructureDamageIssues, originalIndex);

                                return (
                                    <tr key={originalIndex} className={rowClass(issue)}>
                                        <NumberCell value={displayIndex + 1} />
                                        <SelectorCell value={row.barangay} onChange={set('infrastructure_damage_rows', originalIndex, 'barangay')} options={affectedBarangayOptions} placeholder="Search barangay..." allLabel="Barangay" />
                                        <OtherOptionCell
                                            value={row.structure_type}
                                            otherValue={row.structure_type_other}
                                            onChange={(value) => updateSupportingRow('infrastructure_damage_rows', originalIndex, {
                                                structure_type: value,
                                                structure_type_other: value === 'Others' ? row.structure_type_other : '',
                                            })}
                                            onOtherChange={set('infrastructure_damage_rows', originalIndex, 'structure_type_other')}
                                            options={withOthersOption(['Barangay Hall', 'Covered Court', 'Flood Control', 'School Building', 'Health Station', 'Road', 'Bridge'])}
                                            placeholder="Search structure..."
                                            allLabel="Structure"
                                            otherPlaceholder="Specify type of structure"
                                        />
                                        <TextareaCell value={row.damage_description} onChange={set('infrastructure_damage_rows', originalIndex, 'damage_description')} />
                                        <EditableCell type="number" value={row.length_meters} onChange={set('infrastructure_damage_rows', originalIndex, 'length_meters')} />
                                        <EditableCell type="number" value={row.estimated_cost} onChange={set('infrastructure_damage_rows', originalIndex, 'estimated_cost')} />
                                        <TextareaCell value={row.remarks} onChange={set('infrastructure_damage_rows', originalIndex, 'remarks')} />
                                        <RemarkCell text={validRemark(issue, row, infrastructureValueFields, infrastructureRequiredFields)} issue={issue} />
                                        <DeleteCell onClick={() => askRemove('infrastructure_damage_rows', originalIndex, `Infrastructure Damage Row ${displayIndex + 1}`)} />
                                    </tr>
                                );
                            }) : <EmptyRows colSpan={9} message="No infrastructure damage encoded." />}
                        </tbody>
                        {infrastructureDamageRows.length > 0 && (
                            <tfoot>
                                <tr className="bg-cyan-100 font-black text-slate-950">
                                    <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                                    <td colSpan="3" className="border border-slate-700 px-3 py-2 text-center">{formatNumber(infrastructureDamageRows.length)} infrastructure row/s</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">{formatNumber(infrastructureTotals.length_meters)}</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">{formatCurrency(infrastructureTotals.estimated_cost)}</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">Validate above</td>
                                    <td className="border border-slate-700 px-3 py-2" />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>}
                {affectedPopulationReady && !isSectionNotApplicable('infrastructure_damage') && <DromicIssueNotice issues={issues.infrastructureDamageIssues} tableName="Damages to Infrastructure table" />}
            </DromicSectionCard>

            <DromicSectionCard id="dromic-section-agriculture-damage">
                <AdditionalSectionHeader
                    title="Damage and Losses to Agriculture"
                    description="Capture crop, livestock/poultry, fisheries, and equipment losses. Totals are computed while the LGU encodes."
                    onAdd={affectedPopulationReady && !isSectionNotApplicable('agriculture_damage') ? () => addSupportingRow('agriculture_damage_rows', emptyAgricultureDamageRow(lguProfile)) : null}
                    button="Add agriculture loss"
                    naControl={<NotApplicableToggle checked={isSectionNotApplicable('agriculture_damage')} onChange={() => toggleSectionNotApplicable('agriculture_damage')} />}
                />
                <SupplementarySectionInstruction />
                {isSectionNotApplicable('agriculture_damage') ? <NotApplicableNotice section="Damage and Losses to Agriculture" /> : !affectedPopulationReady ? <LockedSectionNotice>{lockedNotice}</LockedSectionNotice> : <div className="dromic-table-scroll rounded-md border border-slate-200 dark:border-zinc-800">
                    <table className="dromic-wide-table w-full min-w-[2300px] table-auto border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr className={dromicHeadClass}>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">No.</th>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Barangay</th>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Classification</th>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Type</th>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">No. of Farmers / Fisherfolks Affected</th>
                                <th colSpan="3" className="border border-slate-700 px-3 py-2 font-black uppercase">Area Affected (Ha)</th>
                                <th colSpan="3" className="border border-slate-700 px-3 py-2 font-black uppercase">No. of Damaged Infrastructure, Machineries, Equipment</th>
                                <th colSpan="3" className="border border-slate-700 px-3 py-2 font-black uppercase">Production Loss</th>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Production Loss in Volume (MT)</th>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Production Loss / Cost of Damage in Value (Php)</th>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Validation Remarks</th>
                                <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Action</th>
                            </tr>
                            <tr className={dromicHeadClass}>
                                {['With No Chance of Recovery', 'With Chance of Recovery', 'Total', 'Totally Damaged', 'Partially Damaged', 'Total', 'No. of Heads', 'Cost per Head', 'Total Value'].map((head) => (
                                    <th key={head} className="border border-slate-700 px-2 py-2 text-xs font-black uppercase">{head}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {sortedAgricultureDamageRows.length ? sortedAgricultureDamageRows.map(({ row, originalIndex }, displayIndex) => {
                                const issue = issueFor(issues.agricultureDamageIssues, originalIndex);
                                const areaTotal = Number(row.area_no_chance_recovery || 0) + Number(row.area_with_chance_recovery || 0);
                                const infraTotal = Number(row.infrastructure_totally_damaged || 0) + Number(row.infrastructure_partially_damaged || 0);
                                const productionTotal = Number(row.production_loss_heads || 0) * Number(row.production_loss_cost_per_head || 0);

                                return (
                                    <tr key={originalIndex} className={rowClass(issue)}>
                                        <NumberCell value={displayIndex + 1} />
                                        <SelectorCell value={row.barangay} onChange={set('agriculture_damage_rows', originalIndex, 'barangay')} options={affectedBarangayOptions} placeholder="Search barangay..." allLabel="Barangay" />
                                        <OtherOptionCell
                                            value={row.classification}
                                            otherValue={row.classification_other}
                                            onChange={(value) => updateSupportingRow('agriculture_damage_rows', originalIndex, {
                                                classification: value,
                                                classification_other: value === 'Others' ? row.classification_other : '',
                                            })}
                                            onOtherChange={set('agriculture_damage_rows', originalIndex, 'classification_other')}
                                            options={withOthersOption(['Crop', 'Livestock and Poultry', 'Fisheries', 'Equipment'])}
                                            placeholder="Search classification..."
                                            allLabel="Classification"
                                            otherPlaceholder="Specify classification"
                                        />
                                        <OtherOptionCell
                                            value={row.type}
                                            otherValue={row.type_other}
                                            onChange={(value) => updateSupportingRow('agriculture_damage_rows', originalIndex, {
                                                type: value,
                                                type_other: value === 'Others' ? row.type_other : '',
                                            })}
                                            onOtherChange={set('agriculture_damage_rows', originalIndex, 'type_other')}
                                            options={withOthersOption(['Rice', 'Corn', 'High Value Crops', 'Chicken', 'Swine', 'Motorized Banca', 'Fishing Gear'])}
                                            placeholder="Search type..."
                                            allLabel="Type"
                                            otherPlaceholder="Specify type"
                                        />
                                        <EditableCell type="number" value={row.affected_farmers_fisherfolks} onChange={set('agriculture_damage_rows', originalIndex, 'affected_farmers_fisherfolks')} />
                                        <EditableCell type="number" value={row.area_no_chance_recovery} onChange={set('agriculture_damage_rows', originalIndex, 'area_no_chance_recovery')} />
                                        <EditableCell type="number" value={row.area_with_chance_recovery} onChange={set('agriculture_damage_rows', originalIndex, 'area_with_chance_recovery')} />
                                        <NumberCell value={formatNumber(areaTotal)} />
                                        <EditableCell type="number" value={row.infrastructure_totally_damaged} onChange={set('agriculture_damage_rows', originalIndex, 'infrastructure_totally_damaged')} />
                                        <EditableCell type="number" value={row.infrastructure_partially_damaged} onChange={set('agriculture_damage_rows', originalIndex, 'infrastructure_partially_damaged')} />
                                        <NumberCell value={formatNumber(infraTotal)} />
                                        <EditableCell type="number" value={row.production_loss_heads} onChange={set('agriculture_damage_rows', originalIndex, 'production_loss_heads')} />
                                        <EditableCell type="number" value={row.production_loss_cost_per_head} onChange={set('agriculture_damage_rows', originalIndex, 'production_loss_cost_per_head')} />
                                        <NumberCell value={formatCurrency(productionTotal)} />
                                        <EditableCell type="number" value={row.production_loss_volume_mt} onChange={set('agriculture_damage_rows', originalIndex, 'production_loss_volume_mt')} />
                                        <EditableCell type="number" value={row.production_loss_value} onChange={set('agriculture_damage_rows', originalIndex, 'production_loss_value')} />
                                        <RemarkCell text={validRemark(issue, row, agricultureValueFields, agricultureRequiredFields)} issue={issue} />
                                        <DeleteCell onClick={() => askRemove('agriculture_damage_rows', originalIndex, `Agriculture Damage/Loss Row ${displayIndex + 1}`)} />
                                    </tr>
                                );
                            }) : <EmptyRows colSpan={18} message="No agriculture damage/loss encoded." />}
                        </tbody>
                        {agricultureDamageRows.length > 0 && (
                            <tfoot>
                                <tr className="bg-cyan-100 font-black text-slate-950">
                                    <td className="border border-slate-700 px-2 py-2 dromic-footer-label text-center uppercase">Total</td>
                                    <td className="nowrap-cell border border-slate-700 px-2 py-2 text-center">Total: {formatNumber(agricultureTotals.rows)}</td>
                                    <td className="nowrap-cell border border-slate-700 px-2 py-2 text-center">Total</td>
                                    <td className="nowrap-cell border border-slate-700 px-2 py-2 text-center">Total</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.farmers)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.area_no_chance)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.area_with_chance)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.area)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.infra_totally)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.infra_partially)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.infra)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.production_heads)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatCurrency(agricultureTotals.production_cost_per_head)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatCurrency(agricultureTotals.production)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatNumber(agricultureTotals.volume)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">{formatCurrency(agricultureTotals.value)}</td>
                                    <td className="border border-slate-700 px-2 py-2 text-center">Validate above</td>
                                    <td className="border border-slate-700 px-2 py-2" />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>}
                {affectedPopulationReady && !isSectionNotApplicable('agriculture_damage') && <DromicIssueNotice issues={issues.agricultureDamageIssues} tableName="Damage and Losses to Agriculture table" />}
            </DromicSectionCard>

            <DromicSectionCard id="dromic-section-suspension">
                <AdditionalSectionHeader
                    title="Suspension"
                    description="Record class and work suspension orders only when issued or validated by the LGU or authorized office."
                />
                <SupplementarySectionInstruction />
                <div className="mt-4 grid gap-5">
                    <SuspensionTable
                        id="dromic-section-class-suspension"
                        title="Class Suspension"
                        rows={classSuspensionRows}
                        issues={issues.classSuspensionIssues}
                        locked={!affectedPopulationReady}
                        lockedNotice={lockedNotice}
                        addRow={isSectionNotApplicable('class_suspension') ? null : () => addSupportingRow('class_suspension_rows', emptyClassSuspensionRow(lguProfile))}
                        update={(index, field, value) => updateSupportingRow('class_suspension_rows', index, field, value)}
                        remove={(index) => askRemove('class_suspension_rows', index, `Class Suspension Row ${index + 1}`)}
                        barangayOptions={affectedBarangayOptions}
                        withBarangay
                        subsection
                        isNa={isSectionNotApplicable('class_suspension')}
                        toggleNa={() => toggleSectionNotApplicable('class_suspension')}
                    />
                    <SuspensionTable
                        id="dromic-section-work-suspension"
                        title="Work Suspension"
                        rows={workSuspensionRows}
                        issues={issues.workSuspensionIssues}
                        locked={!affectedPopulationReady}
                        lockedNotice={lockedNotice}
                        addRow={isSectionNotApplicable('work_suspension') ? null : () => addSupportingRow('work_suspension_rows', emptyWorkSuspensionRow(lguProfile))}
                        update={(index, field, value) => updateSupportingRow('work_suspension_rows', index, field, value)}
                        remove={(index) => askRemove('work_suspension_rows', index, `Work Suspension Row ${index + 1}`)}
                        barangayOptions={affectedBarangayOptions}
                        withBarangay
                        subsection
                        isNa={isSectionNotApplicable('work_suspension')}
                        toggleNa={() => toggleSectionNotApplicable('work_suspension')}
                    />
                </div>
            </DromicSectionCard>

            <DromicSectionCard id="dromic-section-lifelines">
                <AdditionalSectionHeader
                    title="Status of Lifelines"
                    description="Use this section for affected roads, bridges, and other lifeline status updates."
                />
                <div id="dromic-section-roads-bridges" className="dromic-section-anchor mt-4">
                    <AdditionalSectionHeader
                        title="Roads and Bridges"
                        description="Record not passable/passable roads and bridges, including alternate-route remarks if available."
                        onAdd={affectedPopulationReady && !isSectionNotApplicable('roads_bridges') ? () => addSupportingRow('road_bridge_rows', emptyRoadBridgeRow(lguProfile)) : null}
                        button="Add road / bridge"
                        subsection
                        naControl={<NotApplicableToggle checked={isSectionNotApplicable('roads_bridges')} onChange={() => toggleSectionNotApplicable('roads_bridges')} />}
                    />
                <SupplementarySectionInstruction />
                {isSectionNotApplicable('roads_bridges') ? <NotApplicableNotice section="Roads and Bridges" /> : !affectedPopulationReady ? <LockedSectionNotice>{lockedNotice}</LockedSectionNotice> : <div className="dromic-table-scroll mt-2 rounded-md border border-slate-200 dark:border-zinc-800">
                    <table className="dromic-wide-table w-full min-w-[1650px] table-auto border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr className={dromicHeadClass}>
                                {['No.', 'Barangay', 'Type', 'Classification', 'Road Section', 'Status', 'Date and Time Reported Not Passable', 'Date and Time Reported Passable', 'Remarks', 'Validation Remarks', 'Action'].map((head) => (
                                    <th key={head} className="border border-slate-700 px-3 py-2 font-black uppercase">{head}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {sortedRoadBridgeRows.length ? sortedRoadBridgeRows.map(({ row, originalIndex }, displayIndex) => {
                                const issue = issueFor(issues.roadBridgeIssues, originalIndex);

                                return (
                                    <tr key={originalIndex} className={rowClass(issue)}>
                                        <NumberCell value={displayIndex + 1} />
                                        <SelectorCell value={row.barangay} onChange={set('road_bridge_rows', originalIndex, 'barangay')} options={affectedBarangayOptions} placeholder="Search barangay..." allLabel="Barangay" />
                                        <OtherOptionCell
                                            value={row.type}
                                            otherValue={row.type_other}
                                            onChange={(value) => updateSupportingRow('road_bridge_rows', originalIndex, {
                                                type: value,
                                                type_other: value === 'Others' ? row.type_other : '',
                                            })}
                                            onOtherChange={set('road_bridge_rows', originalIndex, 'type_other')}
                                            options={withOthersOption(['Road', 'Bridge'])}
                                            placeholder="Search type..."
                                            allLabel="Type"
                                            otherPlaceholder="Specify type"
                                        />
                                        <OtherOptionCell
                                            value={row.classification}
                                            otherValue={row.classification_other}
                                            onChange={(value) => updateSupportingRow('road_bridge_rows', originalIndex, {
                                                classification: value,
                                                classification_other: value === 'Others' ? row.classification_other : '',
                                            })}
                                            onOtherChange={set('road_bridge_rows', originalIndex, 'classification_other')}
                                            options={withOthersOption(['National', 'Provincial', 'City', 'Municipal', 'Barangay'])}
                                            placeholder="Search classification..."
                                            allLabel="Classification"
                                            otherPlaceholder="Specify classification"
                                        />
                                        <TextareaCell value={row.road_section} onChange={set('road_bridge_rows', originalIndex, 'road_section')} />
                                        <SelectorCell value={row.status} onChange={set('road_bridge_rows', originalIndex, 'status')} options={['Not passable', 'Passable to light vehicles', 'Passable to all vehicles', 'One lane passable', 'Limited accessibility'].map((value) => ({ value, label: value }))} placeholder="Search status..." allLabel="Status" />
                                        <EditableCell type="datetime-local" value={row.reported_not_passable_at} onChange={set('road_bridge_rows', originalIndex, 'reported_not_passable_at')} />
                                        <EditableCell type="datetime-local" value={row.reported_passable_at} onChange={set('road_bridge_rows', originalIndex, 'reported_passable_at')} />
                                        <TextareaCell value={row.remarks} onChange={set('road_bridge_rows', originalIndex, 'remarks')} />
                                        <RemarkCell text={validRemark(issue, row, roadBridgeValueFields, roadBridgeRequiredFields)} issue={issue} />
                                        <DeleteCell onClick={() => askRemove('road_bridge_rows', originalIndex, `Road/Bridge Lifeline Row ${displayIndex + 1}`)} />
                                    </tr>
                                );
                            }) : <EmptyRows colSpan={11} message="No road or bridge lifeline status encoded." />}
                        </tbody>
                        {roadBridgeRows.length > 0 && (
                            <tfoot>
                                <tr className="bg-cyan-100 font-black text-slate-950">
                                    <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                                    <td colSpan="8" className="border border-slate-700 px-3 py-2 text-center">{formatNumber(roadBridgeTotals.rows)} road/bridge row/s</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">Validate above</td>
                                    <td className="border border-slate-700 px-3 py-2" />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>}
                {affectedPopulationReady && !isSectionNotApplicable('roads_bridges') && <DromicIssueNotice issues={issues.roadBridgeIssues} tableName="Roads and Bridges table" />}
                </div>
                <SupportingRowsTable
                    id="dromic-section-power"
                    title="Power"
                    description="Record power outage/interruption by entire LGU, all affected barangays, or a selected barangay."
                    rows={powerLifelineRows}
                    issues={issues.powerLifelineIssues}
                    locked={!affectedPopulationReady}
                    lockedNotice={lockedNotice}
                    isNa={isSectionNotApplicable('power_lifelines')}
                    toggleNa={() => toggleSectionNotApplicable('power_lifelines')}
                    onAdd={() => addSupportingRow('power_lifeline_rows', emptyUtilityLifelineRow())}
                    addLabel="Add power status"
                    update={(index, field, value) => updateSupportingRow('power_lifeline_rows', index, field, value)}
                    remove={(index) => askRemove('power_lifeline_rows', index, `Power Lifeline Row ${index + 1}`)}
                    tableName="Power table"
                    emptyMessage="No power lifeline status encoded."
                    minWidth="min-w-[1350px]"
                    columns={[
                        ...lifelineCoverageColumns('power_lifeline_rows', affectedOrAllBarangayOptions),
                        { label: 'Type', field: 'type', otherField: 'type_other', kind: 'other', options: utilityTypeOptions, allLabel: 'Type' },
                        { label: 'Service Provider', field: 'service_provider' },
                        { label: 'Date and Time of Interruption', field: 'interrupted_at', kind: 'datetime' },
                        { label: 'Date and Time Restored', field: 'restored_at', kind: 'datetime', required: false },
                        { label: 'Remarks / Status', field: 'remarks_status', kind: 'textarea' },
                    ]}
                />
                <SupportingRowsTable
                    id="dromic-section-water"
                    title="Water"
                    description="Record water service interruption/restoration by entire LGU, all affected barangays, or a selected barangay."
                    rows={waterLifelineRows}
                    issues={issues.waterLifelineIssues}
                    locked={!affectedPopulationReady}
                    lockedNotice={lockedNotice}
                    isNa={isSectionNotApplicable('water_lifelines')}
                    toggleNa={() => toggleSectionNotApplicable('water_lifelines')}
                    onAdd={() => addSupportingRow('water_lifeline_rows', emptyUtilityLifelineRow())}
                    addLabel="Add water status"
                    update={(index, field, value) => updateSupportingRow('water_lifeline_rows', index, field, value)}
                    remove={(index) => askRemove('water_lifeline_rows', index, `Water Lifeline Row ${index + 1}`)}
                    tableName="Water table"
                    emptyMessage="No water lifeline status encoded."
                    minWidth="min-w-[1350px]"
                    columns={[
                        ...lifelineCoverageColumns('water_lifeline_rows', affectedOrAllBarangayOptions),
                        { label: 'Type', field: 'type', otherField: 'type_other', kind: 'other', options: utilityTypeOptions, allLabel: 'Type' },
                        { label: 'Service Provider', field: 'service_provider' },
                        { label: 'Date and Time of Interruption', field: 'interrupted_at', kind: 'datetime' },
                        { label: 'Date and Time Restored', field: 'restored_at', kind: 'datetime', required: false },
                        { label: 'Remarks / Status', field: 'remarks_status', kind: 'textarea' },
                    ]}
                />
                <SupportingRowsTable
                    id="dromic-section-communication-lines"
                    title="Communication Lines"
                    description="Record communication service status by entire LGU, all affected barangays, or a selected barangay."
                    rows={communicationLifelineRows}
                    issues={issues.communicationLifelineIssues}
                    locked={!affectedPopulationReady}
                    lockedNotice={lockedNotice}
                    isNa={isSectionNotApplicable('communication_lifelines')}
                    toggleNa={() => toggleSectionNotApplicable('communication_lifelines')}
                    onAdd={() => addSupportingRow('communication_lifeline_rows', emptyCommunicationLifelineRow())}
                    addLabel="Add communication status"
                    update={(index, field, value) => updateSupportingRow('communication_lifeline_rows', index, field, value)}
                    remove={(index) => askRemove('communication_lifeline_rows', index, `Communication Lifeline Row ${index + 1}`)}
                    tableName="Communication Lines table"
                    emptyMessage="No communication lifeline status encoded."
                    minWidth="min-w-[1350px]"
                    columns={[
                        ...lifelineCoverageColumns('communication_lifeline_rows', affectedOrAllBarangayOptions),
                        { label: 'Status of Communication', field: 'communication_status', otherField: 'communication_status_other', kind: 'other', options: communicationStatusOptions, allLabel: 'Status' },
                        { label: 'Service Provider', field: 'service_provider' },
                        { label: 'Date and Time of Interruption', field: 'interrupted_at', kind: 'datetime' },
                        { label: 'Date and Time Restored', field: 'restored_at', kind: 'datetime', required: false },
                        { label: 'Remarks', field: 'remarks', kind: 'textarea' },
                    ]}
                />
            </DromicSectionCard>

            <DromicSectionCard id="dromic-section-ports-terminals">
                <AdditionalSectionHeader
                    title="Status of Ports / Terminals"
                    description="Encode port, airport, land transport terminal, and stranded passenger/cargo status when applicable."
                />
                <SupportingRowsTable
                        id="dromic-section-seaports"
                        title="Status of Seaports"
                        description="Record seaport operational status and stranded passengers."
                        rows={seaportRows}
                        issues={issues.seaportIssues}
                        locked={!affectedPopulationReady}
                        lockedNotice={lockedNotice}
                        isNa={isSectionNotApplicable('seaports')}
                        toggleNa={() => toggleSectionNotApplicable('seaports')}
                        onAdd={() => addSupportingRow('seaport_rows', emptyPortTerminalStatusRow())}
                        addLabel="Add seaport status"
                        update={(index, field, value) => updateSupportingRow('seaport_rows', index, field, value)}
                        remove={(index) => askRemove('seaport_rows', index, `Seaport Row ${index + 1}`)}
                        tableName="Status of Seaports table"
                        emptyMessage="No seaport status encoded."
                        minWidth="min-w-[1350px]"
                        columns={[
                            { label: 'Name of Port', field: 'name' },
                            { label: 'Status', field: 'status', otherField: 'status_other', kind: 'other', options: portStatusOptions, allLabel: 'Status' },
                            { label: 'No. of Stranded Passengers', field: 'stranded_passengers', kind: 'number', total: true },
                            { label: 'Date and Time Reported Non-Operational / Cancelled Trips', field: 'reported_non_operational_at', kind: 'datetime' },
                            { label: 'Date and Time Reported Operational / Resumed Trips', field: 'reported_operational_at', kind: 'datetime', required: false },
                            { label: 'Remarks', field: 'remarks', kind: 'textarea' },
                        ]}
                    />
                <SupportingRowsTable
                        id="dromic-section-airports"
                        title="Status of Airports"
                        description="Record airport operational status and stranded passengers."
                        rows={airportRows}
                        issues={issues.airportIssues}
                        locked={!affectedPopulationReady}
                        lockedNotice={lockedNotice}
                        isNa={isSectionNotApplicable('airports')}
                        toggleNa={() => toggleSectionNotApplicable('airports')}
                        onAdd={() => addSupportingRow('airport_rows', emptyPortTerminalStatusRow())}
                        addLabel="Add airport status"
                        update={(index, field, value) => updateSupportingRow('airport_rows', index, field, value)}
                        remove={(index) => askRemove('airport_rows', index, `Airport Row ${index + 1}`)}
                        tableName="Status of Airports table"
                        emptyMessage="No airport status encoded."
                        minWidth="min-w-[1350px]"
                        columns={[
                            { label: 'Name of Airport', field: 'name' },
                            { label: 'Status', field: 'status', otherField: 'status_other', kind: 'other', options: portStatusOptions, allLabel: 'Status' },
                            { label: 'No. of Stranded Passengers', field: 'stranded_passengers', kind: 'number', total: true },
                            { label: 'Date and Time Reported Non-Operational / Cancelled Trips', field: 'reported_non_operational_at', kind: 'datetime' },
                            { label: 'Date and Time Reported Operational / Resumed Trips', field: 'reported_operational_at', kind: 'datetime', required: false },
                            { label: 'Remarks', field: 'remarks', kind: 'textarea' },
                        ]}
                    />
                <SupportingRowsTable
                        id="dromic-section-land-transport-terminals"
                        title="Status of Land Transport Terminals"
                        description="Record bus terminal / land transport terminal status and stranded passengers."
                        rows={landTransportTerminalRows}
                        issues={issues.landTransportTerminalIssues}
                        locked={!affectedPopulationReady}
                        lockedNotice={lockedNotice}
                        isNa={isSectionNotApplicable('land_transport_terminals')}
                        toggleNa={() => toggleSectionNotApplicable('land_transport_terminals')}
                        onAdd={() => addSupportingRow('land_transport_terminal_rows', emptyPortTerminalStatusRow())}
                        addLabel="Add terminal status"
                        update={(index, field, value) => updateSupportingRow('land_transport_terminal_rows', index, field, value)}
                        remove={(index) => askRemove('land_transport_terminal_rows', index, `Land Transport Terminal Row ${index + 1}`)}
                        tableName="Status of Land Transport Terminals table"
                        emptyMessage="No land transport terminal status encoded."
                        minWidth="min-w-[1350px]"
                        columns={[
                            { label: 'Name of Bus Terminal', field: 'name' },
                            { label: 'Status', field: 'status', otherField: 'status_other', kind: 'other', options: portStatusOptions, allLabel: 'Status' },
                            { label: 'No. of Stranded Passengers', field: 'stranded_passengers', kind: 'number', total: true },
                            { label: 'Date and Time Reported Non-Operational / Cancelled Trips', field: 'reported_non_operational_at', kind: 'datetime' },
                            { label: 'Date and Time Reported Operational / Resumed Trips', field: 'reported_operational_at', kind: 'datetime', required: false },
                            { label: 'Remarks', field: 'remarks', kind: 'textarea' },
                        ]}
                    />
                <SupportingRowsTable
                        id="dromic-section-stranded-transport"
                        title="Stranded Passengers, Rolling Cargoes, Vessels, MBCAs"
                        description="Record stranded passengers, rolling cargoes, vessels/bus liners, and motor bancas by station or terminal."
                        rows={strandedTransportRows}
                        issues={issues.strandedTransportIssues}
                        locked={!affectedPopulationReady}
                        lockedNotice={lockedNotice}
                        isNa={isSectionNotApplicable('stranded_transport')}
                        toggleNa={() => toggleSectionNotApplicable('stranded_transport')}
                        onAdd={() => addSupportingRow('stranded_transport_rows', emptyStrandedTransportRow())}
                        addLabel="Add stranded entry"
                        update={(index, field, value) => updateSupportingRow('stranded_transport_rows', index, field, value)}
                        remove={(index) => askRemove('stranded_transport_rows', index, `Stranded Transport Row ${index + 1}`)}
                        tableName="Stranded Passengers / Cargoes table"
                        emptyMessage="No stranded passenger/cargo status encoded."
                        minWidth="min-w-[1250px]"
                        columns={[
                            { label: 'Barangay', field: 'barangay', kind: 'selector', options: affectedBarangayOptions, allLabel: 'Barangay' },
                            { label: 'Station', field: 'station' },
                            { label: 'Port / Terminal', field: 'port_terminal' },
                            { label: 'Passenger', field: 'passengers', kind: 'number', total: true },
                            { label: 'Rolling Cargoes', field: 'rolling_cargoes', kind: 'number', total: true },
                            { label: 'Vessel / Bus Liner', field: 'vessel_bus_liner' },
                            { label: 'MBCA (Motor Banca)', field: 'mbca', kind: 'number', total: true },
                            { label: 'Remarks', field: 'remarks', kind: 'textarea' },
                        ]}
                    />
            </DromicSectionCard>

            <SupportingRowsTable
                    id="dromic-section-calamity-declaration"
                    title="Declaration of State of Calamity"
                    description="Record declarations by barangay, city/municipality, or province-wide scope when issued."
                    rows={calamityDeclarationRows}
                    issues={issues.calamityDeclarationIssues}
                    locked={!affectedPopulationReady}
                    lockedNotice={lockedNotice}
                    isNa={isSectionNotApplicable('calamity_declaration')}
                    toggleNa={() => toggleSectionNotApplicable('calamity_declaration')}
                    onAdd={() => addSupportingRow('calamity_declaration_rows', emptyCalamityDeclarationRow())}
                    addLabel="Add declaration"
                    update={(index, field, value) => updateSupportingRow('calamity_declaration_rows', index, field, value)}
                    remove={(index) => askRemove('calamity_declaration_rows', index, `State of Calamity Row ${index + 1}`)}
                    tableName="Declaration of State of Calamity table"
                    emptyMessage="No state of calamity declaration encoded."
                    minWidth="min-w-[1150px]"
                    sectionLabel
                    columns={[
                        { label: 'City / Municipality / Barangay', field: 'location' },
                        { label: 'Type', field: 'type', otherField: 'type_other', kind: 'other', options: calamityTypeOptions, allLabel: 'Type' },
                        { label: 'Resolution Number', field: 'resolution_number' },
                        { label: 'Resolution Date', field: 'resolution_date', kind: 'date' },
                        { label: 'Remarks', field: 'remarks', kind: 'textarea' },
                    ]}
                />

            <SupportingRowsTable
                    id="dromic-section-preemptive-evacuation"
                    title="Pre-Emptive Evacuation"
                    description="Record pre-emptively evacuated families and persons by affected barangay."
                    rows={preemptiveEvacuationRows}
                    issues={issues.preemptiveEvacuationIssues}
                    locked={!affectedPopulationReady}
                    lockedNotice={lockedNotice}
                    isNa={isSectionNotApplicable('preemptive_evacuation')}
                    toggleNa={() => toggleSectionNotApplicable('preemptive_evacuation')}
                    onAdd={() => addSupportingRow('preemptive_evacuation_rows', emptyPreemptiveEvacuationRow())}
                    addLabel="Add evacuation row"
                    update={(index, field, value) => updateSupportingRow('preemptive_evacuation_rows', index, field, value)}
                    remove={(index) => askRemove('preemptive_evacuation_rows', index, `Pre-Emptive Evacuation Row ${index + 1}`)}
                    tableName="Pre-Emptive Evacuation table"
                    emptyMessage="No pre-emptive evacuation encoded."
                    minWidth="min-w-[1100px]"
                    sectionLabel
                    columns={[
                        { label: 'Barangay', field: 'barangay', kind: 'selector', options: affectedBarangayOptions, allLabel: 'Barangay', count: true },
                        { label: 'Families', field: 'families', kind: 'number', total: true },
                        { label: 'Male', field: 'male', kind: 'number', total: true },
                        { label: 'Female', field: 'female', kind: 'number', total: true },
                        { label: 'Remarks', field: 'remarks', kind: 'textarea' },
                    ]}
                />

            <SupportingRowsTable
                    id="dromic-section-cluster-gaps"
                    title="Gaps / Challenges and Status / Actions Undertaken"
                    description="Encode cluster-led areas of concern, actions undertaken, and current status or remarks."
                    rows={clusterGapRows}
                    issues={issues.clusterGapIssues}
                    locked={!affectedPopulationReady}
                    lockedNotice={lockedNotice}
                    isNa={isSectionNotApplicable('cluster_gaps')}
                    toggleNa={() => toggleSectionNotApplicable('cluster_gaps')}
                    onAdd={() => addSupportingRow('cluster_gap_rows', emptyClusterGapRow())}
                    addLabel="Add cluster action"
                    update={(index, field, value) => updateSupportingRow('cluster_gap_rows', index, field, value)}
                    remove={(index) => askRemove('cluster_gap_rows', index, `Cluster Gap Row ${index + 1}`)}
                    tableName="Gaps / Challenges and Status / Actions Undertaken table"
                    emptyMessage="No cluster gap/action encoded."
                    minWidth="min-w-[1150px]"
                    sectionLabel
                    columns={[
                        { label: 'Type / Cluster', field: 'cluster', otherField: 'cluster_other', kind: 'other', options: clusterOptions, allLabel: 'Cluster' },
                        { label: 'Areas of Concern', field: 'areas_of_concern', kind: 'textarea' },
                        { label: 'Actions Undertaken', field: 'actions_undertaken', kind: 'textarea' },
                        { label: 'Status / Remarks', field: 'status_remarks', kind: 'textarea' },
                    ]}
                />

            <div id="dromic-section-response-actions" className="dromic-section-anchor mt-3 w-full min-w-0 rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-4">
                <AdditionalSectionHeader
                    title="Response Actions and Interventions"
                    description="Required: encode at least one recent action or intervention undertaken by the reporting LGU."
                    onAdd={affectedPopulationReady ? () => addSupportingRow('response_action_rows', emptyResponseActionRow()) : null}
                    button="Add response action"
                    subsection={false}
                />
                <SupplementarySectionInstruction />
                {!affectedPopulationReady ? (
                    <LockedSectionNotice>{lockedNotice}</LockedSectionNotice>
                ) : (
                    <>
                        <div className="dromic-table-scroll mt-2 rounded-md border border-slate-200 dark:border-zinc-800">
                            <table className="dromic-wide-table min-w-[1100px] w-full table-auto border-collapse text-xs sm:text-sm">
                                <thead>
                                    <tr className={dromicHeadClass}>
                                        {['No.', 'Acted by', 'Response Action / Intervention', 'Validation Remarks', 'Action'].map((head) => (
                                            <th key={head} className="border border-slate-700 px-3 py-2 font-black uppercase">{head}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {responseActionRows.length ? responseActionRows.map((row, index) => {
                                        const issue = issueFor(issues.responseActionIssues, index);
                                        const valueFields = ['acted_by_office', 'acted_by_office_other', 'action_intervention'];
                                        const requiredFields = ['acted_by_office', 'action_intervention'];
                                        const hasValue = valueFields.some((field) => String(row[field] ?? '').trim() !== '');
                                        const complete = requiredFields.every((field) => String(row[field] ?? '').trim() !== '')
                                            && (String(row.acted_by_office || '').trim() !== 'Others' || String(row.acted_by_office_other || '').trim() !== '');
                                        const remark = issue
                                            ? issue.message
                                            : !hasValue
                                                ? 'Awaiting Entry!'
                                                : !complete
                                                    ? 'Complete started row'
                                                    : 'VALID DATA!';

                                        return (
                                            <tr key={index} className={issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                                                <NumberCell value={index + 1} />
                                                <OtherOptionCell
                                                    value={row.acted_by_office}
                                                    otherValue={row.acted_by_office_other}
                                                    onChange={(value) => updateSupportingRow('response_action_rows', index, {
                                                        acted_by_office: value,
                                                        acted_by_office_other: value === 'Others' ? row.acted_by_office_other : '',
                                                    })}
                                                    onOtherChange={(value) => updateSupportingRow('response_action_rows', index, 'acted_by_office_other', value)}
                                                    options={responseOfficeOptions}
                                                    placeholder="Search office / unit..."
                                                    allLabel="Office / Unit"
                                                    otherPlaceholder="Specify office / unit"
                                                />
                                                <TextareaCell
                                                    value={row.action_intervention}
                                                    onChange={(value) => updateSupportingRow('response_action_rows', index, 'action_intervention', value)}
                                                    placeholder="Describe the response action or intervention"
                                                />
                                                <RemarkCell text={remark} issue={issue} />
                                                <DeleteCell onClick={() => askRemove('response_action_rows', index, `Response Action Row ${index + 1}`)} />
                                            </tr>
                                        );
                                    }) : (
                                        <EmptyRows colSpan={5} message="Required: add the LGU's most recent response action or intervention." />
                                    )}
                                </tbody>
                                {responseActionRows.length > 0 && (
                                    <tfoot>
                                        <tr className="bg-cyan-100 font-black text-slate-950">
                                            <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                                            <td colSpan={2} className="border border-slate-700 px-3 py-2 text-center">{formatNumber(responseActionRows.length)} response action row/s</td>
                                            <td className="border border-slate-700 px-3 py-2 text-center">Validate above</td>
                                            <td className="border border-slate-700 px-3 py-2" />
                                        </tr>
                                    </tfoot>
                                )}
                            </table>
                        </div>
                        <DromicIssueNotice issues={issues.responseActionIssues} tableName="Response Actions and Interventions table" />
                    </>
                )}
            </div>

            <DromicSectionCard id="dromic-section-photo-documentation">
                <PhotoDocumentationSection
                    form={form}
                    rows={photoDocumentationRows}
                    collages={photoCollageRows}
                    locked={!affectedPopulationReady}
                    lockedNotice={lockedNotice}
                    isNa={isSectionNotApplicable('photo_documentation')}
                    toggleNa={() => toggleSectionNotApplicable('photo_documentation')}
                    issues={issues.photoDocumentationIssues}
                />
            </DromicSectionCard>
            </div>
    );
}

function PhotoDocumentationSection({
    form,
    rows = [],
    collages = [],
    locked = false,
    lockedNotice = '',
    isNa = false,
    toggleNa = null,
    issues = [],
}) {
    const [busy, setBusy] = useState(false);
    const [captionAiBusy, setCaptionAiBusy] = useState({});
    const [captionAiMessages, setCaptionAiMessages] = useState({});
    const [message, setMessage] = useState('');
    const [editingCollageIndex, setEditingCollageIndex] = useState(null);
    const [layoutHistory, setLayoutHistory] = useState([]);
    const [dragState, setDragState] = useState(null);
    const { auth } = usePage().props;
    const lguLogoUrl = auth?.user?.lgu_profile?.logo_url || null;
    const snapshotRows = (sourceRows = rows) => sourceRows.map((row) => ({
        ...row,
        crop: { ...(row.crop || {}) },
    }));
    const pushLayoutUndo = () => {
        setLayoutHistory((history) => [...history.slice(-9), snapshotRows()]);
    };
    const setPhotoState = (nextRows, nextCollages = []) => {
        form.setData({
            ...form.data,
            photo_documentation_rows: nextRows,
            photo_collage_rows: nextCollages,
        });
    };
    const buildCollages = async (photoRows, sourceCollages = collages) => {
        const chunks = [photoRows.slice(0, 5), photoRows.slice(5, 10)].filter((chunk) => chunk.length);

        return Promise.all(chunks.map(async (chunk, index) => {
            const previous = sourceCollages[index] || {};
            const generatedAt = previous.generated_at || new Date().toISOString();
            const nextCollage = {
                id: previous.id || makeClientId('collage'),
                title: previous.title || defaultPhotoCollageTitle(index),
                heading: previous.heading || defaultPhotoCollageHeading(index),
                date_label: previous.date_label || collageDateLabel(generatedAt),
                layout: previous.layout && previous.layout !== 'balanced' ? previous.layout : 'featured',
                lgu_logo_url: previous.lgu_logo_url || lguLogoUrl,
                photo_count: chunk.length,
                generated_at: generatedAt,
            };

            return {
                ...nextCollage,
                data_url: await generatePhotoDocumentationCollage(chunk, index, nextCollage),
            };
        }));
    };
    const patchCollageMeta = (index, patch) => {
        form.setData((current) => ({
            ...current,
            photo_collage_rows: (current.photo_collage_rows || []).map((collage, collageIndex) => (
                collageIndex === index ? { ...collage, ...patch } : collage
            )),
        }));
    };
    const updateCollageMeta = (index, field, value) => patchCollageMeta(index, { [field]: value });
    const setCaptionMessage = (index, value) => setCaptionAiMessages((current) => ({ ...current, [index]: value }));
    const polishCollageCaption = async (index) => {
        const collage = collages[index];
        const draft = String(collage?.heading || '').trim();

        if (!draft) {
            setCaptionMessage(index, 'Please enter a caption idea first. Groq AI can polish it, but it will not invent the documentation caption.');
            return;
        }

        setCaptionAiBusy((current) => ({ ...current, [index]: true }));
        setCaptionMessage(index, 'Groq AI is checking and polishing this caption...');

        try {
            const { data } = await window.axios.post('/lgu/dromic-sitrep/polish', {
                mode: 'caption',
                text: draft,
                facts: buildCaptionFacts(form.data),
            });
            const polished = limitPhotoCaption(data.polished || draft);
            patchCollageMeta(index, { heading: polished });
            const previewUpdated = await refreshCollagePreview(index, { heading: polished }, { silent: true, manageBusy: false });
            setCaptionMessage(
                index,
                previewUpdated
                    ? `Caption ${index + 1} polished with ${data.provider || 'Groq'} AI${data.model ? ` (${data.model})` : ''}. Please review it before saving.`
                    : `Caption ${index + 1} was polished with Groq AI, but its collage preview could not be refreshed. Change the layout or regenerate the collage to refresh it.`,
            );
        } catch (error) {
            setCaptionMessage(index, error.response?.data?.message || 'Groq AI could not polish this caption. Please make the caption clearer and try again.');
        } finally {
            setCaptionAiBusy((current) => ({ ...current, [index]: false }));
        }
    };
    const refreshCollagePreview = async (index, override = {}, { silent = false, manageBusy = true } = {}) => {
        const collage = collages[index] ? { lgu_logo_url: lguLogoUrl, ...collages[index], ...override } : null;
        const chunk = rows.slice(index * 5, (index * 5) + 5);

        if (!collage || !chunk.length) return false;

        if (manageBusy) setBusy(true);
        if (!silent) setMessage('Updating collage preview...');

        try {
            const dataUrl = await generatePhotoDocumentationCollage(chunk, index, collage);
            patchCollageMeta(index, { ...override, data_url: dataUrl });
            if (!silent) setMessage('Collage preview updated.');
            return true;
        } catch (error) {
            setMessage('DROMIS could not refresh the collage preview. Please try again.');
            return false;
        } finally {
            if (manageBusy) setBusy(false);
        }
    };
    const updatePhotoCrop = (globalIndex, patch) => {
        form.setData('photo_documentation_rows', rows.map((row, rowIndex) => {
            if (rowIndex !== globalIndex) return row;

            return {
                ...row,
                crop: {
                    scale: 1,
                    x: 0,
                    y: 0,
                    ...(row.crop || {}),
                    ...patch,
                },
            };
        }));
    };
    const applyPhotoLayout = async (nextRows = rows) => {
        setBusy(true);
        setMessage('Applying photo arrangement to collage...');

        try {
            const generated = await buildCollages(nextRows);
            setPhotoState(nextRows, generated);
            setMessage('Photo arrangement applied. Review the regenerated collage preview.');
        } catch (error) {
            setMessage('DROMIS could not apply the photo arrangement. Please try again.');
        } finally {
            setBusy(false);
        }
    };
    const undoPhotoLayout = async () => {
        const previous = layoutHistory[layoutHistory.length - 1];

        if (!previous) {
            setMessage('No photo layout change to undo yet.');
            return;
        }

        setLayoutHistory((history) => history.slice(0, -1));
        await applyPhotoLayout(previous);
    };
    const resetPhotoCrop = (globalIndex) => {
        pushLayoutUndo();
        const nextRows = rows.map((row, rowIndex) => (
            rowIndex === globalIndex ? { ...row, crop: { scale: 1, x: 0, y: 0 } } : row
        ));
        form.setData('photo_documentation_rows', nextRows);
    };
    const beginPhotoDrag = (event, globalIndex) => {
        event.preventDefault();
        pushLayoutUndo();
        const crop = rows[globalIndex]?.crop || {};
        setDragState({
            globalIndex,
            startX: event.clientX,
            startY: event.clientY,
            originX: Number(crop.x || 0),
            originY: Number(crop.y || 0),
        });
    };
    const movePhotoDrag = (event) => {
        if (!dragState) return;

        const nextX = Math.max(-50, Math.min(50, dragState.originX + ((event.clientX - dragState.startX) / 2)));
        const nextY = Math.max(-50, Math.min(50, dragState.originY + ((event.clientY - dragState.startY) / 2)));
        updatePhotoCrop(dragState.globalIndex, { x: nextX, y: nextY });
    };
    const endPhotoDrag = () => {
        if (dragState) setDragState(null);
    };
    const editorPhotos = editingCollageIndex === null
        ? []
        : rows.slice(editingCollageIndex * 5, (editingCollageIndex * 5) + 5).map((row, localIndex) => ({
            row,
            localIndex,
            globalIndex: (editingCollageIndex * 5) + localIndex,
        }));
    const removePhoto = (index) => {
        const nextRows = rows.filter((_, rowIndex) => rowIndex !== index);

        setBusy(true);
        setMessage('Photo removed. Updating collage...');
        buildCollages(nextRows)
            .then((generated) => {
                setPhotoState(nextRows, generated);
                setMessage(nextRows.length ? `${generated.length} collage/s updated.` : 'All photos removed.');
            })
            .catch(() => {
                setPhotoState(nextRows, []);
                setMessage('Photo removed, but DROMIS could not refresh the collage. Please choose photos again.');
            })
            .finally(() => setBusy(false));
    };
    const handleUpload = async (event) => {
        const files = Array.from(event.target.files || []).filter((file) => file.type?.startsWith('image/'));
        event.target.value = '';

        if (!files.length) {
            setMessage('Please select image files only.');
            return;
        }

        const slots = Math.max(0, 10 - rows.length);
        if (!slots) {
            setMessage('Maximum reached. Remove a photo first before adding another one.');
            return;
        }

        const accepted = files.slice(0, slots);
        const rejectedCount = files.length - accepted.length;
        setBusy(true);
        setMessage('Preparing photos and generating collage...');

        try {
            const prepared = await Promise.all(accepted.map(async (file) => ({
                id: makeClientId('photo'),
                name: file.name,
                type: file.type,
                size: file.size,
                crop: { scale: 1, x: 0, y: 0 },
                data_url: await resizePhotoToDataUrl(file),
            })));

            const nextRows = [...rows, ...prepared];
            const generated = await buildCollages(nextRows);
            setPhotoState(nextRows, generated);
            setMessage(rejectedCount > 0
                ? `${prepared.length} photo/s added and ${generated.length} collage/s generated. ${rejectedCount} photo/s were skipped because only 10 photos are allowed.`
                : `${prepared.length} photo/s added and ${generated.length} collage/s generated.`);
        } catch (error) {
            setMessage('DROMIS could not prepare one or more photos. Please try smaller JPG/PNG files.');
        } finally {
            setBusy(false);
        }
    };
    const handleGenerateCollages = async () => {
        if (!rows.length) {
            setMessage('Upload at least one photo before generating a collage.');
            return;
        }

        setBusy(true);
        setMessage('Generating photo documentation collage...');

        try {
            const generated = await buildCollages(rows);
            form.setData('photo_collage_rows', generated);
            setMessage(`${generated.length} collage/s generated. Review the preview before saving.`);
        } catch (error) {
            setMessage('DROMIS could not generate the collage. Please retry or replace very large photos.');
        } finally {
            setBusy(false);
        }
    };
    const issueFor = (index) => (issues || []).find((issue) => issue.index === index);

    return (
        <div>
            <AdditionalSectionHeader
                title="Photo Documentation"
                description="Upload up to 10 photos. DROMIS automatically generates a maximum of 2 collage images with up to 5 photos each for report attachment."
                naControl={<NotApplicableToggle checked={isNa} onChange={toggleNa} />}
                sectionLabel
            />

            {isNa ? (
                <NotApplicableNotice section="Photo Documentation" />
            ) : locked ? (
                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                    {lockedNotice || 'Complete the Status of Affected Population first before uploading photo documentation.'}
                </div>
            ) : (
                <div className="mt-3 grid gap-4">
                    <div className="rounded-lg border border-dashed border-emerald-300 bg-emerald-50/70 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p className="text-sm font-black uppercase tracking-wide text-emerald-800">Upload photos</p>
                                <p className="mt-1 text-sm text-slate-600 dark:text-zinc-300">
                                    Add JPG/PNG photos. Large photos are resized automatically and converted into collage previews right after upload.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <label data-tip="Choose photo documentation images" className="dromis-tip inline-flex cursor-pointer items-center gap-2 rounded-md bg-emerald-700 px-4 py-2 text-sm font-black text-white shadow-sm hover:bg-emerald-800">
                                    <UploadCloud className="h-4 w-4" />
                                    <span className="sr-only">Choose photos</span>
                                    <input type="file" accept="image/*" multiple className="hidden" onChange={handleUpload} disabled={busy || rows.length >= 10} />
                                </label>
                                <button type="button" data-tip="Regenerate photo documentation collage" onClick={handleGenerateCollages} disabled={busy || !rows.length} className="dromis-tip inline-flex items-center gap-2 rounded-md bg-brand-600 px-4 py-2 text-sm font-black text-white shadow-sm disabled:opacity-60">
                                    <Images className="h-4 w-4" />
                                    <span className="sr-only">Regenerate collage</span>
                                </button>
                            </div>
                        </div>
                        <p className="mt-3 text-xs font-bold uppercase tracking-wide text-slate-500">{formatNumber(rows.length)} of 10 photos uploaded • {formatNumber(collages.length)} of 2 collages generated</p>
                        {message && <p className="mt-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-bold text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">{message}</p>}
                    </div>

                    {rows.length > 0 ? (
                        <div className="rounded-lg border border-slate-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950">
                            <div className="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <p className="text-sm font-black uppercase tracking-wide text-slate-900 dark:text-zinc-100">Selected photos</p>
                                    <p className="text-xs font-semibold text-slate-500">Compact preview shown. Hover a thumbnail to enlarge it for checking.</p>
                                </div>
                            </div>
                        <div className="grid gap-2 sm:grid-cols-3 md:grid-cols-5 xl:grid-cols-10">
                            {rows.map((row, index) => {
                                const rowIssue = issueFor(index);

                                return (
                                    <div key={row.id || index} className={`group relative rounded-lg border bg-white p-2 shadow-sm transition-transform duration-200 hover:z-30 hover:scale-[1.7] hover:shadow-2xl dark:bg-zinc-950 ${rowIssue ? 'border-rose-200 dark:border-rose-900' : 'border-slate-200 dark:border-zinc-800'}`}>
                                        <div className="aspect-square overflow-hidden rounded-md bg-slate-100 dark:bg-zinc-900">
                                            {row.data_url ? <img src={row.data_url} alt={row.name || 'Selected documentation photo'} className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-xs font-bold text-slate-500">Photo missing</div>}
                                        </div>
                                        <div className="mt-2 grid gap-2">
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="dromis-tip truncate text-[11px] font-black text-slate-700 dark:text-zinc-200" data-tip={row.name || 'Selected image'}>{row.name || 'Selected image'}</p>
                                                <button type="button" data-tip="Remove this photo" onClick={() => removePhoto(index)} className="dromis-tip inline-flex items-center gap-1 rounded-md border border-rose-200 px-2 py-1 text-[10px] font-black text-rose-600 hover:bg-rose-50">
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                    <span className="sr-only">Remove</span>
                                                </button>
                                            </div>
                                            <p className={`text-xs font-black ${rowIssue ? 'text-rose-600' : 'text-emerald-700'}`}>{rowIssue ? rowIssue.message : 'VALID DATA!'}</p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        </div>
                    ) : (
                        <div className="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm font-bold text-slate-500 dark:border-zinc-800 dark:text-zinc-400">
                            No photo uploaded yet. Upload photos or mark this section N/A if no photo documentation is available.
                        </div>
                    )}

                    {collages.length > 0 && (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {collages.slice(0, 2).map((collage, index) => {
                                const collageIssue = (issues || []).find((issue) => issue.collageIndex === index);

                                return (
                                <div key={collage.id || index} className="rounded-lg border border-slate-200 bg-slate-50 p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Editable collage fields</p>
                                            <p className="text-xs font-bold text-slate-500">{formatNumber(collage.photo_count)} photo/s included</p>
                                        </div>
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <button type="button" data-tip="Edit photo positions and crop inside the collage" onClick={() => setEditingCollageIndex(index)} className="dromis-tip inline-flex items-center gap-2 rounded-md border border-emerald-200 bg-white px-3 py-2 text-xs font-black text-emerald-800 hover:bg-emerald-50 dark:border-emerald-900 dark:bg-zinc-900 dark:text-emerald-200">
                                                <Move className="h-4 w-4" />
                                                <span className="sr-only">Edit actual layout</span>
                                            </button>
                                            <a href={collage.data_url} data-tip="Download this collage image" download={`dromis-photo-documentation-${index + 1}.jpg`} className="dromis-tip inline-flex items-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white">
                                                <Download className="h-4 w-4" />
                                                <span className="sr-only">Download</span>
                                            </a>
                                        </div>
                                    </div>
                                    <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                        You can edit the collage title, required caption, centered date box, and layout below. Click outside a text field or change layout to refresh the preview image.
                                    </div>
                                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                                        <label className="grid gap-1 text-xs font-black uppercase tracking-wide text-slate-500">
                                            Collage title
                                            <input
                                                type="text"
                                                value={collage.title || defaultPhotoCollageTitle(index)}
                                                onChange={(event) => updateCollageMeta(index, 'title', event.target.value)}
                                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold normal-case text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                                            />
                                        </label>
                                        <label className="grid gap-1 text-xs font-black uppercase tracking-wide text-slate-500">
                                            Date range / date box
                                            <input
                                                type="text"
                                                value={collage.date_label || collageDateLabel(collage.generated_at)}
                                                onChange={(event) => updateCollageMeta(index, 'date_label', event.target.value)}
                                                onBlur={(event) => refreshCollagePreview(index, { date_label: event.target.value })}
                                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-sm font-bold normal-case text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                                            />
                                        </label>
                                        <label className="grid gap-1 text-xs font-black uppercase tracking-wide text-slate-500">
                                            Layout
                                            <select
                                                value={collage.layout && collage.layout !== 'balanced' ? collage.layout : 'featured'}
                                                onChange={(event) => {
                                                    updateCollageMeta(index, 'layout', event.target.value);
                                                    refreshCollagePreview(index, { layout: event.target.value });
                                                }}
                                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold normal-case text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                                            >
                                                <option value="featured">Featured photo + support photos</option>
                                                <option value="feature_right">Support photos + featured photo</option>
                                                <option value="stacked">Stacked story layout</option>
                                                <option value="magazine">Wide hero + support strip</option>
                                                <option value="panorama">Two-row panorama</option>
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-xs font-black uppercase tracking-wide text-slate-500 md:col-span-3">
                                            Caption <span className="text-rose-600">*</span>
                                            <div className="flex gap-2">
                                                <textarea
                                                    rows={3}
                                                    maxLength={photoCaptionMaxLength}
                                                    value={collage.heading || defaultPhotoCollageHeading(index)}
                                                    onChange={(event) => updateCollageMeta(index, 'heading', limitPhotoCaption(event.target.value))}
                                                    onBlur={(event) => {
                                                        if (event.relatedTarget?.dataset?.captionPolish === String(index)) return;
                                                        refreshCollagePreview(index, { heading: event.target.value });
                                                    }}
                                                    required
                                                    placeholder="Enter caption here..."
                                                    className="min-h-[5.75rem] min-w-0 flex-1 resize-none rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold normal-case leading-6 text-slate-900 shadow-sm focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                                                />
                                                <button
                                                    type="button"
                                                    data-caption-polish={index}
                                                    data-tip="Polish caption with AI Roger"
                                                    onClick={() => polishCollageCaption(index)}
                                                    disabled={Boolean(captionAiBusy[index])}
                                                    className="dromis-tip inline-flex h-[5.75rem] w-10 shrink-0 items-center justify-center rounded-md bg-brand-600 text-white shadow-sm transition hover:bg-brand-700 disabled:opacity-60"
                                                >
                                                    <Sparkles className={`h-4 w-4 ${captionAiBusy[index] ? 'animate-pulse' : ''}`} />
                                                    <span className="sr-only">Polish caption</span>
                                                </button>
                                            </div>
                                            <span className="text-[10px] font-bold normal-case text-slate-500">{String(collage.heading || '').length}/{photoCaptionMaxLength} characters · maximum 3 lines</span>
                                            {captionAiMessages[index] && (
                                                <span className="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-bold normal-case leading-5 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">
                                                    {captionAiMessages[index]}
                                                </span>
                                            )}
                                        </label>
                                    </div>
                                    {collageIssue && (
                                        <div className="mt-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs font-black text-rose-700 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200">
                                            {collageIssue.message}
                                        </div>
                                    )}
                                    <img src={collage.data_url} alt={collage.title || `Photo collage ${index + 1}`} className="mt-3 w-full rounded-md border border-slate-200 bg-white object-contain dark:border-zinc-800" />
                                </div>
                                );
                            })}
                        </div>
                    )}

                    {editingCollageIndex !== null && (
                        <div
                            className="fixed inset-0 z-[90] bg-slate-950/70 p-3 sm:p-5"
                            onPointerMove={movePhotoDrag}
                            onPointerUp={endPhotoDrag}
                            onPointerCancel={endPhotoDrag}
                            onPointerLeave={endPhotoDrag}
                        >
                            <div className="mx-auto flex max-h-[94vh] max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-950">
                                <div className="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-zinc-800 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p className="text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Photo Documentation Collage {editingCollageIndex + 1}</p>
                                        <h3 className="text-xl font-black text-slate-950 dark:text-zinc-50">Arrange and crop photos</h3>
                                        <p className="mt-1 text-sm font-semibold text-slate-600 dark:text-zinc-300">
                                            Drag a photo inside its frame to reposition it. Use zoom to crop closer, then apply the layout to regenerate the collage.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" onClick={undoPhotoLayout} disabled={busy || !layoutHistory.length} className="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-200">
                                            <Undo2 className="h-4 w-4" />
                                            Undo
                                        </button>
                                        <button type="button" onClick={() => applyPhotoLayout(rows)} disabled={busy} className="rounded-md bg-emerald-700 px-4 py-2 text-sm font-black text-white disabled:opacity-60">
                                            Apply layout
                                        </button>
                                        <button type="button" onClick={() => setEditingCollageIndex(null)} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-black text-slate-700 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-200">
                                            Close
                                        </button>
                                    </div>
                                </div>

                                <div className="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                    Tip: every layout change is undoable. “Apply layout” updates only the generated collage preview; it does not remove or replace your selected photos.
                                </div>

                                <div className="grid gap-4 overflow-y-auto p-4 md:grid-cols-2 xl:grid-cols-3">
                                    {editorPhotos.map(({ row, localIndex, globalIndex }) => {
                                        const crop = {
                                            scale: Number(row.crop?.scale || 1),
                                            x: Number(row.crop?.x || 0),
                                            y: Number(row.crop?.y || 0),
                                        };

                                        return (
                                            <div key={row.id || globalIndex} className="rounded-xl border border-slate-200 bg-slate-50 p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                                                <div className="mb-2 flex items-center justify-between gap-2">
                                                    <p className="dromis-tip truncate text-sm font-black text-slate-900 dark:text-zinc-100" data-tip={row.name || 'Selected image'}>Photo {localIndex + 1}: {row.name || 'Selected image'}</p>
                                                    <button type="button" onClick={() => resetPhotoCrop(globalIndex)} className="rounded-md border border-slate-300 px-2 py-1 text-xs font-black text-slate-600 hover:bg-white dark:border-zinc-700 dark:text-zinc-200">
                                                        Reset
                                                    </button>
                                                </div>
                                                <div
                                                    role="button"
                                                    tabIndex={0}
                                                    onPointerDown={(event) => beginPhotoDrag(event, globalIndex)}
                                                    className="relative aspect-[4/3] cursor-grab overflow-hidden rounded-lg border-2 border-emerald-300 bg-white active:cursor-grabbing dark:border-emerald-900 dark:bg-zinc-950"
                                                >
                                                    {row.data_url ? (
                                                        <img
                                                            src={row.data_url}
                                                            alt={row.name || 'Selected documentation photo'}
                                                            draggable={false}
                                                            className="h-full w-full select-none object-cover"
                                                            style={{ transform: `translate(${crop.x}%, ${crop.y}%) scale(${crop.scale})`, transformOrigin: 'center' }}
                                                        />
                                                    ) : (
                                                        <div className="flex h-full items-center justify-center text-sm font-bold text-slate-500">Photo missing</div>
                                                    )}
                                                    <span className="absolute left-2 top-2 rounded-full bg-slate-950/70 px-2 py-1 text-[11px] font-black text-white">Drag to arrange</span>
                                                </div>
                                                <label className="mt-3 grid gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
                                                    Zoom / crop
                                                    <input
                                                        type="range"
                                                        min="1"
                                                        max="3"
                                                        step="0.05"
                                                        value={crop.scale}
                                                        onPointerDown={pushLayoutUndo}
                                                        onChange={(event) => updatePhotoCrop(globalIndex, { scale: Number(event.target.value) })}
                                                        className="w-full accent-emerald-700"
                                                    />
                                                </label>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    )}

                    <DromicIssueNotice issues={issues} tableName="Photo Documentation section" />
                </div>
            )}
        </div>
    );
}

function SupportingRowsTable({
    id = null,
    title,
    description,
    rows = [],
    issues = [],
    locked = false,
    lockedNotice = '',
    isNa = false,
    toggleNa = null,
    onAdd = null,
    addLabel = 'Add row',
    update,
    remove,
    columns = [],
    emptyMessage = 'No row encoded.',
    tableName = 'table',
    minWidth = 'min-w-[1250px]',
    sectionLabel = false,
}) {
    const issueFor = (index) => issues.find((issue) => issue.index === index);
    const valueFields = columns.flatMap((column) => [column.field, column.otherField].filter(Boolean));
    const requiredFields = columns.filter((column) => column.required !== false && column.field).map((column) => column.field);
    const numericColumns = columns.filter((column) => column.total);
    const rowHasValue = (row = {}) => valueFields.some((field) => String(row[field] ?? '').trim() !== '');
    const rowComplete = (row = {}) => requiredFields.every((field) => String(row[field] ?? '').trim() !== '');
    const remarkFor = (issue, row) => {
        if (issue) return issue.message;
        if (!rowHasValue(row)) return 'Awaiting Entry!';
        if (!rowComplete(row)) return 'Complete started row';
        return 'VALID DATA!';
    };
    const displayRows = rows
        .map((row, originalIndex) => ({ row, originalIndex }))
        .sort((a, b) => {
            if (!columns.some((column) => column.field === 'type')) return a.originalIndex - b.originalIndex;

            return String(a.row.type || '').localeCompare(String(b.row.type || ''), undefined, { sensitivity: 'base' })
                || a.originalIndex - b.originalIndex;
        });
    const renderCell = (column, row, index) => {
        const onChange = (field) => (value) => update(index, field, value);

        if (column.render) return column.render(row, index);
        if (column.kind === 'selector') {
            return (
                <SelectorCell
                    value={row[column.field]}
                    onChange={onChange(column.field)}
                    options={column.options || []}
                    placeholder={column.placeholder || `Search ${column.label.toLowerCase()}...`}
                    allLabel={column.allLabel || column.label}
                />
            );
        }
        if (column.kind === 'other') {
            return (
                <OtherOptionCell
                    value={row[column.field]}
                    otherValue={row[column.otherField]}
                    onChange={(value) => update(index, {
                        [column.field]: value,
                        [column.otherField]: value === 'Others' ? row[column.otherField] : '',
                    })}
                    onOtherChange={onChange(column.otherField)}
                    options={column.options || []}
                    placeholder={column.placeholder || `Search ${column.label.toLowerCase()}...`}
                    allLabel={column.allLabel || column.label}
                    otherPlaceholder={column.otherPlaceholder || `Specify ${column.label.toLowerCase()}`}
                />
            );
        }
        if (column.kind === 'textarea') {
            return <TextareaCell value={row[column.field]} onChange={onChange(column.field)} placeholder={column.placeholder || 'Type details'} />;
        }
        if (column.kind === 'number') {
            return <EditableCell type="number" value={row[column.field]} onChange={onChange(column.field)} placeholder={column.placeholder || '0'} />;
        }
        if (column.kind === 'datetime') {
            return <EditableCell type="datetime-local" value={row[column.field]} onChange={onChange(column.field)} />;
        }
        if (column.kind === 'date') {
            return <EditableCell type="date" value={row[column.field]} onChange={onChange(column.field)} />;
        }

        return <EditableCell value={row[column.field]} onChange={onChange(column.field)} placeholder={column.placeholder || 'Type here'} />;
    };

    return (
        <div id={id || undefined} className="dromic-section-anchor mt-3 w-full min-w-0 rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-4">
            <AdditionalSectionHeader
                title={title}
                description={description}
                onAdd={locked || isNa ? null : onAdd}
                button={addLabel}
                subsection={!sectionLabel}
                naControl={toggleNa ? <NotApplicableToggle checked={isNa} onChange={toggleNa} /> : null}
            />
            <SupplementarySectionInstruction />
            {isNa ? (
                <NotApplicableNotice section={title} />
            ) : locked ? (
                <LockedSectionNotice>{lockedNotice}</LockedSectionNotice>
            ) : (
                <>
                    <div className="dromic-table-scroll mt-2 rounded-md border border-slate-200 dark:border-zinc-800">
                        <table className={`dromic-wide-table ${minWidth} w-full table-auto border-collapse text-xs sm:text-sm`}>
                            <thead>
                                <tr className={dromicHeadClass}>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">No.</th>
                                    {columns.map((column) => (
                                        <th key={column.key || column.field || column.label} className="border border-slate-700 px-3 py-2 font-black uppercase">{column.label}</th>
                                    ))}
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Validation Remarks</th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {displayRows.length ? displayRows.map(({ row, originalIndex }, displayIndex) => {
                                    const issue = issueFor(originalIndex);

                                    return (
                                        <tr key={originalIndex} className={issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                                            <NumberCell value={displayIndex + 1} />
                                            {columns.map((column) => <FragmentCell key={column.key || column.field || column.label}>{renderCell(column, row, originalIndex)}</FragmentCell>)}
                                            <RemarkCell text={remarkFor(issue, row)} issue={issue} />
                                            <DeleteCell onClick={() => remove(originalIndex)} />
                                        </tr>
                                    );
                                }) : <EmptyRows colSpan={columns.length + 3} message={emptyMessage} />}
                            </tbody>
                            {rows.length > 0 && (
                                <tfoot>
                                    <tr className="bg-cyan-100 font-black text-slate-950">
                                        <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                                        {columns.map((column) => {
                                            if (column.total) {
                                                const sum = rows.reduce((total, row) => total + Number(row[column.field] || 0), 0);
                                                return <td key={column.key || column.field} className="border border-slate-700 px-3 py-2 text-center">{column.currency ? formatCurrency(sum) : formatNumber(sum)}</td>;
                                            }
                                            if (column.count) {
                                                return <td key={column.key || column.field} className="nowrap-cell border border-slate-700 px-3 py-2 text-center">Total: {formatNumber(rows.length)}</td>;
                                            }
                                            return <td key={column.key || column.field || column.label} className="border border-slate-700 px-3 py-2 text-center" />;
                                        })}
                                        <td className="border border-slate-700 px-3 py-2 text-center">Validate above</td>
                                        <td className="border border-slate-700 px-3 py-2" />
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                    <DromicIssueNotice issues={issues} tableName={tableName} />
                </>
            )}
        </div>
    );
}

function FragmentCell({ children }) {
    return children;
}

function NotApplicableToggle({ checked, onChange }) {
    return (
        <button
            type="button"
            onClick={onChange}
            className="group inline-flex items-center gap-2"
            aria-pressed={checked}
            title={checked ? 'This section is marked not applicable' : 'Mark this section as not applicable'}
        >
            <span className="text-xs font-black uppercase tracking-wide text-slate-600 dark:text-zinc-300">N/A</span>
            <span className={`relative inline-flex h-8 w-16 items-center rounded-full border shadow-inner transition ${checked ? 'border-emerald-700 bg-gradient-to-r from-emerald-600 to-lime-500' : 'border-rose-700 bg-gradient-to-r from-rose-800 to-rose-600'}`}>
                <span className={`absolute text-[10px] font-black text-white transition ${checked ? 'left-2' : 'right-2'}`}>
                    {checked ? 'ON' : 'OFF'}
                </span>
                <span className={`absolute h-7 w-7 rounded-full border border-white/70 bg-gradient-to-br from-white to-slate-200 shadow-lg transition-transform ${checked ? 'translate-x-8' : 'translate-x-0.5'}`} />
            </span>
        </button>
    );
}

function NotApplicableNotice({ section = 'This section' }) {
    return (
        <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">
            {section} is marked as not applicable. It will not be included in report-progress computation unless you turn N/A off.
        </div>
    );
}

function AdditionalSectionHeader({ title, description, onAdd, button, secondaryActions = [], naControl = null, subsection = false }) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <p className={`${subsection ? 'text-base text-slate-950 dark:text-white' : 'text-lg text-emerald-700'} font-black uppercase tracking-wide`}>{title}</p>
                {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
            </div>
            {(naControl || onAdd || secondaryActions.length > 0) && (
                <div className="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2">
                    {onAdd && (
                        <button type="button" onClick={onAdd} className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white hover:bg-emerald-800">
                            <Plus className="h-4 w-4" /> {button}
                        </button>
                    )}
                    {secondaryActions.map(([label, handler]) => (
                        <button key={label} type="button" onClick={handler} className="inline-flex items-center justify-center gap-2 rounded-md border border-emerald-200 px-3 py-2 text-xs font-black text-emerald-800 hover:bg-emerald-50">
                            <Plus className="h-4 w-4" /> {label}
                        </button>
                    ))}
                    {naControl}
                </div>
            )}
        </div>
    );
}

function LockedSectionNotice({ children }) {
    return (
        <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {children}
        </div>
    );
}

function SupplementarySectionInstruction() {
    return (
        <div className="mt-3 rounded-md border border-sky-200 bg-sky-50 p-3 text-xs font-bold leading-relaxed text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100 sm:text-sm">
            Complete every field in rows you start. If an exact date/time, age, value, length, quantity, or cost is not yet available, enter the best LGU estimate and clarify it in the remarks/source field. Use <span className="font-black">N/A</span> for text fields that are truly not applicable.
        </div>
    );
}

function DromicSectionCard({ children, id = null, className = '' }) {
    return (
        <div id={id || undefined} className={`dromic-section-anchor min-w-0 max-w-full overflow-hidden rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-4 ${className}`.trim()}>
            {children}
        </div>
    );
}
function SuspensionTable({ id = null, title, rows, issues = [], addRow, update, remove, barangayOptions = [], withBarangay = false, locked = false, lockedNotice = '', subsection = false, isNa = false, toggleNa = null }) {
    const issueFor = (index) => issues.find((issue) => issue.index === index);
    const isClassSuspension = title === 'Class Suspension';
    const headers = [
        'No.',
        'Coverage',
        ...(withBarangay ? ['Barangay'] : []),
        ...(isClassSuspension ? ['Level From', 'Level To'] : []),
        'Type',
        'Date and Time of Suspension',
        'Date and Time Resumed',
        'Remarks',
        'Validation Remarks',
        'Action',
    ];
    const colSpan = headers.length;
    const totals = { rows: rows.length };
    const coverageOptions = [
        { value: 'Entire LGU', label: 'Entire LGU' },
        { value: 'All affected barangays', label: 'All affected barangays' },
        { value: 'Selected barangay', label: 'Selected barangay' },
    ];
    const levelOptions = withOthersOption(['Pre-School', 'Primary', 'Elementary', 'Junior High School', 'Senior High School', 'College', 'All levels']);
    const typeOptions = withOthersOption(['All', 'Public', 'Private']);
    const valueFields = isClassSuspension
        ? ['coverage', 'barangay', 'level_from', 'level_from_other', 'level_to', 'level_to_other', 'type', 'type_other', 'suspension_at', 'resumed_at', 'remarks']
        : ['coverage', 'barangay', 'type', 'type_other', 'suspension_at', 'resumed_at', 'remarks'];
    const requiredFields = isClassSuspension
        ? ['coverage', 'level_from', 'level_to', 'type', 'suspension_at', 'remarks']
        : ['coverage', 'type', 'suspension_at', 'remarks'];
    const rowHasValue = (row = {}) => valueFields.some((field) => String(row[field] ?? '').trim() !== '');
    const rowComplete = (row = {}) => requiredFields.every((field) => String(row[field] ?? '').trim() !== '');
    const tableMinWidth = withBarangay ? 'min-w-[1500px]' : 'min-w-[1100px]';
    const remarkFor = (issue, row) => {
        if (issue) return issue.message;
        if (!rowHasValue(row)) return 'Awaiting Entry!';
        if (!rowComplete(row)) return 'Complete started row';
        return 'VALID DATA!';
    };
    const displayRows = rows
        .map((row, originalIndex) => ({ row, originalIndex }))
        .sort((a, b) => String(a.row.type || '').localeCompare(String(b.row.type || ''), undefined, { sensitivity: 'base' })
            || a.originalIndex - b.originalIndex);

    return (
        <div id={id || undefined} className="dromic-section-anchor w-full min-w-0">
            <AdditionalSectionHeader
                title={title}
                description="Encode suspension and resumption details when issued by the LGU or authorized office."
                onAdd={locked || isNa ? null : addRow}
                button={`Add ${title.toLowerCase()}`}
                subsection={subsection}
                naControl={toggleNa ? <NotApplicableToggle checked={isNa} onChange={toggleNa} /> : null}
            />
            {isNa ? (
                <NotApplicableNotice section={title} />
            ) : locked ? (
                <LockedSectionNotice>{lockedNotice}</LockedSectionNotice>
            ) : (
                <>
                    <div className="dromic-table-scroll mt-2 rounded-md border border-slate-200 dark:border-zinc-800">
                        <table className={`dromic-wide-table ${tableMinWidth} w-full table-auto border-collapse text-xs sm:text-sm`}>
                            <thead>
                                <tr className={dromicHeadClass}>
                                    {headers.map((head) => <th key={head} className="border border-slate-700 px-3 py-2 font-black uppercase">{head}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {displayRows.length ? displayRows.map(({ row, originalIndex }, displayIndex) => {
                                    const issue = issueFor(originalIndex);

                                    return (
                                        <tr key={originalIndex} className={issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                                            <NumberCell value={displayIndex + 1} />
                                            <SelectorCell value={row.coverage} onChange={(value) => update(originalIndex, {
                                                coverage: value,
                                                barangay: value === 'Selected barangay' ? row.barangay : '',
                                            })} options={coverageOptions} placeholder="Search coverage..." allLabel="Coverage" />
                                            {withBarangay && (
                                                row.coverage === 'Selected barangay'
                                                    ? <SelectorCell value={row.barangay} onChange={(value) => update(originalIndex, 'barangay', value)} options={barangayOptions} placeholder="Search barangay..." allLabel="Barangay" />
                                                    : <td className="nowrap-cell border border-slate-300 px-3 py-2 text-center text-sm font-black text-slate-700">{row.coverage || 'Select coverage first'}</td>
                                            )}
                                            {isClassSuspension && (
                                                <>
                                                    <OtherOptionCell
                                                        value={row.level_from}
                                                        otherValue={row.level_from_other}
                                                        onChange={(value) => update(originalIndex, {
                                                            level_from: value,
                                                            level_from_other: value === 'Others' ? row.level_from_other : '',
                                                        })}
                                                        onOtherChange={(value) => update(originalIndex, 'level_from_other', value)}
                                                        options={levelOptions}
                                                        placeholder="Search level..."
                                                        allLabel="Level from"
                                                        otherPlaceholder="Specify level from"
                                                    />
                                                    <OtherOptionCell
                                                        value={row.level_to}
                                                        otherValue={row.level_to_other}
                                                        onChange={(value) => update(originalIndex, {
                                                            level_to: value,
                                                            level_to_other: value === 'Others' ? row.level_to_other : '',
                                                        })}
                                                        onOtherChange={(value) => update(originalIndex, 'level_to_other', value)}
                                                        options={levelOptions}
                                                        placeholder="Search level..."
                                                        allLabel="Level to"
                                                        otherPlaceholder="Specify level to"
                                                    />
                                                </>
                                            )}
                                            <OtherOptionCell
                                                value={row.type}
                                                otherValue={row.type_other}
                                                onChange={(value) => update(originalIndex, {
                                                    type: value,
                                                    type_other: value === 'Others' ? row.type_other : '',
                                                })}
                                                onOtherChange={(value) => update(originalIndex, 'type_other', value)}
                                                options={typeOptions}
                                                placeholder="Search type..."
                                                allLabel="Type"
                                                otherPlaceholder="Specify type"
                                            />
                                            <EditableCell type="datetime-local" value={row.suspension_at} onChange={(value) => update(originalIndex, 'suspension_at', value)} />
                                            <EditableCell type="datetime-local" value={row.resumed_at} onChange={(value) => update(originalIndex, 'resumed_at', value)} />
                                            <TextareaCell value={row.remarks} onChange={(value) => update(originalIndex, 'remarks', value)} />
                                            <RemarkCell text={remarkFor(issue, row)} issue={issue} />
                                            <DeleteCell onClick={() => remove(originalIndex)} />
                                        </tr>
                                    );
                                }) : <EmptyRows colSpan={colSpan} message={`No ${title.toLowerCase()} encoded.`} />}
                            </tbody>
                            {rows.length > 0 && (
                                <tfoot>
                                    <tr className="bg-cyan-100 font-black text-slate-950">
                                        <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                                        <td colSpan={colSpan - 3} className="nowrap-cell border border-slate-700 px-3 py-2 text-center">{formatNumber(totals.rows)} suspension row/s</td>
                                        <td className="border border-slate-700 px-3 py-2 text-center">Validate above</td>
                                        <td className="border border-slate-700 px-3 py-2" />
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                    <DromicIssueNotice issues={issues} tableName={`${title} table`} />
                </>
            )}
        </div>
    );
}

function NumberCell({ value }) {
    return <td className="dromic-nowrap border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{value}</td>;
}

function EditableCell({ value, onChange, type = 'text', placeholder = 'Type here' }) {
    return (
        <td className="dromic-control-cell border border-slate-300 p-1 align-top">
            <FriendlyInput type={type} value={value} onChange={onChange} placeholder={placeholder} />
        </td>
    );
}

function RelatedIncidentTypeCell({ value, otherValue, onChange, onOtherChange, options = [] }) {
    return (
        <OtherOptionCell
            value={value}
            otherValue={otherValue}
            onChange={onChange}
            onOtherChange={onOtherChange}
            options={options}
            placeholder="Search incident..."
            allLabel="Incident"
            otherPlaceholder="Specify type of incident"
        />
    );
}

function OtherOptionCell({ value, otherValue, onChange, onOtherChange, options = [], placeholder = 'Search...', allLabel = '—', otherPlaceholder = 'Specify other value' }) {
    const isOthers = String(value || '').trim() === 'Others';

    return (
        <td className="dromic-control-cell min-w-0 border border-slate-300 p-1 align-top">
            <LookerMultiSelect
                single
                label=""
                className="w-full min-w-0"
                value={value ? [value] : []}
                onApply={(values) => onChange(values.at(-1) || '')}
                options={(options || []).map((option) => (typeof option === 'string' ? { value: option, label: option } : option))}
                placeholder={placeholder}
                allLabel={allLabel}
            />
            {isOthers && (
                <input
                    type="text"
                    value={otherValue ?? ''}
                    onChange={(event) => onOtherChange(event.target.value)}
                    placeholder={otherPlaceholder}
                    className="mt-2 w-full rounded-md border-2 border-emerald-300 bg-white px-2 py-2 text-xs font-bold text-slate-950 placeholder:text-slate-400 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300 dark:bg-zinc-950 dark:text-white"
                />
            )}
        </td>
    );
}

function SelectorCell({ value, onChange, options = [], placeholder = 'Search...', allLabel = '—' }) {
    return (
        <td className="dromic-control-cell min-w-0 border border-slate-300 p-1 align-top">
            <LookerMultiSelect
                single
                label=""
                className="w-full min-w-0"
                value={value ? [value] : []}
                onApply={(values) => onChange(values.at(-1) || '')}
                options={(options || []).map((option) => (typeof option === 'string' ? { value: option, label: option } : option))}
                placeholder={placeholder}
                allLabel={allLabel}
            />
        </td>
    );
}

function SelectCell({ value, onChange, options = [], placeholder = '—' }) {
    return (
        <td className="dromic-control-cell min-w-0 border border-slate-300 p-1 align-top">
            <select
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                title={value || placeholder}
                className="dromic-native-select w-full min-w-0 rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300"
            >
                <option value="">{placeholder}</option>
                {options.map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
        </td>
    );
}

function TextareaCell({ value, onChange, placeholder = 'Type details' }) {
    return (
        <td className="dromic-control-cell border border-slate-300 p-1 align-top">
            <textarea rows={2} value={value ?? ''} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="min-w-0 w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-bold text-slate-950 placeholder:text-slate-400 focus:border-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-300" />
        </td>
    );
}

function RemarkCell({ text, issue }) {
    const normalized = String(text || '').toLowerCase();
    const needsAttention = issue
        || normalized.includes('awaiting')
        || normalized.includes('needs input')
        || normalized.includes('complete started');

    return <td className={`border border-slate-300 px-2 py-2 text-[11px] font-black leading-snug sm:px-3 ${needsAttention ? 'text-rose-700' : 'text-emerald-700'}`}>{text}</td>;
}

function DeleteCell({ onClick }) {
    return (
        <td className="dromic-control-cell border border-slate-300 p-1 text-center align-top">
            <button type="button" onClick={onClick} className="inline-flex items-center justify-center rounded-md border border-rose-200 p-2 text-rose-600 hover:bg-rose-50">
                <Trash2 className="h-4 w-4" />
            </button>
        </td>
    );
}

function EmptyRows({ colSpan, message }) {
    return (
        <tr>
            <td colSpan={colSpan} className="border border-slate-300 px-3 py-4 text-center text-slate-500">{message}</td>
        </tr>
    );
}

function AffectedPopulationTable({ rows, updateAreaRow, issues = [] }) {
    const totalFamilies = rows.reduce((sum, row) => sum + Number(row.affected_families || 0), 0);
    const totalPersons = rows.reduce((sum, row) => sum + Number(row.affected_persons || 0), 0);
    const uniqueBarangayCount = new Set(rows.map((row) => String(row.psgc_code || row.area || '').trim()).filter(Boolean)).size;
    const hasIssue = (index) => issues.some((issue) => issue.index === index);
    const issueType = (index) => issues.find((issue) => issue.index === index)?.type;

    return (
        <div className="w-full min-w-0 max-w-full overflow-hidden rounded-md border border-slate-200 dark:border-zinc-800">
            <table className="w-full table-fixed border-collapse text-xs sm:text-sm">
                <thead>
                    <tr className={dromicHeadClass}>
                        <th rowSpan="2" className="w-12 border border-slate-700 px-2 py-2 text-center font-black uppercase sm:w-16">No.</th>
                        <th rowSpan="2" className="w-[15%] border border-slate-700 px-2 py-2 text-left font-black uppercase sm:px-3">Barangay</th>
                        <th rowSpan="2" className="w-[20%] border border-slate-700 px-2 py-2 text-center font-black uppercase sm:px-3">PSA 2024 Population</th>
                        <th colSpan="2" className="border border-slate-700 px-2 py-2 text-center font-black uppercase sm:px-3">Number of affected</th>
                        <th rowSpan="2" className="w-[16%] border border-slate-700 px-2 py-2 text-center font-black uppercase sm:px-3">Validation Remarks</th>
                    </tr>
                    <tr className={dromicHeadClass}>
                        <th className="border border-slate-700 px-2 py-2 text-center font-black uppercase sm:px-3">Families <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-2 py-2 text-center font-black uppercase sm:px-3">Persons <span className="text-rose-700">*</span></th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length ? rows.map((row, index) => {
                        const issue = issues.find((item) => item.index === index);

                        return (
                        <tr key={row.area || index} className={issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                            <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{index + 1}</td>
                            <td className="break-words border border-slate-300 px-2 py-2 font-bold sm:px-3">{row.area || `Selected Barangay ${index + 1}`}</td>
                            <td className="border border-slate-300 px-2 py-2 text-center font-black text-emerald-700 dark:text-emerald-200 sm:px-3">
                                {row.psa_2024 ? formatNumber(row.psa_2024) : <span className="text-xs text-slate-400">No data</span>}
                            </td>
                            <td className="dromic-control-cell border border-slate-300 p-1">
                                <input type="number" min="0" required className={`w-full min-w-0 border-0 px-1 text-center font-bold focus:ring-1 ${['families', 'paired'].includes(issueType(index)) ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/20 dark:text-rose-200' : ''}`} value={row.affected_families ?? ''} onChange={(event) => updateAreaRow(index, 'affected_families', event.target.value)} />
                            </td>
                            <td className="dromic-control-cell border border-slate-300 p-1">
                                <input type="number" min="0" required className={`w-full min-w-0 border-0 px-1 text-center font-bold focus:ring-1 ${issue ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/20 dark:text-rose-200' : ''}`} value={row.affected_persons ?? ''} onChange={(event) => updateAreaRow(index, 'affected_persons', event.target.value)} />
                            </td>
                            <td className={`break-words border border-slate-300 px-2 py-2 text-[11px] font-black leading-snug sm:px-3 ${issue ? 'text-rose-700' : 'text-emerald-700'}`}>
                                {issue ? issue.message.replace(`Affected persons for ${row.area || `Barangay ${index + 1}`} `, 'Persons ') : 'VALID DATA!'}
                            </td>
                        </tr>
                        );
                    }) : (
                        <tr>
                            <td colSpan="6" className="border border-slate-300 px-3 py-6 text-center text-slate-500">Select affected barangays above to generate rows here.</td>
                        </tr>
                    )}
                    {rows.length > 0 && (
                        <tr className="bg-cyan-100 font-black text-slate-950">
                            <td className="border border-slate-700 dromic-footer-label px-2 py-2 uppercase sm:px-3">Total</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(uniqueBarangayCount)}</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(rows.reduce((sum, row) => sum + Number(row.psa_2024 || 0), 0))}</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{totalFamilies.toLocaleString()}</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{totalPersons.toLocaleString()}</td>
                            <td className="break-words border border-slate-700 px-2 py-2 text-center text-[11px] leading-snug sm:px-3">{issues.length ? 'Review rows above' : 'VALID DATA!'}</td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

function OutsideEvacuationCentersTable({ rows, insideEcByOriginCode = new Map(), updateAreaRow, updateAreaRows, requestRemoveRow, issues = [] }) {
    const insideConsumedState = (row) => {
        const inside = insideEcByOriginCode.get(String(row.psgc_code || '').trim())
            || insideEcByOriginCode.get(String(row.area || '').trim())
            || { families_cum: 0, families_now: 0, persons_cum: 0, persons_now: 0 };
        const affectedFamilies = Number(row.affected_families || 0);
        const affectedPersons = Number(row.affected_persons || 0);
        const cumConsumed = affectedFamilies > 0
            && affectedPersons > 0
            && inside.families_cum === affectedFamilies
            && inside.persons_cum === affectedPersons;
        const nowConsumed = affectedFamilies > 0
            && affectedPersons > 0
            && inside.families_now === affectedFamilies
            && inside.persons_now === affectedPersons;

        return {
            consumed: cumConsumed || nowConsumed,
            cumConsumed,
            nowConsumed,
        };
    };
    const includedRows = rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => row.outside_ec_included);
    const availableRows = rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => !row.outside_ec_included && !insideConsumedState(row).consumed);
    const availableIndexSet = new Set(availableRows.map(({ index }) => index));
    const blockedRows = rows
        .map((row, index) => ({ row, index, state: insideConsumedState(row) }))
        .filter(({ row, state }) => !row.outside_ec_included && state.consumed);
    const hasIssue = (index) => issues.some((issue) => issue.index === index);
    const totals = includedRows.reduce((summary, { row }) => ({
        families_cum: summary.families_cum + Number(row.outside_ec_families_cum || 0),
        families_now: summary.families_now + Number(row.outside_ec_families_now || 0),
        persons_cum: summary.persons_cum + Number(row.outside_ec_persons_cum || 0),
        persons_now: summary.persons_now + Number(row.outside_ec_persons_now || 0),
        barangays: summary.barangays + (String(row.area || '').trim() ? 1 : 0),
    }), {
        families_cum: 0,
        families_now: 0,
        persons_cum: 0,
        persons_now: 0,
        barangays: 0,
    });
    const addOutsideRowsFromSelection = (values = []) => {
        const selectedIndexes = values
            .map(Number)
            .filter((value) => !Number.isNaN(value) && availableIndexSet.has(value));
        if (!selectedIndexes.length) return;

        updateAreaRows((row, rowIndex) => selectedIndexes.includes(rowIndex) && !insideConsumedState(row).consumed ? { ...row, outside_ec_included: true } : row);
    };
    return (
        <div className="mt-3 space-y-3">
            <div className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-zinc-800 dark:bg-zinc-950">
                <LookerMultiSelect
                    label="Barangay of Origin to Add"
                    value={[]}
                    onApply={addOutsideRowsFromSelection}
                    options={availableRows.map(({ row, index }) => ({
                        value: String(index),
                        label: row.area || `Barangay ${index + 1}`,
                    }))}
                    placeholder={availableRows.length ? 'Search affected barangay...' : 'All affected barangays already added'}
                    allLabel="Outside-EC barangays"
                />
                <p className="mt-2 text-xs font-semibold text-slate-500">Selecting a barangay automatically creates its Outside EC row.</p>
            </div>
            {blockedRows.length > 0 && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                    <p className="font-black">Some barangays are not available for Outside EC encoding</p>
                    <p className="mt-1">
                        {blockedRows.map(({ row }) => row.area || 'Unnamed barangay').join(', ')} {blockedRows.length === 1 ? 'is' : 'are'} already fully encoded in Inside EC. If some families/persons are actually outside evacuation centers, review the Inside EC row first, then reduce or set the Inside EC counts to the correct values before adding Outside EC data.
                    </p>
                </div>
            )}
            <div className="w-full min-w-0 max-w-full overflow-hidden rounded-md border border-slate-200 dark:border-zinc-800">
            <table className="w-full table-fixed border-collapse text-xs sm:text-sm">
                <thead>
                    <tr className={dromicHeadClass}>
                        <th rowSpan="2" className="w-12 border border-slate-700 px-2 py-2 font-black uppercase sm:w-16">No.</th>
                        <th colSpan="2" className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Families</th>
                        <th colSpan="2" className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Persons</th>
                        <th rowSpan="2" className="w-[18%] border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Barangay of Origin of IDPs</th>
                        <th rowSpan="2" className="w-[16%] border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Validation Remarks</th>
                        <th rowSpan="2" className="w-14 border border-slate-700 px-2 py-2 font-black uppercase sm:w-16">Action</th>
                    </tr>
                    <tr className={dromicHeadClass}>
                        <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">CUM <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">NOW <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">CUM <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">NOW <span className="text-rose-700">*</span></th>
                    </tr>
                </thead>
                <tbody>
                    {includedRows.length ? includedRows.map(({ row, index }, displayIndex) => {
                        const issue = issues.find((item) => item.index === index);

                        return (
                        <tr key={row.psgc_code || row.area || index} className={issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                            <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{displayIndex + 1}</td>
                            {['outside_ec_families_cum', 'outside_ec_families_now', 'outside_ec_persons_cum', 'outside_ec_persons_now'].map((field) => (
                                <td key={field} className="dromic-control-cell border border-slate-300 p-1">
                                    <FriendlyInput type="number" value={row[field]} onChange={(value) => updateAreaRow(index, field, value)} required />
                                </td>
                            ))}
                            <td className="break-words border border-slate-300 px-2 py-2 text-center font-bold sm:px-3">{row.area || '-'}</td>
                            <td className={`break-words border border-slate-300 px-2 py-2 text-[11px] font-black leading-snug sm:px-3 ${issue ? 'text-rose-700' : 'text-emerald-700'}`}>
                                {issue ? issue.message.replace(`${row.area || `Barangay ${index + 1}`}: `, '') : 'VALID DATA!'}
                            </td>
                            <td className="dromic-control-cell border border-slate-300 p-1 text-center">
                                <button type="button" onClick={() => requestRemoveRow(index)} className="inline-flex items-center justify-center rounded-md border border-rose-200 p-2 text-rose-600 hover:bg-rose-50">
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        );
                    }) : (
                        <tr>
                            <td colSpan="8" className="border border-slate-300 px-3 py-6 text-center text-slate-500">No Outside EC barangay added. Select affected barangay/s above only if there are displaced families/persons outside evacuation centers.</td>
                        </tr>
                    )}
                </tbody>
                {includedRows.length > 0 && (
                    <tfoot>
                        <tr className="bg-cyan-100 font-black text-slate-950">
                            <td className="border border-slate-700 px-2 py-2 dromic-footer-label text-center uppercase sm:px-3">Total</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.families_cum)}</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.families_now)}</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.persons_cum)}</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.persons_now)}</td>
                            <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.barangays)}</td>
                            <td className="break-words border border-slate-700 px-2 py-2 text-center text-[11px] leading-snug sm:px-3">{issues.length ? 'Review rows above' : 'VALID DATA!'}</td>
                            <td className="border border-slate-700 px-2 py-2 sm:px-3" />
                        </tr>
                    </tfoot>
                )}
            </table>
            </div>
        </div>
    );
}

function DamagedHousesTable({ rows, updateAreaRow, updateAreaRows, requestRemoveRow, issues = [] }) {
    const includedRows = rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => row.damaged_houses_included);
    const availableRows = rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => !row.damaged_houses_included);
    const availableIndexSet = new Set(availableRows.map(({ index }) => index));
    const hasIssue = (index) => issues.some((issue) => issue.index === index);
    const issueFor = (index) => issues.find((issue) => issue.index === index);
    const totals = includedRows.reduce((summary, { row }) => {
        const totally = Number(row.damaged_houses_totally || 0);
        const partially = Number(row.damaged_houses_partially || 0);

        return {
            totally: summary.totally + totally,
            partially: summary.partially + partially,
            total: summary.total + totally + partially,
            estimated_cost: summary.estimated_cost + Number(row.damaged_houses_estimated_cost || 0),
            barangays: summary.barangays + (String(row.area || '').trim() ? 1 : 0),
        };
    }, {
        totally: 0,
        partially: 0,
        total: 0,
        estimated_cost: 0,
        barangays: 0,
    });

    const addRowsFromSelection = (values = []) => {
        const selectedAvailableIndexes = values
            .map(Number)
            .filter((value) => !Number.isNaN(value) && availableIndexSet.has(value));
        if (!selectedAvailableIndexes.length) return;

        updateAreaRows((row, rowIndex) => selectedAvailableIndexes.includes(rowIndex)
            ? {
                ...row,
                damaged_houses_included: true,
                damaged_houses_totally: row.damaged_houses_totally ?? '',
                damaged_houses_partially: row.damaged_houses_partially ?? '',
                damaged_houses_estimated_cost: row.damaged_houses_estimated_cost ?? '',
            }
            : row);
    };

    return (
        <div className="mt-3 space-y-3">
            <div className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-zinc-800 dark:bg-zinc-950">
                <LookerMultiSelect
                    label="Barangay to Add"
                    value={[]}
                    onApply={addRowsFromSelection}
                    options={availableRows.map(({ row, index }) => ({
                        value: String(index),
                        label: row.area || `Barangay ${index + 1}`,
                    }))}
                    placeholder={availableRows.length ? 'Search affected barangay...' : 'All affected barangays already added'}
                    allLabel="Barangays with damaged houses"
                />
                <p className="mt-2 text-xs font-semibold text-slate-500">Selecting a barangay automatically creates its damaged-houses row.</p>
            </div>

            <div className="w-full min-w-0 max-w-full overflow-hidden rounded-md border border-slate-200 dark:border-zinc-800">
                <table className="w-full table-fixed border-collapse text-xs sm:text-sm">
                    <thead>
                        <tr className={dromicHeadClass}>
                            <th rowSpan="2" className="w-12 border border-slate-700 px-2 py-2 font-black uppercase sm:w-16">No.</th>
                            <th rowSpan="2" className="w-[15%] border border-slate-700 px-2 py-2 text-left font-black uppercase sm:px-3">Barangay</th>
                            <th colSpan="4" className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Damaged Houses</th>
                            <th rowSpan="2" className="w-[12%] border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Affected Families</th>
                            <th rowSpan="2" className="w-[16%] border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Validation Remarks</th>
                            <th rowSpan="2" className="w-14 border border-slate-700 px-2 py-2 font-black uppercase sm:w-16">Action</th>
                        </tr>
                        <tr className={dromicHeadClass}>
                            <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Totally <span className="text-rose-700">*</span></th>
                            <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Partially <span className="text-rose-700">*</span></th>
                            <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Total</th>
                            <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Estimated Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        {includedRows.length ? includedRows.map(({ row, index }, displayIndex) => {
                            const totally = Number(row.damaged_houses_totally || 0);
                            const partially = Number(row.damaged_houses_partially || 0);
                            const issue = issueFor(index);

                            return (
                                <tr key={row.psgc_code || row.area || index} className={issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                                    <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{displayIndex + 1}</td>
                                    <td className="break-words border border-slate-300 px-2 py-2 font-bold sm:px-3">{row.area || '-'}</td>
                                    <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                        <FriendlyInput type="number" value={row.damaged_houses_totally} onChange={(value) => updateAreaRow(index, 'damaged_houses_totally', value)} required />
                                    </td>
                                    <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                        <FriendlyInput type="number" value={row.damaged_houses_partially} onChange={(value) => updateAreaRow(index, 'damaged_houses_partially', value)} required />
                                    </td>
                                    <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{formatNumber(totally + partially)}</td>
                                    <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                        <FriendlyInput type="number" value={row.damaged_houses_estimated_cost} onChange={(value) => updateAreaRow(index, 'damaged_houses_estimated_cost', value)} placeholder="₱0.00" />
                                    </td>
                                    <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{formatNumber(row.affected_families)}</td>
                                    <td className={`break-words border border-slate-300 px-2 py-2 text-[11px] font-black leading-snug sm:px-3 ${issue ? 'text-rose-700' : 'text-emerald-700'}`}>
                                        {issue ? issue.message.replace(`${row.area || `Barangay ${index + 1}`}: `, '') : 'VALID DATA!'}
                                    </td>
                                    <td className="dromic-control-cell border border-slate-300 p-1 text-center">
                                        <button type="button" onClick={() => requestRemoveRow(index)} className="inline-flex items-center justify-center rounded-md border border-rose-200 p-2 text-rose-600 hover:bg-rose-50">
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            );
                        }) : (
                            <tr>
                                <td colSpan="9" className="border border-slate-300 px-3 py-6 text-center text-slate-500">No damaged houses row added. Select affected barangay/s above only if there are damaged houses data to encode.</td>
                            </tr>
                        )}
                    </tbody>
                    {includedRows.length > 0 && (
                        <tfoot>
                            <tr className="bg-cyan-100 font-black text-slate-950">
                                <td className="border border-slate-700 px-2 py-2 dromic-footer-label text-center uppercase sm:px-3">Total</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.barangays)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.totally)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.partially)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.total)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatCurrency(totals.estimated_cost)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">Validate above</td>
                                <td className="break-words border border-slate-700 px-2 py-2 text-center text-[11px] leading-snug sm:px-3">{issues.length ? 'Review rows above' : 'VALID DATA!'}</td>
                                <td className="border border-slate-700 px-2 py-2 sm:px-3" />
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </div>
    );
}

function BarangayAssistanceProvidedTable({ rows, areaRows = [], addRow, updateRow, requestRemoveRow, issues = [] }) {
    const sourceOptions = ['Barangay LGU', 'City/Municipal LGU', 'Provincial LGU', 'DSWD', 'NGO / CSO', 'Private Partner', 'Other'];
    const itemTypeOptions = ['Food Items', 'Non-Food Items', 'Financial Assistance', 'Medical Assistance', 'Logistics / Services', 'Other Assistance'];
    const unitOptions = ['Family Food Pack', 'Pack', 'Kilo', 'Sack', 'Piece', 'Set', 'Kit', 'Box', 'Bottle', 'Sachet', 'Liter', 'Unit', 'Service'];
    const selectedAssistanceBarangayKeys = new Set(rows.map((row) => String(row.barangay_code || row.barangay || '')).filter(Boolean));
    const affectedOptions = areaRows
        .filter((row) => String(row.area || '').trim())
        .filter((row) => !selectedAssistanceBarangayKeys.has(String(row.psgc_code || row.area)))
        .map((row) => ({
            label: `${row.area} (${formatNumber(row.affected_families)} families / ${formatNumber(row.affected_persons)} persons)`,
            value: String(row.psgc_code || row.area),
            row,
        }));
    const areaByValue = new Map(affectedOptions.map((option) => [option.value, option.row]));
    const availableAssistanceValues = new Set(affectedOptions.map((option) => option.value));
    const groups = rows.reduce((collection, row, index) => {
        const key = String(row.barangay_code || row.barangay || 'unassigned');
        const area = areaRows.find((item) => String(item.psgc_code || item.area) === key || item.area === row.barangay) || {};

        if (!collection.has(key)) {
            collection.set(key, {
                key,
                area,
                barangay: row.barangay || area.area || 'Unassigned assistance',
                rows: [],
            });
        }

        collection.get(key).rows.push({ row, index });

        return collection;
    }, new Map());
    const totals = rows.reduce((summary, row) => {
        const quantity = Number(row.quantity || 0);

        return {
            quantity: summary.quantity + quantity,
            amount: summary.amount + (quantity * Number(row.cost_per_unit || 0)),
            families_served: summary.families_served + Number(row.families_served || 0),
        };
    }, { quantity: 0, amount: 0, families_served: 0 });
    const groupTotals = (groupRows = []) => groupRows.reduce((summary, item) => {
        const quantity = Number(item.row.quantity || 0);

        return {
            quantity: summary.quantity + quantity,
            amount: summary.amount + (quantity * Number(item.row.cost_per_unit || 0)),
            families_served: summary.families_served + Number(item.row.families_served || 0),
        };
    }, { quantity: 0, amount: 0, families_served: 0 });
    const addAssistanceFromSelection = (values = []) => {
        values
            .filter((value) => availableAssistanceValues.has(value))
            .map((value) => areaByValue.get(value))
            .filter(Boolean)
            .forEach((areaRow) => addRow(areaRow));
    };
    const renderRow = ({ row, index }, sequence) => {
        const quantity = Number(row.quantity || 0);
        const amount = quantity * Number(row.cost_per_unit || 0);
        const issue = issues.find((item) => item.index === index);

        return (
            <tr key={index} className={issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                <td className="border border-slate-300 px-3 py-2 text-center font-black">{sequence}</td>
                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                    <select value={row.source ?? ''} onChange={(event) => updateRow(index, 'source', event.target.value)} className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300">
                    <option value="">Source</option>
                        {sourceOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                    </select>
                </td>
                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                    <FriendlyInput value={row.source_details} onChange={(value) => updateRow(index, 'source_details', value)} placeholder="Example: BLGU Dayano" />
                </td>
                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                    <FriendlyInput type="number" value={row.quantity} onChange={(value) => updateRow(index, 'quantity', value)} required />
                </td>
                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                    <select value={row.unit ?? ''} onChange={(event) => updateRow(index, 'unit', event.target.value)} className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300">
                    <option value="">Unit</option>
                        {unitOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                    </select>
                </td>
                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                    <select value={row.item_type ?? ''} onChange={(event) => updateRow(index, 'item_type', event.target.value)} className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300">
                    <option value="">Type</option>
                        {itemTypeOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                    </select>
                </td>
                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                    {String(row.item_type || '').toLowerCase() === 'financial assistance' ? <select value={row.particular ?? ''} onChange={(event) => updateRow(index, 'particular', event.target.value)} className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300" required><option value="">Select program</option>{['AICS', 'AKAP', 'ECT', 'CFW', 'SLP'].map((program) => <option key={program} value={program}>{program}</option>)}</select> : <FriendlyInput value={row.particular} onChange={(value) => updateRow(index, 'particular', value)} placeholder="Example: Family food pack" required />}
                </td>
                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                    <FriendlyInput type="number" value={row.cost_per_unit} onChange={(value) => updateRow(index, 'cost_per_unit', value)} placeholder="0.00" required />
                </td>
                <td className="border border-slate-300 px-3 py-2 text-center font-black">{formatCurrency(amount)}</td>
                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                    <FriendlyInput type="number" value={row.families_served} onChange={(value) => updateRow(index, 'families_served', value)} required />
                </td>
                <td className="dromic-control-cell border border-slate-300 p-1 text-center align-top">
                    <button type="button" onClick={() => requestRemoveRow(index)} className="inline-flex items-center justify-center rounded-md border border-rose-200 p-2 text-rose-600 hover:bg-rose-50">
                        <Trash2 className="h-4 w-4" />
                    </button>
                </td>
            </tr>
        );
    };

    return (
        <div className="mt-3 space-y-3">
            <div className="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-zinc-800 dark:bg-zinc-950">
                <LookerMultiSelect
                    label="Affected Barangay to Add Assistance"
                    value={[]}
                    onApply={addAssistanceFromSelection}
                    options={affectedOptions}
                    placeholder="Affected barangay..."
                    allLabel="Barangay for assistance"
                />
                <p className="mt-2 text-xs font-semibold text-slate-500">Selecting a barangay automatically creates its assistance table. You can still add several assistance items under the same barangay.</p>
            </div>
            {[...groups.values()].map((group) => {
                const totalsForGroup = groupTotals(group.rows);
                const groupHasIssues = group.rows.some(({ index }) => issues.some((issue) => issue.index === index));

                return (
                    <div key={group.key} className="dromic-table-fit rounded-md border border-slate-200 dark:border-zinc-800">
                        <div className="flex flex-col gap-2 border-b border-slate-200 bg-emerald-50 px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p className="text-xs font-black uppercase tracking-wide text-emerald-700">Assistance for Barangay</p>
                                <h4 className="text-lg font-black text-slate-950">{group.barangay}</h4>
                                {group.area?.area && <p className="text-xs font-semibold text-slate-500">Affected: {formatNumber(group.area.affected_families)} families / {formatNumber(group.area.affected_persons)} persons</p>}
                                <p className={`mt-1 text-xs font-black ${groupHasIssues ? 'text-rose-700' : 'text-emerald-700'}`}>
                                    {groupHasIssues ? 'Review assistance entries below' : 'VALID DATA!'}
                                </p>
                            </div>
                            {group.area?.area && (
                                <button type="button" onClick={() => addRow(group.area)} className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white hover:bg-emerald-800">
                                    <Plus className="h-4 w-4" /> Add item for {group.area.area}
                                </button>
                            )}
                        </div>
                        <table className="w-full min-w-0 table-fixed border-collapse text-xs sm:text-sm">
                            <thead>
                                <tr className={dromicHeadClass}>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">No.</th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Source <span className="text-rose-700">*</span></th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Specify Source if Applicable</th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Quantity <span className="text-rose-700">*</span></th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Unit of Measurement <span className="text-rose-700">*</span></th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Type of Item <span className="text-rose-700">*</span></th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Particular <span className="text-rose-700">*</span></th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Cost per Unit <span className="text-rose-700">*</span></th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Total Amount</th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">No. of Families Served <span className="text-rose-700">*</span></th>
                                    <th className="border border-slate-700 px-3 py-2 font-black uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody>{group.rows.map((item, itemIndex) => renderRow(item, itemIndex + 1))}</tbody>
                            <tfoot>
                                <tr className="bg-cyan-100 font-black text-slate-950">
                                    <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center" colSpan="2">{group.barangay}</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">{formatNumber(totalsForGroup.quantity)}</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">{formatCurrency(totalsForGroup.amount)}</td>
                                    <td className="border border-slate-700 px-3 py-2 text-center">{formatNumber(totalsForGroup.families_served)}</td>
                                    <td className="border border-slate-700 px-3 py-2" />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                );
            })}
            {!rows.length && (
                <div className="rounded-md border border-dashed border-slate-300 px-3 py-8 text-center text-sm text-slate-500">
                    No assistance item encoded yet. Select affected barangay/s above if relief items, cash, services, or other support have already been provided.
                </div>
            )}
            {rows.length > 0 && (
                <div className="w-full min-w-0 max-w-full overflow-hidden rounded-md border border-slate-200 dark:border-zinc-800">
                    <table className="w-full table-fixed border-collapse text-xs sm:text-sm">
                        <tfoot>
                            <tr className="bg-cyan-100 font-black text-slate-950">
                                <td className="break-words border border-slate-700 px-2 py-2 dromic-footer-label text-center uppercase sm:px-3">Total</td>
                                <td className="break-words border border-slate-700 px-2 py-2 text-center sm:px-3">All barangays</td>
                                <td className="break-words border border-slate-700 px-2 py-2 text-center sm:px-3">Quantity: {formatNumber(totals.quantity)}</td>
                                <td className="break-words border border-slate-700 px-2 py-2 text-center sm:px-3">Families served: {formatNumber(totals.families_served)}</td>
                                <td className="break-words border border-slate-700 px-2 py-2 text-center sm:px-3">Total amount: {formatCurrency(totals.amount)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </div>
    );
}

function AssistanceProvidedTable({ rows, updateRow, requestRemoveRow, totalAffectedFamilies = 0, totalAffectedPersons = 0, issues = [] }) {
    const sourceOptions = ['Barangay LGU', 'City/Municipal LGU', 'Provincial LGU', 'DSWD', 'NGO / CSO', 'Private Partner', 'Other'];
    const itemTypeOptions = ['food items', 'non-food items', 'financial assistance', 'medical assistance', 'logistics / services', 'other assistance'];
    const unitOptions = ['family food pack', 'pack', 'kilo', 'sack', 'piece', 'set', 'kit', 'box', 'bottle', 'sachet', 'liter', 'unit', 'service'];
    const hasIssue = (index) => issues.some((issue) => issue.index === index);
    const totals = rows.reduce((summary, row) => {
        const quantity = Number(row.quantity || 0);
        const amount = quantity * Number(row.cost_per_unit || 0);

        return {
            quantity: summary.quantity + quantity,
            amount: summary.amount + amount,
            families_served: summary.families_served + Number(row.families_served || 0),
        };
    }, {
        quantity: 0,
        amount: 0,
        families_served: 0,
    });

    return (
        <div className="dromic-table-fit mt-3 rounded-md border border-slate-200 dark:border-zinc-800">
            <table className="w-full min-w-0 table-fixed border-collapse text-xs sm:text-sm">
                <thead>
                    <tr className={dromicHeadClass}>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">No.</th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Source <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Specify Source if Applicable</th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Quantity <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Unit of Measurement <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Type of Item <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Particular <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Cost per Unit <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Total Amount</th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">No. of Families Served <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Validation Remarks</th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">Action</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length ? rows.map((row, index) => {
                        const quantity = Number(row.quantity || 0);
                        const amount = quantity * Number(row.cost_per_unit || 0);
                        const issue = issues.find((item) => item.index === index);

                        return (
                            <tr key={index} className={hasIssue(index) ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                                <td className="border border-slate-300 px-3 py-2 text-center font-black">{index + 1}</td>
                                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                    <select value={row.source ?? ''} onChange={(event) => updateRow(index, 'source', event.target.value)} className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300">
                                        <option value="">Source</option>
                                        {sourceOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                                    </select>
                                </td>
                                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                    <FriendlyInput value={row.source_details} onChange={(value) => updateRow(index, 'source_details', value)} placeholder="Example: BLGU Dayano" />
                                </td>
                                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                    <FriendlyInput type="number" value={row.quantity} onChange={(value) => updateRow(index, 'quantity', value)} required />
                                </td>
                                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                    <select value={row.unit ?? ''} onChange={(event) => updateRow(index, 'unit', event.target.value)} className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300">
                                        <option value="">Unit</option>
                                        {unitOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                                    </select>
                                </td>
                                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                    <select value={row.item_type ?? ''} onChange={(event) => updateRow(index, 'item_type', event.target.value)} className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300">
                                        <option value="">Type</option>
                                        {itemTypeOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                                    </select>
                                </td>
                                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                    {String(row.item_type || '').toLowerCase() === 'financial assistance' ? <select value={row.particular ?? ''} onChange={(event) => updateRow(index, 'particular', event.target.value)} className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300" required><option value="">Select program</option>{['AICS', 'AKAP', 'ECT', 'CFW', 'SLP'].map((program) => <option key={program} value={program}>{program}</option>)}</select> : <FriendlyInput value={row.particular} onChange={(value) => updateRow(index, 'particular', value)} placeholder="Example: 5-kilo rice" required />}
                                </td>
                                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                    <FriendlyInput type="number" value={row.cost_per_unit} onChange={(value) => updateRow(index, 'cost_per_unit', value)} placeholder="₱0.00" required />
                                </td>
                                <td className="border border-slate-300 px-3 py-2 text-center font-black">{formatCurrency(amount)}</td>
                                <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                    <FriendlyInput type="number" value={row.families_served} onChange={(value) => updateRow(index, 'families_served', value)} required />
                                </td>
                                <td className={`border border-slate-300 px-3 py-2 text-xs font-black ${issue ? 'text-rose-700' : 'text-emerald-700'}`}>
                                    {issue ? issue.message.replace(`Assistance Row ${index + 1}: `, '') : 'Valid data'}
                                </td>
                                <td className="dromic-control-cell border border-slate-300 p-1 text-center align-top">
                                    <button type="button" onClick={() => requestRemoveRow(index)} className="inline-flex items-center justify-center rounded-md border border-rose-200 p-2 text-rose-600 hover:bg-rose-50">
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        );
                    }) : (
                        <tr>
                            <td colSpan="12" className="border border-slate-300 px-3 py-6 text-center text-slate-500">
                                No assistance item encoded yet. Click Add Assistance if relief items, cash, services, or other augmentation have already been provided.
                            </td>
                        </tr>
                    )}
                </tbody>
                {rows.length > 0 && (
                    <tfoot>
                        <tr className="bg-cyan-100 font-black text-slate-950">
                            <td className="border border-slate-700 px-3 py-2 dromic-footer-label text-center uppercase">Total</td>
                            <td className="border border-slate-700 px-3 py-2 text-center" colSpan="2">All encoded assistance</td>
                            <td className="border border-slate-700 px-3 py-2 text-center">{formatNumber(totals.quantity)}</td>
                            <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                            <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                            <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                            <td className="border border-slate-700 px-3 py-2 text-center">N/A</td>
                            <td className="border border-slate-700 px-3 py-2 text-center">{formatCurrency(totals.amount)}</td>
                            <td className="border border-slate-700 px-3 py-2 text-center">{formatNumber(totals.families_served)}</td>
                            <td className="border border-slate-700 px-3 py-2 text-center text-xs">
                                Affected: {formatNumber(totalAffectedFamilies)} families / {formatNumber(totalAffectedPersons)} persons
                            </td>
                            <td className="border border-slate-700 px-3 py-2" />
                        </tr>
                    </tfoot>
                )}
            </table>
        </div>
    );
}

function ReadonlyDisplacementSummaryTable({ title, description, rows = [], emptyMessage = 'Complete affected population first to generate this summary.' }) {
    const totals = rows.reduce((summary, row) => ({
        families_cum: summary.families_cum + Number(row.families_cum || 0),
        families_now: summary.families_now + Number(row.families_now || 0),
        persons_cum: summary.persons_cum + Number(row.persons_cum || 0),
        persons_now: summary.persons_now + Number(row.persons_now || 0),
        barangays: summary.barangays + (String(row.barangay || '').trim() ? 1 : 0),
    }), {
        families_cum: 0,
        families_now: 0,
        persons_cum: 0,
        persons_now: 0,
        barangays: 0,
    });

    return (
        <div>
            <h3 className="text-base font-black uppercase tracking-wide text-slate-950 dark:text-white">{title}</h3>
            {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
            <div className="mt-3 w-full min-w-0 max-w-full overflow-hidden rounded-md border border-slate-200 dark:border-zinc-800">
                <table className="w-full table-fixed border-collapse text-xs sm:text-sm">
                    <thead>
                        <tr className={dromicHeadClass}>
                            <th rowSpan="2" className="w-12 border border-slate-700 px-2 py-2 font-black uppercase sm:w-16">No.</th>
                            <th rowSpan="2" className="w-[23%] border border-slate-700 px-2 py-2 text-left font-black uppercase sm:px-3">Barangay</th>
                            <th colSpan="2" className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Families</th>
                            <th colSpan="2" className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Persons</th>
                            <th rowSpan="2" className="w-[22%] border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">Validation Remarks</th>
                        </tr>
                        <tr className={dromicHeadClass}>
                            <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">CUM</th>
                            <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">NOW</th>
                            <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">CUM</th>
                            <th className="border border-slate-700 px-2 py-2 font-black uppercase sm:px-3">NOW</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length ? rows.map((row, index) => (
                            <tr key={`${title}-${row.barangay}-${index}`} className={row.validation_issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                                <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{index + 1}</td>
                                <td className="break-words border border-slate-300 px-2 py-2 font-bold sm:px-3">{row.barangay || '-'}</td>
                                <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{formatNumber(row.families_cum)}</td>
                                <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{formatNumber(row.families_now)}</td>
                                <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{formatNumber(row.persons_cum)}</td>
                                <td className="border border-slate-300 px-2 py-2 text-center font-black sm:px-3">{formatNumber(row.persons_now)}</td>
                                <td className={`break-words border border-slate-300 px-2 py-2 text-[11px] font-black leading-snug sm:px-3 ${row.validation_issue ? 'text-rose-700' : 'text-emerald-700'}`}>
                                    {row.validation_issue || 'VALID DATA!'}
                                </td>
                            </tr>
                        )) : (
                            <tr>
                                <td colSpan="7" className="border border-slate-300 px-3 py-6 text-center text-slate-500">{emptyMessage}</td>
                            </tr>
                        )}
                    </tbody>
                    {rows.length > 0 && (
                        <tfoot>
                            <tr className="bg-cyan-100 font-black text-slate-950">
                                <td className="border border-slate-700 px-2 py-2 dromic-footer-label text-center uppercase sm:px-3">Total</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.barangays)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.families_cum)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.families_now)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.persons_cum)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">{formatNumber(totals.persons_now)}</td>
                                <td className="border border-slate-700 px-2 py-2 text-center sm:px-3">
                                    {rows.some((row) => row.validation_issue) ? 'Review rows above' : 'VALID DATA!'}
                                </td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </div>
    );
}

function InsideEvacuationCentersTable({ rows, barangayOptions = [], affectedOriginOptions: affectedOriginSource = [], currentLguName, psgcOptions = {}, updateRow, requestRemoveRow, openDisaggregation, issues = [] }) {
    const hasIssue = (index) => issues.some((issue) => issue.index === index);
    const issueFor = (index) => issues.find((issue) => issue.index === index);
    const affectedOriginOptions = (affectedOriginSource || []).map((option) => ({
        value: String(option.label || option.value || option.name || '').trim(),
        label: String(option.label || option.value || option.name || '').trim(),
        code: option.code || option.psgc_code || '',
    })).filter((option) => String(option.value || '').trim());
    const selectAffectedOrigin = (index, selectedName) => {
        const selected = affectedOriginOptions.find((option) => String(option.value) === String(selectedName));

        updateRow(index, {
            barangay_origin_scope: 'current',
            barangay_origin: selectedName,
            barangay_origin_code: selected?.code || '',
            barangay_origin_province: '',
            barangay_origin_province_code: '',
            barangay_origin_city: '',
            barangay_origin_city_code: '',
        });
    };
    const disaggregationStatus = (row) => {
        if (!row.disaggregation_completed) {
            return {
                label: 'Encode Required Data',
                className: 'bg-amber-600 hover:bg-amber-700',
                title: 'Open the Required Disaggregated Data modal and encode age/sex and sectoral counts.',
            };
        }

        const issues = disaggregationValidationIssues(row);

        if (issues.length > 0) {
            return {
                label: 'Review Totals',
                className: 'bg-rose-600 hover:bg-rose-700',
                title: issues[0],
            };
        }

        return {
            label: 'Completed',
            className: 'bg-emerald-600 hover:bg-emerald-700',
            title: 'Required disaggregated data totals match this evacuation center row.',
        };
    };
    const grandTotals = rows.reduce((totals, row) => ({
        families_cum: totals.families_cum + Number(row.families_cum || 0),
        families_now: totals.families_now + Number(row.families_now || 0),
        persons_cum: totals.persons_cum + Number(row.persons_cum || 0),
        persons_now: totals.persons_now + Number(row.persons_now || 0),
        classrooms_used: totals.classrooms_used + Number(row.classrooms_used || 0),
    }), {
        families_cum: 0,
        families_now: 0,
        persons_cum: 0,
        persons_now: 0,
        classrooms_used: 0,
    });
    const normalizedEcName = (row) => String(row.evacuation_center || '').trim().toLowerCase();
    const normalizedEcBarangay = (row) => String(row.barangay_address_code || row.barangay_address || '').trim().toLowerCase();
    const hasNowCount = (row) => Number(row.families_now || 0) > 0 || Number(row.persons_now || 0) > 0;
    const uniqueEcBarangayCount = new Set(rows.map(normalizedEcBarangay).filter(Boolean)).size;
    const uniqueEcCumCount = new Set(rows.map(normalizedEcName).filter(Boolean)).size;
    const uniqueEcNowCount = new Set(rows.filter((row) => normalizedEcName(row) && hasNowCount(row)).map(normalizedEcName)).size;

    return (
        <div className="dromic-table-fit rounded-md border border-slate-200 dark:border-zinc-800">
            <table className="w-full min-w-0 table-fixed border-collapse text-xs sm:text-sm">
                <thead>
                    <tr className={dromicHeadClass}>
                        <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">No.</th>
                        <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Barangay Address of EC <span className="text-rose-700">*</span></th>
                        <th colSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Evacuation Center <span className="text-rose-700">*</span></th>
                        <th colSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Families</th>
                        <th colSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Persons</th>
                        <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Barangay of Origin of IDPs <span className="text-rose-700">*</span></th>
                        <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">No. of Classrooms Used <span className="text-rose-700">*</span></th>
                        <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Disaggregated Data <span className="text-rose-700">*</span></th>
                        <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Validation Remarks</th>
                        <th rowSpan="2" className="border border-slate-700 px-3 py-2 font-black uppercase">Action</th>
                    </tr>
                    <tr className={dromicHeadClass}>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">CUM</th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">NOW</th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">CUM <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">NOW <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">CUM <span className="text-rose-700">*</span></th>
                        <th className="border border-slate-700 px-3 py-2 font-black uppercase">NOW <span className="text-rose-700">*</span></th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length ? rows.map((row, index) => {
                        const issue = issueFor(index);

                        return (
                        <tr key={index} className={issue ? 'bg-rose-50 dark:bg-rose-950/30' : ''}>
                            <td className="border border-slate-300 px-3 py-2 text-center font-black">{index + 1}</td>
                            <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                <AddressSelector
                                    row={row}
                                    prefix="barangay_address"
                                    currentLguName={currentLguName}
                                    currentBarangays={barangayOptions}
                                    psgcOptions={psgcOptions}
                                    update={(key, value) => updateRow(index, key, value)}
                                />
                            </td>
                            <td colSpan="2" className="dromic-control-cell border border-slate-300 p-1 align-top">
                                <FriendlyInput value={row.evacuation_center} onChange={(value) => updateRow(index, 'evacuation_center', value)} placeholder="Example: Dayano Function Hall" required />
                            </td>
                            {['families_cum', 'families_now', 'persons_cum', 'persons_now'].map((field) => (
                                <td key={field} className="dromic-control-cell border border-slate-300 p-1 align-top"><FriendlyInput type="number" value={row[field]} onChange={(value) => updateRow(index, field, value)} required /></td>
                            ))}
                            <td className="dromic-control-cell border border-slate-300 p-1 align-top">
                                <CompactSearchSelect
                                    value={row.barangay_origin || ''}
                                    onChange={(value) => selectAffectedOrigin(index, value)}
                                    options={affectedOriginOptions.map((option) => ({
                                        value: option.value,
                                        label: option.label,
                                    }))}
                                    placeholder="Barangay of origin"
                                />
                            </td>
                            <td className="dromic-control-cell border border-slate-300 p-1 align-top"><FriendlyInput type="number" value={row.classrooms_used} onChange={(value) => updateRow(index, 'classrooms_used', value)} required /></td>
                            <td className="dromic-control-cell border border-slate-300 p-1 text-center align-top">
                                <button
                                    type="button"
                                    onClick={() => openDisaggregation(index)}
                                    title={disaggregationStatus(row).title}
                                    className={`rounded-md px-3 py-2 text-xs font-black text-white ${disaggregationStatus(row).className}`}
                                >
                                    {disaggregationStatus(row).label}
                                </button>
                            </td>
                            <td className={`border border-slate-300 px-3 py-2 text-xs font-black ${issue ? 'text-rose-700' : 'text-emerald-700'}`}>
                                {issue ? issue.message.replace(`Evacuation Center ${index + 1}: `, '') : 'VALID DATA!'}
                            </td>
                            <td className="dromic-control-cell border border-slate-300 p-1 text-center align-top">
                                <button type="button" onClick={() => requestRemoveRow(index)} className="inline-flex items-center justify-center rounded-md border border-rose-200 p-2 text-rose-600 hover:bg-rose-50">
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        );
                    }) : (
                        <tr>
                            <td colSpan="13" className="border border-slate-300 px-3 py-6 text-center text-sm font-semibold text-slate-500">
                                No evacuation center added yet. If evacuation centers were activated, click “Add Evacuation Center”.
                            </td>
                        </tr>
                    )}
                </tbody>
                {rows.length > 0 && (
                    <tfoot>
                        <tr className="bg-cyan-100 font-black text-slate-950">
                            <td className="border border-slate-700 px-3 py-3 dromic-footer-label text-center uppercase">Total</td>
                            <td className="border border-slate-700 px-3 py-3 text-center">{formatNumber(uniqueEcBarangayCount)}</td>
                            <td className="border border-slate-700 px-3 py-3 text-center">{formatNumber(uniqueEcCumCount)}</td>
                            <td className="border border-slate-700 px-3 py-3 text-center">{formatNumber(uniqueEcNowCount)}</td>
                            <td className="border border-slate-700 px-3 py-3 text-center">{formatNumber(grandTotals.families_cum)}</td>
                            <td className="border border-slate-700 px-3 py-3 text-center">{formatNumber(grandTotals.families_now)}</td>
                            <td className="border border-slate-700 px-3 py-3 text-center">{formatNumber(grandTotals.persons_cum)}</td>
                            <td className="border border-slate-700 px-3 py-3 text-center">{formatNumber(grandTotals.persons_now)}</td>
                            <td className="border border-slate-700 px-3 py-3 text-center text-xs uppercase">All EC rows</td>
                            <td className="border border-slate-700 px-3 py-3 text-center">{formatNumber(grandTotals.classrooms_used)}</td>
                            <td className="border border-slate-700 px-3 py-3 text-center text-xs uppercase">Validate above</td>
                            <td className="border border-slate-700 px-3 py-3 text-center text-xs">{issues.length ? 'Review rows above' : 'VALID DATA!'}</td>
                            <td className="border border-slate-700 px-3 py-3" />
                        </tr>
                    </tfoot>
                )}
            </table>
        </div>
    );
}

function AddressSelector({ row, prefix, currentLguName, currentBarangays = [], psgcOptions = {}, update }) {
    const scopeKey = `${prefix}_scope`;
    const provinceKey = `${prefix}_province`;
    const provinceCodeKey = `${prefix}_province_code`;
    const cityKey = `${prefix}_city`;
    const cityCodeKey = `${prefix}_city_code`;
    const barangayKey = prefix;
    const barangayCodeKey = `${prefix}_code`;
    const scope = row[scopeKey] || 'current';
    const shortCurrentLguName = String(currentLguName || 'Current LGU').split(',')[0].trim() || 'Current LGU';
    const currentOptions = (currentBarangays || []).map((option) => ({
        name: option.label || option.value || option.name,
        code: option.code || option.psgc_code || '',
    }));
    const provinces = psgcOptions.provinces || [];
    const cities = (psgcOptions.cities || []).filter((city) => !row[provinceCodeKey] || String(city.parent_code) === String(row[provinceCodeKey]));
    const barangays = (psgcOptions.barangays || []).filter((barangay) => String(barangay.parent_code) === String(row[cityCodeKey] || ''));

    const resetOutside = () => {
        update({
            [provinceKey]: '',
            [provinceCodeKey]: '',
            [cityKey]: '',
            [cityCodeKey]: '',
            [barangayKey]: '',
            [barangayCodeKey]: '',
        });
    };

    const selectBarangay = (barangayName, options = currentOptions) => {
        const selected = options.find((option) => option.name === barangayName);
        update({
            [barangayKey]: barangayName,
            [barangayCodeKey]: selected?.code || '',
        });
    };

    return (
        <div className="space-y-1">
            <select
                value={scope}
                onChange={(event) => {
                    update({
                        [scopeKey]: event.target.value,
                        [provinceKey]: '',
                        [provinceCodeKey]: '',
                        [cityKey]: '',
                        [cityCodeKey]: '',
                        [barangayKey]: '',
                        [barangayCodeKey]: '',
                    });
                }}
                className="w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-xs font-black text-slate-900 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300"
            >
                <option value="current">Inside {shortCurrentLguName}</option>
                <option value="outside">Outside {shortCurrentLguName}</option>
            </select>

            {scope === 'current' ? (
                <CompactSearchSelect
                    value={row[barangayKey] ?? ''}
                    onChange={(value) => selectBarangay(value)}
                    options={currentOptions.map((barangay) => ({ value: barangay.name, label: barangay.name }))}
                    placeholder="Search barangay..."
                />
            ) : (
                <div className="grid gap-1">
                    <CompactSearchSelect
                        value={row[provinceCodeKey] ?? ''}
                        onChange={(value) => {
                            const selected = provinces.find((province) => province.code === value);
                            update({
                                [provinceCodeKey]: selected?.code || '',
                                [provinceKey]: selected?.name || '',
                                [cityCodeKey]: '',
                                [cityKey]: '',
                                [barangayKey]: '',
                                [barangayCodeKey]: '',
                            });
                        }}
                        options={provinces.map((province) => ({ value: province.code, label: province.name }))}
                        placeholder="Search province..."
                    />
                    <CompactSearchSelect
                        value={row[cityCodeKey] ?? ''}
                        onChange={(value) => {
                            const selected = (psgcOptions.cities || []).find((city) => city.code === value);
                            const province = provinces.find((item) => item.code === selected?.parent_code);
                            update({
                                [provinceCodeKey]: province?.code || selected?.parent_code || '',
                                [provinceKey]: province?.name || '',
                                [cityCodeKey]: selected?.code || '',
                                [cityKey]: selected?.name || '',
                                [barangayKey]: '',
                                [barangayCodeKey]: '',
                            });
                        }}
                        options={cities.map((city) => ({ value: city.code, label: city.name }))}
                        placeholder="Search city/municipality..."
                    />
                    <CompactSearchSelect
                        value={row[barangayCodeKey] ?? ''}
                        onChange={(value) => {
                            const selected = barangays.find((barangay) => barangay.code === value);
                            update({
                                [barangayCodeKey]: selected?.code || '',
                                [barangayKey]: selected?.name || '',
                            });
                        }}
                        options={barangays.map((barangay) => ({ value: barangay.code, label: barangay.name }))}
                        placeholder="Search barangay..."
                    />
                </div>
            )}
        </div>
    );
}

function CompactSearchSelect({ value, onChange, options = [], placeholder = 'Search...' }) {
    const selectedValues = value ? [String(value)] : [];
    const closedLabel = String(placeholder || '')
        .replace(/^search\s+/i, '')
        .replace(/\.{3}$/, '')
        .trim();
    const displayLabel = closedLabel
        ? `${closedLabel.charAt(0).toUpperCase()}${closedLabel.slice(1)}`
        : '—';

    return (
        <LookerMultiSelect
            className="w-full min-w-0 [&>span:first-child]:hidden [&>button]:mt-0 [&>button]:min-h-[38px] [&>button]:min-w-0 [&>button]:overflow-hidden [&>button]:border-emerald-300 [&>button]:bg-emerald-50 [&>button]:font-bold"
            value={selectedValues}
            onApply={(values) => onChange(values.at(-1) || '')}
            options={options}
            placeholder={placeholder}
            allLabel={displayLabel}
            single
        />
    );
}

function DisaggregationModal({ row, index, onClose, updateDisaggregation }) {
    const disaggregation = row.disaggregation || emptyDisaggregation();
    const issues = disaggregationValidationIssues(row);

    return (
        <div className="fixed inset-0 z-[95] flex items-start justify-center overflow-y-auto bg-slate-950/70 p-4 py-6 backdrop-blur-sm">
            <div className="dromic-fit-modal w-full max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg bg-white shadow-2xl dark:bg-zinc-900">
                <div className="sticky top-0 z-10 min-w-0 max-w-full border-b border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-black uppercase tracking-wide text-emerald-700">Required Disaggregated Data</p>
                            <h3 className="text-xl font-black">{row.evacuation_center || `Evacuation Center ${index + 1}`}</h3>
                            <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-900">
                                Please encode all entries from 0 and above. Immediate submission of sex, age, and sectoral data is significant for fast and appropriate response delivery, especially for children, older persons, PWDs, pregnant women, lactating mothers, and other vulnerable IDPs.
                            </p>
                            {issues.length > 0 && (
                                <div className="mt-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-700">
                                    <p className="font-black">Please check the disaggregated data:</p>
                                    <ul className="mt-1 list-inside list-disc space-y-1">
                                        {issues.slice(0, 4).map((issue) => <li key={issue}>{issue}</li>)}
                                    </ul>
                                    {issues.length > 4 && <p className="mt-1 text-xs">Plus {issues.length - 4} more row/s to review.</p>}
                                </div>
                            )}
                        </div>
                        <button type="button" onClick={onClose} className="rounded-md border px-3 py-2 text-sm font-bold">Done</button>
                    </div>
                </div>
                <div className="max-h-[calc(100vh-10rem)] space-y-5 overflow-y-auto p-5">
                    <DisaggregationTable
                        title="Sex and Age Disaggregation"
                        rows={ageSexRows}
                        values={disaggregation.age_sex}
                        group="age_sex"
                        index={index}
                        updateDisaggregation={updateDisaggregation}
                    />
                    <DisaggregationTable
                        title="Sectoral Group"
                        rows={sectoralRows.map(([key, label, hasMale]) => [key, label, '', hasMale])}
                        values={disaggregation.sectoral}
                        group="sectoral"
                        index={index}
                        updateDisaggregation={updateDisaggregation}
                    />
                </div>
            </div>
        </div>
    );
}

function DisaggregationTable({ title, rows, values, group, index, updateDisaggregation }) {
    const total = (field) => rows.reduce((sum, [key, , , hasMale = true]) => sum + Number(values?.[key]?.[field] ?? (hasMale ? 0 : field.startsWith('male') ? 0 : 0)), 0);

    return (
        <div className="dromic-table-fit rounded-md border border-slate-200">
            <table className="w-full min-w-0 table-fixed border-collapse text-xs sm:text-sm">
                <thead>
                    <tr className={dromicHeadClass}>
                        <th rowSpan="2" className="border border-white px-3 py-2 text-center font-black">No.</th>
                        <th rowSpan="2" colSpan="2" className="border border-white px-3 py-2 text-left font-black">{title}</th>
                        <th colSpan="2" className="border border-white px-3 py-2 font-black">Male</th>
                        <th colSpan="2" className="border border-white px-3 py-2 font-black">Female</th>
                        <th colSpan="2" className="border border-white px-3 py-2 font-black">Total</th>
                    </tr>
                    <tr className={dromicHeadClass}>
                        {['CUM', 'NOW', 'CUM', 'NOW', 'CUM', 'NOW'].map((label, cellIndex) => <th key={cellIndex} className="border border-white px-3 py-2 font-black">{label}</th>)}
                    </tr>
                </thead>
                <tbody>
                    {rows.map(([key, label, description, hasMale = true], rowIndex) => {
                        const row = values?.[key] || {};
                        const totalCum = Number(row.male_cum || 0) + Number(row.female_cum || 0);
                        const totalNow = Number(row.male_now || 0) + Number(row.female_now || 0);

                        return (
                            <tr key={key}>
                                <td className="border border-white bg-slate-300 px-3 py-2 text-center font-black">{rowIndex + 1}</td>
                                <td className="border border-white bg-white px-3 py-2 font-black">{label}</td>
                                <td className="border border-white bg-slate-300 px-3 py-2 font-bold">{description}</td>
                                {hasMale ? (
                                    <>
                                        <td className="border border-white bg-slate-300 p-1"><MiniNumber value={row.male_cum} onChange={(value) => updateDisaggregation(index, group, key, 'male_cum', value)} /></td>
                                        <td className="border border-white bg-slate-300 p-1"><MiniNumber value={row.male_now} onChange={(value) => updateDisaggregation(index, group, key, 'male_now', value)} /></td>
                                    </>
                                ) : (
                                    <td colSpan="2" className="border border-white bg-slate-300 text-center font-bold">N/A</td>
                                )}
                                <td className="border border-white bg-slate-300 p-1"><MiniNumber value={row.female_cum} onChange={(value) => updateDisaggregation(index, group, key, 'female_cum', value)} /></td>
                                <td className="border border-white bg-slate-300 p-1"><MiniNumber value={row.female_now} onChange={(value) => updateDisaggregation(index, group, key, 'female_now', value)} /></td>
                                <td className="border border-white bg-slate-300 px-3 py-2 text-center font-black">{totalCum}</td>
                                <td className="border border-white bg-slate-300 px-3 py-2 text-center font-black">{totalNow}</td>
                            </tr>
                        );
                    })}
                </tbody>
                <tfoot>
                    <tr className={dromicHeadClass}>
                        <td className="border border-white px-3 py-2 text-center font-black">TOTAL</td>
                        <td colSpan="2" className="border border-white px-3 py-2 text-right font-black">TOTAL &gt;&gt;&gt;</td>
                        <td className="border border-white px-3 py-2 text-center font-black">{total('male_cum')}</td>
                        <td className="border border-white px-3 py-2 text-center font-black">{total('male_now')}</td>
                        <td className="border border-white px-3 py-2 text-center font-black">{total('female_cum')}</td>
                        <td className="border border-white px-3 py-2 text-center font-black">{total('female_now')}</td>
                        <td className="border border-white px-3 py-2 text-center font-black">{total('male_cum') + total('female_cum')}</td>
                        <td className="border border-white px-3 py-2 text-center font-black">{total('male_now') + total('female_now')}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function MiniNumber({ value, onChange }) {
    return <input type="number" min="0" className="w-full rounded border-2 border-slate-300 bg-white px-2 py-1 text-center font-black text-slate-950 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-300" value={value ?? ''} onChange={(event) => onChange(event.target.value)} />;
}

function FriendlyInput({ value, onChange, type = 'text', placeholder = '', required = false }) {
    return <input type={type} min={type === 'number' ? '0' : undefined} required={required} placeholder={placeholder || (type === 'number' ? '0' : 'Type here')} className="min-w-0 w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-2 py-2 text-sm font-bold text-slate-950 placeholder:text-slate-400 focus:border-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-300 dark:bg-zinc-950 dark:text-white sm:px-3" value={value ?? ''} onChange={(event) => onChange(event.target.value)} />;
}

function ConfirmZeroNowModal({ evacuationCenter, onCancel, onConfirm }) {
    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
            <div className="w-full max-w-lg rounded-lg bg-white p-5 shadow-2xl dark:bg-zinc-900">
                <h3 className="text-lg font-black text-slate-950 dark:text-white">Set disaggregated NOW counts to zero?</h3>
                <p className="mt-2 text-sm font-semibold text-slate-600 dark:text-zinc-300">
                    Persons NOW for <span className="font-black">{evacuationCenter}</span> is 0. If this is correct, DROMIS can set all matching NOW fields in Required Disaggregated Data to 0 for rows that already have CUM values.
                </p>
                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs font-bold text-amber-900">
                    This will not erase CUM values. CUM remains cumulative. Only NOW fields connected to rows with encoded CUM counts will be changed to 0.
                </div>
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={onCancel} className="rounded-md border px-4 py-2 text-sm font-bold">No, I will review manually</button>
                    <button type="button" onClick={onConfirm} className="rounded-md bg-emerald-700 px-4 py-2 text-sm font-black text-white hover:bg-emerald-800">Yes, set NOW to 0</button>
                </div>
            </div>
        </div>
    );
}

function ConfirmDeleteModal({ title, message, onCancel, onConfirm }) {
    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
            <div className="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-zinc-900">
                <h3 className="text-lg font-black text-slate-950 dark:text-white">{title}</h3>
                <p className="mt-2 text-sm font-semibold text-slate-600 dark:text-zinc-300">{message}</p>
                <p className="mt-3 rounded-md bg-amber-50 p-3 text-xs font-bold text-amber-800">This is only to prevent accidental deletion. You can cancel and review the row again.</p>
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={onCancel} className="rounded-md border px-4 py-2 text-sm font-bold">Cancel</button>
                    <button type="button" onClick={onConfirm} className="rounded-md bg-rose-600 px-4 py-2 text-sm font-black text-white hover:bg-rose-700">Yes, remove row</button>
                </div>
            </div>
        </div>
    );
}

function Input({ label, value, onChange, error, type = 'text', required = false, min, max, hint = '', disabled = false }) {
    return (
        <label className="block text-sm font-bold text-slate-700 dark:text-zinc-100">
            {label}{required && <span className="text-rose-600"> *</span>}
            <input type={type} min={min} max={max} disabled={disabled} className="mt-1 w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-3 py-2 font-bold text-slate-950 placeholder:text-slate-400 focus:border-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-300 disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-100 disabled:text-slate-600 dark:bg-zinc-950 dark:text-white dark:disabled:bg-zinc-800" value={value ?? ''} onChange={(event) => onChange(event.target.value)} required={required} />
            {typeof error === 'string' && error
                ? <p className="mt-1 text-xs font-bold text-rose-600">{error}</p>
                : error}
            {!error && hint && <p className="mt-1 text-xs font-medium text-slate-500 dark:text-zinc-400">{hint}</p>}
        </label>
    );
}

function Select({ label, value, onChange, options = [], required = false }) {
    return (
        <label className="block text-sm font-bold text-slate-700 dark:text-zinc-100">
            {label}{required && <span className="text-rose-600"> *</span>}
            <select className="mt-1 w-full" value={value ?? ''} onChange={(event) => onChange(event.target.value)} required={required}>
                <option value="">Select...</option>
                {options.map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
        </label>
    );
}

function Textarea({ label, value, onChange, error, rows = 3, required = false, className = '' }) {
    return (
        <label className={`block text-sm font-bold text-slate-700 dark:text-zinc-100 ${className}`}>
            {label}{required && <span className="text-rose-600"> *</span>}
            <textarea rows={rows} className="mt-1 w-full rounded-md border-2 border-emerald-300 bg-emerald-50 px-3 py-2 font-bold text-slate-950 placeholder:text-slate-400 focus:border-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-300 dark:bg-zinc-950 dark:text-white" value={value ?? ''} onChange={(event) => onChange(event.target.value)} required={required} />
            {typeof error === 'string' && error
                ? <p className="mt-1 text-xs font-bold text-rose-600">{error}</p>
                : error}
        </label>
    );
}
