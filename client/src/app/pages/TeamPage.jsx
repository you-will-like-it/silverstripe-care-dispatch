import { useState } from 'react';
import { Badge, Button, Checkbox, ErrorBox, Field, Input, Loading, Modal, PageHeader, Select, useForm, useLoad, useToast } from '../components/ui.jsx';
import { QUALIFICATION_SHORT } from '../../shared/labels.js';

const COLORS = ['#2563eb', '#7c3aed', '#db2777', '#059669', '#d97706', '#0891b2', '#65a30d', '#dc2626', '#4f46e5', '#64748b'];

export default function TeamPage({ admin }) {
  const [showInactive, setShowInactive] = useState(false);
  const { data, error, loading, reload } = useLoad(() => admin.get('staff/list', { inactive: showInactive ? 1 : undefined }), [admin, showInactive]);
  const [editing, setEditing] = useState(null);
  const [password, setPassword] = useState(null);
  const notify = useToast();

  const deactivate = async (m) => {
    if (!window.confirm(`${m.name} deaktivieren? Das Konto verliert jeden Zugang, offene zukünftige Touren werden wieder unbesetzt. Die Historie bleibt erhalten.`)) return;
    try {
      await admin.post(`staff/${m.id}/deactivate`);
      notify({ type: 'success', text: `${m.name} deaktiviert.` });
      reload();
    } catch (e) {
      notify({ type: 'error', text: e.message });
    }
  };

  return (
    <div>
      <PageHeader
        title="Team"
        subtitle="Mitarbeiter, Benutzerkonten und Rollen"
        actions={
          <>
            <Checkbox label="Deaktivierte anzeigen" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} />
            <Button variant="primary" onClick={() => setEditing({})}>Mitarbeiter anlegen</Button>
          </>
        }
      />
      <ErrorBox error={error} onRetry={reload} />
      {!data && loading && <Loading />}
      {data && (
        <div className="space-y-4 p-6">
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-4 py-2">Name</th><th className="px-4 py-2">Login (E-Mail)</th><th className="px-4 py-2">Rolle</th><th className="px-4 py-2">Pflege</th><th className="px-4 py-2" /></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.staff.map((m) => (
                  <tr key={m.id} className={m.active ? '' : 'text-slate-400'}>
                    <td className="px-4 py-2">
                      <div className="flex items-center gap-2">
                        <span className="h-3 w-3 rounded-full" style={{ background: m.caregiver?.color ?? '#cbd5e1' }} />
                        <span className="font-medium">{m.name}</span>
                        {m.isSelf && <Badge tone="blue">du</Badge>}
                        {m.isAdmin && <Badge tone="violet">Admin</Badge>}
                      </div>
                    </td>
                    <td className="px-4 py-2">{m.email}</td>
                    <td className="px-4 py-2">{m.active ? m.roleLabel : <Badge>deaktiviert</Badge>}</td>
                    <td className="px-4 py-2">
                      {m.caregiver?.isActive ? (
                        <span>
                          <Badge tone={m.caregiver.qualification === 'Fachkraft' ? 'red' : 'slate'}>{QUALIFICATION_SHORT[m.caregiver.qualification]}</Badge>{' '}
                          <span className="text-xs text-slate-500">{String(m.caregiver.weeklyHours).replace('.', ',')} h/Woche{!m.caregiver.drivingLicense && ' · ohne Führerschein'}</span>
                        </span>
                      ) : '–'}
                    </td>
                    <td className="px-4 py-2 text-right whitespace-nowrap">
                      {m.editable && (
                        <>
                          <Button variant="ghost" onClick={() => setEditing(m)}>{m.active ? 'Bearbeiten' : 'Reaktivieren'}</Button>
                          <Button variant="ghost" onClick={() => setPassword(m)}>Passwort</Button>
                          {m.active && !m.isSelf && <Button variant="dangerGhost" onClick={() => deactivate(m)}>Deaktivieren</Button>}
                        </>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="grid gap-3 md:grid-cols-3">
            {data.roles.map((r) => (
              <div key={r.value} className="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                <p className="font-semibold">{r.label}</p>
                <p className="mt-1 text-slate-600">{r.description}</p>
              </div>
            ))}
          </div>
        </div>
      )}
      {editing && data && (
        <StaffForm
          admin={admin}
          member={editing.id ? editing : null}
          roles={data.roles}
          qualifications={data.qualifications}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
      {password && <PasswordForm admin={admin} member={password} onClose={() => setPassword(null)} />}
    </div>
  );
}

function StaffForm({ admin, member, roles, qualifications, onClose, onSaved }) {
  const isNew = !member;
  const cg = member?.caregiver;
  const { values, set, errors, busy, submit } = useForm({
    firstName: member?.firstName ?? '',
    lastName: member?.lastName ?? '',
    email: member?.email ?? '',
    password: '',
    role: member?.role ?? 'pflegekraft',
    isCaregiver: member ? !!cg?.isActive || member.role === 'pflegekraft' : true,
    qualification: cg?.qualification ?? 'Hilfskraft',
    weeklyHours: cg?.weeklyHours ?? 39,
    drivingLicense: cg?.drivingLicense ?? true,
    color: cg?.color ?? COLORS[Math.floor(Math.random() * COLORS.length)],
    startStreet: cg?.startStreet ?? '',
    startPostalCode: cg?.startPostalCode ?? '',
    startCity: cg?.startCity ?? '',
  });
  const caregiverForced = values.role === 'pflegekraft';
  const showCaregiver = caregiverForced || values.isCaregiver;

  const save = () => submit((v) => admin.post(isNew ? 'staff/create' : `staff/${member.id}`, {
    firstName: v.firstName,
    lastName: v.lastName,
    email: v.email,
    password: isNew ? v.password : undefined,
    role: v.role,
    isCaregiver: showCaregiver,
    caregiver: showCaregiver ? {
      qualification: v.qualification,
      weeklyHours: Number(String(v.weeklyHours).replace(',', '.')),
      drivingLicense: v.drivingLicense,
      color: v.color,
      startStreet: v.startStreet,
      startPostalCode: v.startPostalCode,
      startCity: v.startCity,
    } : undefined,
  }), isNew ? 'Mitarbeiter angelegt.' : 'Gespeichert.').then((res) => res && onSaved(res.member));

  return (
    <Modal
      title={isNew ? 'Mitarbeiter anlegen' : `${member.name} bearbeiten`}
      onClose={onClose}
      width="max-w-2xl"
      footer={<><Button onClick={onClose}>Abbrechen</Button><Button variant="primary" onClick={save} disabled={busy}>Speichern</Button></>}
    >
      <div className="grid grid-cols-6 gap-3">
        <Field label="Vorname" className="col-span-3" error={errors.FirstName}><Input value={values.firstName} onChange={set('firstName')} autoFocus /></Field>
        <Field label="Nachname" className="col-span-3" error={errors.Surname}><Input value={values.lastName} onChange={set('lastName')} /></Field>
        <Field label="E-Mail (Login)" className={isNew ? 'col-span-3' : 'col-span-6'} error={errors.Email}><Input type="email" value={values.email} onChange={set('email')} /></Field>
        {isNew && (
          <Field label="Passwort" className="col-span-3" error={errors.Password} hint="Wird der Person mitgeteilt, muss nicht geändert werden.">
            <Input type="text" value={values.password} onChange={set('password')} autoComplete="new-password" />
          </Field>
        )}
        <Field label="Rolle" className="col-span-6" hint={roles.find((r) => r.value === values.role)?.description}>
          <Select
            value={values.role}
            onChange={set('role')}
            disabled={member?.isSelf}
            options={roles.map((r) => ({ value: r.value, label: r.label + (r.grantable ? '' : ' (keine Berechtigung)'), disabled: !r.grantable }))}
          />
        </Field>
        {values.role !== 'pflegekraft' && (
          <Checkbox className="col-span-6" label="arbeitet auch in der Pflege (eigene Touren, mobile Ansicht)" checked={values.isCaregiver} onChange={set('isCaregiver')} />
        )}
        {showCaregiver && (
          <>
            <div className="col-span-6 border-t border-slate-100 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Pflegekraft</div>
            <Field label="Qualifikation" className="col-span-3">
              <Select value={values.qualification} onChange={set('qualification')} options={Object.entries(qualifications).map(([value, label]) => ({ value, label }))} />
            </Field>
            <Field label="Wochenstunden" className="col-span-1" error={errors.ContractWeeklyHours}><Input value={values.weeklyHours} onChange={set('weeklyHours')} inputMode="decimal" /></Field>
            <div className="col-span-2 flex items-end pb-1.5"><Checkbox label="Führerschein" checked={values.drivingLicense} onChange={set('drivingLicense')} /></div>
            <Field label="Farbe im Einsatzplan" className="col-span-6">
              <div className="flex gap-1.5">
                {COLORS.map((c) => (
                  <button key={c} type="button" onClick={() => set('color')(c)} className={`h-7 w-7 rounded-full ${values.color === c ? 'ring-2 ring-slate-900 ring-offset-2' : ''}`} style={{ background: c }} aria-label={c} />
                ))}
              </div>
            </Field>
            <Field label="Eigene Startadresse (optional)" className="col-span-3" hint="Sonst startet die Route am Firmenstandort.">
              <Input value={values.startStreet} onChange={set('startStreet')} placeholder="Straße und Nr." />
            </Field>
            <Field label="PLZ" className="col-span-1"><Input value={values.startPostalCode} onChange={set('startPostalCode')} /></Field>
            <Field label="Ort" className="col-span-2"><Input value={values.startCity} onChange={set('startCity')} /></Field>
          </>
        )}
      </div>
    </Modal>
  );
}

function PasswordForm({ admin, member, onClose }) {
  const { values, set, errors, busy, submit } = useForm({ password: '' });
  const save = () => submit((v) => admin.post(`staff/${member.id}/password`, v), `Passwort für ${member.name} gesetzt.`).then((res) => res && onClose());
  return (
    <Modal
      title={`Passwort setzen – ${member.name}`}
      onClose={onClose}
      footer={<><Button onClick={onClose}>Abbrechen</Button><Button variant="primary" onClick={save} disabled={busy || !values.password}>Setzen</Button></>}
    >
      <Field label="Neues Passwort" error={errors.Password} hint="Muss die Passwort-Richtlinie erfüllen. Eine Sperre nach Fehlversuchen wird aufgehoben.">
        <Input type="text" value={values.password} onChange={set('password')} autoComplete="new-password" autoFocus />
      </Field>
    </Modal>
  );
}
