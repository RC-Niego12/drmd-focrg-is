import './bootstrap';
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { useEffect } from 'react';

let systemName = window.__SYSTEM_NAME__ || 'Disaster Response Information Management System (DRIMS)';

function FilterResetBoundary({ children }) {
    useEffect(() => {
        const navigation = performance.getEntriesByType?.('navigation')?.[0];

        if (navigation?.type === 'reload' && window.location.search) {
            router.visit(window.location.pathname, {
                method: 'get',
                replace: true,
                preserveScroll: false,
                preserveState: false,
            });
        }
    }, []);

    return children;
}

createInertiaApp({
    title: (title) => `${title} - ${systemName}`,
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        systemName = props.initialPage.props.systemName || systemName;
        createRoot(el).render(
            <FilterResetBoundary>
                <App {...props} />
            </FilterResetBoundary>
        );
    },
    progress: {
        color: '#2f8f73',
    },
});
