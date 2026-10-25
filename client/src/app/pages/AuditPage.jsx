import { useState } from 'react';
import { Field, Input, PageHeader, Select, useLoad } from '../components/ui.jsx';
import AuditList, { ACTION_LABELS } from '../components/AuditList.jsx';

export default function AuditPage({ admin }) {
  const [filter, setFilter] = useState({ action: '', from: '', to: '' });
  const actions = useLoad(() => admin.get('audit/actions'), [admin]);
  const set = (key) => (e) => setFilter((f) => ({ ...f, [key]: e.target.value }));
  const clean = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== ''));

  return (
    <div>
      <PageHeader title="Änderungsprotokoll" subtitle="Alle Änderungen und Ereignisse, unveränderlich gespeichert." />
      <div className="space-y-4 p-6">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Ereignis" className="w-64">
            <Select
              value={filter.action}
              onChange={set('action')}
              placeholder="alle"
              options={(actions.data?.actions ?? []).map((a) => ({ value: a, label: ACTION_LABELS[a] ?? a }))}
            />
          </Field>
          <Field label="Von"><Input type="date" value={filter.from} onChange={set('from')} /></Field>
          <Field label="Bis"><Input type="date" value={filter.to} onChange={set('to')} /></Field>
        </div>
        <AuditList key={JSON.stringify(clean)} admin={admin} filter={clean} />
      </div>
    </div>
  );
}
