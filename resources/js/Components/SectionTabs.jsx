import SystemTabs from '@/Components/SystemTabs';

const join = (...classes) => classes.filter(Boolean).join(' ');

/** Same token as SystemTabs tray (`border-slate-200` / `dark:border-zinc-700`). */
const RULE_BORDER = 'border-slate-200 dark:border-zinc-700';

/**
 * Page/section view switcher — FNI REQUEST VIEWS style.
 *
 * Long horizontal rules frame a segmented tab control (white tray, emerald active).
 * The tab tray stays `w-fit`; top/bottom rules are always `w-full` on the parent.
 *
 * @param {string} [label] Optional uppercase section title above the tabs
 * @param {{ id: string, label: string, icon?: import('react').ComponentType, count?: number|string, href?: string, onClick?: Function, title?: string, disabled?: boolean, preserveScroll?: boolean, preserveState?: boolean }[]} tabs
 * @param {string} value Active tab id
 * @param {(id: string) => void} [onChange]
 * @param {'framed'|'stack'|'stack-top'|'plain'} [appearance='framed']
 *   - framed: full-width border-y rules (default section switcher)
 *   - stack: continues a bordered card stack (e.g. under workspace tabs)
 *   - stack-top: top of a bordered card stack (workspace switcher); keeps bottom rule for flush tabs
 *   - plain: segmented control only (modals / compact rows)
 * @param {string} [contentClassName] Horizontal inset for label + tab tray only (rules stay full width)
 */
export default function SectionTabs({
    label,
    tabs = [],
    value,
    onChange,
    className = '',
    contentClassName = '',
    ariaLabel,
    appearance = 'framed',
    subtle = true,
}) {
    const items = tabs.map((tab) => ({
        key: tab.id,
        label: tab.label,
        icon: tab.icon,
        count: tab.count,
        href: tab.href,
        title: tab.title,
        disabled: tab.disabled,
        preserveScroll: tab.preserveScroll,
        preserveState: tab.preserveState,
        onClick: tab.onClick || (onChange ? () => onChange(tab.id) : undefined),
    }));

    // Sit the tray on the section bottom rule so that rule is the shared
    // bottom edge (no gap under the tabs box).
    const flushToBottomRule =
        appearance === 'framed' || appearance === 'stack' || appearance === 'stack-top';

    const control = (
        <SystemTabs
            active={value}
            ariaLabel={ariaLabel || label || 'Section tabs'}
            subtle={subtle || appearance === 'stack' || appearance === 'plain'}
            flushBottom={flushToBottomRule}
            items={items}
        />
    );

    const sectionLabel = label ? (
        <p
            className={join(
                'mb-2 text-[10px] font-black uppercase tracking-widest text-slate-400',
                contentClassName,
            )}
        >
            {label}
        </p>
    ) : null;

    if (appearance === 'plain') {
        return (
            <div className={className}>
                {sectionLabel}
                <div className={contentClassName || undefined}>{control}</div>
            </div>
        );
    }

    if (appearance === 'stack-top') {
        // Full-width shell border (including bottom). flushBottom tabs sit on that rule.
        // Content below should use border-t-0 / border-x to join without a double line.
        return (
            <div
                className={join(
                    'w-full rounded-t-lg border bg-white px-4 pt-3 pb-0 dark:bg-zinc-900',
                    RULE_BORDER,
                    className,
                )}
            >
                {sectionLabel}
                {control}
            </div>
        );
    }

    if (appearance === 'stack') {
        return (
            <div
                className={join(
                    'w-full border-x border-b bg-white px-4 pt-3 pb-0 dark:bg-zinc-900',
                    RULE_BORDER,
                    className,
                )}
            >
                {sectionLabel}
                {control}
            </div>
        );
    }

    // framed — explicit full-width top + bottom rules; tray stays w-fit and flush to bottom rule.
    // contentClassName insets label/tray only so rules can span the full parent (e.g. card) width.
    return (
        <div className={join('w-full', className)}>
            {sectionLabel}
            <div className={join('w-full border-t', RULE_BORDER)} aria-hidden="true" />
            <div className={join('w-full border-b pt-3', RULE_BORDER)}>
                <div className={contentClassName || undefined}>{control}</div>
            </div>
        </div>
    );
}
