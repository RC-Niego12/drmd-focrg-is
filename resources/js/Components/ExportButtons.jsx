import { ClipboardCheck, FileText, Image, Maximize2, Minimize2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import * as htmlToImage from 'html-to-image';
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';

const iconButton = 'inline-flex items-center justify-center rounded-md border border-slate-200 bg-white p-2 text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800';
const actionButton = 'inline-flex items-center justify-center rounded-md border border-slate-200 bg-white p-2 text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800';

export default function ExportButtons({ targetRef, filename, onMessage, showOnlyFullscreen = false }) {
    const [fullscreen, setFullscreen] = useState(false);

    useEffect(() => {
        const updateFullscreen = () => {
            setFullscreen(Boolean(document.fullscreenElement));
        };

        document.addEventListener('fullscreenchange', updateFullscreen);
        updateFullscreen();
        return () => document.removeEventListener('fullscreenchange', updateFullscreen);
    }, []);

    const getTargetElement = () => targetRef?.current ?? document.documentElement;

    const cloneWithoutIgnored = (element) => {
        const clone = element.cloneNode(true);
        clone.querySelectorAll('[data-html2canvas-ignore="true"]').forEach((ignored) => ignored.remove());
        if (clone.dataset.html2canvasIgnore === 'true') {
            return null;
        }
        return clone;
    };

    const writeTextToClipboard = async (text) => {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.top = '-9999px';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        const success = document.execCommand('copy');
        document.body.removeChild(textarea);
        return success;
    };

    const captureBlob = async (target) => {
        const clone = createExportClone(target);
        document.body.appendChild(clone);

        try {
            const width = getVisibleWidth(target);
            const height = getExportHeight(clone);
            const blob = await htmlToImage.toBlob(clone, {
                cacheBust: true,
                pixelRatio: Math.min(3, Math.max(2, window.devicePixelRatio || 2)),
                backgroundColor: '#ffffff',
                width,
                height,
                style: {
                    width: `${width}px`,
                    minWidth: `${width}px`,
                    maxWidth: `${width}px`,
                    overflowX: 'hidden',
                    overflowY: 'visible',
                },
                filter: (node) => node.dataset.html2canvasIgnore !== 'true' && node.dataset.exportIgnore !== 'true',
            });
            return blob;
        } finally {
            document.body.removeChild(clone);
        }
    };

    const copyHtml = async () => {
        const target = getTargetElement();
        if (!target) {
            onMessage?.('Unable to copy summary.');
            return;
        }

        const ClipboardItemConstructor = typeof ClipboardItem !== 'undefined' ? ClipboardItem : window.ClipboardItem;
        if (!navigator.clipboard?.write || !ClipboardItemConstructor) {
            onMessage?.('Image copy is not supported in this browser. Use Image export instead.');
            return;
        }

        try {
            const blob = await captureBlob(target);
            if (!blob) {
                onMessage?.('Unable to generate image for clipboard.');
                return;
            }

            const dataUrl = await new Promise((resolve) => {
                const reader = new FileReader();
                reader.onloadend = () => resolve(reader.result);
                reader.readAsDataURL(blob);
            });

            const html = `<img src="${dataUrl}" style="max-width:100%;display:block;" />`;
            const htmlBlob = new Blob([html], { type: 'text/html' });
            const plainBlob = new Blob(['Copied summary image.'], { type: 'text/plain' });

            await navigator.clipboard.write([new ClipboardItemConstructor({
                'image/png': blob,
                'text/html': htmlBlob,
                'text/plain': plainBlob,
            })]);
            onMessage?.('Summary image copied to clipboard. Paste into a document or chat.');
        } catch (error) {
            const message = error?.name === 'NotAllowedError' || error?.name === 'SecurityError'
                ? 'Clipboard access denied. Grant permission or try again.'
                : 'Copy as image failed. Use Image export instead.';
            onMessage?.(message);
        }
    };

    const createExportClone = (target) => {
        const width = getVisibleWidth(target);
        const clone = target.cloneNode(true);
        clone.style.position = 'absolute';
        clone.style.left = '-9999px';
        clone.style.top = '0';
        clone.style.width = `${width}px`;
        clone.style.minWidth = `${width}px`;
        clone.style.maxWidth = `${width}px`;
        clone.style.height = 'auto';
        clone.style.boxSizing = 'border-box';
        clone.style.overflowX = 'hidden';
        clone.style.overflowY = 'visible';

        clone.querySelectorAll('[data-html2canvas-ignore="true"], [data-export-ignore="true"]').forEach((element) => element.remove());
        return clone;
    };

    const captureCanvas = async (target) => {
        const clone = createExportClone(target);
        document.body.appendChild(clone);

        try {
            const width = getVisibleWidth(target);
            const height = getExportHeight(clone);
            const scale = Math.min(3, Math.max(2, window.devicePixelRatio || 2));

            const canvas = await html2canvas(clone, {
                backgroundColor: '#ffffff',
                scale,
                width,
                height,
                windowWidth: Math.max(window.innerWidth || 0, document.documentElement.clientWidth || 0, width),
                windowHeight: Math.max(window.innerHeight || 0, document.documentElement.clientHeight || 0, height),
                scrollX: 0,
                scrollY: 0,
                ignoreElements: (element) => element.dataset.html2canvasIgnore === 'true' || element.dataset.exportIgnore === 'true',
            });

            return canvas;
        } finally {
            document.body.removeChild(clone);
        }
    };

    const exportImage = async () => {
        const target = getTargetElement();
        if (!target) {
            onMessage?.('Unable to create image.');
            return;
        }

        try {
            const canvas = await captureCanvas(target);
            const dataUrl = canvas.toDataURL('image/png');
            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = `${filename || 'export'}.png`;
            link.click();
            onMessage?.('Image export started.');
        } catch (error) {
            onMessage?.('Image export failed.');
        }
    };

    const exportPdf = async () => {
        const target = getTargetElement();
        if (!target) {
            onMessage?.('Unable to create PDF.');
            return;
        }

        try {
            const canvas = await captureCanvas(target);
            const imageData = canvas.toDataURL('image/png');
            const pdf = new jsPDF({ orientation: 'landscape', unit: 'px', format: [canvas.width, canvas.height] });
            pdf.addImage(imageData, 'PNG', 0, 0, canvas.width, canvas.height);
            pdf.save(`${filename || 'export'}.pdf`);
            onMessage?.('PDF export started.');
        } catch (error) {
            onMessage?.('PDF export failed.');
        }
    };

    const toggleFullscreen = async () => {
        if (document.fullscreenElement) {
            await document.exitFullscreen();
            onMessage?.('Exited fullscreen mode');
            return;
        }

        const targetElement = getTargetElement();
        if (!targetElement || !targetElement.requestFullscreen) {
            onMessage?.('Fullscreen mode is not supported in this browser.');
            return;
        }

        await targetElement.requestFullscreen();
        onMessage?.('Entered fullscreen mode');
    };

    if (showOnlyFullscreen) {
        return (
            <div data-html2canvas-ignore="true" data-export-ignore="true" className="flex items-center">
                <button type="button" onClick={toggleFullscreen} title={fullscreen ? 'Exit fullscreen' : 'Fullscreen'} aria-label={fullscreen ? 'Exit fullscreen' : 'Fullscreen'} className={iconButton}>
                    {fullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
                </button>
            </div>
        );
    }

    return (
        <div data-html2canvas-ignore="true" data-export-ignore="true" className="flex flex-wrap items-center gap-2">
            <button type="button" onClick={copyHtml} title="Copy summary as image" aria-label="Copy summary as image" className={actionButton}>
                <ClipboardCheck className="h-4 w-4" />
            </button>
            <button type="button" onClick={exportImage} title="Export summary as image" aria-label="Export summary as image" className={actionButton}>
                <Image className="h-4 w-4" />
            </button>
            <button type="button" onClick={exportPdf} title="Export summary as PDF" aria-label="Export summary as PDF" className={actionButton}>
                <FileText className="h-4 w-4" />
            </button>
            <button type="button" onClick={toggleFullscreen} title={fullscreen ? 'Exit fullscreen' : 'Fullscreen'} aria-label={fullscreen ? 'Exit fullscreen' : 'Fullscreen'} className={iconButton}>
                {fullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
            </button>
        </div>
    );
}

function getVisibleWidth(element) {
    const rect = element.getBoundingClientRect();
    return Math.ceil(rect.width || element.offsetWidth || 1);
}

function getExportHeight(element) {
    return Math.ceil(Math.max(element.scrollHeight || 0, element.offsetHeight || 0, element.getBoundingClientRect().height || 0, 1));
}

export function exportFilename(title) {
    return title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'dashboard-component';
}
