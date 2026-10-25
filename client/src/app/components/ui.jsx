import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import Toast from '../../board/components/Toast.jsx';
import { Button } from '../../board/components/Modal.jsx';

export { Button };
export { default as Modal } from '../../board/components/Modal.jsx';

// ---------------------------------------------------------------- data loading

/** Loads data with `loader()`; reloads when deps change. */
export function useLoad(loader, deps) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const seq = useRef(0);
  const load = useCallback(async () => {
    const id = ++seq.current;
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const data = await loader();
      if (id === seq.current) setState({ data, error: null, loading: false });
    } catch (error) {
      if (id === seq.current) setState((s) => ({ ...s, error, loading: false }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(() => {
    load();
  }, [load]);
  const setData = useCallback((updater) => setState((s) => ({ ...s, data: typeof updater === 'function' ? updater(s.data) : updater })), []);
  return { ...state, reload: load, setData };
}

// ---------------------------------------------------------------- toast

const ToastContext = createContext(() => {});

export function ToastProvider({ children }) {
  const [toast, setToast] = useState(null);
  const close = useCallback(() => setToast(null), []);
  return (
    <ToastContext.Provider value={setToast}>
      {children}
      <Toast toast={toast} onClose={close} />
    </ToastContext.Provider>
  );
}

/** notify({type: 'success'|'error'|'warning', text, details}) */
export function useToast() {
  return useContext(ToastContext);
}

// ---------------------------------------------------------------- layout

export function PageHeader({ title, subtitle, actions, back }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 bg-white px-6 py-4">
      <div className="min-w-0">
        {back}
        <h1 className="truncate text-xl font-semibold text-slate-900">{title}</h1>
        {subtitle && <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Card({ title, actions, children, className = '' }) {
  return (
    <section className={`rounded-xl border border-slate-200 bg-white shadow-sm ${className}`}>
      {(title || actions) && (
        <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
          {title && <h2 className="text-sm font-semibold text-slate-900">{title}</h2>}
          {actions && <div className="flex gap-2">{actions}</div>}
        </div>
      )}
      <div className="p-4">{children}</div>
    </section>
  );
}

export function Loading({ text = 'Lade …' }) {
  return <p className="p-8 text-center text-sm text-slate-500">{text}</p>;
}

export function ErrorBox({ error, onRetry }) {
  if (!error) return null;
  return (
    <div className="m-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
      <p>{error.message ?? String(error)}</p>
      {onRetry && <Button className="mt-2" onClick={onRetry}>Erneut versuchen</Button>}
    </div>
  );
}

export function Empty({ children }) {
  return <p className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">{children}</p>;
}

export function Badge({ tone = 'slate', children, title }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-700',
    green: 'bg-emerald-100 text-emerald-800',
    red: 'bg-red-100 text-red-700',
    amber: 'bg-amber-100 text-amber-800',
    blue: 'bg-brand-100 text-brand-700',
    violet: 'bg-violet-100 text-violet-800',
  };
  return <span title={title} className={`inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ${tones[tone]}`}>{children}</span>;
}

// ---------------------------------------------------------------- forms

const inputCls = 'rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 disabled:bg-slate-50 disabled:text-slate-500';

export function Field({ label, error, hint, children, className = '' }) {
  return (
    <label className={`block text-sm ${className}`}>
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
    </label>
  );
}

/** Full width unless the caller sets its own width class. */
const widthCls = (className) => (/(^|\s)(w-|max-w-|min-w-)/.test(className) ? '' : 'w-full');

export function Input({ className = '', ...props }) {
  return <input className={`${inputCls} ${widthCls(className)} ${className}`} {...props} />;
}

export function Textarea({ className = '', rows = 3, ...props }) {
  return <textarea rows={rows} className={`${inputCls} w-full ${className}`} {...props} />;
}

export function Select({ options, placeholder, className = '', ...props }) {
  return (
    <select className={`${inputCls} ${widthCls(className)} ${className}`} {...props}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map((o) => (
        <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>
      ))}
    </select>
  );
}

export function Checkbox({ label, className = '', ...props }) {
  return (
    <label className={`flex items-center gap-2 text-sm text-slate-700 ${className}`}>
      <input type="checkbox" className="h-4 w-4 rounded border-slate-300" {...props} />
      {label}
    </label>
  );
}

export const WEEKDAYS = [
  [1, 'Mo'], [2, 'Di'], [3, 'Mi'], [4, 'Do'], [5, 'Fr'], [6, 'Sa'], [7, 'So'],
];

export function WeekdayPicker({ value, onChange, disabled }) {
  const set = new Set(value);
  const toggle = (d) => {
    const next = new Set(set);
    if (next.has(d)) next.delete(d);
    else next.add(d);
    onChange([...next].sort());
  };
  return (
    <div className="flex flex-wrap gap-1">
      {WEEKDAYS.map(([d, label]) => (
        <button
          key={d}
          type="button"
          disabled={disabled}
          onClick={() => toggle(d)}
          className={`h-8 w-9 rounded-md border text-xs font-semibold ${set.has(d) ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'}`}
          aria-pressed={set.has(d)}
        >
          {label}
        </button>
      ))}
      <button type="button" disabled={disabled} className="ml-1 text-xs text-brand-600 hover:underline" onClick={() => onChange([1, 2, 3, 4, 5, 6, 7])}>täglich</button>
    </div>
  );
}

/**
 * Small form helper: values, setters, server field errors, submit with busy flag.
 */
export function useForm(initial) {
  const [values, setValues] = useState(initial);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const notify = useToast();

  const set = useCallback((key) => (eOrValue) => {
    const value = eOrValue?.target ? (eOrValue.target.type === 'checkbox' ? eOrValue.target.checked : eOrValue.target.value) : eOrValue;
    setValues((v) => ({ ...v, [key]: value }));
    setErrors((e) => (e[key] ? { ...e, [key]: undefined } : e));
  }, []);

  const submit = useCallback(async (fn, successText) => {
    setBusy(true);
    setErrors({});
    try {
      const result = await fn(values);
      if (successText) notify({ type: 'success', text: successText });
      return result;
    } catch (e) {
      setErrors(e.fields ?? {});
      notify({ type: 'error', text: e.message, details: (e.violations ?? []).map((v) => v.message) });
      return null;
    } finally {
      setBusy(false);
    }
  }, [values, notify]);

  return { values, setValues, set, errors, busy, submit };
}

/** Maps server field names (DB fields) to form keys for error display. */
export function fieldError(errors, ...names) {
  for (const n of names) if (errors?.[n]) return errors[n];
  return undefined;
}

export function formatDate(ymd) {
  if (!ymd) return '';
  const [y, m, d] = ymd.split('-');
  return `${d}.${m}.${y}`;
}

export function formatDateTime(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function minutesToHours(minutes) {
  if (minutes === null || minutes === undefined) return '–';
  const h = Math.floor(minutes / 60);
  const m = Math.abs(minutes % 60);
  return `${h}:${String(m).padStart(2, '0')} h`;
}
