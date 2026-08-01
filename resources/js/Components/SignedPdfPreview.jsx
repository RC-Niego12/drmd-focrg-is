/**
 * Signed report / request letter PDF preview using the browser PDF viewer chrome
 * (page controls, zoom, download, print). Filename comes from Content-Disposition.
 */
export default function SignedPdfPreview({
    src,
    filename = 'Signed document',
    title = 'Signed document preview',
    className = '',
    iframeClassName = 'h-full min-h-[55vh] w-full flex-1 bg-slate-200',
}) {
    if (!src) {
        return (
            <div className={`flex min-h-[55vh] items-center justify-center bg-slate-100 p-8 text-center text-sm text-slate-500 ${className}`}>
                No signed PDF is available for preview.
            </div>
        );
    }

    const baseSrc = String(src).split('#')[0];
    const namedSrc = (() => {
        try {
            const url = new URL(baseSrc, window.location.origin);
            if (filename) {
                url.searchParams.set('filename', filename);
            }
            return `${url.pathname}${url.search}#toolbar=1&navpanes=0&scrollbar=1`;
        } catch {
            const separator = baseSrc.includes('?') ? '&' : '?';
            return `${baseSrc}${filename ? `${separator}filename=${encodeURIComponent(filename)}` : ''}#toolbar=1&navpanes=0&scrollbar=1`;
        }
    })();

    return (
        <div className={`flex min-h-0 flex-1 flex-col overflow-hidden bg-slate-100 ${className}`}>
            <iframe title={title} src={namedSrc} className={iframeClassName} />
        </div>
    );
}
