import { useEffect, useMemo, useState } from 'react';
import { DndContext, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Button, Card, Checkbox, ErrorBox, Field, Input, Loading, PageHeader, Select, WeekdayPicker, useForm, useLoad, useToast } from '../components/ui.jsx';
import { Link, useRouter } from '../router.jsx';
import { toHHMM, toMinutes } from '../../shared/time.js';

const SLOT_LABELS = { Frueh: 'Früh', Mittag: 'Mittag', Spaet: 'Spät', Nacht: 'Nacht' };
const TRAVEL_MINUTES = 10;

let rowKey = 0;
const withKey = (item) => ({ ...item, key: `r${++rowKey}` });

export default function TemplateDetailPage({ admin, id }) {
  const { navigate } = useRouter();
  const notify = useToast();
  const { data, error, reload, setData } = useLoad(() => admin.get(`templates/${id}`), [admin, id]);
  const options = useLoad(() => admin.get('patients/options'), [admin]);
  const caregivers = useLoad(() => admin.get('staff/caregivers'), [admin]);
  const meta = useLoad(() => admin.get('templates/list'), [admin]);
  const template = data?.template;

  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (!template || !options.data || !caregivers.data || !meta.data) return <Loading />;

  const remove = async () => {
    if (!window.confirm(`Tourvorlage „${template.title}“ löschen? Bereits erzeugte Tagestouren bleiben erhalten.`)) return;
    try {
      await admin.post(`templates/${id}/delete`);
      notify({ type: 'success', text: 'Tourvorlage gelöscht.' });
      navigate('/touren');
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  return (
    <div>
      <PageHeader
        back={<Link to="/touren" className="text-xs text-brand-600 hover:underline">← Tourvorlagen</Link>}
        title={template.title}
        subtitle={`${template.stopCount} Stopps · ${template.weekdaysLabel}`}
        actions={<Button variant="dangerGhost" onClick={remove}>Löschen</Button>}
      />
      <div className="grid gap-4 p-6 xl:grid-cols-[22rem_1fr]">
        <MetaForm
          key={`m${template.id}`}
          admin={admin}
          template={template}
          shiftTypes={meta.data.shiftTypes}
          caregivers={caregivers.data.caregivers}
          onSaved={(t) => setData({ template: t })}
        />
        <Stops
          key={`s${template.id}`}
          admin={admin}
          template={template}
          patients={options.data.patients}
          onSaved={(t) => setData({ template: t })}
        />
      </div>
    </div>
  );
}

function MetaForm({ admin, template, shiftTypes, caregivers, onSaved }) {
  const { values, set, errors, busy, submit } = useForm({
    title: template.title,
    shiftType: template.shiftType,
    weekdays: template.weekdays,
    defaultCaregiverId: template.defaultCaregiverId ? String(template.defaultCaregiverId) : '',
    isActive: template.isActive,
  });
  const save = () => submit((v) => admin.post(`templates/${template.id}`, { ...v, defaultCaregiverId: Number(v.defaultCaregiverId) || 0 }), 'Gespeichert.')
    .then((res) => res && onSaved(res.template));

  return (
    <Card title="Eigenschaften" actions={<Button variant="primary" onClick={save} disabled={busy}>Speichern</Button>}>
      <div className="space-y-3">
        <Field label="Titel" error={errors.Title}><Input value={values.title} onChange={set('title')} /></Field>
        <Field label="Dienst"><Select value={values.shiftType} onChange={set('shiftType')} options={Object.entries(shiftTypes).map(([value, label]) => ({ value, label }))} /></Field>
        <Field label="Gültig an" error={errors.Weekdays}><WeekdayPicker value={values.weekdays} onChange={set('weekdays')} /></Field>
        <Field label="Bezugspflegekraft" hint="Wird beim Generieren automatisch eingeplant, wenn sie Dienst hat und alle Regeln erfüllt.">
          <Select
            value={values.defaultCaregiverId}
            onChange={set('defaultCaregiverId')}
            placeholder="– keine –"
            options={caregivers.filter((c) => c.qualification !== 'Azubi').map((c) => ({ value: String(c.id), label: `${c.name} (${c.qualificationLabel})` }))}
          />
        </Field>
        <Checkbox label="aktiv" checked={values.isActive} onChange={set('isActive')} />
      </div>
    </Card>
  );
}

function Stops({ admin, template, patients, onSaved }) {
  const notify = useToast();
  const [rows, setRows] = useState(() => template.items.map(withKey));
  const [busy, setBusy] = useState(false);
  const [addPatient, setAddPatient] = useState('');
  const [addSlot, setAddSlot] = useState(template.shiftType === 'Spaet' ? 'Spaet' : 'Frueh');
  const [startAt, setStartAt] = useState(template.items[0]?.plannedStart ?? (template.shiftType === 'Spaet' ? '17:00' : '06:30'));
  const patientsById = useMemo(() => new Map(patients.map((p) => [p.id, p])), [patients]);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

  useEffect(() => setRows(template.items.map(withKey)), [template.items]);

  const planned = (row) => row.durationMinutes || patientsById.get(row.patientId)?.slots?.[row.slot] || 0;
  const dirty = JSON.stringify(rows.map(({ key, ...r }) => [r.patientId, r.slot, r.plannedStart, r.durationMinutes]))
    !== JSON.stringify(template.items.map((r) => [r.patientId, r.slot, r.plannedStart, r.durationMinutes]));

  const recalc = () => {
    let t = toMinutes(startAt);
    setRows(rows.map((r) => {
      const next = { ...r, plannedStart: toHHMM(t) };
      t += Math.max(10, planned(r)) + TRAVEL_MINUTES;
      t = Math.ceil(t / 5) * 5;
      return next;
    }));
  };

  const add = () => {
    const p = patientsById.get(Number(addPatient));
    if (!p) return;
    if (rows.some((r) => r.patientId === p.id && r.slot === addSlot)) {
      notify({ type: 'warning', text: 'Dieser Patient ist mit diesem Slot bereits auf der Tour.' });
      return;
    }
    const last = rows[rows.length - 1];
    const start = last ? toHHMM(Math.ceil((toMinutes(last.plannedStart) + Math.max(10, planned(last)) + TRAVEL_MINUTES) / 5) * 5) : startAt;
    setRows([...rows, withKey({ patientId: p.id, patientName: p.name, address: p.address, slot: addSlot, plannedStart: start, durationMinutes: 0 })]);
    setAddPatient('');
  };

  const update = (key, patch) => setRows(rows.map((r) => (r.key === key ? { ...r, ...patch } : r)));

  const onDragEnd = ({ active, over }) => {
    if (!over || active.id === over.id) return;
    const from = rows.findIndex((r) => r.key === active.id);
    const to = rows.findIndex((r) => r.key === over.id);
    setRows(arrayMove(rows, from, to));
  };

  const save = async () => {
    setBusy(true);
    try {
      const res = await admin.post(`templates/${template.id}/items`, {
        items: rows.map((r) => ({ patientId: r.patientId, slot: r.slot, plannedStart: r.plannedStart, durationMinutes: Number(r.durationMinutes) || 0 })),
      });
      onSaved(res.template);
      notify({ type: 'success', text: 'Stopps gespeichert. Gilt für alle neu generierten Tage.' });
    } catch (e) {
      notify({ type: 'error', text: e.message });
    } finally {
      setBusy(false);
    }
  };

  const missingPlan = rows.filter((r) => !patientsById.get(r.patientId)?.slots?.[r.slot]);

  return (
    <Card
      title="Stopps"
      actions={
        <>
          <span className="flex items-center gap-1 text-xs text-slate-500">
            ab <Input type="time" className="w-32 py-1" value={startAt} onChange={(e) => setStartAt(e.target.value)} />
          </span>
          <Button onClick={recalc} disabled={rows.length === 0} title={`Zeiten aus Planzeiten + ${TRAVEL_MINUTES} Min. Fahrt berechnen`}>Zeiten berechnen</Button>
          <Button variant="primary" onClick={save} disabled={busy || !dirty}>Speichern</Button>
        </>
      }
    >
      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
        <SortableContext items={rows.map((r) => r.key)} strategy={verticalListSortingStrategy}>
          <ol className="space-y-1.5">
            {rows.map((r, i) => (
              <StopRow key={r.key} row={r} index={i} planned={planned(r)} onChange={(patch) => update(r.key, patch)} onRemove={() => setRows(rows.filter((x) => x.key !== r.key))} />
            ))}
          </ol>
        </SortableContext>
      </DndContext>
      {rows.length === 0 && <p className="py-4 text-center text-sm text-slate-500">Noch keine Stopps.</p>}
      {missingPlan.length > 0 && (
        <p className="mt-2 text-xs text-amber-700">Ohne passende Pflegeplan-Position (Slot) entsteht für {missingPlan.map((r) => r.patientName).join(', ')} kein Einsatz.</p>
      )}
      <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-4">
        <Field label="Patient hinzufügen" className="min-w-[16rem] flex-1">
          <Select value={addPatient} onChange={(e) => setAddPatient(e.target.value)} placeholder="Patient wählen …" options={patients.map((p) => ({ value: String(p.id), label: p.name }))} />
        </Field>
        <Field label="Slot">
          <Select value={addSlot} onChange={(e) => setAddSlot(e.target.value)} options={Object.entries(SLOT_LABELS).map(([value, label]) => ({ value, label }))} />
        </Field>
        <Button onClick={add} disabled={!addPatient}>Hinzufügen</Button>
      </div>
    </Card>
  );
}

function StopRow({ row, index, planned, onChange, onRemove }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: row.key });
  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={`flex items-center gap-2 rounded-lg border bg-white px-2 py-1.5 text-sm ${isDragging ? 'z-10 border-brand-400 shadow-lg' : 'border-slate-200'}`}
    >
      <button type="button" className="cursor-grab px-1 text-slate-400 active:cursor-grabbing" aria-label="Verschieben" {...attributes} {...listeners}>⋮⋮</button>
      <span className="w-5 text-right text-xs text-slate-400">{index + 1}</span>
      <Input type="time" className="w-32 py-1" value={row.plannedStart} onChange={(e) => onChange({ plannedStart: e.target.value })} aria-label="Geplanter Beginn" />
      <div className="min-w-0 flex-1">
        <p className="truncate font-medium">{row.patientName}</p>
        <p className="truncate text-xs text-slate-500">{row.address}</p>
      </div>
      <span className="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{SLOT_LABELS[row.slot]}</span>
      <span className="w-20 text-right text-xs text-slate-500" title="Planzeit aus Pflegeplan">{planned ? `${planned} Min.` : '–'}</span>
      <button type="button" onClick={onRemove} className="rounded px-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Entfernen">✕</button>
    </li>
  );
}
