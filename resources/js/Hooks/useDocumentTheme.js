import { useEffect, useState } from 'react';

function readIsDark() {
    if (typeof document === 'undefined') {
        return false;
    }

    return document.documentElement.classList.contains('dark');
}

/**
 * Sync light/dark with the html.dark class and theme cookie (no auth required).
 * Authenticated AppLayout remains the source of truth when a user.theme_mode exists.
 */
export default function useDocumentTheme() {
    const [dark, setDark] = useState(readIsDark);

    useEffect(() => {
        document.documentElement.classList.toggle('dark', dark);
        document.cookie = `theme=${dark ? 'dark' : 'light'}; path=/; max-age=31536000`;
    }, [dark]);

    return [dark, setDark];
}
