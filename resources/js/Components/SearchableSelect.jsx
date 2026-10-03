import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown, Plus } from 'lucide-react';

const normalizeValues = (value) => {
    if (Array.isArray(value)) {
        return value.map(String).filter((item) => item.trim() !== '');
    }

    if (value === null || value === undefined || String(value).trim() === '') {
        return [];
    }

    return [String(value)];
};
const selectedDisplayLabel = (label) => String(label || '')
    .replace(/^Recommended\s*•\s*/i, '')
    .split(' • ')[0]
    .split(/\s*[·•]\s*/)[0]
    .trim();

export default function SearchableSelect({
    label,
    options = [],
    value,
    onChange,
    onSelect,
    placeholder = 'Select...',
    disabled = false,
    multiple = false,
    creatable = false,
    createLabel = 'Add new…',
    createInline = false,
    onCreate,
    creating = false,
    compact = false,
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [naming, setNaming] = useState(false);
    const [createDraft, setCreateDraft] = useState('');
    const [dropdownStyle, setDropdownStyle] = useState(null);
    const wrapperRef = useRef(null);
    const dropdownRef = useRef(null);
    const triggerRef = useRef(null);
    const selectedLabelRef = useRef('');
    const openRef = useRef(false);
    const selectedValues = normalizeValues(value);
    const selectedOptions = options.filter((option) => selectedValues.includes(String(option.value)));
    const selected = !multiple ? options.find((option) => String(option.value) === String(value)) : null;
    const summary = selectedOptions.map((option) => option.label).join(', ');
    const trimmedQuery = query.trim();
    const exactMatch = options.some(
        (option) => String(option.label || '').trim().toLowerCase() === trimmedQuery.toLowerCase()
            || String(option.value || '').trim().toLowerCase() === trimmedQuery.toLowerCase(),
    );
    const canOfferCreateFromQuery = creatable && !multiple && typeof onCreate === 'function' && trimmedQuery !== '' && !exactMatch;

    const isInsideControl = (target) => Boolean(
        target
        && (wrapperRef.current?.contains(target) || dropdownRef.current?.contains(target)),
    );

    const optionSearchText = (option) => [
        option?.label,
        option?.value,
        option?.description,
        option?.contact_number,
        option?.position,
        option?.office,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle || (!multiple && (
            query === selected?.label
            || query === selectedDisplayLabel(selected?.label || value)
        ))) {
            return options;
        }

        return options.filter((option) => optionSearchText(option).includes(needle));
    }, [options, query, multiple, selected?.label, value]);

    const closeMenu = ({ restoreFocus = false } = {}) => {
        openRef.current = false;
        setOpen(false);
        setNaming(false);
        setCreateDraft('');
        if (!multiple) {
            setQuery(selectedLabelRef.current);
        } else {
            setQuery('');
        }
        if (restoreFocus) {
            // Defer so Escape / outside-pointer handlers finish before reclaiming focus.
            window.requestAnimationFrame(() => triggerRef.current?.focus());
        }
    };

    const openMenu = () => {
        if (disabled) {
            return;
        }
        openRef.current = true;
        setOpen(true);
    };

    useEffect(() => {
        if (multiple) {
            return;
        }

        const displayLabel = selectedDisplayLabel(selected?.label || value || '');
        selectedLabelRef.current = displayLabel;
        setQuery(displayLabel);
    }, [multiple, selected?.label, value]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        // Capture phase: ancestors (e.g. modal panels) often stopPropagation on bubble,
        // which would otherwise prevent document listeners from seeing outside clicks.
        const onPointerDown = (event) => {
            if (isInsideControl(event.target)) {
                return;
            }

            closeMenu();
        };

        const onKeyDown = (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            closeMenu({ restoreFocus: true });
        };

        document.addEventListener('pointerdown', onPointerDown, true);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown, true);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open, multiple]);

    useEffect(() => {
        if (!open || disabled) {
            setDropdownStyle(null);
            return undefined;
        }

        const positionDropdown = () => {
            const rect = wrapperRef.current?.getBoundingClientRect();

            if (!rect) {
                return;
            }

            const viewportHeight = window.innerHeight;
            const maxHeight = 256;
            const gap = 4;
            const spaceBelow = viewportHeight - rect.bottom - 8;
            const spaceAbove = rect.top - 8;
            const openAbove = spaceBelow < Math.min(maxHeight, 160) && spaceAbove > spaceBelow;
            const available = Math.max(120, openAbove ? spaceAbove : spaceBelow);
            const height = Math.min(maxHeight, available);

            setDropdownStyle({
                position: 'fixed',
                left: `${rect.left}px`,
                width: `${Math.max(rect.width, 160)}px`,
                maxHeight: `${height}px`,
                zIndex: 1300,
                ...(openAbove
                    ? { bottom: `${viewportHeight - rect.top + gap}px`, top: 'auto' }
                    : { top: `${rect.bottom + gap}px`, bottom: 'auto' }),
            });
        };

        positionDropdown();
        window.addEventListener('resize', positionDropdown);
        window.addEventListener('scroll', positionDropdown, true);

        return () => {
            window.removeEventListener('resize', positionDropdown);
            window.removeEventListener('scroll', positionDropdown, true);
        };
    }, [open, disabled, filtered.length, creatable, canOfferCreateFromQuery]);

    const toggleMultiple = (optionValue) => {
        const next = selectedValues.includes(String(optionValue))
            ? selectedValues.filter((item) => item !== String(optionValue))
            : [...selectedValues, String(optionValue)];

        onChange(next);
    };

    const triggerCreate = (suggestedName = '') => {
        if (!creatable || multiple || typeof onCreate !== 'function' || creating || disabled) {
            return;
        }

        closeMenu({ restoreFocus: true });
        onCreate(String(suggestedName || '').trim());
    };

    // Option mousedown is canceled so the trigger does not blur before the click handler runs.
    // The menu itself must receive the click: a pointer-events-none shell lets the click
    // fall through onto a modal backdrop and dismiss the dialog instead of selecting.
    const keepOptionPointer = {
        onMouseDown: (event) => {
            event.preventDefault();
        },
    };

    const handleTriggerBlur = (event) => {
        const next = event.relatedTarget;
        if (isInsideControl(next)) {
            return;
        }

        window.requestAnimationFrame(() => {
            if (!openRef.current) {
                return;
            }
            if (isInsideControl(document.activeElement)) {
                return;
            }
            closeMenu();
        });
    };

    const menu = open && !disabled && dropdownStyle ? (
        <div
            ref={dropdownRef}
            style={dropdownStyle}
            className={`pointer-events-auto overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900 ${compact ? 'text-xs' : 'text-sm'}`}
            role="listbox"
            onMouseDown={(event) => {
                const tag = event.target?.tagName;
                event.stopPropagation();
                if (tag === 'INPUT' || tag === 'TEXTAREA') {
                    return;
                }
                event.preventDefault();
            }}
        >
            {multiple && (
                <div className="pointer-events-auto sticky top-0 z-10 border-b border-slate-100 bg-white p-2 dark:border-zinc-800 dark:bg-zinc-900">
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
            <div className="py-1">
                {filtered.length === 0 && !canOfferCreateFromQuery && (
                    <div className="px-3 py-2 text-slate-500 dark:text-zinc-400">No matches</div>
                )}
                {filtered.map((option) => {
                    const optionValue = String(option.value);
                    const checked = selectedValues.includes(optionValue);
                    const labelParts = String(option.label || '').split(' • ');
                    const isRecommended = !multiple && labelParts[0] === 'Recommended' && labelParts.length > 1;

                    if (multiple) {
                        return (
                            <button
                                type="button"
                                key={optionValue}
                                className="pointer-events-auto flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-100 dark:hover:bg-zinc-800"
                                {...keepOptionPointer}
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
                            className={`pointer-events-auto block w-full border-l-4 px-3 py-2 text-left transition ${isRecommended ? 'border-emerald-600 bg-emerald-50 hover:bg-emerald-100 dark:border-emerald-500 dark:bg-emerald-950/40 dark:hover:bg-emerald-950/60' : 'border-transparent hover:bg-slate-100 dark:hover:bg-zinc-800'}`}
                            {...keepOptionPointer}
                            onClick={() => {
                                onChange(option.value);
                                onSelect?.(option);
                                selectedLabelRef.current = selectedDisplayLabel(option.label || option.value);
                                setQuery(selectedLabelRef.current);
                                closeMenu({ restoreFocus: true });
                            }}
                        >
                            {isRecommended ? (
                                <span className="block">
                                    <span className="block text-[9px] font-black uppercase tracking-[.16em] text-emerald-700 dark:text-emerald-300">Recommended source</span>
                                    <span className="mt-0.5 block font-black text-slate-900 dark:text-white">{labelParts[1]}</span>
                                    {labelParts.length > 2 && <span className="mt-0.5 block text-[11px] font-semibold text-emerald-800 dark:text-emerald-200">{labelParts.slice(2).join(' • ')}</span>}
                                </span>
                            ) : (
                                <span className="block">
                                    <span className="block font-semibold text-slate-700 dark:text-zinc-100">{option.label}</span>
                                    {option.description ? (
                                        <span className="mt-0.5 block text-[11px] font-semibold text-slate-400 dark:text-zinc-400">
                                            {option.description}
                                        </span>
                                    ) : null}
                                </span>
                            )}
                        </button>
                    );
                })}
                {canOfferCreateFromQuery && (
                    <button
                        type="button"
                        disabled={creating}
                        className="pointer-events-auto flex w-full items-center gap-2 border-t border-slate-100 px-3 py-2 text-left font-semibold text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-800 dark:text-emerald-300 dark:hover:bg-emerald-950/40"
                        {...keepOptionPointer}
                        onClick={() => triggerCreate(trimmedQuery)}
                    >
                        <Plus className="h-3.5 w-3.5 shrink-0" />
                        <span className="min-w-0">Add &ldquo;{trimmedQuery}&rdquo;</span>
                    </button>
                )}
            </div>
            {creatable && !multiple && typeof onCreate === 'function' && (
                <div className="sticky bottom-0 z-10 border-t border-slate-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    {createInline && naming ? (
                        <div className="pointer-events-auto flex items-center gap-2 p-2">
                            <input
                                className="form-input min-w-0 flex-1"
                                value={createDraft}
                                autoFocus
                                placeholder="Type the recipient name"
                                onChange={(event) => setCreateDraft(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key !== 'Enter') {
                                        return;
                                    }
                                    event.preventDefault();
                                    event.stopPropagation();
                                    const name = createDraft.trim();
                                    if (!name) {
                                        return;
                                    }
                                    setCreateDraft('');
                                    setNaming(false);
                                    triggerCreate(name);
                                }}
                            />
                            <button
                                type="button"
                                disabled={creating || createDraft.trim() === ''}
                                className="rounded-md bg-emerald-700 px-2.5 py-1.5 text-[11px] font-black text-white disabled:opacity-50"
                                onClick={() => {
                                    const name = createDraft.trim();
                                    if (!name) {
                                        return;
                                    }
                                    setCreateDraft('');
                                    setNaming(false);
                                    triggerCreate(name);
                                }}
                            >
                                Add
                            </button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            disabled={creating}
                            className="pointer-events-auto flex w-full items-center gap-2 px-3 py-2 text-left text-[12px] font-black uppercase tracking-wide text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60 dark:text-emerald-300 dark:hover:bg-emerald-950/40"
                            {...keepOptionPointer}
                            onClick={() => {
                                if (trimmedQuery && !exactMatch) {
                                    triggerCreate(trimmedQuery);
                                    return;
                                }
                                if (createInline) {
                                    setNaming(true);
                                    return;
                                }
                                triggerCreate('');
                            }}
                        >
                            <Plus className="h-3.5 w-3.5 shrink-0" />
                            {creating ? 'Adding…' : createLabel}
                        </button>
                    )}
                </div>
            )}
            {multiple && selectedValues.length > 0 && (
                <div className="sticky bottom-0 z-10 flex items-center justify-between border-t border-slate-100 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900">
                    <span className="text-[11px] font-bold text-slate-500">{selectedValues.length} selected</span>
                    <button
                        type="button"
                        className="pointer-events-auto text-[11px] font-black text-rose-600 hover:text-rose-700"
                        onClick={() => onChange([])}
                    >
                        Clear
                    </button>
                </div>
            )}
        </div>
    ) : null;

    return (
        <div className="relative block text-sm font-medium" ref={wrapperRef}>
            {label ? <div className="mb-1">{label}</div> : null}
            <div className="relative">
                {multiple ? (
                    <button
                        ref={triggerRef}
                        type="button"
                        disabled={disabled}
                        className={`flex w-full items-center justify-between gap-2 rounded-md border border-slate-300 bg-white text-left font-semibold text-slate-700 shadow-sm transition hover:border-brand-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:disabled:bg-zinc-800 ${compact ? 'min-h-[36px] px-2 py-1.5 pr-8 text-xs' : 'min-h-[42px] px-3 py-2 pr-10 text-sm'}`}
                        onClick={() => {
                            if (disabled) {
                                return;
                            }
                            if (open) {
                                closeMenu();
                                return;
                            }
                            openMenu();
                        }}
                        onBlur={handleTriggerBlur}
                        aria-expanded={open}
                        aria-haspopup="listbox"
                    >
                        <span className={`min-w-0 flex-1 truncate ${summary ? '' : 'font-medium text-slate-400 dark:text-zinc-500'}`} title={summary || placeholder}>
                            {summary || placeholder}
                        </span>
                    </button>
                ) : (
                    <input
                        ref={triggerRef}
                        className={`w-full disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:disabled:bg-zinc-800 ${compact ? 'min-h-[36px] px-2 py-1.5 pr-8 text-xs' : 'pr-10'}`}
                        disabled={disabled}
                        placeholder={placeholder}
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            openMenu();
                        }}
                        onFocus={openMenu}
                        onBlur={handleTriggerBlur}
                        role="combobox"
                        aria-expanded={open}
                        aria-haspopup="listbox"
                        autoComplete="off"
                    />
                )}
                <button
                    type="button"
                    disabled={disabled}
                    className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md text-slate-400 transition hover:text-slate-700 dark:text-zinc-500 dark:hover:text-zinc-200"
                    onMouseDown={(event) => {
                        event.preventDefault();
                    }}
                    onClick={() => {
                        if (disabled) {
                            return;
                        }
                        if (open) {
                            closeMenu({ restoreFocus: true });
                            return;
                        }
                        openMenu();
                        triggerRef.current?.focus();
                    }}
                    tabIndex={-1}
                    aria-label={open ? 'Close options' : 'Open options'}
                >
                    <ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>
            </div>
            {typeof document !== 'undefined' && menu ? createPortal(menu, document.body) : null}
        </div>
    );
}
