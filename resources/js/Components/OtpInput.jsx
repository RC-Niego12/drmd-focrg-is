import { useEffect, useRef } from 'react';

const LENGTH = 6;

function digitsOnly(value) {
    return String(value || '').replace(/\D/g, '').slice(0, LENGTH);
}

export default function OtpInput({
    value = '',
    onChange,
    disabled = false,
    autoFocus = false,
    error = false,
    idPrefix = 'otp',
}) {
    const inputsRef = useRef([]);
    const code = digitsOnly(value).padEnd(LENGTH, ' ').slice(0, LENGTH);
    const digits = Array.from({ length: LENGTH }, (_, index) => {
        const char = code[index];
        return char === ' ' ? '' : char;
    });

    useEffect(() => {
        if (autoFocus) {
            inputsRef.current[0]?.focus();
        }
    }, [autoFocus]);

    const emit = (next) => {
        onChange?.(digitsOnly(next));
    };

    const focusAt = (index) => {
        const el = inputsRef.current[Math.max(0, Math.min(LENGTH - 1, index))];
        el?.focus();
        el?.select();
    };

    const handleChange = (index, raw) => {
        const cleaned = digitsOnly(raw);

        if (cleaned.length > 1) {
            const merged = digitsOnly(digits.join('').slice(0, index) + cleaned);
            emit(merged);
            focusAt(Math.min(LENGTH - 1, merged.length));
            return;
        }

        const next = [...digits];
        next[index] = cleaned;
        const merged = next.join('');
        emit(merged);

        if (cleaned && index < LENGTH - 1) {
            focusAt(index + 1);
        }
    };

    const handleKeyDown = (index, event) => {
        if (event.key === 'Backspace') {
            event.preventDefault();
            if (digits[index]) {
                const next = [...digits];
                next[index] = '';
                emit(next.join(''));
                return;
            }
            if (index > 0) {
                const next = [...digits];
                next[index - 1] = '';
                emit(next.join(''));
                focusAt(index - 1);
            }
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            focusAt(index - 1);
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            focusAt(index + 1);
        }
    };

    const handlePaste = (event) => {
        event.preventDefault();
        const pasted = digitsOnly(event.clipboardData.getData('text'));
        if (!pasted) {
            return;
        }
        emit(pasted);
        focusAt(Math.min(LENGTH - 1, pasted.length - 1));
    };

    return (
        <div className="flex items-center justify-between gap-2 sm:gap-2.5" role="group" aria-label="One-time passcode">
            {digits.map((digit, index) => (
                <input
                    key={`${idPrefix}-${index}`}
                    id={`${idPrefix}-${index}`}
                    ref={(el) => {
                        inputsRef.current[index] = el;
                    }}
                    type="text"
                    inputMode="numeric"
                    autoComplete={index === 0 ? 'one-time-code' : 'off'}
                    maxLength={index === 0 ? LENGTH : 1}
                    value={digit}
                    disabled={disabled}
                    aria-invalid={error || undefined}
                    onChange={(event) => handleChange(index, event.target.value)}
                    onKeyDown={(event) => handleKeyDown(index, event)}
                    onPaste={handlePaste}
                    onFocus={(event) => event.target.select()}
                    className={[
                        'h-12 w-10 rounded-md border-2 bg-white text-center font-serif text-xl font-bold text-brand-800 shadow-sm outline-none transition dark:bg-zinc-950 dark:text-brand-100 sm:h-14 sm:w-12 sm:text-2xl',
                        error
                            ? 'border-red-500 focus:border-red-600 focus:ring-2 focus:ring-red-200 dark:border-red-400 dark:focus:ring-red-900'
                            : 'border-brand-300 focus:border-brand-600 focus:ring-2 focus:ring-brand-100 dark:border-brand-700 dark:focus:border-brand-400 dark:focus:ring-brand-900/50',
                        disabled ? 'opacity-60' : '',
                    ].join(' ')}
                />
            ))}
        </div>
    );
}
