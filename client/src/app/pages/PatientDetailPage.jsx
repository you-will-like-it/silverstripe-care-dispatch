import { useMemo, useState } from 'react';
import { Badge, Button, Card, Empty, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, Textarea, WeekdayPicker, useForm, useLoad, useToast, formatDate } from '../components/ui.jsx';
import { Link, useRouter } from '../router.jsx';
import PatientForm from '../components/PatientForm.jsx';
import AuditList from '../components/AuditList.jsx';
import { QUALIFICATION_SHORT } from '../../shared/labels.js';

const SLOTS = [
  ['Frueh', 'Früh', ['06:00', '11:00']],
  ['Mittag', 'Mittag', ['11:30', '13:30']],
  ['Spaet', 'Spät', ['16:30', '21:00']],
  ['Nacht', 'Nacht', ['21:00', '23:30']],
];

export default function PatientDetailPage({ admin, can, P, id }) {
  const { navigate } = useRouter();
  const notify = useToast();
  const { data, error, reload, setData } = useLoad(() => admin.get(`patients/${id}`), [admin, id]);
  const caregivers = useLoad(() => admin.get('staff/caregivers'), [admin]);
  const tasks = useLoad(() => admin.get('tasks/list'), [admin]);
  const [tab, setTab] = useState('plan');
  const [editing, setEditing] = useState(false);
  const [planItem, setPlanItem] = useState(null);

  const patient = data?.patient;
  const canMaster = can(P.MASTERDATA);
  const canPlan = can(P.CAREPLAN);

  const update = (p) => setData({ patient: p });

  const toggleActive = async () => {
    try {
      const res = await admin.post(`patients/${id}`, { isActive: !patient.isActive });
      update(res.patient);
      notify({ type: 'success', text: res.patient.isActive ? 'Patient aktiviert.' : 'Patient deaktiviert – wird nicht mehr geplant.' });
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  const remove = async () => {
    if (!window.confirm(`${patient.name} endgültig löschen? Nur möglich, solange keine Einsätze dokumentiert sind.`)) return;
    try {
      await admin.post(`patients/${id}/delete`);
      notify({ type: 'success', text: 'Patient gelöscht.' });
      navigate('/patienten');
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (!patient) return <Loading />;

  return (
    <div>
      <PageHeader
        back={<Link to="/patienten" className="text-xs text-brand-600 hover:underline">← Patienten</Link>}
        title={patient.name}
        subtitle={[patient.street, `${patient.postalCode} ${patient.city}`, patient.phone].filter(Boolean).join(' · ')}
        actions={canMaster && (
          <>
            <Button onClick={() => setEditing(true)}>Stammdaten bearbeiten</Button>
            <Button onClick={toggleActive}>{patient.isActive ? 'Deaktivieren' : 'Aktivieren'}</Button>
            {!patient.hasVisits && <Button variant="dangerGhost" onClick={remove}>Löschen</Button>}
          </>
        )}
      />
      <div className="flex gap-1 border-b border-slate-200 bg-white px-6">
        <Tab active={tab === 'plan'} onClick={() => setTab('plan')}>Pflegeplan</Tab>
        <Tab active={tab === 'info'} onClick={() => setTab('info')}>Stammdaten & Wünsche</Tab>
        {can(P.AUDIT) && <Tab active={tab === 'history'} onClick={() => setTab('history')}>Historie</Tab>}
      </div>

      <div className="space-y-4 p-6">
        {!patient.isActive && <p className="rounded-lg bg-slate-100 p-3 text-sm text-slate-700">Patient ist inaktiv und wird nicht mehr geplant.</p>}

        {tab === 'plan' && (
          <CarePlan patient={patient} canEdit={canPlan} onEdit={setPlanItem} />
        )}

        {tab === 'info' && (
          <div className="grid gap-4 lg:grid-cols-2">
            <Card title="Hinweise">
              <dl className="space-y-3 text-sm">
                <div><dt className="font-medium text-slate-500">Zugang</dt><dd className="whitespace-pre-line">{patient.accessNotes || '–'}</dd></div>
                <div><dt className="font-medium text-slate-500">Pflegehinweise</dt><dd className="whitespace-pre-line">{patient.careNotes || '–'}</dd></div>
              </dl>
            </Card>
            <Coordinates admin={admin} patient={patient} canEdit={canMaster} onUpdate={update} />
            <Wishes admin={admin} patient={patient} caregivers={caregivers.data?.caregivers ?? []} canEdit={canMaster} onUpdate={update} />
          </div>
        )}

        {tab === 'history' && <AuditList admin={admin} filter={{ patient: id }} />}
      </div>

      {editing && <PatientForm admin={admin} patient={patient} onClose={() => setEditing(false)} onSaved={(p) => { update(p); setEditing(false); }} />}
      {planItem && (
        <CarePlanForm
          admin={admin}
          patient={patient}
          item={planItem}
          tasks={(tasks.data?.tasks ?? []).filter((t) => t.isActive || t.id === planItem.taskId)}
          onClose={() => setPlanItem(null)}
          onSaved={(p) => { update(p); setPlanItem(null); }}
        />
      )}
    </div>
  );
}

function Tab({ active, ...props }) {
  return <button type="button" className={`-mb-px border-b-2 px-3 py-2.5 text-sm ${active ? 'border-brand-600 font-semibold text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`} {...props} />;
}

function CarePlan({ patient, canEdit, onEdit }) {
  const bySlot = useMemo(() => {
    const map = {};
    patient.carePlan.forEach((i) => {
      (map[i.slot] ??= []).push(i);
    });
    return map;
  }, [patient]);

  return (
    <>
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-600">Je Slot entsteht pro Tag ein Einsatz. Die benötigte Qualifikation ergibt sich aus der anspruchsvollsten Leistung.</p>
        {canEdit && <Button variant="primary" onClick={() => onEdit({ slot: 'Frueh' })}>Position hinzufügen</Button>}
      </div>
      {patient.carePlan.length === 0 && <Empty>Noch kein Pflegeplan. Ohne Positionen werden keine Einsätze erzeugt.</Empty>}
      {SLOTS.filter(([slot]) => bySlot[slot]).map(([slot, label]) => {
        const items = bySlot[slot];
        const minutes = items.reduce((n, i) => n + i.durationMinutes, 0);
        const req = patient.slotRequirements[slot];
        return (
          <Card
            key={slot}
            title={
              <span className="flex items-center gap-2">
                {label}
                <Badge tone={req?.value === 'Fachkraft' ? 'red' : req?.value === 'PflegehelferLG1' ? 'amber' : 'slate'}>mind. {req?.label}</Badge>
                <span className="text-xs font-normal text-slate-500">max. {minutes} Min.</span>
                {items.some((i) => i.requiredStaff > 1) && <Badge tone="blue">2 Personen</Badge>}
              </span>
            }
            actions={canEdit && <Button onClick={() => onEdit({ slot })}>+ Leistung</Button>}
          >
            <table className="w-full text-sm">
              <thead className="text-left text-xs text-slate-500">
                <tr><th className="pb-1">Leistung</th><th>Tage</th><th>Fenster</th><th>Anweisung</th><th>Gültig</th><th /></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {items.map((i) => (
                  <tr key={i.id} className="align-top">
                    <td className="py-2 pr-3">
                      <span className="text-xs text-slate-500">{i.taskCode}</span> {i.taskTitle}
                      <span className="ml-1"><Badge>{QUALIFICATION_SHORT[i.minQualification]}</Badge></span>
                      {i.requiredStaff > 1 && <span className="ml-1"><Badge tone="blue">2P</Badge></span>}
                    </td>
                    <td className="py-2 pr-3 whitespace-nowrap">{i.weekdaysLabel}</td>
                    <td className="py-2 pr-3 whitespace-nowrap tabular-nums">{i.windowStart}–{i.windowEnd}</td>
                    <td className="py-2 pr-3">{i.instruction || '–'}</td>
                    <td className="py-2 pr-3 whitespace-nowrap text-xs text-slate-500">
                      {i.validFrom || i.validUntil ? `${i.validFrom ? formatDate(i.validFrom) : '…'} – ${i.validUntil ? formatDate(i.validUntil) : '…'}` : 'unbefristet'}
                    </td>
                    <td className="py-2 text-right">{canEdit && <Button variant="ghost" onClick={() => onEdit(i)}>Bearbeiten</Button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        );
      })}
    </>
  );
}

function CarePlanForm({ admin, patient, item, tasks, onClose, onSaved }) {
  const isNew = !item.id;
  const slotDefaults = Object.fromEntries(SLOTS.map(([s, , w]) => [s, w]));
  const { values, set, setValues, errors, busy, submit } = useForm({
    taskId: item.taskId ? String(item.taskId) : '',
    slot: item.slot ?? 'Frueh',
    weekdays: item.weekdays ?? [1, 2, 3, 4, 5, 6, 7],
    windowStart: item.windowStart ?? slotDefaults[item.slot ?? 'Frueh'][0],
    windowEnd: item.windowEnd ?? slotDefaults[item.slot ?? 'Frueh'][1],
    validFrom: item.validFrom ?? '',
    validUntil: item.validUntil ?? '',
    requiredStaff: String(item.requiredStaff ?? 1),
    instruction: item.instruction ?? '',
  });
  const notify = useToast();
  const selectedTask = tasks.find((t) => String(t.id) === values.taskId);

  const changeSlot = (e) => {
    const slot = e.target.value;
    setValues((v) => ({ ...v, slot, windowStart: slotDefaults[slot][0], windowEnd: slotDefaults[slot][1] }));
  };

  const save = () => submit((v) => admin.post(isNew ? `patients/${patient.id}/careplan` : `patients/careplan/${item.id}`, {
    ...v,
    taskId: Number(v.taskId),
    requiredStaff: Number(v.requiredStaff),
  }), 'Pflegeplan gespeichert.').then((res) => res && onSaved(res.patient));

  const remove = async () => {
    if (!window.confirm('Position aus dem Pflegeplan entfernen? Bereits erzeugte Einsätze bleiben unverändert.')) return;
    try {
      const res = await admin.post(`patients/careplan/${item.id}/delete`);
      notify({ type: 'success', text: 'Position entfernt.' });
      onSaved(res.patient);
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  return (
    <Modal
      title={isNew ? 'Pflegeplan-Position hinzufügen' : 'Pflegeplan-Position bearbeiten'}
      onClose={onClose}
      width="max-w-2xl"
      footer={
        <>
          {!isNew && <Button variant="dangerGhost" className="mr-auto" onClick={remove} disabled={busy}>Entfernen</Button>}
          <Button onClick={onClose}>Abbrechen</Button>
          <Button variant="primary" onClick={save} disabled={busy || !values.taskId}>Speichern</Button>
        </>
      }
    >
      <div className="grid grid-cols-4 gap-3">
        <Field label="Leistung" className="col-span-3" error={errors.CareTaskID} hint={selectedTask && `${selectedTask.durationMinutes} Min. · mind. ${selectedTask.minQualificationLabel}`}>
          <Select value={values.taskId} onChange={set('taskId')} placeholder="Bitte wählen …" options={tasks.map((t) => ({ value: String(t.id), label: `${t.code} – ${t.title}` }))} />
        </Field>
        <Field label="Slot">
          <Select value={values.slot} onChange={changeSlot} options={SLOTS.map(([value, label]) => ({ value, label }))} />
        </Field>
        <Field label="Wochentage" className="col-span-4" error={errors.Weekdays}>
          <WeekdayPicker value={values.weekdays} onChange={set('weekdays')} />
        </Field>
        <Field label="Zeitfenster von"><Input type="time" value={values.windowStart} onChange={set('windowStart')} /></Field>
        <Field label="bis" error={errors.WindowEnd}><Input type="time" value={values.windowEnd} onChange={set('windowEnd')} /></Field>
        <Field label="Gültig ab" hint="z. B. Verordnung"><Input type="date" value={values.validFrom} onChange={set('validFrom')} /></Field>
        <Field label="Gültig bis" error={errors.ValidUntil}><Input type="date" value={values.validUntil} onChange={set('validUntil')} /></Field>
        <Field label="Anweisung" className="col-span-4" hint="Medikament und Dosierung, Wundversorgung … (wird in der mobilen Checkliste angezeigt)">
          <Textarea rows={2} value={values.instruction} onChange={set('instruction')} />
        </Field>
        <Field label="Personen" className="col-span-2">
          <Select value={values.requiredStaff} onChange={set('requiredStaff')} options={[{ value: '1', label: '1 Person' }, { value: '2', label: '2 Personen (Doppeleinsatz)' }]} />
        </Field>
      </div>
    </Modal>
  );
}

function Coordinates({ admin, patient, canEdit, onUpdate }) {
  const notify = useToast();
  const [manual, setManual] = useState(false);
  const [lat, setLat] = useState(patient.lat ?? '');
  const [lng, setLng] = useState(patient.lng ?? '');

  const send = async (body) => {
    try {
      const res = await admin.post(`patients/${patient.id}/coordinates`, body);
      onUpdate(res.patient);
      setManual(false);
      notify({ type: 'success', text: 'Koordinaten gespeichert.' });
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  return (
    <Card title="Verortung (Routenplanung)">
      {patient.lat !== null ? (
        <p className="text-sm">
          <Badge tone="green">verortet</Badge> <span className="tabular-nums text-slate-600">{patient.lat.toFixed(5)}, {patient.lng.toFixed(5)}</span>
          {' '}<a className="text-xs text-brand-600 hover:underline" target="_blank" rel="noopener noreferrer" href={`https://www.openstreetmap.org/?mlat=${patient.lat}&mlon=${patient.lng}#map=17/${patient.lat}/${patient.lng}`}>Karte</a>
        </p>
      ) : (
        <p className="text-sm"><Badge tone="red">nicht verortet</Badge> <span className="text-slate-600">Wird bei der Routenoptimierung nicht berücksichtigt.</span></p>
      )}
      {canEdit && !manual && (
        <div className="mt-3 flex gap-2">
          <Button onClick={() => send({})}>Adresse neu verorten</Button>
          <Button onClick={() => setManual(true)}>Manuell eintragen</Button>
        </div>
      )}
      {manual && (
        <div className="mt-3 flex items-end gap-2">
          <Field label="Breitengrad"><Input value={lat} onChange={(e) => setLat(e.target.value)} placeholder="52.5200" /></Field>
          <Field label="Längengrad"><Input value={lng} onChange={(e) => setLng(e.target.value)} placeholder="13.4050" /></Field>
          <Button variant="primary" onClick={() => send({ lat: Number(String(lat).replace(',', '.')), lng: Number(String(lng).replace(',', '.')) })}>Speichern</Button>
          <Button variant="ghost" onClick={() => setManual(false)}>Abbrechen</Button>
        </div>
      )}
    </Card>
  );
}

function Wishes({ admin, patient, caregivers, canEdit, onUpdate }) {
  const notify = useToast();
  const [preferred, setPreferred] = useState(patient.preferredCaregiverIds);
  const [excluded, setExcluded] = useState(patient.excludedCaregiverIds);
  const dirty = String(preferred) !== String(patient.preferredCaregiverIds) || String(excluded) !== String(patient.excludedCaregiverIds);

  const cycle = (id) => {
    if (!canEdit) return;
    if (preferred.includes(id)) {
      setPreferred(preferred.filter((x) => x !== id));
      setExcluded([...excluded, id]);
    } else if (excluded.includes(id)) {
      setExcluded(excluded.filter((x) => x !== id));
    } else {
      setPreferred([...preferred, id]);
    }
  };

  const save = async () => {
    try {
      const res = await admin.post(`patients/${patient.id}`, { preferredCaregiverIds: preferred, excludedCaregiverIds: excluded });
      onUpdate(res.patient);
      notify({ type: 'success', text: 'Wünsche gespeichert.' });
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  return (
    <Card title="Wünsche zu Pflegekräften" actions={canEdit && dirty && <Button variant="primary" onClick={save}>Speichern</Button>}>
      <p className="mb-2 text-xs text-slate-500">Klick wechselt: neutral → bevorzugt (Routenplanung bevorzugt) → ausgeschlossen (nie zuweisbar).</p>
      <div className="flex flex-wrap gap-1.5">
        {caregivers.map((c) => {
          const state = preferred.includes(c.id) ? 'preferred' : excluded.includes(c.id) ? 'excluded' : 'neutral';
          const cls = { preferred: 'border-emerald-500 bg-emerald-50 text-emerald-800', excluded: 'border-red-400 bg-red-50 text-red-700 line-through', neutral: 'border-slate-300 bg-white text-slate-600' }[state];
          return (
            <button key={c.id} type="button" disabled={!canEdit} onClick={() => cycle(c.id)} className={`rounded-full border px-2.5 py-1 text-xs ${cls}`}>
              {state === 'preferred' && '★ '}{c.name}
            </button>
          );
        })}
      </div>
    </Card>
  );
}
