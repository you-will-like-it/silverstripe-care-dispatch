import { useEffect, useState } from 'react';
import { Badge, Button, ErrorBox, Loading, PageHeader, Select, Input, useLoad } from '../components/ui.jsx';
import { Link, useRouter } from '../router.jsx';
import PatientForm from '../components/PatientForm.jsx';

export default function PatientsPage({ admin, can, P }) {
  const { navigate } = useRouter();
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('active');
  const [creating, setCreating] = useState(false);
  const { data, error, loading, reload } = useLoad(() => admin.get('patients/list', { q: query, status }), [admin, query, status]);

  useEffect(() => {
    const t = setTimeout(() => setQuery(q), 250);
    return () => clearTimeout(t);
  }, [q]);

  return (
    <div>
      <PageHeader
        title="Patienten"
        subtitle={data ? `${data.patients.length} Einträge` : ' '}
        actions={can(P.MASTERDATA) && <Button variant="primary" onClick={() => setCreating(true)}>Patient aufnehmen</Button>}
      />
      <div className="flex flex-wrap gap-2 px-6 pt-4">
        <Input type="search" className="w-72" placeholder="Name, Straße oder Ort …" value={q} onChange={(e) => setQ(e.target.value)} />
        <Select
          className="max-w-[14rem]"
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          options={[
            { value: 'active', label: 'Aktive Patienten' },
            { value: 'nogeo', label: 'Ohne Koordinaten' },
            { value: 'inactive', label: 'Inaktive Patienten' },
            { value: 'all', label: 'Alle' },
          ]}
        />
      </div>
      <ErrorBox error={error} onRetry={reload} />
      {!data && loading && <Loading />}
      {data && (
        <div className="p-6">
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-4 py-2">Name</th>
                  <th className="px-4 py-2">Adresse</th>
                  <th className="px-4 py-2">Telefon</th>
                  <th className="px-4 py-2">Pflegeplan</th>
                  <th className="px-4 py-2">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.patients.map((p) => (
                  <tr key={p.id} className="cursor-pointer hover:bg-slate-50" onClick={() => navigate(`/patienten/${p.id}`)}>
                    <td className="px-4 py-2 font-medium"><Link to={`/patienten/${p.id}`} className="hover:underline">{p.name}</Link></td>
                    <td className="px-4 py-2 text-slate-600">{p.address}</td>
                    <td className="px-4 py-2 text-slate-600">{p.phone ?? '–'}</td>
                    <td className="px-4 py-2">{p.carePlanCount > 0 ? `${p.carePlanCount} Positionen` : <Badge tone="amber">leer</Badge>}</td>
                    <td className="px-4 py-2 space-x-1">
                      {!p.isActive && <Badge>inaktiv</Badge>}
                      {!p.isGeocoded && <Badge tone="red">keine Koordinaten</Badge>}
                      {p.isActive && p.isGeocoded && <Badge tone="green">aktiv</Badge>}
                    </td>
                  </tr>
                ))}
                {data.patients.length === 0 && (
                  <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">Keine Patienten gefunden.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
      {creating && (
        <PatientForm
          admin={admin}
          onClose={() => setCreating(false)}
          onSaved={(patient) => navigate(`/patienten/${patient.id}`)}
        />
      )}
    </div>
  );
}
