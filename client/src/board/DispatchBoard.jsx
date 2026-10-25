import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { DndContext, DragOverlay, PointerSensor, KeyboardSensor, pointerWithin, rectIntersection, useSensor, useSensors } from '@dnd-kit/core';
import Timeline from './components/Timeline.jsx';
import UnassignedPool, { PoolCard } from './components/UnassignedPool.jsx';
import SosDialog from './components/SosDialog.jsx';
import OverrideDialog from './components/OverrideDialog.jsx';
import VisitDetail from './components/VisitDetail.jsx';
import ProposalPanel from './components/ProposalPanel.jsx';
import PatientDrawer from './components/PatientDrawer.jsx';
import Toast from './components/Toast.jsx';
import { Button } from './components/Modal.jsx';
import { ApiError, createApi } from '../shared/api.js';
import { addDays, formatDateLong, snap, toHHMM, toMinutes, todayYmd } from '../shared/time.js';

const ZOOM_LEVELS = [1, 1.5, 2, 3];

/** Drop target = element under the pointer (intuitive on a dense timeline); keyboard drags fall back to rect overlap. */
const collisionDetection = (args) => {
  const hits = pointerWithin(args);
  return hits.length > 0 ? hits : rectIntersection(args);
};

export default function DispatchBoard({ config }) {
  const api = useMemo(() => createApi(config), [config]);
  const [date, setDate] = useState(config.date ?? todayYmd());
  const [board, setBoard] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);
  const [zoom, setZoom] = useState(2);
  const [showAll, setShowAll] = useState(false);
  const [proposal, setProposal] = useState(null);
  const [sosTour, setSosTour] = useState(null);
  const [override, setOverride] = useState(null);
  const [detailId, setDetailId] = useState(null);
  const [patientId, setPatientId] = useState(null);
  const [dragVisitId, setDragVisitId] = useState(null);
  const [dragSource, setDragSource] = useState(null);
  const requestSeq = useRef(0);
  const closeToast = useCallback(() => setToast(null), []);

  // ---------- Data ----------

  const load = useCallback(async (silent = false) => {
    const seq = ++requestSeq.current;
    if (!silent) setLoading(true);
    try {
      const data = await api.get('board-data', { date });
      if (seq === requestSeq.current) setBoard(data);
    } catch (e) {
      if (!silent) setToast({ type: 'error', text: e.message });
    } finally {
      if (seq === requestSeq.current) setLoading(false);
    }
  }, [api, date]);

  useEffect(() => {
    setProposal(null);
    load();
  }, [load]);

  const interactionOpen = busy || dragVisitId !== null || sosTour !== null || override !== null || proposal !== null;
  useEffect(() => {
    const seconds = Math.max(10, config.pollSeconds ?? 30);
    const id = setInterval(() => {
      if (!interactionOpen && document.visibilityState === 'visible') load(true);
    }, seconds * 1000);
    return () => clearInterval(id);
  }, [load, interactionOpen, config.pollSeconds]);

  /**
   * POST a mutation. On 409 (soft warnings) the override dialog asks for a reason and the
   * same request is resent with force=true.
   */
  const mutate = useCallback(async (path, body, successText) => {
    setBusy(true);
    try {
      const res = await api.post(path, body);
      if (res.board) setBoard(res.board);
      if (successText) setToast({ type: 'success', text: typeof successText === 'function' ? successText(res.result) : successText });
      return res.result;
    } catch (e) {
      if (e instanceof ApiError && e.needsConfirmation) {
        setOverride({
          violations: e.violations,
          retry: (reason) => mutate(path, { ...body, force: true, reason }, successText),
        });
      } else {
        setToast({ type: 'error', text: e.message, details: (e.violations ?? []).map((v) => v.message) });
        if (e instanceof ApiError && e.status === 409) load(true);
      }
      return null;
    } finally {
      setBusy(false);
    }
  }, [api, load]);

  // ---------- Derived ----------

  const visitsById = useMemo(() => new Map((board?.visits ?? []).map((v) => [v.id, v])), [board]);
  const toursById = useMemo(() => new Map((board?.tours ?? []).map((t) => [t.id, t])), [board]);
  const caregiversById = useMemo(() => new Map((board?.caregivers ?? []).map((c) => [c.id, c])), [board]);

  const toursByCaregiver = useMemo(() => {
    const map = new Map();
    (board?.tours ?? []).forEach((t) => {
      if (!t.caregiverId) return;
      if (!map.has(t.caregiverId)) map.set(t.caregiverId, []);
      map.get(t.caregiverId).push(t);
    });
    return map;
  }, [board]);

  const rows = useMemo(
    () => (board?.caregivers ?? []).filter((c) => showAll || c.onDuty || toursByCaregiver.has(c.id)),
    [board, showAll, toursByCaregiver],
  );
  const unstaffedTours = useMemo(() => (board?.tours ?? []).filter((t) => !t.caregiverId && t.status === 'Draft'), [board]);
  const poolVisits = useMemo(() => (board?.pool ?? []).map((id) => visitsById.get(id)).filter(Boolean), [board, visitsById]);

  const proposalByCaregiver = useMemo(() => {
    if (!proposal) return null;
    return new Map(proposal.routes.map((r) => [r.caregiverId, r.stops]));
  }, [proposal]);
  const proposedIds = useMemo(
    () => new Set((proposal?.routes ?? []).flatMap((r) => r.stops.filter((s) => s.changed && !s.pinned).map((s) => s.visitId))),
    [proposal],
  );

  const stats = useMemo(() => {
    const visits = board?.visits ?? [];
    return {
      total: visits.filter((v) => v.role === 'Primary').length,
      done: visits.filter((v) => v.role === 'Primary' && v.status === 'Completed').length,
      running: visits.filter((v) => v.status === 'InProgress').length,
      late: visits.filter((v) => v.warnings.includes('LATE')).length,
      missingCompanion: visits.filter((v) => v.warnings.includes('COMPANION_MISSING')).length,
      onDuty: (board?.caregivers ?? []).filter((c) => c.attendance && c.attendance.status !== 'Completed').length,
    };
  }, [board]);

  // ---------- Actions ----------

  const assignVisit = (visitId, target, minutes) => {
    const body = { visit_id: visitId, target_time: toHHMM(minutes) };
    if (target.tourId) body.target_tour_id = target.tourId;
    else body.target_caregiver_id = target.caregiverId;
    const name = target.tourId ? toursById.get(target.tourId)?.title : caregiversById.get(target.caregiverId)?.name;
    return mutate('assign-visit', body, (r) => `Einsatz ${body.target_time} → ${name}${r?.createdTour ? ' (neue Ad-hoc-Tour)' : ''}`);
  };

  const unassignVisit = (visit) => mutate('unassign-visit', { visit_id: visit.id }, `${visit.patient?.name ?? 'Einsatz'} zurück im Pool`).then(() => setDetailId(null));

  const interruptTour = async (tour, payload) => {
    const result = await mutate(`tour/${tour.id}/interrupt`, payload, (r) => `Tour unterbrochen – ${r.pooledVisitIds.length} Einsatz/Einsätze im Pool`);
    if (result) setSosTour(null);
  };

  const assignTour = (tour, caregiverId) => mutate(
    `tour/${tour.id}/assign-caregiver`,
    { caregiver_id: caregiverId },
    `${tour.title} → ${caregiversById.get(caregiverId)?.name}`,
  );

  const addCompanion = (visit, caregiverId) => mutate(
    `visit/${visit.id}/companion`,
    { caregiver_id: caregiverId },
    `Begleitung eingeplant: ${caregiversById.get(caregiverId)?.name}`,
  );

  const runOptimize = async (body) => {
    setBusy(true);
    try {
      const res = await api.post('optimize', { date, ...body });
      setProposal(res.proposal);
      const moves = res.proposal.routes.reduce((n, r) => n + r.stops.filter((s) => s.changed && !s.pinned).length, 0);
      if (moves === 0) setToast({ type: 'warning', text: 'Keine Verbesserung gefunden.' });
    } catch (e) {
      setToast({ type: 'error', text: e.message });
    } finally {
      setBusy(false);
    }
  };

  const applyProposal = async () => {
    const result = await mutate('apply-proposal', { date, proposal }, null);
    if (result) {
      setProposal(null);
      setToast({
        type: result.failures.length ? 'warning' : 'success',
        text: `${result.applied} Änderung(en) übernommen${result.failures.length ? `, ${result.failures.length} nicht möglich` : ''}.`,
        details: result.failures.map((f) => f.message),
      });
    }
  };

  const generateDay = () => {
    if (!window.confirm(`Touren und Einsätze für ${formatDateLong(date)} aus Vorlagen und Pflegeplänen erzeugen? Bereits vorhandene werden nicht doppelt angelegt.`)) return;
    mutate('generate-day', { date }, (r) => `${r.visitsOnTours} Einsätze auf Touren, ${r.visitsInPool} im Pool, ${r.toursCreated} Touren neu`);
  };

  // ---------- Drag & drop ----------

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor),
  );

  const onDragStart = ({ active }) => {
    setDragVisitId(active.data.current?.visitId ?? null);
    setDragSource(active.data.current?.source ?? null);
  };

  const onDragEnd = ({ active, over, delta, activatorEvent }) => {
    setDragVisitId(null);
    const visitId = active.data.current?.visitId;
    if (!visitId || !over || busy) return;
    const visit = visitsById.get(visitId);
    if (!visit) return;

    if (over.data.current?.type === 'pool') {
      if (visit.status === 'Pending') unassignVisit(visit);
      return;
    }
    if (over.data.current?.type !== 'lane') return;

    // Timeline blocks keep their grab offset (left edge = new start); pool cards use the pointer position.
    let x;
    if (active.data.current?.source === 'timeline' && active.rect.current.translated) {
      x = active.rect.current.translated.left;
    } else {
      const pointerX = (activatorEvent?.clientX ?? 0) + delta.x;
      x = pointerX;
    }
    const minutes = snap(toMinutes(board.timeline.start) + (x - over.rect.left) / zoom, 5);
    const { caregiverId, tourId } = over.data.current;
    const unchanged = visit.status === 'Pending' && visit.start === toHHMM(minutes)
      && ((tourId && visit.tourId === tourId) || (caregiverId && toursById.get(visit.tourId)?.caregiverId === caregiverId));
    if (unchanged) return;
    assignVisit(visitId, { caregiverId, tourId }, minutes);
  };

  const dragVisit = dragVisitId ? visitsById.get(dragVisitId) : null;
  const detailVisit = detailId ? visitsById.get(detailId) : null;

  // ---------- Render ----------

  return (
    <div className="flex h-full flex-col font-sans text-slate-900">
      <header className="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white px-4 py-2.5">
        <h1 className="mr-2 text-lg font-semibold">Einsatzplan</h1>
        <div className="flex items-center gap-1">
          <Button variant="ghost" onClick={() => setDate(addDays(date, -1))} aria-label="Vortag">‹</Button>
          <input
            type="date"
            value={date}
            onChange={(e) => e.target.value && setDate(e.target.value)}
            className="rounded-lg border border-slate-300 px-2 py-1 text-sm"
            aria-label="Datum"
          />
          <Button variant="ghost" onClick={() => setDate(addDays(date, 1))} aria-label="Folgetag">›</Button>
          {date !== todayYmd() && <Button variant="ghost" onClick={() => setDate(todayYmd())}>Heute</Button>}
        </div>
        <span className="hidden text-sm text-slate-500 lg:inline">{formatDateLong(date)}</span>

        <div className="ml-auto flex flex-wrap items-center gap-2">
          <Stat label="Einsätze" value={`${stats.done}/${stats.total}`} />
          <Stat label="Laufend" value={stats.running} />
          <Stat label="Im Dienst" value={stats.onDuty} />
          {stats.late > 0 && <Stat label="Verspätet" value={stats.late} tone="danger" />}
          {stats.missingCompanion > 0 && <Stat label="2. Person fehlt" value={stats.missingCompanion} tone="warning" />}
          <label className="flex items-center gap-1.5 text-sm text-slate-600">
            <input type="checkbox" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} /> alle Pflegekräfte
          </label>
          <select value={zoom} onChange={(e) => setZoom(Number(e.target.value))} className="rounded-lg border border-slate-300 px-1.5 py-1 text-sm" aria-label="Zoom">
            {ZOOM_LEVELS.map((z) => <option key={z} value={z}>{Math.round(z * 50)} %</option>)}
          </select>
          {date >= todayYmd() && <Button onClick={generateDay} disabled={busy}>Tag generieren</Button>}
          <Button onClick={() => load()} disabled={loading} aria-label="Aktualisieren">⟳</Button>
          {config.adminUrl && <a href={config.adminUrl} className="text-sm text-slate-500 hover:text-slate-800">Stammdaten</a>}
        </div>
      </header>

      {proposal && <ProposalPanel proposal={proposal} onApply={applyProposal} onDiscard={() => setProposal(null)} busy={busy} />}

      <DndContext sensors={sensors} collisionDetection={collisionDetection} onDragStart={onDragStart} onDragEnd={onDragEnd} onDragCancel={() => setDragVisitId(null)}>
        <div className="flex min-h-0 flex-1">
          <main className="relative min-w-0 flex-1 bg-white">
            {!board && loading && <p className="p-8 text-center text-slate-500">Lade Einsatzplan …</p>}
            {board && (
              <Timeline
                board={board}
                rows={rows}
                toursByCaregiver={toursByCaregiver}
                unstaffedTours={unstaffedTours}
                visitsById={visitsById}
                zoom={zoom}
                proposalByCaregiver={proposalByCaregiver}
                caregivers={board.caregivers}
                onOpenVisit={setDetailId}
                onSos={setSosTour}
                onOptimize={(tour) => runOptimize({ mode: 'tour', tour_id: tour.id })}
                onAssignTour={assignTour}
                busy={busy}
              />
            )}
            {busy && <div className="pointer-events-none absolute inset-x-0 top-0 h-0.5 animate-pulse bg-brand-500" />}
          </main>
          <UnassignedPool
            visits={poolVisits}
            visitsById={visitsById}
            proposedIds={proposedIds}
            onOpenVisit={setDetailId}
            onDistribute={() => runOptimize({ mode: 'pool' })}
            busy={busy}
          />
        </div>
        <DragOverlay dropAnimation={null}>
          {dragVisit && dragSource === 'pool' && <PoolCard visit={dragVisit} overlay />}
          {dragVisit && dragSource === 'timeline' && (
            <div
              className="flex h-12 flex-col justify-center overflow-hidden rounded-md bg-brand-600 px-1.5 text-[11px] leading-tight text-white shadow-xl"
              style={{ width: Math.max((toMinutes(dragVisit.end) - toMinutes(dragVisit.start)) * zoom, 22) }}
            >
              <span className="truncate font-semibold">{dragVisit.patient?.name}</span>
              <span className="truncate">{dragVisit.start}</span>
            </div>
          )}
        </DragOverlay>
      </DndContext>

      {sosTour && (
        <SosDialog
          tour={sosTour}
          caregiver={caregiversById.get(sosTour.caregiverId)}
          visits={sosTour.visitIds.map((id) => visitsById.get(id)).filter(Boolean)}
          onConfirm={(payload) => interruptTour(sosTour, payload)}
          onClose={() => setSosTour(null)}
          busy={busy}
        />
      )}
      {override && (
        <OverrideDialog
          violations={override.violations}
          busy={busy}
          onClose={() => setOverride(null)}
          onConfirm={(reason) => {
            const { retry } = override;
            setOverride(null);
            retry(reason);
          }}
        />
      )}
      {detailVisit && (
        <VisitDetail
          visit={detailVisit}
          visitsById={visitsById}
          toursById={toursById}
          caregiversById={caregiversById}
          caregivers={board.caregivers}
          onClose={() => setDetailId(null)}
          onUnassign={unassignVisit}
          onAddCompanion={addCompanion}
          onOpenPatient={(id) => { setDetailId(null); setPatientId(id); }}
          busy={busy}
        />
      )}
      {patientId && <PatientDrawer api={api} patientId={patientId} canViewAudit={!!config.canViewAudit} onClose={() => setPatientId(null)} />}
      <Toast toast={toast} onClose={closeToast} />
    </div>
  );
}

function Stat({ label, value, tone }) {
  const cls = tone === 'danger' ? 'bg-red-50 text-red-700' : tone === 'warning' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-700';
  return (
    <span className={`rounded-lg px-2 py-1 text-xs ${cls}`}>
      {label} <strong className="tabular-nums">{value}</strong>
    </span>
  );
}
