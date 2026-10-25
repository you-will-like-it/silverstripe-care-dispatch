import { useEffect, useRef } from 'react';

export default function Modal({ title, tone = 'default', onClose, children, footer, width = 'max-w-lg' }) {
  const ref = useRef(null);

  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    ref.current?.querySelector('textarea, select, input, button')?.focus();
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const titleCls = tone === 'danger' ? 'text-red-700' : tone === 'warning' ? 'text-amber-700' : 'text-slate-900';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div ref={ref} role="dialog" aria-modal="true" aria-label={title} className={`w-full ${width} overflow-hidden rounded-xl bg-white shadow-2xl`}>
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
          <h2 className={`text-base font-semibold ${titleCls}`}>{title}</h2>
          <button type="button" onClick={onClose} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Schließen">✕</button>
        </div>
        <div className="max-h-[70vh] overflow-y-auto px-5 py-4 text-sm text-slate-700">{children}</div>
        {footer && <div className="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">{footer}</div>}
      </div>
    </div>
  );
}

export function Button({ variant = 'secondary', className = '', ...props }) {
  const styles = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-600/50',
    danger: 'bg-red-600 text-white hover:bg-red-700 disabled:bg-red-600/50',
    warning: 'bg-amber-500 text-white hover:bg-amber-600 disabled:bg-amber-500/50',
    secondary: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 disabled:opacity-50',
    ghost: 'text-slate-600 hover:bg-slate-100 disabled:opacity-50',
    dangerGhost: 'text-red-600 hover:bg-red-50 disabled:opacity-50',
  };
  return <button type="button" className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition ${styles[variant]} ${className}`} {...props} />;
}
