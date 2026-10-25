/** "HH:MM" <-> minutes since midnight. */
export function toMinutes(hhmm) {
  if (!hhmm) return 0;
  const [h, m] = hhmm.split(':').map(Number);
  return h * 60 + (m || 0);
}

export function toHHMM(minutes) {
  const clamped = Math.max(0, Math.min(23 * 60 + 59, Math.round(minutes)));
  return `${String(Math.floor(clamped / 60)).padStart(2, '0')}:${String(clamped % 60).padStart(2, '0')}`;
}

export function snap(minutes, step = 5) {
  return Math.round(minutes / step) * step;
}

export function isoToMinutes(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  return d.getHours() * 60 + d.getMinutes();
}

export function isoToHHMM(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}

export function addDays(ymd, days) {
  const d = new Date(`${ymd}T12:00:00`);
  d.setDate(d.getDate() + days);
  return ymdOf(d);
}

export function todayYmd() {
  return ymdOf(new Date());
}

function ymdOf(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function formatDateLong(ymd) {
  return new Date(`${ymd}T12:00:00`).toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
}
