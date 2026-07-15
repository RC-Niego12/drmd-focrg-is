import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';

export default function SearchableSelect({ label, options, value, onChange, placeholder = 'Select...', disabled = false }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const wrapperRef = useRef(null);
    const selectedLabelRef = useRef('');
    const selected = options.find((option) => String(option.value) === String(value));

    useEffect(() => {
        selectedLabelRef.current = selected?.label ?? '';
        setQuery(selected?.label ?? '');
    }, [selected?.label]);

    useEffect(() => {
        const close = (event) => {
            if (!wrapperRef.current?.contains(event.target)) {
                setOpen(false);
                setQuery(selectedLabelRef.current);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle || query === selected?.label) {
            return options;
        }

        return options.filter((option) => option.label.toLowerCase().includes(needle));
    }, [options, query, selected?.label]);

    return (
        <label className="relative block text-sm font-medium" ref={wrapperRef}>
            {label}
            <div className="relative mt-1">
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
                <div className="absolute z-30 mt-1 max-h-56 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                    {filtered.length === 0 && <div className="px-3 py-2 text-slate-500 dark:text-zinc-400">No matches</div>}
                    {filtered.map((option) => (
                        <button
                            type="button"
                            key={option.value}
                            className="block w-full px-3 py-2 text-left hover:bg-slate-100 dark:hover:bg-zinc-800"
                            onClick={() => {
                                onChange(option.value);
                                setQuery(option.label);
                                setOpen(false);
                            }}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            )}
        </label>
    );
}
