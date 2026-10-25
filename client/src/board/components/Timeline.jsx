import { useDroppable } from '@dnd-kit/core';
import VisitBlock, { GhostBlock } from './VisitBlock.jsx';
import { isoToMinutes, toMinutes } from '../../shared/time.js';
import { ATTENDANCE, QUALIFICATION_SHORT, TOUR_STATUS } from '../../shared/labels.js';

const HEADER_WIDTH = 264;
const ROW_HEIGHT = 64;

const OPEN_TOUR = new Set(['Draft', 'Assigned', 'InProgress']);

export default function Timeline({
  board,
  rows,
  toursByCaregiver,
  unstaffedTours,
  visitsById,
  zoom,
  proposalByCaregiver,
  caregivers,
  onOpenVisit,
  onSos,
  onOptimize,
  onAssignTour,
  busy,
}) {
  const tlStart = toMinutes(board.timeline.start);
  const tlEnd = toMinutes(board.timeline.end);
  const width = (tlEnd - tlStart) * zoom;
  const nowMinutes = board.isToday ? isoToMinutes(board.now) : null;
  const hours = [];
  for (let m = Math.ceil(tlStart / 60) * 60; m <= tlEnd; m += 60) hours.push(m);

  const geometry = { tlStart, tlEnd, width, zoom, nowMinutes, hours };

  return (
    <div className="cd-scroll relative h-full overflow-auto">
      <div style={{ width: HEADER_WIDTH + width }} className="relative">
        {/* Time axis */}
        <div className="sticky top-0 z-20 flex border-b border-slate-200 bg-white">
          <div className="sticky left-0 z-30 shrink-0 border-r border-slate-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500" style={{ width: HEADER_WIDTH }}>
            Pflegekraft / Tour
          </div>
          <div className="relative h-9" style={{ width }}>
            {hours.map((m) => (
              <div key={m} className="absolute top-0 h-full border-l border-slate-200 pl-1 pt-2 text-[11px] tabular-nums text-slate-500" style={{ left: (m - tlStart) * zoom }}>
                {String(m / 60).padStart(2, '0')}:00
              </div>
            ))}
            {nowMinutes !== null && nowMinutes >= tlStart && nowMinutes <= tlEnd && (
              <div className="absolute top-1 -translate-x-1/2 rounded bg-red-600 px-1.5 text-[10px] font-semibold text-white" style={{ left: (nowMinutes - tlStart) * zoom }}>
                Jetzt
              </div>
            )}
          </div>
        </div>

        {rows.length === 0 && unstaffedTours.length === 0 && (
          <p className="p-8 text-center text-slate-500">Für diesen Tag sind keine Pflegekräfte im Dienst und keine Touren geplant.</p>
        )}

        {rows.map((caregiver) => (
          <CaregiverRow
            key={caregiver.id}
            caregiver={caregiver}
            tours={toursByCaregiver.get(caregiver.id) ?? []}
            visitsById={visitsById}
            geometry={geometry}
            proposalStops={proposalByCaregiver?.get(caregiver.id)}
            onOpenVisit={onOpenVisit}
            onSos={onSos}
            onOptimize={onOptimize}
            busy={busy}
          />
        ))}

        {unstaffedTours.length > 0 && (
          <div className="sticky left-0 border-y border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-amber-800" style={{ width: HEADER_WIDTH + width }}>
            Unbesetzte Touren
          </div>
        )}
        {unstaffedTours.map((tour) => (
          <UnstaffedTourRow
            key={tour.id}
            tour={tour}
            visitsById={visitsById}
            geometry={geometry}
            caregivers={caregivers}
            onOpenVisit={onOpenVisit}
            onAssignTour={onAssignTour}
            busy={busy}
          />
        ))}
      </div>
    </div>
  );
}

function CaregiverRow({ caregiver, tours, visitsById, geometry, proposalStops, onOpenVisit, onSos, onOptimize, busy }) {
  const { setNodeRef, isOver } = useDroppable({ id: `lane:cg:${caregiver.id}`, data: { type: 'lane', caregiverId: caregiver.id } });
  const visits = tours.flatMap((t) => t.visitIds.map((id) => visitsById.get(id)).filter(Boolean));
  const attendance = caregiver.attendance;
  const status = attendance?.status ?? 'Absent';
  const dot = { Active: 'bg-emerald-500', Break: 'bg-amber-400', Completed: 'bg-slate-400', Absent: 'bg-slate-300' }[status];
  const movedIds = new Set((proposalStops ?? []).filter((s) => s.changed && !s.pinned).map((s) => s.visitId));

  return (
    <div className="flex border-b border-slate-200" style={{ minHeight: ROW_HEIGHT }}>
      <div className="sticky left-0 z-10 shrink-0 border-r border-slate-200 bg-white px-3 py-2" style={{ width: HEADER_WIDTH }}>
        <div className="flex items-center gap-2">
          <span
            className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white"
            style={{ background: caregiver.color ?? '#64748b' }}
            aria-hidden="true"
          >
            {caregiver.initials}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-slate-900">{caregiver.name}</p>
            <p className="flex items-center gap-1.5 text-[11px] text-slate-500">
              <span className={`inline-block h-2 w-2 rounded-full ${dot}`} />
              {caregiver.absence ? <span className="rounded bg-amber-100 px-1 font-medium text-amber-800">{caregiver.absence.label}</span> : ATTENDANCE[status]}
              <span className="rounded bg-slate-100 px-1 font-medium text-slate-600">{QUALIFICATION_SHORT[caregiver.qualification]}</span>
              {!caregiver.drivingLicense && <span title="Kein Führerschein">🚶</span>}
            </p>
          </div>
        </div>
        {tours.length > 0 && (
          <div className="mt-1.5 space-y-1">
            {tours.map((tour) => (
              <div key={tour.id} className="flex items-center gap-1 text-[11px]">
                <span className={`min-w-0 flex-1 truncate ${tour.status === 'Interrupted' ? 'text-red-700 line-through' : 'text-slate-600'}`} title={tour.interruptReason ?? tour.title}>
                  {tour.title}
                </span>
                <span className={`shrink-0 rounded px-1 ${tour.status === 'Interrupted' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'}`}>
                  {TOUR_STATUS[tour.status]}
                </span>
                {OPEN_TOUR.has(tour.status) && (
                  <>
                    <button type="button" disabled={busy} onClick={() => onOptimize(tour)} title="Reihenfolge optimieren" className="shrink-0 rounded px-1 text-brand-600 hover:bg-brand-50 disabled:opacity-40">
                      ⇄
                    </button>
                    {tour.status !== 'Draft' && (
                      <button type="button" disabled={busy} onClick={() => onSos(tour)} title="Tour unterbrechen (SOS)" className="shrink-0 rounded bg-red-600 px-1.5 font-bold text-white hover:bg-red-700 disabled:opacity-40">
                        SOS
                      </button>
                    )}
                  </>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      <div ref={setNodeRef} className={`relative shrink-0 ${isOver ? 'bg-brand-50' : ''}`} style={{ width: geometry.width }}>
        <LaneBackground geometry={geometry} />
        {caregiver.shifts.map((shift) => {
          const s = toMinutes(shift.start);
          let e = toMinutes(shift.end);
          if (e <= s) e = geometry.tlEnd;
          return (
            <div
              key={shift.id}
              className="absolute inset-y-0 border-x border-dashed border-emerald-300 bg-emerald-50/70"
              style={{ left: (Math.max(s, geometry.tlStart) - geometry.tlStart) * geometry.zoom, width: (Math.min(e, geometry.tlEnd) - Math.max(s, geometry.tlStart)) * geometry.zoom }}
              title={`Soll: ${shift.typeLabel} ${shift.start}–${shift.end}`}
            />
          );
        })}
        {attendance?.checkIn && <AttendanceBar attendance={attendance} geometry={geometry} />}
        {geometry.nowMinutes !== null && <NowLine geometry={geometry} />}
        {visits.map((visit) => (
          <VisitBlock
            key={visit.id}
            visit={visit}
            timelineStart={geometry.tlStart}
            zoom={geometry.zoom}
            onOpen={onOpenVisit}
            dimmed={movedIds.has(visit.id)}
          />
        ))}
        {(proposalStops ?? []).filter((s) => s.changed && !s.pinned).map((stop) => (
          <GhostBlock key={`g${stop.visitId}`} stop={stop} timelineStart={geometry.tlStart} zoom={geometry.zoom} />
        ))}
      </div>
    </div>
  );
}

function UnstaffedTourRow({ tour, visitsById, geometry, caregivers, onOpenVisit, onAssignTour, busy }) {
  const { setNodeRef, isOver } = useDroppable({ id: `lane:tour:${tour.id}`, data: { type: 'lane', tourId: tour.id } });
  const visits = tour.visitIds.map((id) => visitsById.get(id)).filter(Boolean);

  return (
    <div className="flex border-b border-amber-100" style={{ minHeight: ROW_HEIGHT }}>
      <div className="sticky left-0 z-10 shrink-0 border-r border-slate-200 bg-amber-50 px-3 py-2" style={{ width: HEADER_WIDTH }}>
        <p className="truncate text-sm font-semibold text-slate-900">{tour.title}</p>
        <select
          className="mt-1 w-full rounded border border-amber-300 bg-white px-1.5 py-1 text-xs"
          value=""
          disabled={busy}
          onChange={(e) => e.target.value && onAssignTour(tour, Number(e.target.value))}
          aria-label={`Pflegekraft für ${tour.title} wählen`}
        >
          <option value="">Pflegekraft zuweisen …</option>
          {caregivers.filter((c) => c.qualification !== 'Azubi').map((c) => (
            <option key={c.id} value={c.id}>
              {c.name} ({QUALIFICATION_SHORT[c.qualification]}){c.absence ? ` – ${c.absence.label}` : c.onDuty ? '' : ' – kein Dienst'}
            </option>
          ))}
        </select>
      </div>
      <div ref={setNodeRef} className={`relative shrink-0 ${isOver ? 'bg-brand-50' : 'bg-amber-50/40'}`} style={{ width: geometry.width }}>
        <LaneBackground geometry={geometry} />
        {geometry.nowMinutes !== null && <NowLine geometry={geometry} />}
        {visits.map((visit) => (
          <VisitBlock key={visit.id} visit={visit} timelineStart={geometry.tlStart} zoom={geometry.zoom} onOpen={onOpenVisit} />
        ))}
      </div>
    </div>
  );
}

function LaneBackground({ geometry }) {
  return geometry.hours.map((m) => (
    <div key={m} className="pointer-events-none absolute inset-y-0 border-l border-slate-100" style={{ left: (m - geometry.tlStart) * geometry.zoom }} />
  ));
}

function NowLine({ geometry }) {
  const { nowMinutes, tlStart, tlEnd, zoom } = geometry;
  if (nowMinutes < tlStart || nowMinutes > tlEnd) return null;
  return <div className="pointer-events-none absolute inset-y-0 z-[5] w-0.5 bg-red-500" style={{ left: (nowMinutes - tlStart) * zoom }} />;
}

function AttendanceBar({ attendance, geometry }) {
  const start = isoToMinutes(attendance.checkIn);
  const end = attendance.checkOut ? isoToMinutes(attendance.checkOut) : geometry.nowMinutes ?? start;
  const s = Math.max(start, geometry.tlStart);
  const e = Math.min(Math.max(end, s), geometry.tlEnd);
  const color = attendance.status === 'Break' ? 'bg-amber-400' : attendance.status === 'Completed' ? 'bg-slate-400' : 'bg-emerald-500';
  return (
    <div
      className={`pointer-events-none absolute bottom-0 h-1 rounded-full ${color}`}
      style={{ left: (s - geometry.tlStart) * geometry.zoom, width: Math.max((e - s) * geometry.zoom, 2) }}
      title={`Ist: ${ATTENDANCE[attendance.status]}`}
    />
  );
}
