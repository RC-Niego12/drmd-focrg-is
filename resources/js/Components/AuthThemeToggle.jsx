import { router, usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import useDocumentTheme from '@/Hooks/useDocumentTheme';

export default function AuthThemeToggle({ className = '' }) {
    const [dark, setDark] = useDocumentTheme();
    const { auth } = usePage().props;

    const toggle = () => {
        const next = !dark;
        setDark(next);

        if (auth?.user) {
            router.patch('/settings/theme', { theme_mode: next ? 'dark' : 'light' }, {
                preserveState: true,
                preserveScroll: true,
            });
        }
    };

    return (
        <button
            type="button"
            onClick={toggle}
            title={dark ? 'Switch to light mode' : 'Switch to dark mode'}
            aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}
            className={`rounded-md border border-slate-200 bg-white/90 p-2 text-slate-700 shadow-sm backdrop-blur transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900/90 dark:text-zinc-100 dark:hover:bg-zinc-800 ${className}`}
        >
            {dark ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
        </button>
    );
}
