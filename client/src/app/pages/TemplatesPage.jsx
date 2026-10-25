import { useState } from 'react';
import { Badge, Button, Empty, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, useForm, useLoad } from '../components/ui.jsx';
import { Link, useRouter } from '../router.jsx';

export default function TemplatesPage({ admin }) {
  const { navigate } = useRouter();
  const { data, error, loading, reload } = useLoad(() => admin.get('templates/list'), [admin]);
  const [creating, setCreating] = useState(false);

  return (
    <div>
      <PageHeader
        title="Tourvorlagen"
        subtitle="Wiederkehrende Touren. Aus ihnen und den Pflegeplänen entstehen die Einsätze jedes Tages."
        actions={<Button variant="primary" onClick={() => setCreating(true)}>Neue Tour</Button>}
      />
      <ErrorBox error={error} onRetry={reload} />
      {!data && loading && <Loading />}
      {data && (
        <div className="grid gap-3 p-6 md:grid-cols-2 xl:grid-cols-3">
          {data.templates.length === 0 && <Empty>Noch keine Tourvorlagen.</Empty>}
          {data.templates.map((t) => (
            <Link key={t.id} to={`/touren/${t.id}`} className="block rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300 hover:shadow">
              <div className="flex items-start justify-between gap-2">
                <h2 className="font-semibold text-slate-900">{t.title}</h2>
                {!t.isActive && <Badge>inaktiv</Badge>}
              </div>
              <p className="mt-1 text-sm text-slate-600">
                {data.shiftTypes[t.shiftType]} · {t.weekdaysLabel}
              </p>
              <p className="mt-2 text-sm">
                <strong>{t.stopCount}</strong> Stopps{t.start && <> · ab <span className="tabular-nums">{t.start}</span></>}{t.estimatedMinutes > 0 && <> · ca. {Math.round(t.estimatedMinutes / 6) / 10} h</>}
              </p>
              <p className="mt-1 text-sm text-slate-500">{t.defaultCaregiverName ? `Bezugspflege: ${t.defaultCaregiverName}` : <span className="text-amber-700">ohne Bezugspflegekraft</span>}</p>
            </Link>
          ))}
        </div>
      )}
      {creating && data && (
        <CreateTemplate admin={admin} shiftTypes={data.shiftTypes} onClose={() => setCreating(false)} onSaved={(t) => navigate(`/touren/${t.id}`)} />
      )}
    </div>
  );
}

function CreateTemplate({ admin, shiftTypes, onClose, onSaved }) {
  const { values, set, errors, busy, submit } = useForm({ title: '', shiftType: 'Frueh' });
  const save = () => submit((v) => admin.post('templates/create', { ...v, weekdays: [1, 2, 3, 4, 5, 6, 7] }), 'Tour angelegt.').then((res) => res && onSaved(res.template));

  return (
    <Modal
      title="Neue Tourvorlage"
      onClose={onClose}
      footer={<><Button onClick={onClose}>Abbrechen</Button><Button variant="primary" disabled={busy || !values.title.trim()} onClick={save}>Anlegen</Button></>}
    >
      <div className="grid grid-cols-3 gap-3">
        <Field label="Titel" className="col-span-2" error={errors.Title}><Input value={values.title} onChange={set('title')} placeholder="z. B. Tour Nord – Früh" autoFocus /></Field>
        <Field label="Dienst"><Select value={values.shiftType} onChange={set('shiftType')} options={Object.entries(shiftTypes).map(([value, label]) => ({ value, label }))} /></Field>
      </div>
    </Modal>
  );
}
