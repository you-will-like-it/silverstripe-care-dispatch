import { useDraggable } from '@dnd-kit/core';
import { toMinutes } from '../../shared/time.js';
import { QUALIFICATION_SHORT, VISIT_STATUS, WARNING_LABELS } from '../../shared/labels.js';

const STATUS_STYLES = {
  Pending: 'bg-brand-500 text-white',
  InProgress: 'bg-amber-500 text-white',
  Completed: 'bg-emerald-600 text-white',
  Unassigned: 'bg-slate-400 text-white',
  Cancelled: 'bg-slate-300 text-slate-600',
};

const MOVABLE = new Set(['Pending', 'Unassigned']);

/** A visit positioned on a caregiver lane of the timeline. */
export default function VisitBlock({ visit, timelineStart, zoom, onOpen, dimmed }) {
  const movable = MOVABLE.has(visit.status);
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: `visit:${visit.id}`,
    data: { visitId: visit.id, source: 'timeline' },
    disabled: !movable,
  });

  const start = toMinutes(visit.start);
  const end = toMinutes(visit.end);
  const left = (start - timelineStart) * zoom;
  const width = Math.max((end - start) * zoom, 22);
  const companion = visit.role === 'Begleitung';
  const hasWarning = visit.warnings.length > 0;
  const title = [
    `${visit.start}–${visit.end} ${visit.patient?.name ?? ''}`,
    VISIT_STATUS[visit.status],
    companion ? 'Begleitung' : `mind. ${visit.requiredQualificationLabel}`,
    ...visit.warnings.map((w) => WARNING_LABELS[w] ?? w),
  ].join(' · ');

  return (
    <button
      ref={setNodeRef}
      type="button"
      title={title}
      onClick={() => onOpen(visit.id)}
      className={[
        'absolute top-2 bottom-2 overflow-hidden rounded-md px-1.5 text-left text-[11px] leading-tight shadow-sm transition-opacity',
        STATUS_STYLES[visit.status] ?? STATUS_STYLES.Pending,
        companion ? 'cd-companion' : '',
        hasWarning ? 'ring-2 ring-red-500 ring-offset-1' : '',
        movable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
        isDragging ? 'opacity-30' : dimmed ? 'opacity-40' : '',
      ].join(' ')}
      style={{ left, width }}
      {...attributes}
      {...listeners}
    >
      <span className="block truncate font-semibold">{visit.patient?.name ?? '–'}</span>
      <span className="block truncate opacity-90">
        {visit.start}
        {!companion && visit.requiredRank >= 2 && ` · ${QUALIFICATION_SHORT[visit.requiredQualification]}`}
        {visit.requiredStaff > 1 && ' · 2P'}
        {companion && ' · Begl.'}
      </span>
    </button>
  );
}

/** Dashed proposal block (route optimisation preview). */
export function GhostBlock({ stop, timelineStart, zoom }) {
  const start = toMinutes(stop.start);
  const end = toMinutes(stop.end);
  return (
    <div
      className="cd-ghost pointer-events-none absolute top-1 bottom-1 rounded-md px-1 text-[10px] leading-tight text-brand-700"
      style={{ left: (start - timelineStart) * zoom, width: Math.max((end - start) * zoom, 18) }}
      title={`Vorschlag: ${stop.start} ${stop.patientName ?? ''} (+${stop.travelMinutes} Min. Fahrt)`}
    >
      <span className="block truncate font-semibold">{stop.patientName}</span>
      <span className="block truncate">{stop.start}</span>
    </div>
  );
}
