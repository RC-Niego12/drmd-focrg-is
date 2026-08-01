import { useEffect, useMemo, useRef, useState } from 'react';
import { X } from 'lucide-react';
import LookerMultiSelect from '@/Components/LookerMultiSelect';

const sanitizeCodes = (value = []) => [...new Set(
    (Array.isArray(value) ? value : [])
        .filter((item) => item !== null && item !== undefined && String(item).trim() !== '')
        .map((item) => String(item).trim()),
)];

const normalizeRows = (payload) => {
    const rows = Array.isArray(payload) ? payload : Array.isArray(payload?.data) ? payload.data : [];

    return rows
        .map((row) => {
            const code = String(row?.code ?? row?.value ?? row?.id ?? '').trim();
            const label = String(row?.name ?? row?.label ?? row?.short_name ?? row?.value ?? row?.code ?? '').trim();

            if (!code || !label) {
                return null;
            }

            return {
                code,
                label,
                value: code,
                parent_code: row?.parent_code ? String(row.parent_code) : null,
                district_code: row?.district_code
                    ? String(row.district_code)
                    : (row?.districtCode ? String(row.districtCode) : null),
                district: row?.district ?? null,
                raw: row,
            };
        })
        .filter(Boolean);
};

async function fetchPsgc(url) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`PSGC request failed: ${url}`);
    }

    return normalizeRows(await response.json());
}

function mergeCatalog(current, rows = [], extras = {}) {
    const next = { ...current };

    rows.forEach((row) => {
        next[row.code] = {
            ...(next[row.code] || {}),
            ...row,
            ...extras,
            code: row.code,
            label: row.label,
            value: row.code,
        };
    });

    return next;
}

function cityBelongsToSelectedDistrict(cityCode, districtCodes, catalog) {
    const selectedDistricts = new Set(sanitizeCodes(districtCodes));
    if (!selectedDistricts.size) {
        return false;
    }

    const entry = catalog[String(cityCode)];
    return Boolean(entry?.district_code && selectedDistricts.has(String(entry.district_code)));
}

function getAorSelectionErrors({ provinceCodes = [], districtCodes = [], cityCodes = [], catalog = {} }) {
    const provinces = sanitizeCodes(provinceCodes);
    const districts = sanitizeCodes(districtCodes);
    const cities = sanitizeCodes(cityCodes).filter((code) => cityBelongsToSelectedDistrict(code, districts, catalog));
    const errors = [];

    if (!provinces.length) {
        errors.push('Province is required. Select at least one province.');
    }

    if (provinces.length && !districts.length) {
        errors.push('District is required. Select at least one district for each selected province.');
    }

    if (districts.length && !cities.length) {
        errors.push('Municipality / City is required. Select at least one city/municipality for each selected district.');
    }

    const districtLinksReady = districts.every((districtCode) => {
        const entry = catalog[districtCode];
        return Boolean(entry?.province_code || entry?.parent_code);
    });

    if (districtLinksReady) {
        provinces.forEach((provinceCode) => {
            const provinceLabel = catalog[provinceCode]?.label || 'selected province';
            const hasDistrict = districts.some((districtCode) => {
                const entry = catalog[districtCode];
                return String(entry?.province_code || entry?.parent_code || '') === String(provinceCode);
            });

            if (!hasDistrict) {
                errors.push(`Select at least one district under ${provinceLabel}.`);
            }
        });
    }

    const cityLinksReady = cities.every((cityCode) => Boolean(catalog[cityCode]?.district_code))
        || cities.length === 0;

    if (cityLinksReady && cities.length > 0) {
        districts.forEach((districtCode) => {
            const districtLabel = catalog[districtCode]?.label || 'selected district';
            const hasCity = cities.some((cityCode) => {
                const entry = catalog[cityCode];
                return String(entry?.district_code || '') === String(districtCode);
            });

            if (!hasCity) {
                errors.push(`Select at least one city/municipality under ${districtLabel}.`);
            }
        });
    } else if (districts.length && cities.length > 0 && !cityLinksReady) {
        // Wait for catalog links before enforcing per-district city coverage.
    } else if (districts.length && cities.length === 0) {
        // Already covered by the required city message above.
    }

    return [...new Set(errors)];
}

function buildGroupedAor({ provinceCodes, districtCodes, cityCodes, catalog }) {
    const provinceMap = new Map();
    const selectedDistricts = sanitizeCodes(districtCodes);
    const selectedCities = sanitizeCodes(cityCodes).filter((cityCode) => (
        cityBelongsToSelectedDistrict(cityCode, selectedDistricts, catalog)
    ));

    const ensureProvince = (provinceCode, fallbackLabel = null) => {
        const key = String(provinceCode || '__unknown__');
        if (!provinceMap.has(key)) {
            const entry = catalog[key];
            provinceMap.set(key, {
                code: key,
                label: entry?.label || fallbackLabel || key,
                districts: [],
            });
        }

        return provinceMap.get(key);
    };

    const ensureDistrict = (province, districtCode, fallbackLabel = null) => {
        const key = String(districtCode);
        let district = province.districts.find((entry) => entry.code === key);

        if (!district) {
            const entry = catalog[key];
            district = {
                code: key,
                label: entry?.label || fallbackLabel || key,
                cities: [],
            };
            province.districts.push(district);
        }

        return district;
    };

    sanitizeCodes(provinceCodes).forEach((provinceCode) => {
        ensureProvince(provinceCode);
    });

    selectedDistricts.forEach((districtCode) => {
        const entry = catalog[districtCode];
        const provinceCode = entry?.province_code || entry?.parent_code || '__unknown__';
        const province = ensureProvince(provinceCode, entry?.province_label || null);
        ensureDistrict(province, districtCode, entry?.label || null);
    });

    selectedCities.forEach((cityCode) => {
        const entry = catalog[cityCode];
        if (!entry?.district_code || !selectedDistricts.includes(String(entry.district_code))) {
            return;
        }

        const provinceCode = entry?.province_code || entry?.parent_code || '__unknown__';
        const province = ensureProvince(provinceCode, entry?.province_label || null);
        const district = ensureDistrict(province, entry.district_code, catalog[entry.district_code]?.label || entry.district_code);

        if (!district.cities.some((city) => city.code === cityCode)) {
            district.cities.push({
                code: cityCode,
                label: entry?.label || cityCode,
            });
        }
    });

    return [...provinceMap.values()]
        .map((province) => ({
            ...province,
            districts: province.districts
                .map((district) => ({
                    ...district,
                    cities: [...district.cities].sort((left, right) => left.label.localeCompare(right.label)),
                }))
                .sort((left, right) => left.label.localeCompare(right.label)),
        }))
        .sort((left, right) => left.label.localeCompare(right.label));
}

function AorHierarchyPreview({ groups = [], emptyLabel = 'No AOR entries selected' }) {
    if (!groups.length) {
        return (
            <div className="rounded-xl border border-dashed border-slate-200 bg-white p-3 text-sm font-bold text-slate-500 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-400">
                {emptyLabel}
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {groups.map((province) => (
                <div key={province.code} className="rounded-xl border border-emerald-200 bg-white p-3 dark:border-emerald-900 dark:bg-zinc-950">
                    <div className="flex flex-wrap items-center gap-2 pb-3">
                        <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">Province</span>
                        <span className="text-sm font-black text-slate-800 dark:text-zinc-100">{province.label}</span>
                    </div>

                    <div className="ml-1 space-y-3 border-l-2 border-emerald-100 pl-3 dark:border-emerald-900/70">
                        {province.districts.length ? province.districts.map((district) => (
                            <div key={district.code} className="rounded-lg border border-violet-100 bg-violet-50/60 p-2.5 dark:border-violet-900 dark:bg-violet-950/40">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="rounded-full border border-violet-200 bg-violet-50 px-2 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-violet-700 dark:border-violet-900 dark:bg-violet-950 dark:text-violet-100">District</span>
                                    <span className="text-sm font-black text-slate-800 dark:text-zinc-100">{district.label}</span>
                                </div>

                                <div className="mt-3 rounded-lg border border-sky-100 bg-sky-50/60 p-2.5 dark:border-sky-900 dark:bg-sky-950/40">
                                    <p className="text-[10px] font-black uppercase tracking-[0.18em] text-sky-700 dark:text-sky-200">Cities / Municipalities</p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {district.cities.length ? district.cities.map((city) => (
                                            <span key={city.code} className="rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-800 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-100">
                                                {city.label}
                                            </span>
                                        )) : (
                                            <span className="text-xs font-bold text-slate-500">No cities / municipalities selected</span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )) : (
                            <div className="rounded-lg border border-dashed border-slate-200 bg-white p-3 text-sm font-bold text-slate-500 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-400">
                                No districts selected
                            </div>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}

export default function AorScopeFields({
    regionCode = '1600000000',
    value,
    onChange,
    occupiedScopes = [],
    disabled = false,
    showEditors = true,
    onValidityChange,
}) {
    const [catalog, setCatalog] = useState({});
    const [provinceRows, setProvinceRows] = useState([]);
    const [districtRows, setDistrictRows] = useState([]);
    const [cityRows, setCityRows] = useState([]);
    const [loading, setLoading] = useState('');
    const [error, setError] = useState('');
    const prunedRef = useRef('');

    const provinceCodes = sanitizeCodes(value?.aor_provinces);
    const districtCodes = sanitizeCodes(value?.aor_districts);
    const cityCodes = sanitizeCodes(value?.aor_cities_municipalities);

    const occupied = useMemo(() => {
        const reserved = {
            province: new Set(),
            district: new Set(),
            city_municipality: new Set(),
        };

        (occupiedScopes || []).forEach((scope) => {
            const level = String(scope?.level || '');
            const code = String(scope?.psgc_code || '');
            if (reserved[level] && code) {
                reserved[level].add(code);
            }
        });

        return reserved;
    }, [occupiedScopes]);

    const citiesClaimedByOccupiedDistricts = useMemo(() => {
        const claimed = new Set();

        const markFromRows = (rows = []) => {
            rows.forEach((city) => {
                if (city.district_code && occupied.district.has(String(city.district_code))) {
                    claimed.add(city.code);
                }
            });
        };

        markFromRows(cityRows);
        provinceRows.forEach((province) => markFromRows(province.child_cities || []));

        Object.values(catalog).forEach((entry) => {
            if (entry?.district_code && occupied.district.has(String(entry.district_code))) {
                claimed.add(entry.code);
            }
        });

        return claimed;
    }, [cityRows, provinceRows, catalog, occupied]);

    const isCityOccupied = (code) => {
        const key = String(code);
        if (occupied.city_municipality.has(key) || citiesClaimedByOccupiedDistricts.has(key)) {
            return true;
        }

        const entry = catalog[key];
        return Boolean(entry?.district_code && occupied.district.has(String(entry.district_code)));
    };
    const isDistrictOccupied = (code) => occupied.district.has(String(code));

    const setScope = (next) => {
        onChange({
            aor_provinces: sanitizeCodes(next.aor_provinces),
            aor_districts: sanitizeCodes(next.aor_districts),
            aor_cities_municipalities: sanitizeCodes(next.aor_cities_municipalities),
        });
    };

    useEffect(() => {
        let cancelled = false;

        const loadProvinces = async () => {
            setLoading('provinces');
            setError('');

            try {
                const rows = await fetchPsgc(`/profile/psgc/regions/${regionCode}/provinces`);
                if (cancelled) {
                    return;
                }

                const enriched = await Promise.all(rows.map(async (row) => {
                    const [districts, cities] = await Promise.all([
                        fetchPsgc(`/profile/psgc/provinces/${row.code}/districts`),
                        fetchPsgc(`/profile/psgc/provinces/${row.code}/cities-municipalities`),
                    ]);

                    return {
                        ...row,
                        level: 'province',
                        province_code: row.code,
                        child_districts: districts.map((district) => ({
                            ...district,
                            level: 'district',
                            province_code: row.code,
                            province_label: row.label,
                            parent_code: row.code,
                        })),
                        child_cities: cities.map((city) => ({
                            ...city,
                            level: 'city_municipality',
                            province_code: row.code,
                            province_label: row.label,
                            parent_code: city.parent_code || row.code,
                        })),
                    };
                }));

                if (cancelled) {
                    return;
                }

                setProvinceRows(enriched);
                setCatalog((current) => {
                    let next = mergeCatalog(current, enriched.map((row) => ({
                        ...row,
                        level: 'province',
                        province_code: row.code,
                    })));

                    enriched.forEach((province) => {
                        next = mergeCatalog(next, province.child_districts || []);
                        next = mergeCatalog(next, province.child_cities || []);
                    });

                    return next;
                });
            } catch (exception) {
                if (!cancelled) {
                    setProvinceRows([]);
                    setError('Unable to load province options.');
                }
            } finally {
                if (!cancelled) {
                    setLoading('');
                }
            }
        };

        loadProvinces();

        return () => {
            cancelled = true;
        };
    }, [regionCode]);

    useEffect(() => {
        let cancelled = false;

        if (!provinceCodes.length) {
            setDistrictRows([]);
            return undefined;
        }

        const loadDistricts = async () => {
            setLoading('districts');
            setError('');

            try {
                const bundles = await Promise.all(provinceCodes.map(async (provinceCode) => {
                    const rows = await fetchPsgc(`/profile/psgc/provinces/${provinceCode}/districts`);
                    const provinceLabel = catalog[provinceCode]?.label
                        || provinceRows.find((row) => row.code === provinceCode)?.label
                        || provinceCode;

                    return rows.map((row) => ({
                        ...row,
                        level: 'district',
                        province_code: provinceCode,
                        province_label: provinceLabel,
                        parent_code: provinceCode,
                    }));
                }));

                if (cancelled) {
                    return;
                }

                const merged = [...new Map(bundles.flat().map((row) => [row.code, row])).values()];
                setDistrictRows(merged);
                setCatalog((current) => mergeCatalog(current, merged));
            } catch (exception) {
                if (!cancelled) {
                    setDistrictRows([]);
                    setError('Unable to load district options.');
                }
            } finally {
                if (!cancelled) {
                    setLoading('');
                }
            }
        };

        loadDistricts();

        return () => {
            cancelled = true;
        };
    }, [JSON.stringify(provinceCodes), provinceRows, regionCode]);

    useEffect(() => {
        let cancelled = false;

        if (!districtCodes.length) {
            setCityRows([]);
            return undefined;
        }

        const loadCities = async () => {
            setLoading('cities');
            setError('');

            try {
                const districtBundles = await Promise.all(districtCodes.map(async (districtCode) => {
                    const rows = await fetchPsgc(`/profile/psgc/districts/${districtCode}/cities-municipalities`);
                    const district = districtRows.find((row) => row.code === districtCode) || catalog[districtCode] || {};

                    return rows.map((row) => ({
                        ...row,
                        level: 'city_municipality',
                        district_code: row.district_code || districtCode,
                        province_code: district.province_code || district.parent_code || row.parent_code || null,
                        province_label: district.province_label
                            || catalog[district.province_code || district.parent_code]?.label
                            || null,
                        parent_code: row.parent_code || district.province_code || district.parent_code || null,
                    }));
                }));

                if (cancelled) {
                    return;
                }

                const merged = [...new Map(districtBundles.flat().map((row) => [row.code, row])).values()];
                setCityRows(merged);
                setCatalog((current) => mergeCatalog(current, merged));
            } catch (exception) {
                if (!cancelled) {
                    setCityRows([]);
                    setError('Unable to load city / municipality options.');
                }
            } finally {
                if (!cancelled) {
                    setLoading('');
                }
            }
        };

        loadCities();

        return () => {
            cancelled = true;
        };
    }, [JSON.stringify(districtCodes), JSON.stringify(districtRows.map((row) => row.code))]);

    // Drop cities that do not belong to currently selected districts (fixes SDS2 leakage).
    useEffect(() => {
        if (!Object.keys(catalog).length) {
            return;
        }

        const signature = JSON.stringify({ provinceCodes, districtCodes, cityCodes });
        if (prunedRef.current === signature) {
            return;
        }

        const validCities = cityCodes.filter((code) => cityBelongsToSelectedDistrict(code, districtCodes, catalog));
        const citiesChanged = validCities.length !== cityCodes.length
            || validCities.some((code, index) => code !== cityCodes[index]);

        prunedRef.current = signature;

        if (!citiesChanged) {
            return;
        }

        setScope({
            aor_provinces: provinceCodes,
            aor_districts: districtCodes,
            aor_cities_municipalities: validCities,
        });
    }, [catalog, JSON.stringify(provinceCodes), JSON.stringify(districtCodes), JSON.stringify(cityCodes)]);

    const provinceOptions = useMemo(() => {
        return provinceRows
            .filter((row) => {
                if (provinceCodes.includes(row.code)) {
                    return true;
                }

                const districtsForProvince = row.child_districts || [];
                const citiesForProvince = row.child_cities || [];

                const allDistrictsTaken = districtsForProvince.length > 0
                    && districtsForProvince.every((district) => isDistrictOccupied(district.code));
                const allCitiesTaken = citiesForProvince.length > 0
                    && citiesForProvince.every((city) => isCityOccupied(city.code));

                if (districtsForProvince.length && citiesForProvince.length) {
                    return !(allDistrictsTaken && allCitiesTaken);
                }

                if (districtsForProvince.length) {
                    return !allDistrictsTaken;
                }

                if (citiesForProvince.length) {
                    return !allCitiesTaken;
                }

                return !occupied.province.has(row.code);
            })
            .map((row) => ({ value: row.code, label: row.label }));
    }, [provinceRows, provinceCodes, occupied, citiesClaimedByOccupiedDistricts]);

    const districtOptions = useMemo(() => {
        return districtRows
            .filter((row) => provinceCodes.includes(row.province_code || row.parent_code))
            .filter((row) => districtCodes.includes(row.code) || !isDistrictOccupied(row.code))
            .map((row) => ({
                value: row.code,
                label: row.label,
            }));
    }, [districtRows, provinceCodes, districtCodes, occupied]);

    const cityOptions = useMemo(() => {
        const allowedDistricts = new Set(districtCodes);

        return cityRows
            .filter((row) => allowedDistricts.has(String(row.district_code || '')))
            .filter((row) => cityCodes.includes(row.code) || !isCityOccupied(row.code))
            .map((row) => ({
                value: row.code,
                label: row.label,
            }));
    }, [cityRows, districtCodes, cityCodes, occupied, citiesClaimedByOccupiedDistricts]);

    const visibleCityCodes = useMemo(
        () => cityCodes.filter((code) => cityBelongsToSelectedDistrict(code, districtCodes, catalog)),
        [cityCodes, districtCodes, catalog],
    );

    const selectionErrors = useMemo(
        () => getAorSelectionErrors({
            provinceCodes,
            districtCodes,
            cityCodes: visibleCityCodes,
            catalog,
        }),
        [provinceCodes, districtCodes, visibleCityCodes, catalog],
    );

    useEffect(() => {
        onValidityChange?.({
            valid: selectionErrors.length === 0,
            errors: selectionErrors,
        });
    }, [JSON.stringify(selectionErrors)]);

    const grouped = useMemo(
        () => buildGroupedAor({
            provinceCodes,
            districtCodes,
            cityCodes: visibleCityCodes,
            catalog,
        }),
        [provinceCodes, districtCodes, visibleCityCodes, catalog],
    );

    const selectProvinces = (nextProvinces) => {
        const nextProvinceCodes = sanitizeCodes(nextProvinces);
        const nextDistricts = districtCodes.filter((code) => {
            const entry = catalog[code] || districtRows.find((row) => row.code === code);
            return nextProvinceCodes.includes(String(entry?.province_code || entry?.parent_code || ''));
        });
        const nextCities = cityCodes.filter((code) => cityBelongsToSelectedDistrict(code, nextDistricts, catalog));

        setScope({
            aor_provinces: nextProvinceCodes,
            aor_districts: nextDistricts,
            aor_cities_municipalities: nextCities,
        });
    };

    const selectDistricts = (nextDistricts) => {
        const nextDistrictCodes = sanitizeCodes(nextDistricts);
        const derivedProvinces = new Set(provinceCodes);

        nextDistrictCodes.forEach((code) => {
            const entry = catalog[code] || districtRows.find((row) => row.code === code);
            if (entry?.province_code || entry?.parent_code) {
                derivedProvinces.add(String(entry.province_code || entry.parent_code));
            }
        });

        const nextCities = cityCodes.filter((code) => cityBelongsToSelectedDistrict(code, nextDistrictCodes, catalog));

        setScope({
            aor_provinces: [...derivedProvinces],
            aor_districts: nextDistrictCodes,
            aor_cities_municipalities: nextCities,
        });
    };

    const selectCities = (nextCities) => {
        const nextCityCodes = sanitizeCodes(nextCities)
            .filter((code) => cityBelongsToSelectedDistrict(code, districtCodes, catalog) || cityRows.some((row) => (
                row.code === code && districtCodes.includes(String(row.district_code || ''))
            )));

        setScope({
            aor_provinces: provinceCodes,
            aor_districts: districtCodes,
            aor_cities_municipalities: nextCityCodes,
        });
    };

    const clearAll = () => {
        prunedRef.current = '';
        setScope({
            aor_provinces: [],
            aor_districts: [],
            aor_cities_municipalities: [],
        });
    };

    const hasSelection = provinceCodes.length > 0 || districtCodes.length > 0 || cityCodes.length > 0;

    return (
        <div className="space-y-4">
            {showEditors && (
                <div className="space-y-3">
                    <div className="flex items-end justify-end">
                        <button
                            type="button"
                            onClick={clearAll}
                            disabled={disabled || !hasSelection}
                            aria-label="Clear all selections"
                            data-tip="Clear all selections"
                            data-tip-side="bottom"
                            data-tip-align="right"
                            data-tip-preferred-side="bottom"
                            data-tip-locked="true"
                            className="dromis-tip inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-300 dark:hover:border-rose-800 dark:hover:bg-rose-950 dark:hover:text-rose-200"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                        <LookerMultiSelect
                            label="Province *"
                            placeholder={loading === 'provinces' ? 'Loading provinces...' : 'Search province...'}
                            allLabel="Select provinces"
                            options={provinceOptions}
                            value={provinceCodes}
                            onApply={selectProvinces}
                            disabled={disabled}
                        />
                        <LookerMultiSelect
                            label="District *"
                            placeholder={provinceCodes.length
                                ? (loading === 'districts' ? 'Loading districts...' : 'Search district...')
                                : 'Select province first'}
                            allLabel="Select districts"
                            options={districtOptions}
                            value={districtCodes}
                            onApply={selectDistricts}
                            disabled={disabled || !provinceCodes.length}
                        />
                        <LookerMultiSelect
                            label="Municipality / City *"
                            placeholder={districtCodes.length
                                ? (loading === 'cities' ? 'Loading cities/municipalities...' : 'Search municipality / city...')
                                : 'Select district first'}
                            allLabel="Select cities / municipalities"
                            options={cityOptions}
                            value={visibleCityCodes}
                            onApply={selectCities}
                            disabled={disabled || !districtCodes.length}
                        />
                    </div>

                    {selectionErrors.length > 0 && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                            <p className="font-black uppercase tracking-wide">Complete required selections</p>
                            <ul className="mt-1 list-disc space-y-1 pl-4">
                                {selectionErrors.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}

            {error && (
                <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    {error}
                </div>
            )}

            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <p className="mb-3 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Selected areas</p>
                <AorHierarchyPreview groups={grouped} />
            </div>
        </div>
    );
}

export { AorHierarchyPreview, buildGroupedAor, getAorSelectionErrors, sanitizeCodes };
