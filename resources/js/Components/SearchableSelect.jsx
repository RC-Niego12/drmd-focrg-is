import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown } from 'lucide-react';

const normalizeValues = (value) => {
    if (Array.isArray(value)) {
        return value.map(String).filter((item) => item.trim() !== '');
    }

    if (value === null || value === undefined || String(value).trim() === '') {
        return [];
    }

    return [String(value)];
};

export default function SearchableSelect({
    label,
    options = [],
    value,
    onChange,
    placeholder = 'Select...',
    disabled = false,
    multiple = false,
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const wrapperRef = useRef(null);
    const selectedLabelRef = useRef('');
    const selectedValues = normalizeValues(value);
    const selectedOptions = options.filter((option) => selectedValues.includes(String(option.value)));
    const selected = !multiple ? options.find((option) => String(option.value) === String(value)) : null;
    const summary = selectedOptions.map((option) => option.label).join(', ');

    useEffect(() => {
        if (multiple) {
            return;
        }

        selectedLabelRef.current = selected?.label ?? '';
        setQuery(selected?.label ?? '');
    }, [multiple, selected?.label]);

    useEffect(() => {
        const close = (event) => {
            if (!wrapperRef.current?.contains(event.target)) {
                setOpen(false);
                if (!multiple) {
                    setQuery(selectedLabelRef.current);
                } else {
                    setQuery('');
                }
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [multiple]);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle || (!multiple && query === selected?.label)) {
            return options;
        }

        return options.filter((option) => String(option.label || '').toLowerCase().includes(needle));
    }, [options, query, multiple, selected?.label]);

    const toggleMultiple = (optionValue) => {
        const next = selectedValues.includes(String(optionValue))
            ? selectedValues.filter((item) => item !== String(optionValue))
            : [...selectedValues, String(optionValue)];

        onChange(next);
    };

    return (
        <label className="relative block text-sm font-medium" ref={wrapperRef}>
            {label}
            <div className="relative mt-1">
                {multiple ? (
                    <button
                        type="button"
                        disabled={disabled}
                        className="flex min-h-[42px] w-full items-center justify-between gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 pr-10 text-left text-sm font-semibold text-slate-700 shadow-sm transition hover:border-brand-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:disabled:bg-zinc-800"
                        onClick={() => !disabled && setOpen((current) => !current)}
                        aria-expanded={open}
                    >
                        <span className={`min-w-0 flex-1 truncate ${summary ? '' : 'font-medium text-slate-400 dark:text-zinc-500'}`} title={summary || placeholder}>
                            {summary || placeholder}
                        </span>
                    </button>
                ) : (
                    <input
                        className="w-full pr-10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:disabled:bg-zinc-800"
                        disabled={disabled}
                        placeholder={placeholder}
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setOpen(true);
                        }}
                        onFocus={() => !disabled && setOpen(true)}
                        role="combobox"
                        aria-expanded={open}
                    />
                )}
                <button
                    type="button"
                    disabled={disabled}
                    className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md text-slate-400 transition hover:text-slate-700 dark:text-zinc-500 dark:hover:text-zinc-200"
                    onClick={() => !disabled && setOpen((current) => !current)}
                    tabIndex={-1}
                >
                    <ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>
            </div>
            {open && !disabled && (
                <div className="absolute z-30 mt-1 max-h-64 w-full overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                    {multiple && (
                        <div className="border-b border-slate-100 p-2 dark:border-zinc-800">
                            <input
                                className="w-full"
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Search..."
                                autoFocus
                            />
                        </div>
                    )}
                    <div className="max-h-56 overflow-auto py-1">
                        {filtered.length === 0 && <div className="px-3 py-2 text-slate-500 dark:text-zinc-400">No matches</div>}
                        {filtered.map((option) => {
                            const optionValue = String(option.value);
                            const checked = selectedValues.includes(optionValue);

                            if (multiple) {
                                return (
                                    <button
                                        type="button"
                                        key={optionValue}
                                        className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-100 dark:hover:bg-zinc-800"
                                        onClick={() => toggleMultiple(optionValue)}
                                    >
                                        <span className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 text-transparent dark:border-zinc-600'}`}>
                                            <Check className="h-3 w-3" />
                                        </span>
                                        <span className="min-w-0 flex-1 whitespace-normal break-words font-semibold text-slate-700 dark:text-zinc-100">{option.label}</span>
                                    </button>
                                );
                            }

                            return (
                                <button
                                    type="button"
                                    key={optionValue}
                                    className="block w-full px-3 py-2 text-left hover:bg-slate-100 dark:hover:bg-zinc-800"
                                    onClick={() => {
                                        onChange(option.value);
                                        setQuery(option.label);
                                        setOpen(false);
                                    }}
                                >
                                    {option.label}
                                </button>
                            );
                        })}
                    </div>
                    {multiple && selectedValues.length > 0 && (
                        <div className="flex items-center justify-between border-t border-slate-100 px-3 py-2 dark:border-zinc-800">
                            <span className="text-[11px] font-bold text-slate-500">{selectedValues.length} selected</span>
                            <button
                                type="button"
                                className="text-[11px] font-black text-rose-600 hover:text-rose-700"
                                onClick={() => onChange([])}
                            >
                                Clear
                            </button>
                        </div>
                    )}
                </div>
            )}
        </label>
    );
}
