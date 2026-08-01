import { Link } from '@inertiajs/react';
import { ArrowRight, FileSignature, ShieldCheck, Stamp } from 'lucide-react';
import clsx from 'clsx';

/**
 * Guides DRRS users to validate signed LGU request letters before Create Assessment.
 * Renders only when signed letters still need review or LGU correction.
 */
export default function ReliefAssessmentGateBanner({
    awaitingValidation = 0,
    needsLguAction = 0,
    context = 'fni',
}) {
    const awaiting = Number(awaitingValidation) || 0;
    const blocked = Number(needsLguAction) || 0;
    const total = awaiting + blocked;
    if (total <= 0) {
        return null;
    }

    const onRequestsTab = context === 'requests';
    const reviewHref = '/dromic/lgu-reports?tab=requests&validation=pending_review';

    return (
        <aside
            className={clsx(
                'relative overflow-hidden border-x border-b px-4 py-4 sm:px-5',
                'border-amber-200/90 bg-gradient-to-br from-amber-50 via-white to-emerald-50/70',
                'dark:border-amber-900/50 dark:from-amber-950/40 dark:via-zinc-950 dark:to-emerald-950/20',
            )}
            role="status"
            aria-live="polite"
        >
            <div
                className="pointer-events-none absolute -right-6 -top-8 h-36 w-36 rounded-full bg-amber-200/40 blur-2xl dark:bg-amber-700/20"
                aria-hidden
            />
            <div
                className="pointer-events-none absolute -bottom-10 left-1/3 h-28 w-28 rounded-full bg-emerald-200/30 blur-2xl dark:bg-emerald-700/15"
                aria-hidden
            />

            <div className="relative flex flex-col gap-4 lg:flex-row lg:items-stretch lg:justify-between">
                <div className="flex min-w-0 flex-1 gap-3">
                    <div className="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-amber-300/80 bg-white shadow-sm dark:border-amber-800 dark:bg-zinc-900">
                        <Stamp className="h-6 w-6 text-amber-700 dark:text-amber-300" />
                        <span className="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-black text-white shadow">
                            {total}
                        </span>
                    </div>
                    <div className="min-w-0">
                        <p className="text-[10px] font-black uppercase tracking-[0.18em] text-amber-800/80 dark:text-amber-200/80">
                            Assessment checkpoint
                        </p>
                        <h2 className="mt-0.5 text-base font-black text-slate-900 dark:text-zinc-50 sm:text-lg">
                            Validate signed request letters before creating assessments
                        </h2>
                        <p className="mt-1 max-w-2xl text-sm text-slate-600 dark:text-zinc-300">
                            {onRequestsTab
                                ? 'Clear each signed relief request letter here first. Once marked Validated — No Findings, the request unlocks on FNI Requests for Create Assessment.'
                                : 'Signed LGU request letters still need your validation. Create Assessment stays locked for those cases until the letter is cleared with no findings.'}
                        </p>
                    </div>
                </div>

                <div className="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-center lg:flex-col lg:items-stretch xl:flex-row">
                    <div className="flex flex-wrap gap-2">
                        {awaiting > 0 && (
                            <span className="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-100/80 px-2.5 py-1.5 text-xs font-black text-amber-950 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-100">
                                <FileSignature className="h-3.5 w-3.5" />
                                {awaiting} awaiting DRRS validation
                            </span>
                        )}
                        {blocked > 0 && (
                            <span className="inline-flex items-center gap-1.5 rounded-md border border-rose-300 bg-rose-50 px-2.5 py-1.5 text-xs font-black text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100">
                                <ShieldCheck className="h-3.5 w-3.5" />
                                {blocked} returned for LGU action
                            </span>
                        )}
                    </div>
                    {!onRequestsTab && (
                        <Link
                            href={reviewHref}
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-amber-700 px-3.5 py-2 text-xs font-black text-white shadow-sm transition hover:bg-amber-800 dark:bg-amber-600 dark:hover:bg-amber-500"
                        >
                            Open request letters
                            <ArrowRight className="h-3.5 w-3.5" />
                        </Link>
                    )}
                    {onRequestsTab && (
                        <div className="inline-flex items-center gap-2 rounded-md border border-emerald-300/80 bg-emerald-50/90 px-3 py-2 text-[11px] font-bold text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                            <ShieldCheck className="h-3.5 w-3.5 shrink-0" />
                            Step: review → validate → then create assessment
                        </div>
                    )}
                </div>
            </div>

            <ol className="relative mt-3 grid gap-2 border-t border-amber-200/70 pt-3 text-[11px] font-semibold text-slate-600 dark:border-amber-900/40 dark:text-zinc-400 sm:grid-cols-3">
                <li className="flex items-start gap-2">
                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-700 text-[10px] font-black text-white">1</span>
                    Confirm the signed PDF matches the encoded request.
                </li>
                <li className="flex items-start gap-2">
                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-700 text-[10px] font-black text-white">2</span>
                    Mark Validated — No Findings (or return Needs LGU Action).
                </li>
                <li className="flex items-start gap-2">
                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-[10px] font-black text-white">3</span>
                    Proceed to FNI Requests → Create Assessment.
                </li>
            </ol>
        </aside>
    );
}

export function reliefLetterBlocksAssessment(request) {
    const source = request?.source_lgu_dromic_report;
    if (!source?.lgu_signed_request_path) {
        return false;
    }
    return source.lgu_relief_validation_status !== 'validated_no_findings';
}
