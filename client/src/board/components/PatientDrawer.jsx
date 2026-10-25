import { useEffect, useState } from 'react';
import Modal from './Modal.jsx';
import { QUALIFICATION_SHORT } from '../../shared/labels.js';

const ACTION_LABELS = {
  create: 'angelegt',
  update: 'geändert',
  delete: 'gelöscht',
  read: 'angesehen',
  visit_assigned: 'Einsatz zugewiesen',
  visit_unassigned: 'Einsatz in Pool',
  visit_started: 'Einsatz begonnen',
  visit_completed: 'Einsatz abgeschlossen',
  visit_aborted_on_sos: 'Einsatz abgebrochen (SOS)',
  visit_completed_on_sos: 'Einsatz abgeschlossen (SOS)',
  companion_released_on_sos: 'Begleitung freigegeben (SOS)',
};

function shortClass(subject) {
  return subject.replace(/ #\d+$/, '');
}

export default function PatientDrawer({ api, patientId, canViewAudit, onClose }) {
  const [patient, setPatient] = useState(null);
  const [history, setHistory] = useState(null);
  const [error, setError] = useState(null);
  const [tab, setTab] = useState('plan');

  useEffect(() => {
    let alive = true;
    api.get(`patient/${patientId}`).then((p) => alive && setPatient(p)).catch((e) => alive && setError(e.message));
    return () => { alive = false; };
  }, [api, patientId]);

  useEffect(() => {
    if (tab !== 'history' || history !== null || !canViewAudit) return undefined;
    let alive = true;
    api.get(`patient/${patientId}/history`, { limit: 200 }).then((h) => alive && setHistory(h.entries)).catch((e) => alive && setError(e.message));
    return () => { alive = false; };
  }, [api, patientId, tab, history, canViewAudit]);

  return (
    <Modal title={patient?.name ?? 'Patient'} onClose={onClose} width="max-w-3xl">
      {error && <p className="rounded bg-red-50 p-2 text-red-700">{error}</p>}
      {!patient && !error && <p className="text-slate-500">Lade …</p>}
      {patient && (
        <>
          <p className="text-slate-700">{patient.address}{patient.phone && ` · ${patient.phone}`}</p>
          {patient.accessNotes && <p className="mt-2 rounded bg-amber-50 p-2 text-amber-900"><strong>Zugang:</strong> {patient.accessNotes}</p>}
          {patient.careNotes && <p className="mt-2 rounded bg-sky-50 p-2 text-sky-900"><strong>Pflegehinweise:</strong> {patient.careNotes}</p>}

          <div className="mt-4 flex gap-1 border-b border-slate-200">
            <TabButton active={tab === 'plan'} onClick={() => setTab('plan')}>Pflegeplan</TabButton>
            {canViewAudit && <TabButton active={tab === 'history'} onClick={() => setTab('history')}>Änderungsprotokoll</TabButton>}
          </div>

          {tab === 'plan' && (
            <table className="mt-2 w-full text-left text-xs">
              <thead className="text-slate-500">
                <tr><th className="py-1">Leistung</th><th>Slot</th><th>Fenster</th><th>Tage</th><th>Anweisung</th><th>Qual.</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {patient.carePlan.map((i) => (
                  <tr key={i.id}>
                    <td className="py-1.5 pr-2"><span className="text-slate-500">{i.code}</span> {i.task}{i.requiredStaff > 1 && ' (2P)'}</td>
                    <td className="pr-2">{i.slot}</td>
                    <td className="pr-2 tabular-nums">{i.window}</td>
                    <td className="pr-2">{i.weekdays}</td>
                    <td className="pr-2">{i.instruction ?? '–'}</td>
                    <td>{QUALIFICATION_SHORT[i.minQualification]}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {tab === 'history' && (
            <div className="mt-2">
              {history === null && <p className="text-slate-500">Lade …</p>}
              {history?.length === 0 && <p className="text-slate-500">Keine Einträge.</p>}
              <ol className="space-y-2">
                {history?.map((e) => (
                  <li key={e.id} className="rounded-lg border border-slate-200 p-2">
                    <p className="text-xs text-slate-500">
                      {new Date(e.at).toLocaleString('de-DE')} · {e.member} · {shortClass(e.subject)} {ACTION_LABELS[e.action] ?? e.action}
                    </p>
                    {e.reason && <p className="text-xs">Grund: {e.reason}</p>}
                    {e.action === 'update' && (
                      <ul className="mt-1 text-xs text-slate-700">
                        {Object.entries(e.changes).map(([field, [before, after]]) => (
                          <li key={field}><strong>{field}</strong>: {String(before ?? '–')} → {String(after ?? '–')}</li>
                        ))}
                      </ul>
                    )}
                  </li>
                ))}
              </ol>
            </div>
          )}
        </>
      )}
    </Modal>
  );
}

function TabButton({ active, ...props }) {
  return <button type="button" className={`-mb-px border-b-2 px-3 py-1.5 text-sm ${active ? 'border-brand-600 font-semibold text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`} {...props} />;
}
