import { useEffect } from 'react';

export default function Toast({ toast, onClose }) {
  useEffect(() => {
    if (!toast) return undefined;
    const t = setTimeout(onClose, toast.type === 'error' ? 9000 : 4500);
    return () => clearTimeout(t);
  }, [toast, onClose]);

  if (!toast) return null;
  const cls = toast.type === 'error' ? 'bg-red-600' : toast.type === 'warning' ? 'bg-amber-500' : 'bg-slate-900';

  return (
    <div className="pointer-events-none fixed bottom-5 left-1/2 z-[60] w-full max-w-xl -translate-x-1/2 px-4" role="status" aria-live="polite">
      <div className={`pointer-events-auto rounded-xl px-4 py-3 text-sm text-white shadow-xl ${cls}`}>
        <div className="flex items-start gap-3">
          <div className="flex-1">
            <p className="font-medium">{toast.text}</p>
            {toast.details?.length > 0 && (
              <ul className="mt-1 list-disc space-y-0.5 pl-5 opacity-90">
                {toast.details.map((d, i) => <li key={i}>{d}</li>)}
              </ul>
            )}
          </div>
          <button type="button" onClick={onClose} className="opacity-70 hover:opacity-100" aria-label="Schließen">✕</button>
        </div>
      </div>
    </div>
  );
}
