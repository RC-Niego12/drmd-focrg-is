import { Link } from '@inertiajs/react';

const join = (...classes) => classes.filter(Boolean).join(' ');

/**
 * Canonical tab navigation used throughout DROMIS.
 *
 * Items accept: key, label, icon, href, onClick, title, disabled.
 * Use `subtle` only for a nested tab row; its shape and active treatment remain
 * identical so tab hierarchy never introduces a second visual language.
 */
export default function SystemTabs({
    items = [],
    active,
    ariaLabel = 'Section tabs',
    className = '',
    subtle = false,
}) {
    return (
        <div
            role="tablist"
            aria-label={ariaLabel}
            className={join(
                'flex w-fit max-w-full flex-wrap items-center gap-1 overflow-visible rounded-lg border border-slate-200 p-1',
                subtle
                    ? 'bg-white dark:border-zinc-700 dark:bg-zinc-900'
                    : 'bg-slate-100/90 dark:border-zinc-700 dark:bg-zinc-800/80',
                className,
            )}
        >
            {items.map((item) => {
                const selected = active === item.key;
                const Icon = item.icon;
                const sharedProps = {
                    role: 'tab',
                    'aria-selected': selected,
                    'aria-label': item.title || item.label,
                    className: join(
                        'dromis-tip inline-flex min-h-9 shrink-0 items-center justify-center gap-2 rounded-md px-3.5 py-2 text-xs font-black transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2',
                        selected
                            ? 'bg-emerald-700 text-white shadow-sm'
                            : 'text-slate-600 hover:bg-white hover:text-slate-950 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white',
                        item.disabled && 'cursor-not-allowed opacity-50',
                    ),
                    'data-tip': item.title || item.label,
                    'data-tip-side': 'bottom',
                };

                if (item.href) {
                    return (
                        <Link
                            key={item.key}
                            {...sharedProps}
                            href={item.href}
                            preserveScroll={item.preserveScroll ?? true}
                            preserveState={item.preserveState ?? false}
                        >
                            {Icon && <Icon aria-hidden="true" className="h-4 w-4 shrink-0" />}
                            <span>{item.label}</span>
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
                        {Icon && <Icon aria-hidden="true" className="h-4 w-4 shrink-0" />}
                        <span>{item.label}</span>
                    </button>
                );
            })}
        </div>
    );
}
