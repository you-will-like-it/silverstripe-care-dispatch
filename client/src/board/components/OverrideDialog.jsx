import { useState } from 'react';
import Modal, { Button } from './Modal.jsx';

/** Soft rule violations (HTTP 409): the PDL may confirm with a mandatory reason (audited). */
export default function OverrideDialog({ violations, onConfirm, onClose, busy }) {
  const [reason, setReason] = useState('');

  return (
    <Modal
      title="Warnungen bestätigen"
      tone="warning"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Abbrechen</Button>
          <Button variant="warning" disabled={reason.trim().length < 3 || busy} onClick={() => onConfirm(reason.trim())}>
            Trotzdem zuweisen
          </Button>
        </>
      }
    >
      <ul className="space-y-2">
        {violations.map((v, i) => (
          <li key={i} className="flex gap-2 rounded-lg bg-amber-50 p-2.5 text-amber-900">
            <span aria-hidden="true">⚠</span>
            <span>{v.message}</span>
          </li>
        ))}
      </ul>
      <label className="mt-4 block font-medium">
        Begründung (wird protokolliert)
        <textarea className="mt-1 w-full rounded-lg border border-slate-300 p-2" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="z. B. Absprache mit Pflegekraft, Überstunden genehmigt" />
      </label>
    </Modal>
  );
}
