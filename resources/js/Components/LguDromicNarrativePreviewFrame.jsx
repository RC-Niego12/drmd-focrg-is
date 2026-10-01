import { useEffect, useRef } from 'react';

export default function LguDromicNarrativePreviewFrame({ html }) {
    const iframeRef = useRef(null);

    useEffect(() => {
        const iframe = iframeRef.current;
        if (!iframe) return undefined;

        const resize = () => {
            const doc = iframe.contentDocument;
            if (!doc?.documentElement) return;
            iframe.style.height = `${Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight || 0, 1100)}px`;
        };

        iframe.addEventListener('load', resize);
        resize();

        return () => iframe.removeEventListener('load', resize);
    }, [html]);

    if (!html) return null;

    return (
        <iframe
            ref={iframeRef}
            title="LGU narrative report preview"
            srcDoc={html}
            className="print-document block w-full border-0 bg-white"
            sandbox="allow-same-origin"
        />
    );
}
