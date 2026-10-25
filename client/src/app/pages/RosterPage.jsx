import { useMemo, useState } from 'react';
import { Badge, Button, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, useForm, useLoad, useToast, formatDate } from '../components/ui.jsx';
import { addDays, todayYmd } from '../../shared/time.js';
import { QUALIFICATION_SHORT } from '../../shared/labels.js';

const PRESETS = [
  { label: 'Früh', type: 'Frueh', start: '06:00', end: '14:00' },
  { label: 'Spät', type: 'Spaet', start: '13:00', end: '21:00' },
  { label: 'Nacht', type: 'Nacht', start: '21:00', end: '06:00' },
  { label: 'Teil (vorm.)', type: 'Teildienst', start: '06:30', end: '10:30' },
  { label: 'Teil (nachm.)', type: 'Teildienst', start: '16:00', end: '20:30' },
];

const SHIFT_COLORS = {
  Frueh: 'bg-sky-100 text-sky-900 border-sky-200',
  Spaet: 'bg-violet-100 text-violet-900 border-violet-200',
  Nacht: 'bg-slate-700 text-white border-slate-700',
  Teildienst: 'bg-teal-100 text-teal-900 border-teal-200',
};

const WEEKDAY_SHORT = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

function mondayOf(ymd) {
  const d = new Date(`${ymd}T12:00:00`);
  const diff = (d.getDay() + 6) % 7;
  return addDays(ymd, -diff);
}

export default function RosterPage({ admin }) {
  const [start, setStart] = useState(() => mondayOf(todayYmd()));
  const { data, error, loading, reload, setData } = useLoad(() => admin.get('roster/week', { start }), [admin, start]);
  const [shiftDialog, setShiftDialog] = useState(null);
  const [absenceDialog, setAbsenceDialog] = useState(null);
  const notify = useToast();

  const today = todayYmd();
  const canEdit = !!data?.week.canEdit;

  const byCell = useMemo(() => {
    const map = new Map();
    (data?.shifts ?? []).forEach((s) => {
      const key = `${s.caregiverId}|${s.date}`;
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(s);
    });
    return map;
  }, [data]);

  const absenceOn = (caregiverId, date) => (data?.absences ?? []).find((a) => a.caregiverId === caregiverId && a.from <= date && a.to >= date);

  const weekMinutes = (caregiverId) => (data?.shifts ?? []).filter((s) => s.caregiverId === caregiverId).reduce((n, s) => n + s.minutes, 0);

  const copyPrevious = async () => {
    if (!window.confirm(`Dienste der Vorwoche in die Woche ab ${formatDate(start)} kopieren? Bestehende Dienste bleiben erhalten, Überschneidungen und Abwesenheiten werden übersprungen.`)) return;
    try {
      const res = await admin.post('roster/copy-week', { from: addDays(start, -7), to: start });
      setData(res);
      notify({ type: 'success', text: `${res.result.created} Dienste kopiert, ${res.result.skipped} übersprungen.` });
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  return (
    <div>
      <PageHeader
        title="Dienstplan"
        subtitle={data ? `KW ${data.week.isoWeek} · ${formatDate(data.week.start)} – ${formatDate(data.week.end)}` : ' '}
        actions={
          <>
            <Button onClick={() => setStart(addDays(start, -7))} aria-label="Vorwoche">‹</Button>
            <Button onClick={() => setStart(mondayOf(todayYmd()))}>Diese Woche</Button>
            <Button onClick={() => setStart(addDays(start, 7))} aria-label="Nächste Woche">›</Button>
            {canEdit && <Button onClick={copyPrevious}>Vorwoche übernehmen</Button>}
            {canEdit && <Button variant="primary" onClick={() => setAbsenceDialog({})}>Abwesenheit eintragen</Button>}
          </>
        }
      />
      <ErrorBox error={error} onRetry={reload} />
      {!data && loading && <Loading />}
      {data && (
        <div className="cd-scroll overflow-x-auto p-6">
          <table className="w-full min-w-[960px] border-separate border-spacing-0 rounded-xl border border-slate-200 bg-white text-sm shadow-sm">
            <thead>
              <tr>
                <th className="sticky left-0 z-10 w-56 rounded-tl-xl border-b border-slate-200 bg-white px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Pflegekraft</th>
                {data.week.days.map((d, i) => (
                  <th key={d} className={`border-b border-l border-slate-200 px-2 py-2 text-left text-xs font-semibold ${d === today ? 'bg-brand-50 text-brand-700' : 'text-slate-600'}`}>
                    {WEEKDAY_SHORT[i]} <span className="font-normal">{formatDate(d).slice(0, 6)}</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.caregivers.map((c) => {
                const minutes = weekMinutes(c.id);
                const over = minutes > c.weeklyHours * 60 * 1.1;
                return (
                  <tr key={c.id}>
                    <td className="sticky left-0 z-10 border-b border-slate-100 bg-white px-3 py-2 align-top">
                      <div className="flex items-center gap-2">
                        <span className="h-3 w-3 shrink-0 rounded-full" style={{ background: c.color ?? '#94a3b8' }} />
                        <span className="truncate font-medium">{c.name}</span>
                      </div>
                      <div className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                        <Badge>{QUALIFICATION_SHORT[c.qualification]}</Badge>
                        <span className={over ? 'font-semibold text-red-600' : ''} title="geplant / vertraglich">
                          {(minutes / 60).toFixed(1).replace('.', ',')} / {String(c.weeklyHours).replace('.', ',')} h
                        </span>
                      </div>
                    </td>
                    {data.week.days.map((d) => {
                      const absence = absenceOn(c.id, d);
                      const shifts = byCell.get(`${c.id}|${d}`) ?? [];
                      return (
                        <td key={d} className={`group border-b border-l border-slate-100 p-1 align-top ${d === today ? 'bg-brand-50/40' : ''}`}>
                          {absence ? (
                            <button
                              type="button"
                              disabled={!canEdit}
                              onClick={() => setAbsenceDialog(absence)}
                              className="w-full rounded-md border border-amber-200 bg-amber-50 px-1.5 py-1 text-left text-xs text-amber-900"
                              title={absence.note || absence.typeLabel}
                            >
                              {absence.typeLabel}
                            </button>
                          ) : (
                            <div className="space-y-1">
                              {shifts.map((s) => (
                                <button
                                  key={s.id}
                                  type="button"
                                  disabled={!canEdit}
                                  onClick={() => setShiftDialog({ ...s, caregiverName: c.name })}
                                  className={`block w-full rounded-md border px-1.5 py-1 text-left text-xs ${SHIFT_COLORS[s.type] ?? 'bg-slate-100'}`}
                                >
                                  <span className="font-semibold">{s.typeLabel}</span>
                                  <span className="block tabular-nums">{s.start}–{s.end}</span>
                                </button>
                              ))}
                              {canEdit && (
                                <button
                                  type="button"
                                  onClick={() => setShiftDialog({ caregiverId: c.id, caregiverName: c.name, date: d })}
                                  className="w-full rounded-md border border-dashed border-slate-300 py-0.5 text-xs text-slate-400 opacity-0 transition hover:border-brand-400 hover:text-brand-600 focus:opacity-100 group-hover:opacity-100"
                                  aria-label={`Dienst für ${c.name} am ${formatDate(d)} anlegen`}
                                >
                                  +
                                </button>
                              )}
                            </div>
                          )}
                        </td>
                      );
                    })}
                  </tr>
                );
              })}
            </tbody>
          </table>
          {data.caregivers.length === 0 && <p className="mt-4 text-sm text-slate-500">Noch keine aktiven Pflegekräfte. Lege sie im Bereich „Team“ an.</p>}
        </div>
      )}

      {shiftDialog && (
        <ShiftDialog
          admin={admin}
          shift={shiftDialog}
          types={data.shiftTypes}
          onClose={() => setShiftDialog(null)}
          onSaved={(payload) => { setData(payload); setShiftDialog(null); }}
        />
      )}
      {absenceDialog && (
        <AbsenceDialog
          admin={admin}
          absence={absenceDialog}
          caregivers={data.caregivers}
          types={data.absenceTypes}
          defaultDate={start <= today && today <= addDays(start, 6) ? today : start}
          onClose={() => setAbsenceDialog(null)}
          onSaved={(payload) => {
            setData(payload);
            setAbsenceDialog(null);
            if (payload.result) {
              const r = payload.result;
              const details = [
                r.cancelledShifts ? `${r.cancelledShifts} Dienst(e) storniert` : null,
                ...r.unstaffedTours.map((t) => `Tour wieder unbesetzt: ${t}`),
                ...r.runningTours.map((t) => `Tour läuft bereits – im Einsatzplan per SOS unterbrechen: ${t}`),
              ].filter(Boolean);
              notify({ type: r.runningTours.length ? 'warning' : 'success', text: 'Abwesenheit gespeichert.', details });
            }
          }}
        />
      )}
    </div>
  );
}

function ShiftDialog({ admin, shift, types, onClose, onSaved }) {
  const isNew = !shift.id;
  const { values, setValues, set, busy, submit } = useForm({
    type: shift.type ?? 'Frueh',
    start: shift.start ?? '06:00',
    end: shift.end ?? '14:00',
  });

  const save = () => submit((v) => admin.post(isNew ? 'roster/shift' : `roster/shift/${shift.id}`, {
    caregiverId: shift.caregiverId,
    date: shift.date,
    type: v.type,
    start: v.start,
    end: v.end,
  })).then((res) => res && onSaved(res));

  const remove = () => {
    if (!window.confirm('Dienst entfernen? Vergangene Dienste werden storniert, zukünftige gelöscht.')) return;
    submit(() => admin.post(`roster/shift/${shift.id}/delete`)).then((res) => res && onSaved(res));
  };

  return (
    <Modal
      title={`${isNew ? 'Dienst anlegen' : 'Dienst bearbeiten'} – ${shift.caregiverName}, ${formatDate(shift.date)}`}
      onClose={onClose}
      footer={
        <>
          {!isNew && <Button variant="dangerGhost" className="mr-auto" onClick={remove} disabled={busy}>Entfernen</Button>}
          <Button onClick={onClose}>Abbrechen</Button>
          <Button variant="primary" onClick={save} disabled={busy}>Speichern</Button>
        </>
      }
    >
      <div className="mb-3 flex flex-wrap gap-1.5">
        {PRESETS.map((p) => (
          <Button key={p.label} onClick={() => setValues({ type: p.type, start: p.start, end: p.end })}>{p.label} {p.start}–{p.end}</Button>
        ))}
      </div>
      <div className="grid grid-cols-3 gap-3">
        <Field label="Dienstart">
          <Select value={values.type} onChange={set('type')} options={Object.entries(types).map(([value, label]) => ({ value, label }))} />
        </Field>
        <Field label="Beginn"><Input type="time" value={values.start} onChange={set('start')} /></Field>
        <Field label="Ende" hint={values.type === 'Nacht' ? 'Ende am Folgetag' : undefined}><Input type="time" value={values.end} onChange={set('end')} /></Field>
      </div>
    </Modal>
  );
}

function AbsenceDialog({ admin, absence, caregivers, types, defaultDate, onClose, onSaved }) {
  const isNew = !absence.id;
  const { values, set, busy, submit, errors } = useForm({
    caregiverId: absence.caregiverId ? String(absence.caregiverId) : '',
    type: absence.type ?? 'Urlaub',
    from: absence.from ?? defaultDate,
    to: absence.to ?? defaultDate,
    note: absence.note ?? '',
  });

  const save = () => submit((v) => admin.post(isNew ? 'roster/absence' : `roster/absence/${absence.id}`, {
    ...v,
    caregiverId: Number(v.caregiverId),
  })).then((res) => res && onSaved(res));

  const remove = () => {
    if (!window.confirm('Abwesenheit löschen? Bereits stornierte Dienste werden nicht wiederhergestellt.')) return;
    submit(() => admin.post(`roster/absence/${absence.id}/delete`)).then((res) => res && onSaved(res));
  };

  return (
    <Modal
      title={isNew ? 'Abwesenheit eintragen' : 'Abwesenheit bearbeiten'}
      onClose={onClose}
      footer={
        <>
          {!isNew && <Button variant="dangerGhost" className="mr-auto" onClick={remove} disabled={busy}>Löschen</Button>}
          <Button onClick={onClose}>Abbrechen</Button>
          <Button variant="primary" onClick={save} disabled={busy || !values.caregiverId}>Speichern</Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-3">
        <Field label="Pflegekraft" className="col-span-2" error={errors.CaregiverID}>
          <Select value={values.caregiverId} onChange={set('caregiverId')} placeholder="Bitte wählen …" options={caregivers.map((c) => ({ value: String(c.id), label: c.name }))} />
        </Field>
        <Field label="Art">
          <Select value={values.type} onChange={set('type')} options={Object.entries(types).map(([value, label]) => ({ value, label }))} />
        </Field>
        <div />
        <Field label="Von"><Input type="date" value={values.from} onChange={set('from')} /></Field>
        <Field label="Bis" error={errors.DateTo}><Input type="date" value={values.to} onChange={set('to')} /></Field>
        <Field label="Notiz" className="col-span-2"><Input value={values.note} onChange={set('note')} maxLength={255} /></Field>
      </div>
      <p className="mt-3 text-xs text-slate-500">Dienste im Zeitraum werden storniert. Noch nicht begonnene Touren der Pflegekraft werden wieder unbesetzt und erscheinen im Einsatzplan.</p>
    </Modal>
  );
}
