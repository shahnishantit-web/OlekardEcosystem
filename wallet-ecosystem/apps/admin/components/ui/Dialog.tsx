'use client';
import React, { useEffect, useRef } from 'react';
interface DialogProps {
    open: boolean;
    onClose: () => void;
    title?: string;
    children: React.ReactNode;
    labelledBy?: string;
    label?: string;
    className?: string;
    overlayClassName?: string;
    showCloseButton?: boolean;
}
export function Dialog({ open, onClose, title, children, labelledBy, label, className, overlayClassName, showCloseButton = true }: DialogProps) {
    const dialogRef = useRef<HTMLDivElement>(null);
    useEffect(() => { if (!open)
        return; const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null; dialogRef.current?.focus(); return () => { if (previous?.isConnected === true)
        previous.focus(); }; }, [open]);
    useEffect(() => {
        if (!open)
            return;
        const handle = (event: KeyboardEvent) => {
            const dialogs = document.querySelectorAll('[role="dialog"][aria-modal="true"]');
            const current = dialogRef.current;
            if (current === null || dialogs[dialogs.length - 1] !== current)
                return;
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
                return;
            }
            if (event.key !== 'Tab')
                return;
            const items = Array.from(current.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled):not([type="hidden"]),select:not(:disabled),textarea:not(:disabled),a[href],[tabindex]:not([tabindex="-1"])')).filter(el => el.closest('[hidden],[aria-hidden="true"]') === null && getComputedStyle(el).display !== 'none');
            const first = items[0], last = items[items.length - 1];
            if (first === undefined || last === undefined) {
                event.preventDefault();
                current.focus();
                return;
            }
            if (event.shiftKey && (document.activeElement === first || document.activeElement === current || !current.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            }
            else if (!event.shiftKey && (document.activeElement === last || !current.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', handle);
        return () => document.removeEventListener('keydown', handle);
    }, [open, onClose]);
    if (!open)
        return null;
    const content = <div role="dialog" aria-modal="true" {...(labelledBy !== undefined ? { 'aria-labelledby': labelledBy } : title !== undefined ? { 'aria-labelledby': 'dialog-title' } : { 'aria-label': label ?? 'Dialog' })} ref={dialogRef} tabIndex={-1} {...(className !== undefined ? { className: 'relative z-10 ' + className } : {})}>
 {title !== undefined && <h2 id="dialog-title">{title}</h2>}<div>{children}</div>
 {showCloseButton && <button type="button" onClick={onClose} aria-label="Close dialog">Close</button>}
 </div>;
    return overlayClassName === undefined ? content : <div className={overlayClassName}><button type="button" tabIndex={-1} aria-label="Close dialog backdrop" className="absolute inset-0 h-full w-full cursor-default border-0 bg-transparent" onClick={onClose}/>{content}</div>;
}
