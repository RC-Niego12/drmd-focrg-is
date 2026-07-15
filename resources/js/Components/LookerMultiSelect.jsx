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
            const top = Math.min(rect.bottom + 8, viewportHeight - 180);

            setDropdownStyle({
                left: `${left}px`,
                top: `${Math.max(16, top)}px`,
                width: `${width}px`,
                maxHeight: `${Math.max(180, viewportHeight - Math.max(16, top) - 16)}px`,
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

    const normalizedOptions = useMemo(() => options.map((option) => ({
        ...option,
        value: String(option.value),
        label: String(option.label ?? option.value),
    })), [options]);

    const filteredOptions = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle) {
            return normalizedOptions;
        }

        return normalizedOptions.filter((option) => option.label.toLowerCase().includes(needle));
    }, [normalizedOptions, query]);

    const selectedLabels = draft
        .map((selectedValue) => normalizedOptions.find((option) => option.value === selectedValue)?.label)
        .filter(Boolean);

    const commit = (next) => {
        setDraft(next);
        onApply(next);
    };

    const toggle = (optionValue) => {
        setDraft((current) => {
            const next = current.includes(optionValue)
                ? current.filter((value) => value !== optionValue)
                : [...current, optionValue];

            onApply(next);

            return next;
        });
    };

    const clear = () => {
        setQuery('');
        commit([]);
    };

    const selectAll = () => commit(normalizedOptions.map((option) => option.value));
    const only = (optionValue) => commit([optionValue]);

    return (
        <div ref={wrapperRef} className={`relative min-w-0 text-sm font-medium ${className}`}>
            <span>{label}</span>
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                className="mt-1 flex min-h-[42px] w-full items-center justify-between gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-left text-sm font-semibold text-slate-700 shadow-sm transition hover:border-brand-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
            >
                <span className="min-w-0 truncate">
                    {selectedLabels.length > 0 ? `${selectedLabels.length} selected` : (allLabel || `All ${label}`)}
                </span>
                <ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
            </button>
            {selectedLabels.length > 0 && (
                <p className="mt-1 truncate text-xs font-semibold text-slate-500 dark:text-zinc-400" title={selectedLabels.join(', ')}>
                    {selectedLabels.join(', ')}
                </p>
            )}
            {open && createPortal((
                <div ref={dropdownRef} style={dropdownStyle} className="fixed z-[120] overflow-hidden rounded-md border border-slate-200 bg-white p-3 shadow-2xl dark:border-zinc-700 dark:bg-zinc-950">
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
                        <button type="button" onClick={selectAll} className="rounded bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-700 hover:bg-brand-50 hover:text-brand-700 dark:bg-zinc-800 dark:text-zinc-200">
                            Select all
                        </button>
                        <button type="button" onClick={clear} className="rounded bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-700 hover:bg-rose-50 hover:text-rose-700 dark:bg-zinc-800 dark:text-zinc-200">
                            Clear
                        </button>
                    </div>
                    <div className="mt-2 max-h-[min(18rem,calc(100vh-12rem))] space-y-1 overflow-y-auto pr-1">
                        {filteredOptions.map((option) => {
                            const checked = draft.includes(option.value);

                            return (
                                <div key={option.value} className="group grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded px-2 py-1.5 hover:bg-brand-50 dark:hover:bg-zinc-800">
                                    <label className="flex min-w-0 cursor-pointer items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={checked}
                                            onChange={() => toggle(option.value)}
                                        />
                                        <span className="min-w-0 whitespace-normal break-words text-sm font-semibold" title={option.label}>{option.label}</span>
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() => only(option.value)}
                                        className="inline-flex items-center gap-1 rounded px-2 py-1 text-[11px] font-black text-brand-700 opacity-0 transition hover:bg-white group-hover:opacity-100 dark:text-brand-100 dark:hover:bg-zinc-950"
                                    >
                                        <Check className="h-3 w-3" />
                                        Only
                                    </button>
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
