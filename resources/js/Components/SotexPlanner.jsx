import SearchableSelect from '@/Components/SearchableSelect';
import { router, useForm } from '@inertiajs/react';
import { CalendarRange, ChevronRight, Eye, MessageSquareQuote, PackageCheck, Pencil, Trash2, Truck, Warehouse, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Card } from '@/Layouts/AppLayout';
import { listenRealtime } from '@/realtime';

const qty = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });

const statusMeta = {
    allocated: { label: 'Allocated', className: 'bg-sky-100 text-sky-800' },
    partial: { label: 'Partially released', className: 'bg-amber-100 text-amber-900' },
    released: { label: 'Released', className: 'bg-emerald-100 text-emerald-800' },
    no_stock: { label: 'No matching stock', className: 'bg-rose-100 text-rose-800' },
};

const proposalStages = [
    { title: 'Receipt of proposal', target: ['proposal_target_on', 'Target: receipt of proposal'], actual: ['proposal_received_on', 'Actual: receipt of proposal'] },
    { title: 'Review', actual: ['proposal_reviewed_on', 'Actual: review'] },
    { title: 'Compliance on findings', target: ['compliance_target_on', 'Target: compliance on findings'], na: 'compliance_target_na', actual: ['compliance_on', 'Actual: LGU compliance on findings'] },
    { title: 'Approval', actual: ['proposal_approved_on', 'Actual: approval of proposal'] },
    { title: 'Work schedule', target: ['work_schedule_target_on', 'Target: work schedule', 'work_schedule_target_end_on'], actual: ['work_schedule_on', 'Actual: work schedule', 'work_schedule_end_on'] },
    { title: 'Delivery of items', target: ['delivery_target_on', 'Target: delivery of items', 'delivery_target_end_on'], na: 'delivery_target_na', actual: ['delivered_on', 'Actual: delivery of items', 'delivered_end_on'] },
    { title: 'Distribution of items', target: ['distribution_target_on', 'Target: distribution of items', 'distribution_target_end_on'], actual: ['distributed_on', 'Actual: distribution of items', 'distributed_end_on'] },
];

const proposalDates = proposalStages.flatMap((stage) => [stage.target, stage.actual].filter(Boolean));
const proposalDateKeys = proposalDates.flatMap(([start, , end]) => [start, end].filter(Boolean));

const naActualKeys = (stage) => (stage.actual ? [stage.actual[0], stage.actual[2]].filter(Boolean) : []);

const progressTone = (status) => ({
    "Awaiting LGU's Submission": 'bg-amber-100 text-amber-900',
    'Approved Proposal': 'bg-sky-100 text-sky-800',
    'Ongoing Activity': 'bg-indigo-100 text-indigo-800',
    'SoTEx Partially Delivered': 'bg-cyan-50 text-cyan-800 ring-1 ring-cyan-200',
    'SoTEx Fully Delivered': 'bg-teal-100 text-teal-800',
    'Activity Completed': 'bg-emerald-100 text-emerald-800',
    'SoTEx Partially Distributed': 'bg-lime-100 text-lime-900',
    'SoTEx Fully Distributed': 'bg-emerald-600 text-white',
}[status] || 'bg-slate-100 text-slate-500');

const PROGRESS_STEPS = ["Awaiting LGU's Submission", 'Approved Proposal', 'Ongoing Activity', 'SoTEx Partially Delivered', 'SoTEx Fully Delivered', 'Activity Completed', 'SoTEx Partially Distributed', 'SoTEx Fully Distributed'];

// Mirrors SotexPlanningService::derivedProgress(); the server value is the one that is saved.
const derivedProgress = (data, allocated, released) => {
    const has = (key) => Boolean(data[key]);
    const delivered = has('delivered_on') && has('delivered_end_on');
    const deliveryNa = Boolean(data.delivery_target_na);
    if (has('distributed_on') && has('distributed_end_on') && allocated > 0 && released >= allocated - 0.001) return 'SoTEx Fully Distributed';
    if (has('distributed_on') || released > 0) return 'SoTEx Partially Distributed';
    if (has('work_schedule_on') && has('work_schedule_end_on') && (delivered || deliveryNa)) return 'Activity Completed';
    if (delivered && !deliveryNa) return 'SoTEx Fully Delivered';
    if (has('delivered_on') && !deliveryNa) return 'SoTEx Partially Delivered';
    if (has('work_schedule_on')) return 'Ongoing Activity';
    if (has('proposal_approved_on')) return 'Approved Proposal';
    return "Awaiting LGU's Submission";
};

// Mirrors SotexPlanningService::ACTUAL_PREREQUISITES. The work schedule has no entry on purpose:
// an activity may run while the proposal is still under review or revision.
const ACTUAL_PREREQUISITES = {
    proposal_reviewed_on: [['proposal_received_on', null]],
    compliance_on: [['proposal_reviewed_on', null]],
    proposal_approved_on: [['proposal_reviewed_on', null], ['compliance_on', 'compliance_target_na']],
    delivered_on: [['proposal_approved_on', null]],
    distributed_on: [['proposal_approved_on', null], ['delivered_on', 'delivery_target_na']],
    distributed_end_on: [['delivered_end_on', 'delivery_target_na']],
};

const ACTUAL_LABELS = {
    proposal_received_on: 'receipt of proposal',
    proposal_reviewed_on: 'review',
    compliance_on: 'LGU compliance on findings',
    proposal_approved_on: 'approval of proposal',
    delivered_on: 'delivery start',
    delivered_end_on: 'delivery end',
    distributed_on: 'distribution start',
    distributed_end_on: 'distribution end',
};

const longDate = (value) => new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

const actualGate = (data, field) => {
    const prerequisites = (ACTUAL_PREREQUISITES[field] || []).filter(([, flag]) => !(flag && data[flag]));
    const missing = prerequisites.find(([key]) => !data[key]);
    const min = prerequisites.map(([key]) => data[key]).filter(Boolean).sort().pop();
    return { missing: missing ? ACTUAL_LABELS[missing[0]] : null, min: min ? String(min).slice(0, 10) : undefined };
};

const actualOrderIssues = (data) => {
    const issues = {};
    Object.entries(ACTUAL_PREREQUISITES).forEach(([field, prerequisites]) => {
        if (!data[field]) return;
        for (const [key, flag] of prerequisites) {
            if (flag && data[flag]) continue;
            if (!data[key]) {
                issues[field] = `Record the actual ${ACTUAL_LABELS[key]} first.`;
                return;
            }
            if (String(data[field]).slice(0, 10) < String(data[key]).slice(0, 10)) {
                issues[field] = `This cannot be earlier than the actual ${ACTUAL_LABELS[key]} (${longDate(data[key])}).`;
                return;
            }
        }
    });
    return issues;
};

const proposalStanding = (data) => {
    if (data.proposal_approved_on) return null;
    if (data.proposal_reviewed_on && !data.compliance_target_na && !data.compliance_on) return 'Proposal under revision';
    if (data.proposal_reviewed_on) return 'Proposal for approval';
    if (data.proposal_received_on) return 'Proposal under review';
    return 'Proposal not yet received';
};

// Only worth showing when it adds something: the activity is ahead of approval, or the proposal is already in DSWD's hands.
const standingNote = (data, status) => {
    const standing = proposalStanding(data);
    return standing && (status !== "Awaiting LGU's Submission" || data.proposal_received_on) ? standing : null;
};

const progressHint = (status, data) => {
    const deliveryNa = Boolean(data.delivery_target_na);
    const standing = proposalStanding(data);
    if (standing && ['Ongoing Activity', 'Activity Completed'].includes(status)) {
        return `The activity is ahead of the proposal (${standing.toLowerCase()}). Delivery and distribution open once the actual approval date is entered.`;
    }
    switch (status) {
        case "Awaiting LGU's Submission": return standing && standing !== 'Proposal not yet received'
            ? `${standing}. Moves to Approved Proposal once the actual approval date is entered, or to Ongoing Activity if the work schedule starts first.`
            : 'Moves to Approved Proposal once the actual approval date is entered.';
        case 'Approved Proposal': return 'Moves to Ongoing Activity once the actual work schedule start date is entered.';
        case 'Ongoing Activity': return deliveryNa
            ? 'Moves to Activity Completed once the actual work schedule end date is entered.'
            : 'Moves to SoTEx Partially Delivered once delivery starts, and to SoTEx Fully Delivered once the delivery end date is entered.';
        case 'SoTEx Partially Delivered': return 'Delivery has started. Moves to SoTEx Fully Delivered once the actual delivery end date is entered.';
        case 'SoTEx Fully Delivered': return 'Moves to Activity Completed once the actual work schedule end date is entered.';
        case 'Activity Completed': return 'Moves to SoTEx Partially Distributed once distribution starts.';
        case 'SoTEx Partially Distributed': return 'Distribution has started or part of the allocation is released. Moves to SoTEx Fully Distributed once the distribution end date is entered and every allocated quantity is released.';
        default: return 'Every stage is recorded and the allocation is fully released.';
    }
};

const missingActualStages = (data) => proposalStages
    .filter((stage) => stage.actual && !(stage.na && data[stage.na]))
    .filter((stage) => naActualKeys(stage).some((key) => !data[key]))
    .map((stage) => stage.title);

const blankProposal = Object.fromEntries(proposalDateKeys.map((key) => [key, '']));

const DAY_MS = 24 * 60 * 60 * 1000;

const toDay = (value) => {
    const [year, month, day] = String(value || '').slice(0, 10).split('-').map(Number);
    return year && month && day ? new Date(year, month - 1, day).getTime() : null;
};

const todayStart = () => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
};

const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;

const stageTone = {
    done: { dot: 'bg-emerald-500 ring-emerald-100', text: 'text-emerald-700', bar: 'bg-emerald-200', label: 'Done' },
    active: { dot: 'bg-sky-500 ring-sky-100', text: 'text-sky-700', bar: 'bg-sky-300', label: 'In progress' },
    upcoming: { dot: 'bg-white ring-indigo-200 border-2 border-indigo-400', text: 'text-indigo-700', bar: 'bg-indigo-200', label: 'Upcoming' },
    overdue: { dot: 'bg-rose-500 ring-rose-100', text: 'text-rose-700', bar: 'bg-rose-200', label: 'Overdue' },
    na: { dot: 'bg-slate-200 ring-slate-50', text: 'text-slate-400', bar: 'bg-slate-100', label: 'Not applicable' },
    pending: { dot: 'bg-white ring-slate-100 border-2 border-dashed border-slate-300', text: 'text-slate-400', bar: 'bg-slate-100', label: 'Not scheduled' },
};

const stageState = (stage, proposal, today = todayStart()) => {
    const actual = stage.actual ? toDay(proposal[stage.actual[0]]) : null;
    const actualEnd = stage.actual?.[2] ? toDay(proposal[stage.actual[2]]) : null;
    const start = stage.target ? toDay(proposal[stage.target[0]]) : null;
    const end = stage.target?.[2] ? toDay(proposal[stage.target[2]]) : null;
    const windowEnd = end ?? start;
    const base = { stage, actual, actualEnd: actualEnd ?? actual, start, end: windowEnd };
    if (stage.na && proposal[stage.na] && !actual) {
        return { ...base, kind: 'na', note: 'Not applicable to this proposal' };
    }
    if (actual && stage.actual?.[2] && actualEnd == null) {
        return { ...base, kind: 'active', note: 'Started, end date not recorded yet' };
    }
    if (actual) {
        const late = windowEnd != null ? Math.round(((actualEnd ?? actual) - windowEnd) / DAY_MS) : 0;
        return { ...base, kind: 'done', note: late > 0 ? `Done ${plural(late, 'day')} after target` : 'Done on time' };
    }
    if (start == null && windowEnd == null) {
        return { ...base, kind: 'pending', note: stage.target ? 'No target date yet' : 'Recorded when it happens' };
    }
    if (windowEnd < today) {
        return { ...base, kind: 'overdue', note: `Overdue by ${plural(Math.round((today - windowEnd) / DAY_MS), 'day')}` };
    }
    if ((start ?? windowEnd) <= today) {
        return { ...base, kind: 'active', note: windowEnd === today ? 'Due today' : `Window closes in ${plural(Math.round((windowEnd - today) / DAY_MS), 'day')}` };
    }
    const days = Math.round(((start ?? windowEnd) - today) / DAY_MS);
    return { ...base, kind: 'upcoming', note: days === 1 ? 'Starts tomorrow' : `Starts in ${plural(days, 'day')}` };
};

const showWindow = (start, end) => {
    const label = (time) => showDate(new Date(time).toLocaleDateString('en-CA'));
    if (start == null && end == null) return '—';
    if (start == null || end == null || start === end) return label(start ?? end);
    return `${label(start)} – ${label(end)}`;
};

const emptyForm = {
    lgu: '',
    activity: 'Food-for-Work',
    delivery_mode: '',
    delivery_mode_na: false,
    compliance_target_na: false,
    delivery_target_na: false,
    remarks: '',
    sources: [],
    ...blankProposal,
};

const suggestedQuantity = (line) => {
    const remaining = Number(line?.remaining);
    return Number.isFinite(remaining) && remaining > 0 ? String(Math.round(remaining * 100) / 100) : '';
};

const sourceFromLine = (line, quantities = {}) => ({
    key: line.key,
    warehouse_id: line.warehouse_id || '',
    item_name: line.item || '',
    brand: line.brand || '',
    expiry_month: line.expiry_month || '',
    warehouse: line.warehouse || '',
    district: line.district || '',
    partnership: line.partnership || '',
    allocated_quantity: quantities.allocated_quantity ?? suggestedQuantity(line),
    released_quantity: quantities.released_quantity ?? '0',
});

const showDate = (value) => {
    if (!value) {
        return '—';
    }
    const [year, month, day] = String(value).slice(0, 10).split('-');
    const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const index = Number(month) - 1;
    return year && names[index] && day ? `${names[index]} ${Number(day)}, ${year}` : value;
};

const selectedHas = (filters, key, value) => !Array.isArray(filters?.[key]) || filters[key].length === 0 || filters[key].map(String).includes(String(value ?? ''));

export default function SotexPlanner({ sotex = {}, canEdit = false, section = 'plans', filters = {} }) {
    const stock = sotex.stock ?? [];
    const plans = sotex.plans ?? [];
    const activities = sotex.activities ?? [];
    const [showAllocatedOut, setShowAllocatedOut] = useState(false);
    const [composerOpen, setComposerOpen] = useState(false);
    const [pickedKeys, setPickedKeys] = useState([]);
    const [editingId, setEditingId] = useState(null);
    const [editingKey, setEditingKey] = useState(null);
    const [confirmingId, setConfirmingId] = useState(null);
    const [liveRecipients, setLiveRecipients] = useState(null);
    const [recipientDraft, setRecipientDraft] = useState(null);
    const [detailKey, setDetailKey] = useState(null);
    const form = useForm(emptyForm);

    const recipientOptions = useMemo(() => (liveRecipients ?? sotex.recipients ?? []).map((recipient) => ({
        value: recipient.name,
        label: recipient.name,
        description: [recipient.level, recipient.details].filter(Boolean).join(' · '),
    })), [liveRecipients, sotex.recipients]);

    useEffect(() => {
        setLiveRecipients(null);
    }, [sotex.recipients]);

    useEffect(() => {
        if (!canEdit) {
            return undefined;
        }
        let cancelled = false;
        const refresh = () => {
            window.axios.get('/near-expiry/recipients')
                .then(({ data }) => {
                    if (!cancelled && Array.isArray(data?.recipients)) setLiveRecipients(data.recipients);
                })
                .catch(() => {});
        };
        const stopChanges = listenRealtime('sotex.recipients.changed', refresh);
        // Catch up on anything added while this tab's socket was down.
        const stopConnection = listenRealtime('realtime.connection', ({ connected } = {}) => {
            if (connected) refresh();
        });
        return () => {
            cancelled = true;
            stopChanges();
            stopConnection();
        };
    }, [canEdit]);

    const filteredStock = stock.filter((line) => {
        const needle = String(filters.q || '').trim().toLowerCase();
        const haystack = [line.item, line.brand, line.expiry_month, line.warehouse, line.district, line.partnership, line.category, line.status].join(' ').toLowerCase();
        return (!needle || haystack.includes(needle))
            && selectedHas(filters, 'category', line.category)
            && selectedHas(filters, 'item', line.item)
            && selectedHas(filters, 'brand', line.brand)
            && selectedHas(filters, 'warehouse', line.warehouse)
            && selectedHas(filters, 'status', line.status)
            && selectedHas(filters, 'expiry_month', line.expiry_month)
            && (showAllocatedOut || Number(line.remaining) > 0 || line.over_allocated);
    });

    const filteredPlans = plans.filter((plan) => {
        const needle = String(filters.q || '').trim().toLowerCase();
        const haystack = [plan.lgu, plan.activity, plan.item, plan.brand, plan.expiry_month, plan.warehouse, plan.district, plan.partnership].join(' ').toLowerCase();
        return (!needle || haystack.includes(needle))
            && selectedHas(filters, 'item', plan.item)
            && selectedHas(filters, 'brand', plan.brand)
            && selectedHas(filters, 'warehouse', plan.warehouse)
            && selectedHas(filters, 'expiry_month', plan.expiry_month)
            && selectedHas(filters, 'recipient', plan.lgu);
    });

    const totals = filteredPlans.reduce((sum, plan) => ({
        allocated: sum.allocated + Number(plan.allocated_quantity || 0),
        released: sum.released + Number(plan.released_quantity || 0),
        waiting: sum.waiting + Number(plan.still_to_release || 0),
    }), { allocated: 0, released: 0, waiting: 0 });

    const toggleSource = (line) => {
        if (!canEdit || Number(line.remaining) <= 0) {
            return;
        }
        setPickedKeys((current) => (current.includes(line.key) ? current.filter((key) => key !== line.key) : [...current, line.key]));
    };

    const openProposal = (lines, proposal = null) => {
        form.clearErrors();
        const dates = Object.fromEntries(proposalDateKeys.map((key) => [key, proposal?.[key] ? String(proposal[key]).slice(0, 10) : '']));
        if (proposal?.compliance_target_na) {
            dates.compliance_target_on = '';
            dates.compliance_on = '';
        }
        if (proposal?.delivery_target_na) {
            dates.delivery_target_on = '';
            dates.delivery_target_end_on = '';
            dates.delivered_on = '';
            dates.delivered_end_on = '';
        }
        form.setData({
            ...emptyForm,
            activity: proposal?.activity || activities[0] || 'Food-for-Work',
            lgu: proposal?.lgu || '',
            delivery_mode: proposal?.delivery_mode_na ? '' : (proposal?.delivery_mode || ''),
            delivery_mode_na: Boolean(proposal?.delivery_mode_na),
            compliance_target_na: Boolean(proposal?.compliance_target_na),
            delivery_target_na: Boolean(proposal?.delivery_target_na),
            remarks: proposal?.remarks || '',
            ...dates,
            sources: lines.map((line) => sourceFromLine(line.line || line, line.quantities || {})),
        });
        setEditingId(proposal?.id || null);
        setEditingKey(proposal?.key || null);
        setComposerOpen(true);
    };

    const fillFromProposal = (proposal) => {
        openProposal(proposal.sources.map((plan) => {
            const line = stock.find((row) => row.key === plan.stock_key) || {
                key: plan.stock_key || `saved-${plan.id}`,
                warehouse_id: plan.warehouse_id,
                item: plan.item,
                brand: plan.brand,
                expiry_month: plan.expiry_month,
                warehouse: plan.warehouse,
                district: plan.district,
                partnership: plan.partnership,
            };
            return {
                line,
                quantities: {
                    allocated_quantity: String(plan.allocated_quantity ?? ''),
                    released_quantity: String(plan.released_quantity ?? '0'),
                },
            };
        }), proposal);
    };

    const clearForm = () => {
        form.clearErrors();
        form.setData({ ...emptyForm, activity: activities[0] || 'Food-for-Work' });
        setEditingId(null);
        setEditingKey(null);
        setComposerOpen(false);
        setRecipientDraft(null);
    };

    const setRangeDate = (startKey, endKey, side, value) => {
        const start = side === 'start' ? value : (form.data[startKey] || '');
        let end = side === 'end' ? value : (form.data[endKey] || '');
        if (start && end && end < start) {
            end = start;
        }
        form.setData({
            ...form.data,
            [startKey]: start,
            [endKey]: end,
        });
    };

    useEffect(() => {
        if (!composerOpen) {
            return undefined;
        }
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                clearForm();
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [composerOpen]);

    const pickedLines = pickedKeys.map((key) => stock.find((line) => line.key === key)).filter(Boolean);
    const pickedAvailable = pickedLines.reduce((sum, line) => sum + Number(line.remaining || 0), 0);
    const pickedByItem = Object.values(pickedLines.reduce((groups, line) => {
        const item = line.item || 'Item';
        const current = groups[item] || { item, available: 0, lines: 0 };
        current.available += Number(line.remaining || 0);
        current.lines += 1;
        groups[item] = current;
        return groups;
    }, {})).sort((left, right) => left.item.localeCompare(right.item));
    const editingPlans = editingKey ? plans.filter((plan) => (plan.proposal_key || `plan-${plan.id}`) === editingKey) : [];

    const sourceMath = (source) => {
        const line = stock.find((row) => row.key === source.key) || null;
        const editingOpen = editingPlans
            .filter((plan) => plan.stock_key === source.key)
            .reduce((sum, plan) => sum + Math.max(0, Number(plan.allocated_quantity) - Number(plan.released_quantity)), 0);
        const available = line ? Number(line.remaining) + editingOpen : null;
        const nextOpen = Math.max(0, Number(source.allocated_quantity || 0) - Number(source.released_quantity || 0));
        const after = available == null ? null : available - nextOpen;
        return { line, after, over: Boolean(line) && after < -0.001 };
    };

    const sources = form.data.sources || [];
    const over = sources.some((source) => sourceMath(source).over);
    const missingActual = missingActualStages(form.data);
    const releaseLocked = missingActual.length > 0;
    const orderIssues = editingId ? actualOrderIssues(form.data) : {};
    const outOfOrder = Object.keys(orderIssues).length > 0;
    const actualError = (...fields) => fields.map((field) => form.errors[field] || orderIssues[field]).find(Boolean);
    const liveProgress = derivedProgress(
        form.data,
        sources.reduce((sum, source) => sum + Number(source.allocated_quantity || 0), 0),
        editingId ? sources.reduce((sum, source) => sum + Number(source.released_quantity || 0), 0) : 0,
    );
    const sourcesReady = sources.length > 0 && sources.every((source) => Number(source.allocated_quantity) > 0 && source.warehouse_id);

    const updateSource = (key, field, value) => {
        form.setData('sources', sources.map((source) => (source.key === key ? { ...source, [field]: value } : source)));
    };

    const removeSource = (key) => {
        form.setData('sources', sources.filter((source) => source.key !== key));
    };

    const setNotApplicable = (flag, fields, checked) => {
        form.setData({
            ...form.data,
            [flag]: checked,
            ...Object.fromEntries(fields.map((field) => [field, checked ? '' : form.data[field]])),
        });
        form.clearErrors(flag, ...fields);
    };

    const submit = (event) => {
        event.preventDefault();
        const errors = {};
        if (!form.data.compliance_target_na && !form.data.compliance_target_on) {
            errors.compliance_target_on = 'Enter the compliance target date, or mark it N/A.';
        }
        if (!form.data.delivery_target_na && (!form.data.delivery_target_on || !form.data.delivery_target_end_on)) {
            errors.delivery_target_on = 'Enter the delivery date range, or mark it N/A.';
        }
        if (!form.data.delivery_mode_na && !form.data.delivery_mode) {
            errors.delivery_mode = 'Select who delivers, or mark delivery N/A.';
        }
        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            return;
        }
        form.clearErrors();
        form.transform((data) => ({
            ...data,
            compliance_target_na: Boolean(data.compliance_target_na),
            delivery_target_na: Boolean(data.delivery_target_na),
            delivery_mode_na: Boolean(data.delivery_mode_na),
            compliance_target_on: data.compliance_target_na ? null : (data.compliance_target_on || null),
            delivery_target_on: data.delivery_target_na ? null : (data.delivery_target_on || null),
            delivery_target_end_on: data.delivery_target_na ? null : (data.delivery_target_end_on || null),
            delivery_mode: data.delivery_mode_na ? null : (data.delivery_mode || null),
            sources: (data.sources || []).map((source) => ({
                ...source,
                released_quantity: editingId ? source.released_quantity : 0,
            })),
        }));
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setPickedKeys([]);
                clearForm();
            },
        };
        if (editingId) {
            form.patch(`/near-expiry/plans/${editingId}`, options);
            return;
        }
        form.post('/near-expiry/plans', options);
    };

    const openRecipientDraft = (name) => {
        form.clearErrors('lgu');
        setRecipientDraft({ name: String(name || '').trim(), office: '', errors: {}, saving: false });
    };

    const saveRecipient = () => {
        const name = String(recipientDraft?.name || '').trim();
        const office = String(recipientDraft?.office || '').trim();
        if (!name || recipientDraft?.saving) {
            return;
        }
        setRecipientDraft((current) => ({ ...current, saving: true, errors: {} }));
        // A background request, not an Inertia visit, so the proposal being encoded is never re-rendered away.
        window.axios.post('/near-expiry/recipients', { name, office })
            .then(({ data }) => {
                if (Array.isArray(data?.recipients)) setLiveRecipients(data.recipients);
                form.setData('lgu', data?.recipient?.name || name);
                form.clearErrors('lgu');
                setRecipientDraft(null);
            })
            .catch((error) => {
                const errors = Object.fromEntries(Object.entries(error?.response?.data?.errors ?? {}).map(([key, messages]) => [key, Array.isArray(messages) ? messages[0] : messages]));
                setRecipientDraft((current) => (current ? {
                    ...current,
                    saving: false,
                    errors: Object.keys(errors).length ? errors : { name: 'The recipient could not be saved. Check your connection and try again.' },
                } : current));
            });
    };

    const remove = (proposal) => {
        router.delete(`/near-expiry/plans/${proposal.id}`, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setConfirmingId(null),
            onSuccess: () => {
                if (editingId === proposal.id) clearForm();
            },
        });
    };

    const proposals = useMemo(() => {
        const groups = new Map();
        filteredPlans.forEach((plan) => {
            const key = plan.proposal_key || `plan-${plan.id}`;
            const current = groups.get(key) || {
                key,
                id: plan.id,
                lgu: plan.lgu,
                activity: plan.activity,
                delivery_mode: plan.delivery_mode,
                delivery_mode_na: Boolean(plan.delivery_mode_na),
                compliance_target_na: Boolean(plan.compliance_target_na),
                delivery_target_na: Boolean(plan.delivery_target_na),
                progress_status: plan.progress_status,
                remarks: plan.remarks,
                ...Object.fromEntries(proposalDateKeys.map((field) => [field, plan[field] || ''])),
                sources: [],
            };
            current.sources.push(plan);
            groups.set(key, current);
        });
        return Array.from(groups.values());
    }, [filteredPlans]);
    const detailProposal = detailKey ? proposals.find((proposal) => proposal.key === detailKey) || null : null;

    return (
        <div className="mt-6 space-y-4">
            {!canEdit && ['plans', 'register'].includes(section) && (
                <p className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2.5 text-xs font-semibold text-slate-600">
                    <Eye className="h-4 w-4 shrink-0 text-slate-400" />
                    View only. Only DRRS users can record or update SoTEx proposals.
                </p>
            )}
            {section === 'plans' && (
                <div id="near-expiry-plans" className="scroll-mt-28 space-y-4">
                    <Card className="overflow-hidden p-0">
                        <CoverageBanner coverage={sotex.coverage} />
                        <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 className="font-black text-slate-950">Expiry lines still open for allocation</h3>
                                <p className="text-xs font-semibold text-slate-500">{filteredStock.length === 1 ? '1 line matches' : `${filteredStock.length} lines match`} the filters.{canEdit ? ' Select every warehouse line this proposal will draw from, then record the proposal.' : ''} On hand is the stockpile. Planned is not yet released.</p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {canEdit && (
                                    <button type="button" disabled={pickedLines.length === 0} onClick={() => openProposal(pickedLines)} className="rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-black text-white disabled:opacity-50">
                                        Record proposal{pickedLines.length > 0 ? ` · ${pickedLines.length} source${pickedLines.length === 1 ? '' : 's'}` : ''}
                                    </button>
                                )}
                                <button type="button" onClick={() => setShowAllocatedOut((value) => !value)} className="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-black text-slate-600 hover:bg-slate-50">
                                    {showAllocatedOut ? 'Hide fully allocated' : 'Show fully allocated'}
                                </button>
                            </div>
                        </div>
                        {canEdit && (
                            <div className="flex flex-col gap-3 border-b border-emerald-200 bg-emerald-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-[10px] font-black uppercase tracking-[.16em] text-emerald-700">Available in this selection</p>
                                    <p className="mt-1 text-2xl font-black text-emerald-950">{qty(pickedAvailable)}</p>
                                    <p className="text-[11px] font-semibold text-emerald-800">{pickedLines.length === 0 ? 'Select expiry lines to total what is still unallocated.' : `${pickedLines.length} expiry line${pickedLines.length === 1 ? '' : 's'} still unallocated and open for this proposal.`}</p>
                                </div>
                                {pickedByItem.length > 0 && (
                                    <div className="flex flex-wrap gap-2">
                                        {pickedByItem.map((group) => (
                                            <div key={group.item} className="rounded-lg border border-emerald-200 bg-white px-3 py-2">
                                                <p className="text-[10px] font-black uppercase tracking-wide text-slate-500">{group.item}</p>
                                                <p className="mt-0.5 text-sm font-black text-slate-950">{qty(group.available)}</p>
                                                <p className="text-[10px] font-semibold text-slate-400">{group.lines} line{group.lines === 1 ? '' : 's'}</p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                        <div className="max-h-[28rem] overflow-auto">
                            <table className="min-w-full text-sm">
                                <thead className="sticky top-0 z-10 bg-slate-50 text-[10px] font-black uppercase tracking-wide text-slate-500">
                                    <tr>
                                        {canEdit && <th className="px-3 py-2" />}
                                        {['Item', 'Brand', 'Expiry', 'Warehouse'].map((label) => (
                                            <th key={label} className="whitespace-nowrap px-3 py-2 text-left">{label}</th>
                                        ))}
                                        {['On hand', 'Planned', 'Unallocated'].map((label) => (
                                            <th key={label} className="whitespace-nowrap px-3 py-2 text-right">{label}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredStock.map((line) => (
                                        <tr key={line.key} className={`border-t border-slate-100 ${line.over_allocated ? 'bg-rose-50' : ''} ${pickedKeys.includes(line.key) ? 'bg-emerald-50' : ''} ${canEdit && Number(line.remaining) > 0 ? 'cursor-pointer hover:bg-emerald-50/60' : ''}`} onClick={() => toggleSource(line)}>
                                            {canEdit && (
                                                <td className="px-3 py-2">
                                                    <input type="checkbox" checked={pickedKeys.includes(line.key)} disabled={Number(line.remaining) <= 0} onClick={(event) => event.stopPropagation()} onChange={() => toggleSource(line)} aria-label={`Select ${line.item} at ${line.warehouse}`} className="h-4 w-4 rounded border-slate-300" />
                                                </td>
                                            )}
                                            <td className="px-3 py-2 font-black text-slate-950">{line.item}</td>
                                            <td className="px-3 py-2 text-xs text-slate-600">{line.brand}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-xs font-bold text-slate-700">{line.expiry_month}</td>
                                            <td className="px-3 py-2 text-xs font-semibold text-slate-700">{line.warehouse}<span className="mt-0.5 block font-medium text-slate-400">{line.district} · {line.partnership}</span></td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right">{qty(line.on_hand)}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right">{qty(line.open_allocation)}</td>
                                            <td className={`whitespace-nowrap px-3 py-2 text-right font-black ${line.over_allocated ? 'text-rose-700' : 'text-emerald-800'}`}>{line.over_allocated ? `Over by ${qty(Number(line.open_allocation) - Number(line.on_hand))}` : qty(line.remaining)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {filteredStock.length === 0 && <p className="px-4 py-8 text-center text-sm font-semibold text-slate-500">No stock matches these filters.</p>}
                    </Card>
                </div>
            )}

            {section === 'register' && (
                <Card id="near-expiry-register" className="scroll-mt-28 overflow-hidden p-0">
                    <div className="grid gap-3 border-b border-slate-200 px-4 py-4 sm:grid-cols-3">
                        <SummaryTile label="Allocated" value={qty(totals.allocated)} hint={`${proposals.length} proposal${proposals.length === 1 ? '' : 's'}`} />
                        <SummaryTile label="Released on the allocation" value={qty(totals.released)} hint="Tracked here only. The stockpile drops when RIS, dispatch, and delivery are completed." />
                        <SummaryTile label="Still to release" value={qty(totals.waiting)} hint="Allocated and not yet marked released" />
                    </div>
                    <div className="divide-y divide-slate-100">
                        {proposals.map((proposal) => {
                            const warehouses = new Set(proposal.sources.map((plan) => plan.warehouse)).size;
                            return (
                                <article key={proposal.key} className="px-4 py-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <button type="button" onClick={() => setDetailKey(proposal.key)} className="text-left font-black text-slate-950 hover:text-emerald-700 hover:underline">{proposal.lgu}</button>
                                            <p className="mt-1 text-xs font-semibold text-slate-500">{proposal.activity} · {warehouses} warehouse{warehouses === 1 ? '' : 's'} · {proposal.delivery_mode_na ? 'Delivery N/A' : (proposal.delivery_mode || 'Delivery not set')}</p>
                                        </div>
                                        {canEdit && (confirmingId === proposal.id ? (
                                            <span className="inline-flex items-center gap-1">
                                                <button type="button" onClick={() => remove(proposal)} className="rounded-md bg-rose-600 px-2 py-1 text-[10px] font-black text-white">Remove</button>
                                                <button type="button" onClick={() => setConfirmingId(null)} className="rounded-md border px-2 py-1 text-[10px] font-black text-slate-500">Keep</button>
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1">
                                                <button type="button" onClick={() => fillFromProposal(proposal)} className="rounded-md border border-slate-200 p-1.5 text-slate-500 hover:text-emerald-700" aria-label={`Update proposal for ${proposal.lgu}`}><Pencil className="h-3.5 w-3.5" /></button>
                                                <button type="button" onClick={() => setConfirmingId(proposal.id)} className="rounded-md border border-slate-200 p-1.5 text-slate-500 hover:text-rose-700" aria-label={`Remove proposal for ${proposal.lgu}`}><Trash2 className="h-3.5 w-3.5" /></button>
                                            </span>
                                        ))}
                                    </div>
                                    <div className="mt-3 overflow-x-auto">
                                        <table className="min-w-full text-sm">
                                            <thead className="text-[10px] font-black uppercase tracking-wide text-slate-400">
                                                <tr>
                                                    {['Item', 'Expiry', 'Warehouse', 'Allocated', 'Released', 'Balance', 'Current progress'].map((label) => (
                                                        <th key={label} className={`whitespace-nowrap px-2 py-1 ${['Allocated', 'Released'].includes(label) ? 'text-right' : 'text-left'}`}>{label}</th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {proposal.sources.map((plan, index) => {
                                                    const meta = statusMeta[plan.line_status] || statusMeta.allocated;
                                                    return (
                                                        <tr key={plan.id} className="border-t border-slate-100">
                                                            <td className="px-2 py-2 font-bold text-slate-800">{plan.item}<span className="mt-0.5 block text-[11px] font-semibold text-slate-400">{plan.brand}</span></td>
                                                            <td className="whitespace-nowrap px-2 py-2 text-xs font-bold text-slate-700">{plan.expiry_month || '—'}</td>
                                                            <td className="px-2 py-2 text-xs font-semibold text-slate-700">{plan.warehouse}<span className="mt-0.5 block font-medium text-slate-400">{plan.district} · {plan.partnership}</span></td>
                                                            <td className="whitespace-nowrap px-2 py-2 text-right font-black text-slate-950">{qty(plan.allocated_quantity)}</td>
                                                            <td className="whitespace-nowrap px-2 py-2 text-right font-black text-emerald-800">{qty(plan.released_quantity)}</td>
                                                            <td className="px-2 py-2"><span className={`whitespace-nowrap rounded-full px-2 py-1 text-[10px] font-black ${meta.className}`}>{meta.label}</span></td>
                                                            {index === 0 && (
                                                                <td rowSpan={proposal.sources.length} className="border-l border-slate-100 px-2 py-2 align-middle">
                                                                    <span className={`inline-flex whitespace-nowrap rounded-full px-2 py-1 text-[10px] font-black ${progressTone(proposal.progress_status)}`}>{proposal.progress_status || 'No progress yet'}</span>
                                                                    {standingNote(proposal, proposal.progress_status) && <span className="mt-1 block whitespace-nowrap text-[10px] font-black text-amber-700">{standingNote(proposal, proposal.progress_status)}</span>}
                                                                </td>
                                                            )}
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                    <StageRail proposal={proposal} onOpen={() => setDetailKey(proposal.key)} />
                                </article>
                            );
                        })}
                    </div>
                    {proposals.length === 0 && <p className="px-4 py-10 text-center text-sm font-semibold text-slate-500">No allocations match these filters.</p>}
                </Card>
            )}
            {section === 'register' && detailProposal && typeof document !== 'undefined' && createPortal(
                <ProposalDetails
                    proposal={detailProposal}
                    canEdit={canEdit}
                    recipient={(liveRecipients ?? sotex.recipients ?? []).find((row) => row.name === detailProposal.lgu)}
                    onClose={() => setDetailKey(null)}
                    onEdit={() => {
                        setDetailKey(null);
                        fillFromProposal(detailProposal);
                    }}
                />,
                document.body,
            )}
            {composerOpen && canEdit && typeof document !== 'undefined' && createPortal(
                <div className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-950/40 p-4 sm:p-8" role="presentation" onMouseDown={(event) => { if (event.target?.closest?.('[role="listbox"]')) return; if (event.target === event.currentTarget) clearForm(); }}>
                    <div className="my-auto w-full max-w-4xl rounded-2xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="sotex-proposal-title">
                        <form onSubmit={submit} className="space-y-4 p-5">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-[10px] font-black uppercase tracking-[.18em] text-emerald-700">Proposal</p>
                                    <h2 id="sotex-proposal-title" className="mt-1 text-lg font-black text-slate-950">{editingId ? 'Update proposal' : 'Record the proposal'}</h2>
                                    <p className="mt-1 text-sm font-semibold text-slate-500">One proposal can draw from several warehouses. Each line keeps its own quantity. Saving does not deduct the stockpile.</p>
                                </div>
                                <button type="button" onClick={clearForm} className="rounded-md border border-slate-200 p-1.5 text-slate-500" aria-label="Close proposal"><X className="h-4 w-4" /></button>
                            </div>
                            <div className="space-y-2">
                                <div className="flex items-center justify-between gap-3">
                                    <p className="text-sm font-black text-slate-950">Sources · {sources.length} warehouse line{sources.length === 1 ? '' : 's'}</p>
                                </div>
                                {sources.map((source) => {
                                    const math = sourceMath(source);
                                    return (
                                        <div key={source.key} className={`rounded-xl border px-4 py-3 ${math.over ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-emerald-50'}`}>
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="font-black text-slate-950">{source.item_name} · {source.brand} · {source.expiry_month}</p>
                                                    <p className="mt-1 text-xs font-semibold text-slate-600">{source.warehouse} · {source.district} · {source.partnership}</p>
                                                </div>
                                                <button type="button" onClick={() => removeSource(source.key)} className="rounded-md border border-slate-200 bg-white p-1.5 text-slate-500" aria-label={`Remove ${source.warehouse}`}><X className="h-3.5 w-3.5" /></button>
                                            </div>
                                            <div className={`mt-3 grid gap-3 ${editingId ? 'sm:grid-cols-2' : ''}`}>
                                                <div>
                                                    <label className="text-sm font-bold text-slate-800">Allocated quantity
                                                        <input className="form-input mt-1 w-full" type="number" min="0" step="0.01" value={source.allocated_quantity} onChange={(event) => updateSource(source.key, 'allocated_quantity', event.target.value)} />
                                                    </label>
                                                    {!editingId && math.line && suggestedQuantity(math.line) !== '' && String(source.allocated_quantity) !== suggestedQuantity(math.line) && (
                                                        <button type="button" onClick={() => updateSource(source.key, 'allocated_quantity', suggestedQuantity(math.line))} className="mt-1 text-[11px] font-black text-emerald-700 hover:underline">
                                                            Use all {qty(math.line.remaining)} still unallocated
                                                        </button>
                                                    )}
                                                </div>
                                                {editingId && (
                                                    <div>
                                                        <label className="text-sm font-bold text-slate-800">Released quantity
                                                            <input className={`form-input mt-1 w-full ${releaseLocked ? 'cursor-not-allowed bg-slate-100 opacity-60' : ''}`} type="number" min="0" step="0.01" disabled={releaseLocked} title={releaseLocked ? 'Fill in every actual date first' : undefined} value={source.released_quantity} onChange={(event) => updateSource(source.key, 'released_quantity', event.target.value)} />
                                                        </label>
                                                        {releaseLocked && <p className="mt-1 text-[11px] font-semibold text-slate-500">Unlocks once every actual date is filled. Still missing: {missingActual.join(', ')}.</p>}
                                                    </div>
                                                )}
                                            </div>
                                            <p className={`mt-2 text-xs font-black ${math.over ? 'text-rose-700' : 'text-emerald-900'}`}>
                                                {math.line == null
                                                    ? 'This line no longer matches stock on hand.'
                                                    : math.over
                                                        ? `That is ${qty(Math.abs(math.after))} more than this warehouse line can still be planned for.`
                                                        : `After this plan, ${qty(Math.max(0, math.after))} of this line is still unallocated.`}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                            {form.errors.allocated_quantity && <p className="text-xs font-bold text-rose-700">{form.errors.allocated_quantity}</p>}
                            {form.errors.released_quantity && <p className="text-xs font-bold text-rose-700">{form.errors.released_quantity}</p>}
                            <div>
                                <SearchableSelect label="Recipient" options={recipientOptions} value={form.data.lgu} onChange={(value) => form.setData('lgu', value)} placeholder="Search LGU, office, or agency" creatable createLabel="Add recipient" onCreate={openRecipientDraft} />
                                {form.errors.lgu && <p className="mt-1 text-xs font-bold text-rose-700">{form.errors.lgu}</p>}
                                {recipientDraft && (
                                    <div className="mt-2 space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3">
                                        <div>
                                            <p className="text-sm font-black text-slate-950">Add recipient</p>
                                            <p className="text-xs font-semibold text-slate-500">For DSWD offices, agencies, and organizations. LGUs come from the LGU Directory.</p>
                                        </div>
                                        <label className="block text-xs font-bold text-slate-700">
                                            Recipient
                                            <input
                                                className="form-input mt-1 w-full"
                                                value={recipientDraft.name}
                                                autoFocus
                                                onChange={(event) => setRecipientDraft((current) => ({ ...current, name: event.target.value }))}
                                                onKeyDown={(event) => {
                                                    if (event.key === 'Enter') {
                                                        event.preventDefault();
                                                        saveRecipient();
                                                    }
                                                }}
                                            />
                                        </label>
                                        {recipientDraft.errors.name && <p className="text-xs font-bold text-rose-700">{recipientDraft.errors.name}</p>}
                                        <label className="block text-xs font-bold text-slate-700">
                                            Office / Agency Details <span className="font-semibold text-slate-400">(optional)</span>
                                            <input
                                                className="form-input mt-1 w-full"
                                                value={recipientDraft.office}
                                                onChange={(event) => setRecipientDraft((current) => ({ ...current, office: event.target.value }))}
                                                onKeyDown={(event) => {
                                                    if (event.key === 'Enter') {
                                                        event.preventDefault();
                                                        saveRecipient();
                                                    }
                                                }}
                                            />
                                        </label>
                                        {recipientDraft.errors.office && <p className="text-xs font-bold text-rose-700">{recipientDraft.errors.office}</p>}
                                        <div className="flex justify-end gap-2">
                                            <button type="button" onClick={() => setRecipientDraft(null)} className="rounded-lg px-3 py-1.5 text-xs font-black text-slate-600 hover:bg-slate-100">Cancel</button>
                                            <button type="button" onClick={saveRecipient} disabled={recipientDraft.saving || !recipientDraft.name.trim()} className="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-black text-white disabled:opacity-50">{recipientDraft.saving ? 'Saving…' : 'Save recipient'}</button>
                                        </div>
                                    </div>
                                )}
                            </div>
                            <div>
                                <p className="text-sm font-bold text-slate-800">Activity</p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {activities.map((activity) => (
                                        <button key={activity} type="button" onClick={() => form.setData('activity', activity)} className={`rounded-full px-3 py-1.5 text-xs font-black ${form.data.activity === activity ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-600'}`}>{activity}</button>
                                    ))}
                                </div>
                            </div>
                            <div className="rounded-xl border border-slate-200 p-4">
                                <p className="text-sm font-black text-slate-950">{editingId ? 'Update stages' : 'Target dates'}</p>
                                <p className="mt-1 text-xs font-semibold text-slate-500">{editingId ? 'Record the actual date when a stage happens. Target dates can still be adjusted.' : 'Set the target dates now. Work schedule, delivery, and distribution are date ranges. Actual dates are entered later, when each stage is updated.'}</p>
                                <div className="mt-3 divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200">
                                    {proposalStages.filter((stage) => editingId || stage.target).map((stage) => {
                                        const notApplicable = Boolean(stage.na && form.data[stage.na]);
                                        return (
                                        <div key={stage.title} className="grid gap-3 bg-white px-3 py-3 sm:grid-cols-2">
                                            {stage.target?.[2] ? (
                                                <div className="sm:col-span-2">
                                                    <div className="flex items-center justify-between gap-3">
                                                        <p className="text-sm font-bold text-slate-800">{stage.target[1]}</p>
                                                        {stage.na && <NotApplicableCheck checked={notApplicable} label={stage.target[1]} onChange={(checked) => setNotApplicable(stage.na, [stage.target[0], stage.target[2], ...naActualKeys(stage)], checked)} />}
                                                    </div>
                                                    <div className={`mt-1 grid grid-cols-2 gap-2 ${notApplicable ? 'opacity-50' : ''}`}>
                                                        <label className="text-xs font-semibold text-slate-500">From
                                                            <input className="form-input mt-1 w-full disabled:bg-slate-100" type="date" aria-label={`${stage.target[1]} from`} disabled={notApplicable} value={notApplicable ? '' : (form.data[stage.target[0]] || '')} max={form.data[stage.target[2]] || undefined} onChange={(event) => setRangeDate(stage.target[0], stage.target[2], 'start', event.target.value)} />
                                                        </label>
                                                        <label className="text-xs font-semibold text-slate-500">To
                                                            <input className="form-input mt-1 w-full disabled:bg-slate-100" type="date" aria-label={`${stage.target[1]} to`} disabled={notApplicable} value={notApplicable ? '' : (form.data[stage.target[2]] || '')} min={form.data[stage.target[0]] || undefined} onChange={(event) => setRangeDate(stage.target[0], stage.target[2], 'end', event.target.value)} />
                                                        </label>
                                                    </div>
                                                    {(form.errors[stage.target[0]] || form.errors[stage.target[2]]) && <p className="mt-1 text-xs font-bold text-rose-700">{form.errors[stage.target[0]] || form.errors[stage.target[2]]}</p>}
                                                </div>
                                            ) : stage.target && (
                                                <div className={editingId && stage.actual ? '' : 'sm:col-span-2'}>
                                                    <div className="flex items-center justify-between gap-3">
                                                        <span className="text-sm font-bold text-slate-800">{stage.target[1]}</span>
                                                        {stage.na && <NotApplicableCheck checked={notApplicable} label={stage.target[1]} onChange={(checked) => setNotApplicable(stage.na, [stage.target[0], ...naActualKeys(stage)], checked)} />}
                                                    </div>
                                                    <input className={`form-input mt-1 w-full ${notApplicable ? 'bg-slate-100 opacity-50' : ''}`} type="date" aria-label={stage.target[1]} disabled={notApplicable} value={notApplicable ? '' : (form.data[stage.target[0]] || '')} onChange={(event) => form.setData(stage.target[0], event.target.value)} />
                                                    {form.errors[stage.target[0]] && <p className="mt-1 text-xs font-bold text-rose-700">{form.errors[stage.target[0]]}</p>}
                                                </div>
                                            )}
                                            {editingId && stage.actual?.[2] && (() => {
                                                const [startKey, label, endKey] = stage.actual;
                                                const startGate = actualGate(form.data, startKey);
                                                const endGate = actualGate(form.data, endKey);
                                                const startBlocked = Boolean(startGate.missing) && !form.data[startKey];
                                                const endBlocked = (startBlocked || Boolean(endGate.missing)) && !form.data[endKey];
                                                const endMin = [form.data[startKey], endGate.min].filter(Boolean).map((value) => String(value).slice(0, 10)).sort().pop();
                                                const error = actualError(startKey, endKey);
                                                return (
                                                    <div className="sm:col-span-2">
                                                        <p className="text-sm font-bold text-slate-800">{label}</p>
                                                        <div className={`mt-1 grid grid-cols-2 gap-2 ${notApplicable ? 'opacity-50' : ''}`}>
                                                            <label className="text-xs font-semibold text-slate-500">From
                                                                <input className="form-input mt-1 w-full disabled:cursor-not-allowed disabled:bg-slate-100" type="date" aria-label={`${label} from`} disabled={notApplicable || startBlocked} title={startBlocked ? `Record the actual ${startGate.missing} first` : undefined} value={notApplicable ? '' : (form.data[startKey] || '')} min={startGate.min} max={form.data[endKey] || undefined} onChange={(event) => setRangeDate(startKey, endKey, 'start', event.target.value)} />
                                                            </label>
                                                            <label className="text-xs font-semibold text-slate-500">To
                                                                <input className="form-input mt-1 w-full disabled:cursor-not-allowed disabled:bg-slate-100" type="date" aria-label={`${label} to`} disabled={notApplicable || endBlocked} title={endBlocked ? `Record the actual ${endGate.missing || startGate.missing} first` : undefined} value={notApplicable ? '' : (form.data[endKey] || '')} min={endMin} onChange={(event) => setRangeDate(startKey, endKey, 'end', event.target.value)} />
                                                            </label>
                                                        </div>
                                                        {error
                                                            ? <p className="mt-1 text-xs font-bold text-rose-700">{error}</p>
                                                            : !notApplicable && (startBlocked || endBlocked) && <p className="mt-1 text-[11px] font-semibold text-slate-500">{startBlocked ? `Opens once the actual ${startGate.missing} is recorded.` : `The end date opens once the actual ${endGate.missing} is recorded.`}</p>}
                                                    </div>
                                                );
                                            })()}
                                            {editingId && stage.actual && !stage.actual[2] && (() => {
                                                const [key, label] = stage.actual;
                                                const gate = actualGate(form.data, key);
                                                const blocked = Boolean(gate.missing) && !form.data[key];
                                                const error = actualError(key);
                                                return (
                                                    <label className="text-sm font-bold text-slate-800">{label}
                                                        <input className={`form-input mt-1 w-full disabled:cursor-not-allowed disabled:bg-slate-100 ${notApplicable ? 'opacity-50' : ''}`} type="date" aria-label={label} disabled={notApplicable || blocked} title={blocked ? `Record the actual ${gate.missing} first` : undefined} value={notApplicable ? '' : (form.data[key] || '')} min={gate.min} onChange={(event) => form.setData(key, event.target.value)} />
                                                        {error
                                                            ? <span className="mt-1 block text-xs font-bold text-rose-700">{error}</span>
                                                            : !notApplicable && blocked && <span className="mt-1 block text-[11px] font-semibold text-slate-500">Opens once the actual {gate.missing} is recorded.</span>}
                                                    </label>
                                                );
                                            })()}
                                        </div>
                                        );
                                    })}
                                </div>
                                <div className="mt-3 grid gap-3 border-t border-slate-200 pt-3 sm:grid-cols-2">
                                    <div>
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="text-sm font-bold text-slate-800">Delivery</span>
                                            <NotApplicableCheck checked={Boolean(form.data.delivery_mode_na)} label="Delivery" onChange={(checked) => setNotApplicable('delivery_mode_na', ['delivery_mode'], checked)} />
                                        </div>
                                        <select className={`form-input mt-1 w-full ${form.data.delivery_mode_na ? 'bg-slate-100 opacity-50' : ''}`} aria-label="Delivery" disabled={Boolean(form.data.delivery_mode_na)} value={form.data.delivery_mode_na ? '' : form.data.delivery_mode} onChange={(event) => form.setData('delivery_mode', event.target.value)}>
                                            <option value="">Select who delivers</option>
                                            {(sotex.delivery_modes ?? ['c/o LGU', 'c/o RROS']).map((mode) => <option key={mode} value={mode}>{mode}</option>)}
                                        </select>
                                        {form.errors.delivery_mode && <p className="mt-1 text-xs font-bold text-rose-700">{form.errors.delivery_mode}</p>}
                                    </div>
                                    <div>
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="text-sm font-bold text-slate-800">Current progress</span>
                                            <span className="text-[10px] font-black uppercase tracking-wider text-slate-400">Set from the dates</span>
                                        </div>
                                        <p className={`mt-1 flex min-h-[42px] items-center rounded-md px-3 text-sm font-black ${progressTone(liveProgress)}`} aria-live="polite">{liveProgress}</p>
                                        {standingNote(form.data, liveProgress) && <p className="mt-1 text-[11px] font-black text-amber-700">{standingNote(form.data, liveProgress)}</p>}
                                    </div>
                                </div>
                                <ProgressTrack status={liveProgress} hint={progressHint(liveProgress, form.data)} data={form.data} />
                            </div>
                            <label className="block text-sm font-bold text-slate-800">Remarks
                                <textarea className="form-input mt-1 min-h-20 w-full" value={form.data.remarks} onChange={(event) => form.setData('remarks', event.target.value)} />
                            </label>
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                {outOfOrder && <p className="mr-auto text-xs font-bold text-rose-700">Some actual dates are out of order. Fix the dates marked in red to save.</p>}
                                <button type="button" onClick={clearForm} className="rounded-md border border-slate-200 px-4 py-2.5 text-sm font-black text-slate-600">Cancel</button>
                                <button disabled={form.processing || !sourcesReady || !form.data.lgu || over || outOfOrder} className="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-700 px-4 py-2.5 text-sm font-black text-white disabled:opacity-50">
                                    <PackageCheck className="h-4 w-4" />
                                    {form.processing ? 'Saving…' : 'Save proposal'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>,
                document.body,
            )}
        </div>
    );
}

function StageRail({ proposal, onOpen }) {
    const states = proposalStages.map((stage) => stageState(stage, proposal));
    const next = states.find((state) => !['done', 'na'].includes(state.kind));
    const overdue = states.filter((state) => state.kind === 'overdue').length;
    const done = states.filter((state) => state.kind === 'done').length;
    return (
        <button type="button" onClick={onOpen} className="group mt-3 flex w-full flex-col gap-2 rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2.5 text-left transition hover:border-emerald-300 hover:bg-emerald-50/50 sm:flex-row sm:items-center sm:gap-4">
            <span className="flex flex-1 items-center" aria-hidden="true">
                {states.map((state, index) => (
                    <span key={state.stage.title} className="flex flex-1 items-center last:flex-none" title={`${state.stage.title}: ${stageTone[state.kind].label}`}>
                        <span className={`h-3 w-3 shrink-0 rounded-full ring-4 ${stageTone[state.kind].dot}`} />
                        {index < states.length - 1 && <span className={`mx-1 h-0.5 flex-1 rounded ${state.kind === 'done' ? 'bg-emerald-300' : 'bg-slate-200'}`} />}
                    </span>
                ))}
            </span>
            <span className="flex min-w-0 items-center gap-2 text-[11px] font-semibold text-slate-500 sm:w-80 sm:justify-end">
                <span className="truncate">
                    {next
                        ? <><span className={`font-black ${stageTone[next.kind].text}`}>{next.stage.title}</span> · {next.note}</>
                        : <span className="font-black text-emerald-700">Every stage is complete</span>}
                </span>
                <span className="shrink-0 rounded-full bg-white px-2 py-0.5 font-black text-slate-600 ring-1 ring-slate-200">{done}/{states.length}{overdue > 0 ? ` · ${overdue} late` : ''}</span>
                <ChevronRight className="h-4 w-4 shrink-0 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-emerald-700" />
            </span>
        </button>
    );
}

const sourcePalette = ['#10b981', '#0ea5e9', '#f59e0b', '#8b5cf6', '#f43f5e', '#14b8a6', '#6366f1'];

function ReleaseRing({ allocated, released }) {
    const ratio = allocated > 0 ? Math.min(1, released / allocated) : 0;
    const radius = 34;
    const circumference = 2 * Math.PI * radius;
    return (
        <div className="relative h-24 w-24 shrink-0">
            <svg viewBox="0 0 80 80" className="h-full w-full -rotate-90">
                <circle cx="40" cy="40" r={radius} fill="none" stroke="rgba(255,255,255,.18)" strokeWidth="8" />
                <circle cx="40" cy="40" r={radius} fill="none" stroke="#6ee7b7" strokeWidth="8" strokeLinecap="round" strokeDasharray={`${circumference * ratio} ${circumference}`} />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                <span className="text-lg font-black leading-none text-white">{Math.round(ratio * 100)}%</span>
                <span className="mt-0.5 text-[9px] font-black uppercase tracking-wider text-emerald-200">released</span>
            </div>
        </div>
    );
}

function ScheduleChart({ states }) {
    const today = todayStart();
    const points = states.flatMap((state) => [state.start, state.end, state.actual, state.actualEnd]).filter((value) => value != null);
    if (points.length === 0) {
        return <p className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-xs font-semibold text-slate-400">No dates are set yet, so there is nothing to chart.</p>;
    }
    const min = Math.min(...points, today) - DAY_MS;
    const max = Math.max(...points, today) + DAY_MS;
    const at = (time) => `${((time - min) / (max - min)) * 100}%`;
    const span = (from, to) => `${Math.max(1.5, ((to - from) / (max - min)) * 100)}%`;
    const tick = (time) => new Date(time).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-3">
            <div className="relative">
                <div className="pointer-events-none absolute inset-y-0 z-10 w-px bg-rose-400" style={{ left: `calc(9rem + (100% - 9rem) * ${(today - min) / (max - min)})` }}>
                    <span className="absolute -top-1 left-1 whitespace-nowrap rounded bg-rose-500 px-1 text-[9px] font-black uppercase text-white">Today</span>
                </div>
                <div className="space-y-1.5 pt-4">
                    {states.map((state) => (
                        <div key={state.stage.title} className="flex items-center gap-2">
                            <span className={`w-[8.5rem] shrink-0 truncate text-[11px] font-bold ${state.kind === 'na' ? 'text-slate-300 line-through' : 'text-slate-600'}`}>{state.stage.title}</span>
                            <div className="relative h-5 flex-1 rounded bg-slate-50">
                                {state.start != null && state.kind !== 'na' && (
                                    <span className={`absolute inset-y-1 rounded ${stageTone[state.kind].bar}`} style={{ left: at(state.start), width: span(state.start, state.end ?? state.start) }} title={`Target ${showWindow(state.start, state.end)}`} />
                                )}
                                {state.actual != null && state.actualEnd > state.actual && (
                                    <span className="absolute top-1/2 h-1 -translate-y-1/2 rounded-full bg-emerald-600" style={{ left: at(state.actual), width: span(state.actual, state.actualEnd) }} title={`Actual ${showWindow(state.actual, state.actualEnd)}`} />
                                )}
                                {state.actual != null && [...new Set([state.actual, state.actualEnd])].map((time) => (
                                    <span key={time} className="absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[2px] border-2 border-white bg-emerald-600 shadow" style={{ left: at(time) }} title={`Actual ${showWindow(state.actual, state.actualEnd)}`} />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
            <div className="mt-2 flex justify-between pl-[9rem] text-[10px] font-bold text-slate-400">
                <span>{tick(min + DAY_MS)}</span>
                <span>{tick(max - DAY_MS)}</span>
            </div>
            <div className="mt-2 flex flex-wrap gap-3 border-t border-slate-100 pt-2 text-[10px] font-bold text-slate-500">
                <span className="inline-flex items-center gap-1"><span className="h-2 w-4 rounded bg-indigo-200" />Target window</span>
                <span className="inline-flex items-center gap-1"><span className="h-2.5 w-2.5 rotate-45 rounded-[2px] bg-emerald-600" />Actual date or range</span>
                <span className="inline-flex items-center gap-1"><span className="h-3 w-px bg-rose-400" />Today</span>
            </div>
        </div>
    );
}

function ProposalDetails({ proposal, canEdit, recipient, onClose, onEdit }) {
    useEffect(() => {
        const close = (event) => event.key === 'Escape' && onClose();
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, [onClose]);

    const states = proposalStages.map((stage) => stageState(stage, proposal));
    const allocated = proposal.sources.reduce((sum, plan) => sum + Number(plan.allocated_quantity || 0), 0);
    const released = proposal.sources.reduce((sum, plan) => sum + Number(plan.released_quantity || 0), 0);
    const done = states.filter((state) => state.kind === 'done').length;
    const overdue = states.filter((state) => state.kind === 'overdue').length;

    return (
        <div className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-[2px] sm:p-8" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
            <div className="my-auto w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="sotex-detail-title">
                <header className="relative overflow-hidden bg-gradient-to-br from-emerald-800 via-emerald-700 to-teal-600 px-6 py-5 text-white">
                    <div className="pointer-events-none absolute -right-10 -top-16 h-48 w-48 rounded-full bg-white/10" />
                    <div className="pointer-events-none absolute -bottom-20 right-24 h-40 w-40 rounded-full bg-white/5" />
                    <div className="relative flex flex-wrap items-center gap-5">
                        <ReleaseRing allocated={allocated} released={released} />
                        <div className="min-w-0 flex-1">
                            <p className="text-[10px] font-black uppercase tracking-[.2em] text-emerald-200">SoTEx proposal · {proposal.activity}</p>
                            <h2 id="sotex-detail-title" className="mt-1 text-2xl font-black leading-tight">{proposal.lgu}</h2>
                            {recipient?.details && <p className="mt-0.5 text-xs font-semibold text-emerald-100">{[recipient.level, recipient.details].filter(Boolean).join(' · ')}</p>}
                            <div className="mt-3 flex flex-wrap gap-2 text-[11px] font-black">
                                <span className="rounded-full bg-white/15 px-2.5 py-1">{proposal.progress_status || 'No progress yet'}</span>
                                {standingNote(proposal, proposal.progress_status) && <span className="rounded-full bg-amber-300 px-2.5 py-1 text-amber-950">{standingNote(proposal, proposal.progress_status)}</span>}
                                <span className="inline-flex items-center gap-1 rounded-full bg-white/15 px-2.5 py-1"><Truck className="h-3 w-3" />{proposal.delivery_mode_na ? 'Delivery N/A' : (proposal.delivery_mode || 'Delivery not set')}</span>
                                <span className={`rounded-full px-2.5 py-1 ${overdue > 0 ? 'bg-rose-500/90' : 'bg-white/15'}`}>{done} of {states.length} stages done{overdue > 0 ? ` · ${overdue} overdue` : ''}</span>
                            </div>
                        </div>
                        <dl className="grid grid-cols-3 gap-4 text-right">
                            {[['Allocated', allocated], ['Released', released], ['To release', Math.max(0, allocated - released)]].map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-[9px] font-black uppercase tracking-wider text-emerald-200">{label}</dt>
                                    <dd className="text-lg font-black">{qty(value)}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                    <div className="absolute right-3 top-3 flex gap-1.5">
                        {canEdit && <button type="button" onClick={onEdit} className="rounded-md bg-white/15 p-1.5 hover:bg-white/25" aria-label="Update proposal"><Pencil className="h-4 w-4" /></button>}
                        <button type="button" onClick={onClose} className="rounded-md bg-white/15 p-1.5 hover:bg-white/25" aria-label="Close details"><X className="h-4 w-4" /></button>
                    </div>
                </header>

                <div className="grid gap-6 p-6 lg:grid-cols-[1.35fr_1fr]">
                    <section>
                        <h3 className="flex items-center gap-2 text-xs font-black uppercase tracking-[.16em] text-slate-500"><CalendarRange className="h-4 w-4 text-emerald-600" />Schedule at a glance</h3>
                        <div className="mt-3"><ScheduleChart states={states} /></div>

                        <h3 className="mt-6 text-xs font-black uppercase tracking-[.16em] text-slate-500">Stage by stage</h3>
                        <ol className="relative mt-3 ml-1.5 border-l-2 border-slate-100">
                            {states.map((state) => (
                                <li key={state.stage.title} className="relative pb-4 pl-6 last:pb-0">
                                    <span className={`absolute -left-[9px] top-0.5 h-4 w-4 rounded-full ring-4 ${stageTone[state.kind].dot}`} />
                                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <p className={`text-sm font-black ${state.kind === 'na' ? 'text-slate-400' : 'text-slate-900'}`}>{state.stage.title}</p>
                                        <p className={`text-[11px] font-black ${stageTone[state.kind].text}`}>{state.note}</p>
                                    </div>
                                    <p className="mt-0.5 text-xs font-semibold text-slate-500">
                                        {state.stage.target && state.kind !== 'na' && <>Target <span className="font-bold text-slate-700">{showWindow(state.start, state.end)}</span></>}
                                        {state.stage.target && state.kind !== 'na' && state.actual != null && <span className="mx-1.5 text-slate-300">|</span>}
                                        {state.actual != null && <>Actual <span className="font-bold text-emerald-700">{showWindow(state.actual, state.actualEnd)}</span></>}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <section className="space-y-6">
                        <div>
                            <h3 className="flex items-center gap-2 text-xs font-black uppercase tracking-[.16em] text-slate-500"><Warehouse className="h-4 w-4 text-emerald-600" />Where the goods come from</h3>
                            <div className="mt-3 flex h-3 overflow-hidden rounded-full bg-slate-100">
                                {proposal.sources.map((plan, index) => (
                                    <span key={plan.id} style={{ width: `${allocated > 0 ? (Number(plan.allocated_quantity) / allocated) * 100 : 0}%`, backgroundColor: sourcePalette[index % sourcePalette.length] }} title={`${plan.warehouse}: ${qty(plan.allocated_quantity)}`} />
                                ))}
                            </div>
                            <ul className="mt-3 divide-y divide-slate-100">
                                {proposal.sources.map((plan, index) => {
                                    const share = allocated > 0 ? Math.round((Number(plan.allocated_quantity) / allocated) * 100) : 0;
                                    const meta = statusMeta[plan.line_status] || statusMeta.allocated;
                                    const releasedRatio = Number(plan.allocated_quantity) > 0 ? Math.min(1, Number(plan.released_quantity) / Number(plan.allocated_quantity)) : 0;
                                    return (
                                        <li key={plan.id} className="flex gap-3 py-2.5">
                                            <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: sourcePalette[index % sourcePalette.length] }} />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-baseline justify-between gap-2">
                                                    <p className="truncate text-sm font-black text-slate-900">{plan.item} <span className="font-semibold text-slate-400">· {plan.brand}</span></p>
                                                    <p className="shrink-0 text-sm font-black text-slate-950">{qty(plan.allocated_quantity)} <span className="text-[10px] font-bold text-slate-400">{share}%</span></p>
                                                </div>
                                                <p className="text-[11px] font-semibold text-slate-500">{plan.warehouse} · {plan.district} · expires {plan.expiry_month || '—'}</p>
                                                <div className="mt-1.5 flex items-center gap-2">
                                                    <span className="h-1 flex-1 overflow-hidden rounded-full bg-slate-100"><span className="block h-full rounded-full bg-emerald-500" style={{ width: `${releasedRatio * 100}%` }} /></span>
                                                    <span className={`rounded-full px-1.5 py-0.5 text-[9px] font-black ${meta.className}`}>{meta.label}</span>
                                                </div>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>

                        <figure className="relative rounded-xl bg-amber-50/70 px-4 py-3">
                            <MessageSquareQuote className="absolute -top-2.5 left-3 h-5 w-5 rounded bg-white p-0.5 text-amber-500" />
                            <figcaption className="text-[10px] font-black uppercase tracking-[.16em] text-amber-700">Remarks</figcaption>
                            <blockquote className={`mt-1 whitespace-pre-line text-sm ${proposal.remarks ? 'font-semibold text-slate-700' : 'italic text-slate-400'}`}>{proposal.remarks || 'No remarks recorded.'}</blockquote>
                        </figure>
                    </section>
                </div>
            </div>
        </div>
    );
}

// An earlier step is only "done" when its own dates are recorded: the activity can run ahead of approval,
// distribution can start before the activity ends, and delivery steps are skipped when delivery is N/A.
const stepRecorded = (step, data) => ({
    'Approved Proposal': Boolean(data.proposal_approved_on),
    'Ongoing Activity': Boolean(data.work_schedule_on),
    'SoTEx Partially Delivered': Boolean(data.delivered_on),
    'SoTEx Fully Delivered': Boolean(data.delivered_on && data.delivered_end_on),
    'Activity Completed': Boolean(data.work_schedule_on && data.work_schedule_end_on),
}[step] ?? true);

const stepTone = {
    done: 'bg-emerald-500 text-white',
    current: 'bg-emerald-700 text-white ring-4 ring-emerald-100',
    pending: 'bg-amber-100 text-amber-800 ring-1 ring-amber-300',
    skipped: 'bg-slate-100 text-slate-300 ring-1 ring-slate-200',
    future: 'bg-white text-slate-400 ring-1 ring-slate-200',
};

function ProgressTrack({ status, hint, data = {} }) {
    const current = PROGRESS_STEPS.indexOf(status);
    const kindOf = (step, index) => {
        if (index === current) return 'current';
        if (index > current) return 'future';
        if (data.delivery_target_na && step.includes('Delivered')) return 'skipped';
        return stepRecorded(step, data) ? 'done' : 'pending';
    };
    const steps = PROGRESS_STEPS.map((step, index) => ({ step, kind: kindOf(step, index) }));
    const pending = steps.filter(({ kind }) => kind === 'pending').map(({ step }) => step);
    return (
        <div className="mt-3 rounded-lg bg-slate-50 px-3 py-3">
            <ol className="flex items-center" aria-label="Progress steps">
                {steps.map(({ step, kind }, index) => (
                    <li key={step} className="flex flex-1 items-center last:flex-none" title={`${step}${kind === 'pending' ? ': not recorded yet' : kind === 'skipped' ? ': not applicable' : ''}`} data-kind={kind}>
                        <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-black ${stepTone[kind]}`}>{kind === 'pending' ? '!' : kind === 'skipped' ? '–' : index + 1}</span>
                        {index < PROGRESS_STEPS.length - 1 && <span className={`mx-1 h-0.5 flex-1 rounded ${index < current ? 'bg-emerald-400' : 'bg-slate-200'}`} />}
                    </li>
                ))}
            </ol>
            <p className="mt-2 text-xs font-semibold text-slate-500"><span className="font-black text-slate-700">Step {current + 1} of {PROGRESS_STEPS.length}.</span> {hint}</p>
            {pending.length > 0 && <p className="mt-1 text-xs font-bold text-amber-700">Still running or not recorded yet: {pending.join(', ')}.</p>}
        </div>
    );
}

function CoverageBanner({ coverage = {} }) {
    const unallocatedLines = Number(coverage.unallocated_lines || 0);
    const overLines = Number(coverage.over_allocated_lines || 0);
    return (
        <div className={`grid gap-3 border-b px-4 py-4 sm:grid-cols-3 ${overLines > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'}`}>
            <SummaryTile label="Stockpile on hand" value={qty(coverage.on_hand)} hint="Not reduced by these allocations" />
            <SummaryTile label="Still unallocated" value={qty(coverage.unallocated_quantity)} hint={`${unallocatedLines} expiry line${unallocatedLines === 1 ? '' : 's'} still open to plan`} />
            <SummaryTile label="Over-allocated lines" value={String(overLines)} hint={overLines > 0 ? 'Planned quantity is above the stockpile for that expiry line' : 'No expiry line is planned above what is on hand'} />
        </div>
    );
}

function NotApplicableCheck({ checked, label, onChange }) {
    return (
        <label className="inline-flex shrink-0 items-center gap-1.5 text-xs font-black text-slate-600">
            <input type="checkbox" className="h-3.5 w-3.5 rounded border-slate-300" checked={checked} aria-label={`${label} not applicable`} onChange={(event) => onChange(event.target.checked)} />
            N/A
        </label>
    );
}

function SummaryTile({ label, value, hint }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
            <p className="text-[10px] font-black uppercase tracking-wide text-slate-500">{label}</p>
            <p className="mt-1 text-xl font-black text-slate-950">{value}</p>
            <p className="mt-1 text-[11px] font-semibold text-slate-500">{hint}</p>
        </div>
    );
}
