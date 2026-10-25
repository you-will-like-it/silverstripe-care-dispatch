import { Button } from './Modal.jsx';

/** Summary of a route optimisation proposal, shown above the timeline. */
export default function ProposalPanel({ proposal, onApply, onDiscard, busy }) {
  const moves = proposal.routes.reduce((n, r) => n + r.stops.filter((s) => s.changed && !s.pinned).length, 0);

  return (
    <div className="border-b border-brand-200 bg-brand-50 px-4 py-2.5 text-sm">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
        <strong className="text-brand-700">{proposal.mode === 'tour' ? 'Optimierte Reihenfolge' : 'Verteilungsvorschlag'}</strong>
        <span>{moves} Änderung{moves === 1 ? '' : 'en'}</span>
        <span>Fahrzeit gesamt ca. {proposal.totalTravelMinutes} Min.</span>
        <span className="text-slate-500">
          {proposal.solver === 'vroom' ? 'VROOM' : 'Heuristik'} · {proposal.matrixSource === 'osrm' ? 'Straßennetz' : 'Luftlinien-Schätzung'}
        </span>
        <div className="ml-auto flex gap-2">
          <Button onClick={onDiscard} disabled={busy}>Verwerfen</Button>
          <Button variant="primary" onClick={onApply} disabled={busy || moves === 0}>Übernehmen</Button>
        </div>
      </div>
      {proposal.unassigned.length > 0 && (
        <details className="mt-1.5">
          <summary className="cursor-pointer text-amber-800">{proposal.unassigned.length} Einsatz/Einsätze nicht planbar</summary>
          <ul className="mt-1 space-y-0.5 pl-4 text-slate-700">
            {proposal.unassigned.map((u) => <li key={u.visitId}>• {u.patientName ?? `#${u.visitId}`}: {u.reasonLabel}</li>)}
          </ul>
        </details>
      )}
      {proposal.notes.length > 0 && <p className="mt-1 text-xs text-amber-800">{proposal.notes.join(' ')}</p>}
    </div>
  );
}
