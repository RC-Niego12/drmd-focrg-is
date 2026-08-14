import { Link } from '@inertiajs/react';

const join = (...classes) => classes.filter(Boolean).join(' ');

/**
 * Canonical segmented tab control used throughout DROMIS.
 *
 * Items accept: key, label, icon, count, href, onClick, title, disabled.
 * Use `subtle` for a nested/white tray; active treatment stays emerald so
 * tab hierarchy never introduces a second visual language.
 */
export default function SystemTabs({
    items = [],
    active,
    ariaLabel = 'Section tabs',
    className = '',
    subtle = false,
    /** Square bottom + no bottom border — sit flush on a parent horizontal rule */
    flushBottom = false,
}) {
    return (
        <div
            role="tablist"
            aria-label={ariaLabel}
            className={join(
                'flex w-fit max-w-full flex-wrap items-center gap-1 overflow-visible p-1',
                // Avoid `border` + `border-b-0` (shorthand can win); use side borders when flush.
                flushBottom
                    ? 'rounded-t-lg border-x border-t border-slate-200'
                    : 'rounded-lg border border-slate-200',
                subtle
                    ? 'bg-white dark:border-zinc-700 dark:bg-zinc-900'
                    : 'bg-slate-100/90 dark:border-zinc-700 dark:bg-zinc-800/80',
                className,
            )}
        >
            {items.map((item) => {
                const selected = active === item.key;
                const Icon = item.icon;
                const showCount = item.count != null && item.count !== '';
                const sharedProps = {
                    role: 'tab',
                    'aria-selected': selected,
                    'aria-label': item.title || item.label,
                    className: join(
                        'dromis-tip inline-flex min-h-9 shrink-0 items-center justify-center gap-2 rounded-md px-3.5 py-2 text-xs font-black transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2',
                        selected
                            ? 'bg-emerald-700 text-white shadow-sm'
                            : 'bg-transparent text-slate-800 hover:bg-white hover:text-slate-950 dark:text-zinc-100 dark:hover:bg-zinc-700 dark:hover:text-white',
                        item.disabled && 'cursor-not-allowed opacity-50',
                    ),
                    'data-tip': item.title || item.label,
                    'data-tip-side': 'bottom',
                };

                const content = (
                    <>
                        {Icon && <Icon aria-hidden="true" className="h-4 w-4 shrink-0" />}
                        <span>{item.label}</span>
                        {showCount && (
                            <span
                                className={join(
                                    'rounded-full px-1.5 py-0.5 text-[10px] font-black tabular-nums',
                                    selected
                                        ? 'bg-white/20 text-white'
                                        : 'bg-slate-200/80 text-slate-700 dark:bg-zinc-700 dark:text-zinc-200',
                                )}
                            >
                                {item.count}
                            </span>
                        )}
                    </>
                );

                if (item.href) {
                    return (
                        <Link
                            key={item.key}
                            {...sharedProps}
                            href={item.href}
                            preserveScroll={item.preserveScroll ?? true}
                            preserveState={item.preserveState ?? false}
                        >
                            {content}
                        </Link>
                    );
                }

                return (
                    <button
                        key={item.key}
                        {...sharedProps}
                        type="button"
                        disabled={item.disabled}
                        onClick={item.onClick}
                    >
                        {content}
                    </button>
                );
            })}
        </div>
    );
}
