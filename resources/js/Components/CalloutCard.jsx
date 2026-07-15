import { Card } from '@/Layouts/AppLayout';

const toneClasses = {
    brand: 'text-brand-700 bg-brand-50 ring-brand-100 dark:text-brand-200 dark:bg-brand-900 dark:ring-brand-700',
    slate: 'text-slate-700 bg-slate-50 ring-slate-200 dark:text-zinc-100 dark:bg-zinc-800 dark:ring-zinc-600',
    coral: 'text-signal-coral bg-rose-50 ring-rose-100 dark:text-rose-200 dark:bg-rose-950 dark:ring-rose-800',
};

export default function CalloutCard({ title, value, subtext, icon: Icon, tone = 'brand', className = '' }) {
    const toneClass = toneClasses[tone] ?? toneClasses.brand;

    return (
        <Card className={`relative overflow-hidden ${className}`}>
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full bg-brand-50 dark:bg-brand-800/30" />
            <div className="relative flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
                    <p className="mt-2 text-3xl font-black tracking-normal text-slate-950 dark:text-white">{value}</p>
                    {subtext && <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">{subtext}</p>}
                </div>
                {Icon && (
                    <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-md shadow-sm ring-1 ${toneClass}`}>
                        <Icon className="h-5 w-5" />
                    </span>
                )}
            </div>
        </Card>
    );
}
