import { useState } from 'react';
import { Badge, Button, Checkbox, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, useForm, useLoad, useToast } from '../components/ui.jsx';

export default function TasksPage({ admin, can, P }) {
  const { data, error, loading, reload } = useLoad(() => admin.get('tasks/list'), [admin]);
  const [editing, setEditing] = useState(null);
  const canEdit = can(P.MASTERDATA);

  return (
    <div>
      <PageHeader
        title="Leistungskatalog"
        subtitle="Mindestqualifikation und Planzeit je Leistung bestimmen Qualifikation und Dauer der Einsätze."
        actions={canEdit && <Button variant="primary" onClick={() => setEditing({})}>Leistung anlegen</Button>}
      />
      <ErrorBox error={error} onRetry={reload} />
      {!data && loading && <Loading />}
      {data && (
        <div className="p-6">
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-4 py-2">Code</th><th className="px-4 py-2">Leistung</th><th className="px-4 py-2">Kategorie</th><th className="px-4 py-2">Mindestqualifikation</th><th className="px-4 py-2">Planzeit</th><th className="px-4 py-2">Verwendet</th><th className="px-4 py-2" /></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.tasks.map((t) => (
                  <tr key={t.id} className={t.isActive ? '' : 'text-slate-400'}>
                    <td className="px-4 py-2 font-mono text-xs">{t.code}</td>
                    <td className="px-4 py-2 font-medium">{t.title} {!t.isActive && <Badge>inaktiv</Badge>}</td>
                    <td className="px-4 py-2">{t.categoryLabel}</td>
                    <td className="px-4 py-2"><Badge tone={t.minQualification === 'Fachkraft' ? 'red' : t.minQualification === 'PflegehelferLG1' ? 'amber' : 'slate'}>{t.minQualificationLabel}</Badge></td>
                    <td className="px-4 py-2 tabular-nums">{t.durationMinutes} Min.</td>
                    <td className="px-4 py-2 text-slate-500">{t.usage}×</td>
                    <td className="px-4 py-2 text-right">{canEdit && <Button variant="ghost" onClick={() => setEditing(t)}>Bearbeiten</Button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
      {editing && data && (
        <TaskForm
          admin={admin}
          task={editing.id ? editing : null}
          categories={data.categories}
          qualifications={data.qualifications}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
    </div>
  );
}

function TaskForm({ admin, task, categories, qualifications, onClose, onSaved }) {
  const isNew = !task;
  const notify = useToast();
  const { values, set, errors, busy, submit } = useForm({
    code: task?.code ?? '',
    title: task?.title ?? '',
    category: task?.category ?? 'SGBXI',
    minQualification: task?.minQualification ?? 'Hilfskraft',
    durationMinutes: task?.durationMinutes ?? 10,
    isActive: task?.isActive ?? true,
  });
  const save = () => submit((v) => admin.post(isNew ? 'tasks/create' : `tasks/${task.id}`, { ...v, durationMinutes: Number(v.durationMinutes) }), 'Gespeichert.')
    .then((res) => res && onSaved());

  const remove = async () => {
    if (!window.confirm(`Leistung „${task.title}“ löschen?`)) return;
    try {
      await admin.post(`tasks/${task.id}/delete`);
      notify({ type: 'success', text: 'Leistung gelöscht.' });
      onSaved();
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  return (
    <Modal
      title={isNew ? 'Leistung anlegen' : 'Leistung bearbeiten'}
      onClose={onClose}
      footer={
        <>
          {!isNew && task.usage === 0 && <Button variant="dangerGhost" className="mr-auto" onClick={remove}>Löschen</Button>}
          <Button onClick={onClose}>Abbrechen</Button>
          <Button variant="primary" onClick={save} disabled={busy}>Speichern</Button>
        </>
      }
    >
      <div className="grid grid-cols-4 gap-3">
        <Field label="Code" error={errors.Code}><Input value={values.code} onChange={set('code')} placeholder="LK 01" /></Field>
        <Field label="Bezeichnung" className="col-span-3" error={errors.Title}><Input value={values.title} onChange={set('title')} /></Field>
        <Field label="Kategorie" className="col-span-2">
          <Select value={values.category} onChange={set('category')} options={Object.entries(categories).map(([value, label]) => ({ value, label }))} />
        </Field>
        <Field label="Mindestqualifikation" className="col-span-2">
          <Select value={values.minQualification} onChange={set('minQualification')} options={Object.entries(qualifications).map(([value, label]) => ({ value, label }))} />
        </Field>
        <Field label="Planzeit (Min.)" error={errors.DurationMinutes}><Input type="number" min={1} max={480} value={values.durationMinutes} onChange={set('durationMinutes')} /></Field>
        <div className="col-span-3 flex items-end pb-1.5"><Checkbox label="aktiv (in Pflegeplänen wählbar)" checked={values.isActive} onChange={set('isActive')} /></div>
      </div>
      {!isNew && task.usage > 0 && <p className="mt-3 text-xs text-amber-700">Wird in {task.usage} Pflegeplan-Position(en) verwendet. Änderungen der Qualifikation wirken auf alle neu erzeugten Einsätze.</p>}
    </Modal>
  );
}
