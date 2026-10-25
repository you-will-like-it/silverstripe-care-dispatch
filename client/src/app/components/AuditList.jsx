import { useState } from 'react';
import { Button, Empty, ErrorBox, Loading, useLoad, formatDateTime } from './ui.jsx';

export const ACTION_LABELS = {
  create: 'angelegt',
  update: 'geändert',
  delete: 'gelöscht',
  read: 'angesehen',
  visit_assigned: 'Einsatz zugewiesen',
  visit_unassigned: 'Einsatz in den Pool',
  visit_started: 'Einsatz begonnen',
  visit_completed: 'Einsatz abgeschlossen',
  visit_aborted_on_sos: 'Einsatz abgebrochen (SOS)',
  visit_completed_on_sos: 'Einsatz abgeschlossen (SOS)',
  companion_released_on_sos: 'Begleitung freigegeben (SOS)',
  sos_interrupt: 'Tour unterbrochen (SOS)',
  tour_caregiver_changed: 'Tour umbesetzt',
  tour_adhoc_created: 'Ad-hoc-Tour angelegt',
  check_in: 'Kommen',
  check_out: 'Gehen',
  break_start: 'Pause Beginn',
  break_end: 'Pause Ende',
  time_entry_corrected: 'Zeitbuchung korrigiert',
  time_entry_created_manually: 'Zeitbuchung nachgetragen',
  time_entry_auto_closed: 'Zeitbuchung automatisch geschlossen',
  absence_saved: 'Abwesenheit gespeichert',
  roster_week_copied: 'Dienstplan-Woche kopiert',
  staff_created: 'Mitarbeiter angelegt',
  staff_updated: 'Mitarbeiter geändert',
  staff_password_set: 'Passwort gesetzt',
  staff_deactivated: 'Mitarbeiter deaktiviert',
  patient_wishes_changed: 'Wünsche geändert',
  day_generated: 'Tag generiert',
  route_proposal_applied: 'Routenvorschlag übernommen',
};

function shortSubject(subject) {
  return subject.replace(/ #\d+$/, '');
}

function renderValue(v) {
  if (v === null || v === undefined || v === '') return '–';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

/** Paged audit list; `filter` = {patient, member, action, from, to}. */
export default function AuditList({ admin, filter }) {
  const [page, setPage] = useState(1);
  const key = JSON.stringify(filter);
  const { data, error, loading, reload } = useLoad(() => admin.get('audit/list', { ...filter, page }), [admin, key, page]);
  const [open, setOpen] = useState(null);

  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (!data) return <Loading />;
  if (data.entries.length === 0) return <Empty>Keine Einträge.</Empty>;

  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <table className="w-full text-sm">
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr><th className="px-4 py-2">Zeitpunkt</th><th className="px-4 py-2">Benutzer</th><th className="px-4 py-2">Ereignis</th><th className="px-4 py-2">Patient</th><th className="px-4 py-2">Grund</th></tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {data.entries.map((e) => {
            const changes = Object.entries(e.changes ?? {});
            return (
              <tr key={e.id} className="align-top">
                <td className="px-4 py-2 whitespace-nowrap tabular-nums text-slate-600">{formatDateTime(e.at)}</td>
                <td className="px-4 py-2 text-slate-700">{e.member}</td>
                <td className="px-4 py-2">
                  <span className="font-medium">{shortSubject(e.subject)}</span> {ACTION_LABELS[e.action] ?? e.action}
                  {changes.length > 0 && (
                    <button type="button" className="ml-2 text-xs text-brand-600 hover:underline" onClick={() => setOpen(open === e.id ? null : e.id)}>
                      {open === e.id ? 'ausblenden' : 'Details'}
                    </button>
                  )}
                  {open === e.id && (
                    <ul className="mt-1 space-y-0.5 text-xs text-slate-600">
                      {changes.map(([field, value]) => (
                        <li key={field}>
                          <strong>{field}</strong>:{' '}
                          {Array.isArray(value) && value.length === 2 && ['create', 'update', 'delete'].includes(e.action)
                            ? `${renderValue(value[0])} → ${renderValue(value[1])}`
                            : renderValue(value)}
                        </li>
                      ))}
                    </ul>
                  )}
                </td>
                <td className="px-4 py-2 text-slate-600">{e.patientName ?? '–'}</td>
                <td className="px-4 py-2 text-slate-600">{e.reason ?? ''}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <div className="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
        <span>{data.total} Einträge · Seite {data.page} von {data.pages}{loading ? ' · lädt …' : ''}</span>
        <div className="flex gap-1">
          <Button disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</Button>
          <Button disabled={page >= data.pages} onClick={() => setPage(page + 1)}>›</Button>
        </div>
      </div>
    </div>
  );
}
