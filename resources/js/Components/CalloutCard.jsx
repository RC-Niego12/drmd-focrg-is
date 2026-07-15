import { Card } from '@/Layouts/AppLayout';

const toneClasses = {
    brand: 'text-brand-700 dark:text-brand-100 bg-brand-50 dark:bg-brand-950/50',
    slate: 'text-slate-700 dark:text-zinc-100 bg-slate-50 dark:bg-zinc-900',
    coral: 'text-signal-coral bg-rose-50 dark:bg-rose-950/30',
};

export default function CalloutCard({ title, value, subtext, icon: Icon, tone = 'brand', className = '' }) {
    const toneClass = toneClasses[tone] ?? toneClasses.brand;

    return (
        <Card className={`relative overflow-hidden ${className}`}>
            <div className="absolute -right-8 -top-10 h-28 w-28 rounded-full bg-brand-50 dark:bg-brand-950/50" />
            <div className="relative flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-zinc-400">{title}</p>
                    <p className="mt-2 text-3xl font-black tracking-normal text-slate-950 dark:text-white">{value}</p>
                    {subtext && <p className="mt-1 text-sm font-semibold text-slate-500 dark:text-zinc-400">{subtext}</p>}
                </div>
                {Icon && (
                    <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 ${toneClass}`}>
                        <Icon className="h-5 w-5" />
                    </span>
                )}
            </div>
        </Card>
    );
}
