import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, Search } from 'lucide-react';

const normalize = (value) => (Array.isArray(value) ? value.map(String) : value ? [String(value)] : []);

export default function LookerMultiSelect({
    label,
    options = [],
    value = [],
    onApply,
    placeholder = 'Search...',
    allLabel,
    className = '',
    single = false,
    disabled = false,
}) {
    const wrapperRef = useRef(null);
    const dropdownRef = useRef(null);
    const selected = normalize(value);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [draft, setDraft] = useState(selected);
    const [dropdownStyle, setDropdownStyle] = useState({});

    useEffect(() => {
        setDraft(selected);
    }, [JSON.stringify(selected)]);

    useEffect(() => {
        const close = (event) => {
            if (!wrapperRef.current?.contains(event.target) && !dropdownRef.current?.contains(event.target)) {
                setOpen(false);
                setQuery('');
                setDraft(selected);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [JSON.stringify(selected)]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const positionDropdown = () => {
            const rect = wrapperRef.current?.getBoundingClientRect();

            if (!rect) {
                return;
            }

            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;
            const width = Math.min(544, Math.max(288, viewportWidth - 32));
            const left = Math.min(Math.max(16, rect.left), viewportWidth - width - 16);
            const dropdownHeight = Math.min(420, Math.max(220, viewportHeight - 64));
            const spaceBelow = viewportHeight - (rect.bottom + 16);
            const spaceAbove = rect.top - 16;
            const shouldOpenAbove = spaceBelow < dropdownHeight && spaceAbove > dropdownHeight;

            const top = shouldOpenAbove
                ? Math.max(16, rect.top - dropdownHeight - 8)
                : Math.min(rect.bottom + 8, viewportHeight - dropdownHeight - 16);

            setDropdownStyle({
                left: `${left}px`,
                top: `${Math.max(16, top)}px`,
                width: `${width}px`,
                maxHeight: `${dropdownHeight}px`,
            });
        };

        positionDropdown();
        window.addEventListener('resize', positionDropdown);
        window.addEventListener('scroll', positionDropdown, true);

        return () => {
            window.removeEventListener('resize', positionDropdown);
            window.removeEventListener('scroll', positionDropdown, true);
        };
    }, [open]);

    const normalizedOptions = useMemo(() => options.map((option) => {
        if (typeof option === 'string' || typeof option === 'number') {
            return {
                value: String(option),
                label: String(option),
                disabled: false,
            };
        }

        const optionValue = option?.value ?? option?.label ?? option?.name ?? option?.title ?? option?.id ?? '';
        const optionLabel = option?.label ?? option?.name ?? option?.title ?? option?.value ?? option?.id ?? '';

        return {
            ...option,
            value: String(optionValue),
            label: String(optionLabel),
            disabled: Boolean(option?.disabled),
        };
    }).filter((option) => option.value !== ''), [options]);

    const filteredOptions = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle) {
            return normalizedOptions;
        }

        return normalizedOptions.filter((option) => option.label.toLowerCase().includes(needle));
    }, [normalizedOptions, query]);

    const selectableOptions = useMemo(
        () => normalizedOptions.filter((option) => !option.disabled),
        [normalizedOptions],
    );

    const selectedLabels = selected
        .map((selectedValue) => normalizedOptions.find((option) => option.value === selectedValue)?.label ?? selectedValue)
        .filter(Boolean);
    const selectedSummary = selectedLabels.join(', ');

    const commit = (next, closeAfter = false) => {
        setDraft(next);
        onApply(next);

        if (closeAfter) {
            setOpen(false);
            setQuery('');
        }
    };

    const toggle = (optionValue, optionDisabled = false) => {
        if (optionDisabled) {
            return;
        }

        if (single) {
            commit([optionValue], true);
            return;
        }

        const next = draft.includes(optionValue)
            ? draft.filter((value) => value !== optionValue)
            : [...draft, optionValue];

        setDraft(next);
        onApply(next);
    };

    const clear = () => {
        setQuery('');
        commit([], single);
    };

    const selectAll = () => commit(selectableOptions.map((option) => option.value));
    const only = (optionValue) => commit([optionValue]);

    return (
        <div ref={wrapperRef} className={`relative min-w-0 text-sm font-medium ${className}`}>
            <span className="block min-w-0 break-words">{label}</span>
            <button
                type="button"
                disabled={disabled}
                onClick={() => setOpen((current) => !current)}
                className="dromic-select-control mt-1 flex min-h-[42px] w-full min-w-0 items-center justify-between gap-2 overflow-hidden rounded-md border border-slate-300 bg-white px-3 py-2 text-left text-sm font-semibold text-slate-700 shadow-sm transition hover:border-brand-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:disabled:bg-zinc-800"
            >
                <span className="dromic-select-value min-w-0 flex-1 truncate text-left" title={selectedSummary || (allLabel || `All ${label}`)}>
                    {selectedSummary || (allLabel || `All ${label}`)}
                </span>
                <ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
            </button>
            {open && createPortal((
                <div ref={dropdownRef} style={{ ...dropdownStyle, overflow: 'visible' }} className="fixed z-[99999] rounded-md border border-slate-200 bg-white p-3 shadow-2xl dark:border-zinc-700 dark:bg-zinc-950">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            className="w-full pl-9"
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={placeholder}
                            autoFocus
                        />
                    </div>
                    <div className="mt-2 flex flex-wrap gap-2 border-b border-slate-100 pb-2 dark:border-zinc-800">
                        {!single && (
                            <button type="button" onClick={selectAll} className="rounded bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-700 hover:bg-brand-50 hover:text-brand-700 dark:bg-zinc-800 dark:text-zinc-200">
                                Select all
                            </button>
                        )}
                        <button type="button" onClick={clear} className="rounded bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-700 hover:bg-rose-50 hover:text-rose-700 dark:bg-zinc-800 dark:text-zinc-200">
                            Clear
                        </button>
                    </div>
                    <div className="mt-2 max-h-[min(20rem,calc(100vh-8rem))] space-y-1 overflow-y-auto overflow-x-hidden pr-1">
                        {filteredOptions.map((option) => {
                            const checked = draft.includes(option.value);
                            const optionDisabled = Boolean(option.disabled);

                            return (
                                <div
                                    key={option.value}
                                    role="button"
                                    tabIndex={optionDisabled ? -1 : 0}
                                    aria-disabled={optionDisabled}
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        toggle(option.value, optionDisabled);
                                    }}
                                    onKeyDown={(event) => {
                                        if (optionDisabled) {
                                            return;
                                        }
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            event.stopPropagation();
                                            toggle(option.value, optionDisabled);
                                        }
                                    }}
                                    className={`group grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded px-2 py-1.5 text-left ${optionDisabled ? 'cursor-not-allowed opacity-55' : 'hover:bg-brand-50 dark:hover:bg-zinc-800'}`}
                                >
                                    <span className={`flex min-w-0 items-center gap-2 ${optionDisabled ? 'cursor-not-allowed' : 'cursor-pointer'}`}>
                                        <input
                                            type={single ? 'radio' : 'checkbox'}
                                            checked={checked}
                                            disabled={optionDisabled}
                                            readOnly
                                            tabIndex={-1}
                                            className="pointer-events-none"
                                        />
                                        <span className="min-w-0 whitespace-normal break-words text-sm font-semibold" title={option.label}>{option.label}</span>
                                    </span>
                                    {!single && !optionDisabled && (
                                        <button
                                            type="button"
                                            onMouseDown={(event) => {
                                                event.preventDefault();
                                                event.stopPropagation();
                                                only(option.value);
                                            }}
                                            className="inline-flex items-center gap-1 rounded px-2 py-1 text-[11px] font-black text-brand-700 opacity-0 transition hover:bg-white group-hover:opacity-100 dark:text-brand-100 dark:hover:bg-zinc-950"
                                        >
                                            <Check className="h-3 w-3" />
                                            Only
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                        {filteredOptions.length === 0 && <p className="px-2 py-4 text-center text-xs font-bold text-slate-500">No options found.</p>}
                    </div>
                </div>
            ), document.body)}
        </div>
    );
}
