import { useMemo, useState } from 'react';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { QUALIFICATION_SHORT, WARNING_LABELS } from '../../shared/labels.js';

/** Right-hand panel: visits without a tour. Also a drop target to send visits back. */
export default function UnassignedPool({ visits, visitsById, proposedIds, onOpenVisit, onDistribute, busy }) {
  const { setNodeRef, isOver } = useDroppable({ id: 'pool', data: { type: 'pool' } });
  const [query, setQuery] = useState('');

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return visits
      .filter((v) => !q || (v.patient?.name ?? '').toLowerCase().includes(q) || (v.patient?.address ?? '').toLowerCase().includes(q))
      .sort((a, b) => a.start.localeCompare(b.start) || a.id - b.id);
  }, [visits, query]);

  return (
    <aside
      ref={setNodeRef}
      className={`flex h-full w-80 shrink-0 flex-col border-l border-slate-200 transition-colors ${isOver ? 'bg-amber-50' : 'bg-white'}`}
      aria-label="Nicht zugewiesene Einsätze"
    >
      <div className="border-b border-slate-200 px-4 py-3">
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-900">
            Nicht zugewiesen <span className="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{visits.length}</span>
          </h2>
          <button
            type="button"
            onClick={onDistribute}
            disabled={busy || visits.length === 0}
            className="rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-700 disabled:opacity-40"
            title="Automatischen Verteilungsvorschlag berechnen"
          >
            Verteilen
          </button>
        </div>
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Patient oder Adresse suchen …"
          className="mt-2 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm"
        />
      </div>
      <div className="cd-scroll flex-1 space-y-2 overflow-y-auto p-3">
        {filtered.length === 0 && (
          <p className="py-8 text-center text-sm text-slate-400">{visits.length === 0 ? 'Alle Einsätze sind verteilt.' : 'Keine Treffer.'}</p>
        )}
        {filtered.map((visit) => (
          <PoolCard
            key={visit.id}
            visit={visit}
            primary={visit.primaryVisitId ? visitsById.get(visit.primaryVisitId) : null}
            inProposal={proposedIds.has(visit.id)}
            onOpen={onOpenVisit}
          />
        ))}
      </div>
      <p className="border-t border-slate-200 px-4 py-2 text-[11px] text-slate-500">Karten auf eine Pflegekraft ziehen. Einsätze hierher ziehen, um sie aus der Tour zu nehmen.</p>
    </aside>
  );
}

export function PoolCard({ visit, primary, inProposal, onOpen, overlay = false }) {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: overlay ? `overlay:${visit.id}` : `pool:${visit.id}`,
    data: { visitId: visit.id, source: 'pool' },
    disabled: overlay,
  });
  const companion = visit.role === 'Begleitung';

  return (
    <div
      ref={overlay ? undefined : setNodeRef}
      className={[
        'rounded-lg border bg-white p-2.5 text-sm shadow-sm',
        companion ? 'border-violet-300' : 'border-slate-200',
        visit.warnings.includes('NO_GEO') ? 'border-l-4 border-l-red-400' : '',
        isDragging ? 'opacity-30' : '',
        overlay ? 'w-72 rotate-1 shadow-xl' : 'cursor-grab active:cursor-grabbing',
      ].join(' ')}
      {...(overlay ? {} : attributes)}
      {...(overlay ? {} : listeners)}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate font-semibold text-slate-900">{visit.patient?.name ?? '–'}</p>
          <p className="truncate text-xs text-slate-500">{visit.patient?.address}</p>
        </div>
        <span className="shrink-0 tabular-nums text-xs font-semibold text-slate-700">{visit.start}</span>
      </div>
      <div className="mt-1.5 flex flex-wrap items-center gap-1 text-[11px]">
        {visit.windowStart && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600">{visit.windowStart}–{visit.windowEnd}</span>}
        {companion ? (
          <span className="rounded bg-violet-100 px-1.5 py-0.5 text-violet-800">Begleitung{primary ? ` · ${primary.start}` : ''}</span>
        ) : (
          <span className={`rounded px-1.5 py-0.5 ${visit.requiredRank >= 3 ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-600'}`}>
            mind. {QUALIFICATION_SHORT[visit.requiredQualification]}
          </span>
        )}
        {visit.requiredStaff > 1 && <span className="rounded bg-sky-100 px-1.5 py-0.5 text-sky-800">2 Personen</span>}
        {visit.tasks.length > 0 && <span className="text-slate-500">{visit.tasks.length} Leistung{visit.tasks.length > 1 ? 'en' : ''}</span>}
        {inProposal && <span className="rounded bg-brand-100 px-1.5 py-0.5 text-brand-700">im Vorschlag</span>}
        {visit.warnings.filter((w) => w !== 'COMPANION_MISSING').map((w) => (
          <span key={w} className="rounded bg-red-100 px-1.5 py-0.5 text-red-700">{WARNING_LABELS[w] ?? w}</span>
        ))}
      </div>
      {!overlay && (
        <button type="button" onClick={() => onOpen(visit.id)} className="mt-1.5 text-[11px] font-medium text-brand-600 hover:underline" onPointerDown={(e) => e.stopPropagation()}>
          Details
        </button>
      )}
    </div>
  );
}
