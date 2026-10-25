import { Button, Card, Checkbox, ErrorBox, Field, Input, Loading, PageHeader, useForm, useLoad, useToast } from '../components/ui.jsx';

export default function SettingsPage({ admin }) {
  const { data, error, reload } = useLoad(() => admin.get('settings/get'), [admin]);
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (!data) return <Loading />;
  return <SettingsForm admin={admin} settings={data.settings} />;
}

function SettingsForm({ admin, settings }) {
  const notify = useToast();
  const { values, set, setValues, errors, busy, submit } = useForm({
    ...settings,
    depotLat: settings.depotLat ?? '',
    depotLng: settings.depotLng ?? '',
  });
  const canEdit = settings.canEdit;
  const num = (v) => (v === '' || v === null ? null : Number(String(v).replace(',', '.')));

  const save = async (geocode) => {
    const res = await submit((v) => admin.post('settings/save', {
      ...v,
      lateAfterMinutes: Number(v.lateAfterMinutes),
      geocode,
      depotLat: geocode ? null : num(v.depotLat),
      depotLng: geocode ? null : num(v.depotLng),
    }));
    if (res) {
      setValues({ ...res.settings, depotLat: res.settings.depotLat ?? '', depotLng: res.settings.depotLng ?? '' });
      notify(res.notice ? { type: 'warning', text: res.notice } : { type: 'success', text: 'Einstellungen gespeichert.' });
    }
  };

  return (
    <div>
      <PageHeader title="Einstellungen" actions={canEdit && <Button variant="primary" onClick={() => save(false)} disabled={busy}>Speichern</Button>} />
      <div className="grid max-w-5xl gap-4 p-6 lg:grid-cols-2">
        <Card title="Pflegedienst">
          <Field label="Name" hint="Erscheint in der Navigation und im Seitentitel."><Input value={values.companyName} onChange={set('companyName')} disabled={!canEdit} /></Field>
        </Card>
        <Card title="Firmenstandort (Start/Ende der Routen)">
          <div className="grid grid-cols-6 gap-3">
            <Field label="Straße und Nr." className="col-span-6"><Input value={values.depotStreet} onChange={set('depotStreet')} disabled={!canEdit} /></Field>
            <Field label="PLZ" className="col-span-2"><Input value={values.depotPostalCode} onChange={set('depotPostalCode')} disabled={!canEdit} /></Field>
            <Field label="Ort" className="col-span-4"><Input value={values.depotCity} onChange={set('depotCity')} disabled={!canEdit} /></Field>
            <Field label="Breitengrad" className="col-span-3"><Input value={values.depotLat} onChange={set('depotLat')} disabled={!canEdit} placeholder="52.5200" /></Field>
            <Field label="Längengrad" className="col-span-3"><Input value={values.depotLng} onChange={set('depotLng')} disabled={!canEdit} placeholder="13.4050" /></Field>
          </div>
          {canEdit && settings.geocoderEnabled && <Button className="mt-3" onClick={() => save(true)} disabled={busy}>Koordinaten aus Adresse ermitteln</Button>}
          {!settings.geocoderEnabled && <p className="mt-3 text-xs text-slate-500">Kein Geocoder eingerichtet – Koordinaten bitte manuell eintragen (z. B. aus OpenStreetMap: Rechtsklick → „Adresse anzeigen“).</p>}
        </Card>
        <Card title="Einsatzplan">
          <div className="grid grid-cols-3 gap-3">
            <Field label="Zeitleiste von" error={errors.TimelineStart}><Input type="time" value={values.timelineStart} onChange={set('timelineStart')} disabled={!canEdit} /></Field>
            <Field label="bis" error={errors.TimelineEnd}><Input type="time" value={values.timelineEnd} onChange={set('timelineEnd')} disabled={!canEdit} /></Field>
            <Field label="„Verspätet“ nach (Min.)" error={errors.LateAfterMinutes}><Input type="number" min={0} max={240} value={values.lateAfterMinutes} onChange={set('lateAfterMinutes')} disabled={!canEdit} /></Field>
          </div>
        </Card>
        <Card title="Datenschutz">
          <Checkbox label="Zugriffsprotokoll: Öffnen von Patientenakten protokollieren" checked={!!values.logReadAccess} onChange={set('logReadAccess')} disabled={!canEdit} />
          <p className="mt-2 text-xs text-slate-500">Wiederholte Zugriffe derselben Person werden 15 Minuten lang zusammengefasst. Einsehbar im Protokoll („angesehen“).</p>
        </Card>
      </div>
    </div>
  );
}
