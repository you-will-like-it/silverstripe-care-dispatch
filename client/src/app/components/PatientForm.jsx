import { Button, Checkbox, Field, Input, Modal, Textarea, useForm } from './ui.jsx';

/** Create / edit patient master data (modal). */
export default function PatientForm({ admin, patient, onClose, onSaved }) {
  const isNew = !patient;
  const { values, set, errors, busy, submit } = useForm({
    firstName: patient?.firstName ?? '',
    lastName: patient?.lastName ?? '',
    street: patient?.street ?? '',
    postalCode: patient?.postalCode ?? '',
    city: patient?.city ?? '',
    country: patient?.country ?? 'DE',
    phone: patient?.phone ?? '',
    accessNotes: patient?.accessNotes ?? '',
    careNotes: patient?.careNotes ?? '',
    isActive: patient?.isActive ?? true,
  });

  const save = () => submit(
    (v) => admin.post(isNew ? 'patients/create' : `patients/${patient.id}`, v),
    isNew ? 'Patient angelegt.' : 'Gespeichert.',
  ).then((res) => res && onSaved(res.patient));

  return (
    <Modal
      title={isNew ? 'Patient aufnehmen' : 'Stammdaten bearbeiten'}
      onClose={onClose}
      width="max-w-2xl"
      footer={
        <>
          <Button onClick={onClose}>Abbrechen</Button>
          <Button variant="primary" onClick={save} disabled={busy}>Speichern</Button>
        </>
      }
    >
      <div className="grid grid-cols-6 gap-3">
        <Field label="Vorname" className="col-span-3" error={errors.FirstName}><Input value={values.firstName} onChange={set('firstName')} autoFocus /></Field>
        <Field label="Nachname" className="col-span-3" error={errors.Surname}><Input value={values.lastName} onChange={set('lastName')} /></Field>
        <Field label="Straße und Hausnummer" className="col-span-6" error={errors.Street}><Input value={values.street} onChange={set('street')} /></Field>
        <Field label="PLZ" className="col-span-2" error={errors.PostalCode}><Input value={values.postalCode} onChange={set('postalCode')} /></Field>
        <Field label="Ort" className="col-span-3" error={errors.City}><Input value={values.city} onChange={set('city')} /></Field>
        <Field label="Land" className="col-span-1" error={errors.Country}><Input value={values.country} onChange={set('country')} maxLength={2} /></Field>
        <Field label="Telefon" className="col-span-3"><Input value={values.phone} onChange={set('phone')} /></Field>
        <div className="col-span-3 flex items-end pb-1.5">
          <Checkbox label="aktiv (wird geplant)" checked={values.isActive} onChange={set('isActive')} />
        </div>
        <Field label="Zugangshinweise" className="col-span-6" hint="Schlüsselsafe, Klingel, Haustiere … (sichtbar in der mobilen Ansicht)">
          <Textarea rows={2} value={values.accessNotes} onChange={set('accessNotes')} />
        </Field>
        <Field label="Pflegehinweise" className="col-span-6">
          <Textarea rows={2} value={values.careNotes} onChange={set('careNotes')} />
        </Field>
      </div>
      {!isNew && <p className="mt-3 text-xs text-slate-500">Bei Adressänderung wird die Adresse neu verortet (falls ein Geocoder eingerichtet ist).</p>}
    </Modal>
  );
}
