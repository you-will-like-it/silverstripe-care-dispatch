import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import './mobile.css';
import { createApi, readConfig } from '../shared/api.js';
import { isoToHHMM } from '../shared/time.js';
import { ATTENDANCE, TOUR_STATUS, VISIT_STATUS } from '../shared/labels.js';

/**
 * Field staff view (templates/YouWillLikeIT/CareDispatch/MobileApp.ss).
 * Server-rendered shell, Alpine.js for state; all logic validated server-side.
 */
Alpine.data('careMobile', () => ({
  api: null,
  config: {},
  state: null,
  busy: false,
  message: null,
  open: null,
  notes: {},
  sos: { open: false, preset: '', details: '', outcome: '' },
  timer: null,

  init() {
    this.config = readConfig(this.$el);
    this.api = createApi(this.config);
    this.refresh();
    const seconds = Math.max(20, this.config.pollSeconds ?? 60);
    this.timer = setInterval(() => {
      if (!this.busy && !this.sos.open && document.visibilityState === 'visible') this.refresh(true);
    }, seconds * 1000);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && this.refresh(true));
  },

  destroy() {
    clearInterval(this.timer);
  },

  async refresh(silent = false) {
    if (!silent) this.busy = true;
    try {
      this.state = await this.api.get('my-tour');
      const running = this.runningVisit();
      if (running && this.open === null) this.open = running.id;
    } catch (e) {
      if (!silent) this.error(e.message);
    } finally {
      if (!silent) this.busy = false;
    }
  },

  async post(path, body = {}, successText = null) {
    this.busy = true;
    try {
      const res = await this.api.post(path, body);
      if (res.state) this.state = res.state;
      if (successText) this.message = { type: 'success', text: successText };
      else this.message = null;
      return res.result ?? res;
    } catch (e) {
      this.error(e.message);
      return null;
    } finally {
      this.busy = false;
    }
  },

  error(text) {
    this.message = { type: 'error', text };
    if (navigator.vibrate) navigator.vibrate(120);
  },

  toggle(id) {
    this.open = this.open === id ? null : id;
  },

  confirmCheckOut() {
    if (window.confirm('Gehen jetzt buchen?')) this.post('check-out', {}, 'Gehen gebucht. Schönen Feierabend!');
  },

  async setTask(task, status, note = '') {
    const res = await this.post(`task/${task.id}`, { status, note });
    if (res?.task) Object.assign(task, res.task);
  },

  notDone(task) {
    if (task.status === 'NotDone') {
      this.setTask(task, 'Open');
      return;
    }
    const note = window.prompt(`Warum wurde „${task.title}“ nicht erbracht?`);
    if (note && note.trim()) this.setTask(task, 'NotDone', note.trim());
  },

  complete(visit) {
    const open = visit.tasks.filter((t) => t.status === 'Open');
    if (open.length && !window.confirm(`${open.length} Leistung(en) noch offen. Alle als erledigt markieren und abschließen?`)) return;
    const tasks = open.map((t) => ({ id: t.id, status: 'Done' }));
    this.post(`visit/${visit.id}/status`, { action: 'complete', tasks, notes: this.notes[visit.id] ?? '' }, 'Einsatz abgeschlossen.')
      .then((r) => { if (r) { delete this.notes[visit.id]; this.open = this.nextPendingId(); } });
  },

  nextPendingId() {
    for (const tour of this.state?.tours ?? []) {
      const next = tour.visits.find((v) => v.status === 'Pending');
      if (next) return next.id;
    }
    return null;
  },

  runningVisit() {
    for (const tour of this.state?.tours ?? []) {
      const running = tour.visits.find((v) => v.status === 'InProgress');
      if (running) return running;
    }
    return null;
  },

  hasOpenTour() {
    return (this.state?.tours ?? []).some((t) => t.status === 'Assigned' || t.status === 'InProgress');
  },

  sosValid() {
    if (!this.sos.preset) return false;
    if (this.sos.preset === 'other' && !this.sos.details.trim()) return false;
    return !this.runningVisit() || this.sos.outcome !== '';
  },

  async sendSos() {
    const preset = this.sos.preset === 'other' ? 'Sonstiges' : this.sos.preset;
    const reason = [preset, this.sos.details.trim()].filter(Boolean).join(': ');
    const body = { reason };
    if (this.runningVisit()) body.current_visit_outcome = this.sos.outcome;
    const res = await this.post('sos', body);
    if (res) {
      this.sos = { open: false, preset: '', details: '', outcome: '' };
      this.message = { type: 'success', text: `SOS gesendet. ${res.pooledVisits} Einsatz/Einsätze werden neu verteilt. Bitte bei Bedarf 112 anrufen.` };
    }
  },

  navUrl(visit) {
    const p = visit.patient;
    return `https://www.google.com/maps/dir/?api=1&destination=${p.lat},${p.lng}`;
  },

  formatTime: isoToHHMM,
  visitStatusLabel: (s) => VISIT_STATUS[s] ?? s,
  tourStatusLabel: (s) => TOUR_STATUS[s] ?? s,
  tourStatusClass: (s) => (s === 'Interrupted' ? 'text-red-700' : s === 'Completed' ? 'text-emerald-700' : 'text-slate-500'),

  attendanceBadge() {
    const status = this.state?.attendance?.status ?? 'Absent';
    const cls = {
      Active: 'bg-emerald-100 text-emerald-800',
      Break: 'bg-amber-100 text-amber-800',
      Completed: 'bg-slate-200 text-slate-700',
      Absent: 'bg-white/20 text-white',
    }[status];
    return { label: ATTENDANCE[status] ?? status, cls };
  },
}));

Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();
