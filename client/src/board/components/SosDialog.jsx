import { useState } from 'react';
import Modal, { Button } from './Modal.jsx';

const REASONS = ['Verletzung / Unfall', 'Plötzliche Erkrankung', 'Fahrzeugpanne', 'Notfall beim Patienten', 'Pflegekraft nicht erreichbar'];

/**
 * Tour split. Completed visits stay with the caregiver; pending ones go to the pool.
 * If a visit is running, the PDL must state whether it was completed.
 */
export default function SosDialog({ tour, caregiver, visits, onConfirm, onClose, busy }) {
  const running = visits.find((v) => v.status === 'InProgress');
  const pending = visits.filter((v) => v.status === 'Pending').length;
  const done = visits.filter((v) => v.status === 'Completed').length;
  const [preset, setPreset] = useState('');
  const [details, setDetails] = useState('');
  const [outcome, setOutcome] = useState('');

  const reason = [preset, details.trim()].filter(Boolean).join(': ');
  const valid = preset !== '' && (!running || outcome !== '') && (preset !== 'Sonstiges' || details.trim() !== '');

  return (
    <Modal
      title={`Tour unterbrechen – ${tour.title}`}
      tone="danger"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Abbrechen</Button>
          <Button variant="danger" disabled={!valid || busy} onClick={() => onConfirm({ reason, current_visit_outcome: running ? outcome : undefined })}>
            Tour jetzt unterbrechen
          </Button>
        </>
      }
    >
      <p>
        <strong>{caregiver?.name ?? 'Unbesetzt'}</strong>: {done} erledigt, {running ? '1 laufend, ' : ''}
        {pending} offen.
      </p>
      <ul className="mt-2 list-disc space-y-1 pl-5 text-slate-600">
        <li>Erledigte Einsätze bleiben dokumentiert (Abrechnung).</li>
        <li>{pending} offene Einsätze gehen sofort in den Pool, Begleitungen anderer Touren ebenfalls.</li>
        <li>Die Tour wird endgültig als „Unterbrochen“ markiert.</li>
      </ul>

      <label className="mt-4 block font-medium">
        Grund
        <select className="mt-1 w-full rounded-lg border border-slate-300 p-2" value={preset} onChange={(e) => setPreset(e.target.value)}>
          <option value="">Bitte wählen …</option>
          {REASONS.map((r) => <option key={r}>{r}</option>)}
          <option>Sonstiges</option>
        </select>
      </label>
      <textarea
        className="mt-2 w-full rounded-lg border border-slate-300 p-2"
        rows={2}
        placeholder={preset === 'Sonstiges' ? 'Bitte Grund beschreiben (Pflicht)' : 'Details (optional)'}
        value={details}
        onChange={(e) => setDetails(e.target.value)}
      />

      {running && (
        <fieldset className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3">
          <legend className="px-1 font-medium text-amber-900">Laufender Einsatz bei {running.patient?.name}</legend>
          <label className="flex items-center gap-2 py-1">
            <input type="radio" name="outcome" value="completed" checked={outcome === 'completed'} onChange={(e) => setOutcome(e.target.value)} />
            abgeschlossen – bleibt bei {caregiver?.name}
          </label>
          <label className="flex items-center gap-2 py-1">
            <input type="radio" name="outcome" value="not_completed" checked={outcome === 'not_completed'} onChange={(e) => setOutcome(e.target.value)} />
            nicht abgeschlossen – zurück in den Pool (dokumentierte Leistungen bleiben erhalten)
          </label>
        </fieldset>
      )}
    </Modal>
  );
}
