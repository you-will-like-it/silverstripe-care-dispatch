import { useState } from 'react';
import { Badge, Button, Checkbox, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, useForm, useLoad, formatDateTime, minutesToHours } from '../components/ui.jsx';
import { addDays, todayYmd } from '../../shared/time.js';

function toLocalInput(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function TimePage({ admin }) {
  const [filter, setFilter] = useState({ from: addDays(todayYmd(), -6), to: todayYmd(), caregiver: '', autoClosed: false });
  const caregivers = useLoad(() => admin.get('staff/caregivers'), [admin]);
  const { data, error, loading, reload } = useLoad(
    () => admin.get('time/list', { from: filter.from, to: filter.to, caregiver: filter.caregiver || undefined, autoClosed: filter.autoClosed ? 1 : undefined }),
    [admin, filter],
  );
  const [editing, setEditing] = useState(null);
  const set = (key) => (e) => setFilter((f) => ({ ...f, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  const total = (data?.entries ?? []).reduce((n, e) => n + (e.workMinutes ?? 0), 0);

  return (
    <div>
      <PageHeader
        title="Zeiten"
        subtitle="Kommen/Gehen der Pflegekräfte. Korrekturen werden mit Begründung protokolliert."
        actions={<Button variant="primary" onClick={() => setEditing({})}>Buchung nachtragen</Button>}
      />
      <div className="flex flex-wrap items-end gap-3 px-6 pt-4">
        <Field label="Von"><Input type="date" value={filter.from} onChange={set('from')} /></Field>
        <Field label="Bis"><Input type="date" value={filter.to} onChange={set('to')} /></Field>
        <Field label="Pflegekraft" className="w-56">
          <Select value={filter.caregiver} onChange={set('caregiver')} placeholder="alle" options={(caregivers.data?.caregivers ?? []).map((c) => ({ value: String(c.id), label: c.name }))} />
        </Field>
        <Checkbox className="pb-2" label="nur automatisch geschlossene" checked={filter.autoClosed} onChange={set('autoClosed')} />
      </div>
      <ErrorBox error={error} onRetry={reload} />
      {!data && loading && <Loading />}
      {data && (
        <div className="p-6">
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-4 py-2">Pflegekraft</th><th className="px-4 py-2">Kommen</th><th className="px-4 py-2">Gehen</th><th className="px-4 py-2">Pause</th><th className="px-4 py-2">Arbeitszeit</th><th className="px-4 py-2" /></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.entries.map((e) => (
                  <tr key={e.id}>
                    <td className="px-4 py-2 font-medium">{e.caregiverName}</td>
                    <td className="px-4 py-2 tabular-nums">{formatDateTime(e.checkIn)}</td>
                    <td className="px-4 py-2 tabular-nums">
                      {e.checkOut ? formatDateTime(e.checkOut) : <Badge tone="green">{e.status === 'Break' ? 'Pause' : 'im Dienst'}</Badge>}
                      {e.autoClosed && <span className="ml-1"><Badge tone="amber">automatisch</Badge></span>}
                    </td>
                    <td className="px-4 py-2 tabular-nums">{e.breakMinutes} Min.</td>
                    <td className="px-4 py-2 tabular-nums">{minutesToHours(e.workMinutes)}</td>
                    <td className="px-4 py-2 text-right"><Button variant="ghost" onClick={() => setEditing(e)}>Korrigieren</Button></td>
                  </tr>
                ))}
                {data.entries.length === 0 && <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">Keine Buchungen im Zeitraum.</td></tr>}
              </tbody>
              {data.entries.length > 0 && (
                <tfoot className="bg-slate-50 text-sm font-semibold">
                  <tr><td className="px-4 py-2" colSpan={4}>Summe (abgeschlossene Buchungen)</td><td className="px-4 py-2 tabular-nums">{minutesToHours(total)}</td><td /></tr>
                </tfoot>
              )}
            </table>
          </div>
        </div>
      )}
      {editing && (
        <EntryForm
          admin={admin}
          entry={editing.id ? editing : null}
          caregivers={caregivers.data?.caregivers ?? []}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
    </div>
  );
}

function EntryForm({ admin, entry, caregivers, onClose, onSaved }) {
  const isNew = !entry;
  const { values, set, errors, busy, submit } = useForm({
    caregiverId: entry ? String(entry.caregiverId) : '',
    checkIn: toLocalInput(entry?.checkIn),
    checkOut: toLocalInput(entry?.checkOut),
    breakMinutes: entry?.breakMinutes ?? 0,
    reason: '',
  });
  const save = () => submit((v) => admin.post(isNew ? 'time/create' : `time/${entry.id}`, {
    ...v,
    caregiverId: Number(v.caregiverId),
    breakMinutes: Number(v.breakMinutes) || 0,
  }), 'Zeitbuchung gespeichert.').then((res) => res && onSaved());

  return (
    <Modal
      title={isNew ? 'Zeitbuchung nachtragen' : `Zeitbuchung korrigieren – ${entry.caregiverName}`}
      onClose={onClose}
      footer={<><Button onClick={onClose}>Abbrechen</Button><Button variant="primary" onClick={save} disabled={busy || values.reason.trim().length < 3 || (isNew && !values.caregiverId)}>Speichern</Button></>}
    >
      <div className="grid grid-cols-2 gap-3">
        {isNew && (
          <Field label="Pflegekraft" className="col-span-2">
            <Select value={values.caregiverId} onChange={set('caregiverId')} placeholder="Bitte wählen …" options={caregivers.map((c) => ({ value: String(c.id), label: c.name }))} />
          </Field>
        )}
        <Field label="Kommen" error={errors.CheckIn}><Input type="datetime-local" value={values.checkIn} onChange={set('checkIn')} /></Field>
        <Field label="Gehen" error={errors.CheckOut} hint="leer = noch im Dienst"><Input type="datetime-local" value={values.checkOut} onChange={set('checkOut')} /></Field>
        <Field label="Pause (Min.)"><Input type="number" min={0} value={values.breakMinutes} onChange={set('breakMinutes')} /></Field>
        <Field label="Begründung (Pflicht)" className="col-span-2"><Input value={values.reason} onChange={set('reason')} placeholder="z. B. Gehen vergessen, laut Rücksprache 14:10" /></Field>
      </div>
    </Modal>
  );
}
