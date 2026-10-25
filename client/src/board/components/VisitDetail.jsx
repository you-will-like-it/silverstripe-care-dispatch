import { useState } from 'react';
import Modal, { Button } from './Modal.jsx';
import { isoToHHMM } from '../../shared/time.js';
import { QUALIFICATION_SHORT, VISIT_STATUS, WARNING_LABELS } from '../../shared/labels.js';

export default function VisitDetail({ visit, visitsById, toursById, caregiversById, caregivers, onClose, onUnassign, onAddCompanion, onOpenPatient, busy }) {
  const [companionId, setCompanionId] = useState('');
  const tour = visit.tourId ? toursById.get(visit.tourId) : null;
  const caregiver = tour?.caregiverId ? caregiversById.get(tour.caregiverId) : null;
  const primary = visit.primaryVisitId ? visitsById.get(visit.primaryVisitId) : null;
  const companions = visit.companions.map((c) => ({ ...c, visit: visitsById.get(c.visitId) }));
  const needsCompanion = visit.role === 'Primary' && visit.requiredStaff > 1 && !['Completed', 'Cancelled'].includes(visit.status);

  const companionCaregiver = (c) => {
    const t = c.tourId ? toursById.get(c.tourId) : null;
    return t?.caregiverId ? caregiversById.get(t.caregiverId)?.name : 'nicht zugewiesen';
  };

  return (
    <Modal
      title={`${visit.patient?.name ?? 'Einsatz'} · ${visit.start}–${visit.end}`}
      onClose={onClose}
      width="max-w-xl"
      footer={
        <>
          {visit.patient && <Button variant="ghost" onClick={() => onOpenPatient(visit.patient.id)}>Patientenakte</Button>}
          {visit.status === 'Pending' && <Button onClick={() => onUnassign(visit)} disabled={busy}>Zurück in den Pool</Button>}
          <Button variant="primary" onClick={onClose}>Schließen</Button>
        </>
      }
    >
      <dl className="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1.5">
        <dt className="text-slate-500">Status</dt>
        <dd>{VISIT_STATUS[visit.status]}{visit.role === 'Begleitung' && ' (Begleitung)'}</dd>
        <dt className="text-slate-500">Adresse</dt>
        <dd>{visit.patient?.address ?? '–'}</dd>
        <dt className="text-slate-500">Zeitfenster</dt>
        <dd>{visit.windowStart ? `${visit.windowStart}–${visit.windowEnd}` : '–'}</dd>
        <dt className="text-slate-500">Tour</dt>
        <dd>{tour ? `${tour.title}${caregiver ? ` · ${caregiver.name}` : ''}` : 'nicht zugewiesen'}</dd>
        <dt className="text-slate-500">Qualifikation</dt>
        <dd>{visit.role === 'Begleitung' ? 'beliebig (auch Azubi)' : visit.requiredQualificationLabel}</dd>
        {(visit.actualStart || visit.actualEnd) && (
          <>
            <dt className="text-slate-500">Ist-Zeit</dt>
            <dd>{isoToHHMM(visit.actualStart)}–{isoToHHMM(visit.actualEnd) || 'läuft'}</dd>
          </>
        )}
        {primary && (
          <>
            <dt className="text-slate-500">Haupteinsatz</dt>
            <dd>{primary.start} · {VISIT_STATUS[primary.status]}</dd>
          </>
        )}
      </dl>

      {visit.warnings.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-1.5">
          {visit.warnings.map((w) => <span key={w} className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">{WARNING_LABELS[w] ?? w}</span>)}
        </div>
      )}

      {visit.tasks.length > 0 && (
        <>
          <h3 className="mt-4 mb-1.5 font-semibold text-slate-900">Leistungen</h3>
          <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
            {visit.tasks.map((t) => (
              <li key={t.id} className="flex items-start gap-2 px-3 py-2">
                <span className="w-5 shrink-0 text-center">{t.status === 'Done' ? '✓' : t.status === 'NotDone' ? '✕' : '○'}</span>
                <div className="min-w-0 flex-1">
                  <p>
                    <span className="text-xs text-slate-500">{t.code}</span> {t.title}
                    {t.minQualification && <span className="ml-1 rounded bg-slate-100 px-1 text-[10px]">{QUALIFICATION_SHORT[t.minQualification]}</span>}
                  </p>
                  {t.instruction && <p className="text-xs text-slate-600">{t.instruction}</p>}
                  {t.note && <p className="text-xs text-red-700">{t.note}</p>}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}

      {visit.role === 'Primary' && visit.requiredStaff > 1 && (
        <>
          <h3 className="mt-4 mb-1.5 font-semibold text-slate-900">Doppeleinsatz – zweite Person</h3>
          {companions.length === 0 && <p className="text-slate-500">Noch keine Begleitung geplant.</p>}
          <ul className="space-y-1">
            {companions.map((c) => (
              <li key={c.visitId} className="text-slate-700">• {companionCaregiver(c)} ({VISIT_STATUS[c.status]})</li>
            ))}
          </ul>
          {needsCompanion && (
            <div className="mt-2 flex gap-2">
              <select className="flex-1 rounded-lg border border-slate-300 p-1.5" value={companionId} onChange={(e) => setCompanionId(e.target.value)} aria-label="Begleitung wählen">
                <option value="">Begleitung wählen …</option>
                {caregivers.filter((c) => c.id !== caregiver?.id).map((c) => (
                  <option key={c.id} value={c.id}>{c.name} ({QUALIFICATION_SHORT[c.qualification]}){c.onDuty ? '' : ' – kein Dienst'}</option>
                ))}
              </select>
              <Button variant="primary" disabled={!companionId || busy} onClick={() => onAddCompanion(visit, Number(companionId))}>Einplanen</Button>
            </div>
          )}
        </>
      )}

      {visit.notes && (
        <>
          <h3 className="mt-4 mb-1 font-semibold text-slate-900">Notizen</h3>
          <p className="whitespace-pre-line text-slate-700">{visit.notes}</p>
        </>
      )}
    </Modal>
  );
}
