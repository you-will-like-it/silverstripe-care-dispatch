import { useEffect, useMemo } from 'react';
import { Link, RouterProvider, matchPath, useRouter } from './router.jsx';
import { ToastProvider } from './components/ui.jsx';
import { createApi } from '../shared/api.js';
import BoardPage from './pages/BoardPage.jsx';
import RosterPage from './pages/RosterPage.jsx';
import PatientsPage from './pages/PatientsPage.jsx';
import PatientDetailPage from './pages/PatientDetailPage.jsx';
import TemplatesPage from './pages/TemplatesPage.jsx';
import TemplateDetailPage from './pages/TemplateDetailPage.jsx';
import TeamPage from './pages/TeamPage.jsx';
import TimePage from './pages/TimePage.jsx';
import TasksPage from './pages/TasksPage.jsx';
import AuditPage from './pages/AuditPage.jsx';
import SettingsPage from './pages/SettingsPage.jsx';

const P = {
  DISPATCH: 'PFLEGE_DISPATCH_ACCESS',
  MOBILE: 'PFLEGE_MOBILE_ACCESS',
  MASTERDATA: 'PFLEGE_MASTERDATA_EDIT',
  CAREPLAN: 'PFLEGE_CAREPLAN_EDIT',
  ROSTER: 'PFLEGE_ROSTER_EDIT',
  STAFF: 'PFLEGE_STAFF_MANAGE',
  AUDIT: 'PFLEGE_AUDIT_VIEW',
  SETTINGS: 'PFLEGE_SETTINGS_EDIT',
};

/** Navigation; `any` = one of these permissions shows the section. */
const SECTIONS = [
  { path: '/board', label: 'Einsatzplan', icon: '▦', any: [P.DISPATCH] },
  { path: '/dienstplan', label: 'Dienstplan', icon: '▤', any: [P.ROSTER, P.DISPATCH] },
  { path: '/patienten', label: 'Patienten', icon: '♥', any: [P.MASTERDATA, P.CAREPLAN, P.DISPATCH] },
  { path: '/touren', label: 'Tourvorlagen', icon: '⇢', any: [P.DISPATCH] },
  { path: '/team', label: 'Team', icon: '☺', any: [P.STAFF] },
  { path: '/zeiten', label: 'Zeiten', icon: '◷', any: [P.ROSTER] },
  { path: '/leistungen', label: 'Leistungen', icon: '☰', any: [P.MASTERDATA, P.CAREPLAN] },
  { path: '/protokoll', label: 'Protokoll', icon: '⌕', any: [P.AUDIT] },
  { path: '/einstellungen', label: 'Einstellungen', icon: '⚙', any: [P.SETTINGS] },
];

const ROUTES = [
  { pattern: '/board', section: '/board', render: (ctx) => <BoardPage config={ctx.config} /> },
  { pattern: '/dienstplan', section: '/dienstplan', render: (ctx) => <RosterPage {...ctx} /> },
  { pattern: '/patienten', section: '/patienten', render: (ctx) => <PatientsPage {...ctx} /> },
  { pattern: '/patienten/:id', section: '/patienten', render: (ctx, p) => <PatientDetailPage {...ctx} id={Number(p.id)} /> },
  { pattern: '/touren', section: '/touren', render: (ctx) => <TemplatesPage {...ctx} /> },
  { pattern: '/touren/:id', section: '/touren', render: (ctx, p) => <TemplateDetailPage {...ctx} id={Number(p.id)} /> },
  { pattern: '/team', section: '/team', render: (ctx) => <TeamPage {...ctx} /> },
  { pattern: '/zeiten', section: '/zeiten', render: (ctx) => <TimePage {...ctx} /> },
  { pattern: '/leistungen', section: '/leistungen', render: (ctx) => <TasksPage {...ctx} /> },
  { pattern: '/protokoll', section: '/protokoll', render: (ctx) => <AuditPage {...ctx} /> },
  { pattern: '/einstellungen', section: '/einstellungen', render: (ctx) => <SettingsPage {...ctx} /> },
];

export default function App({ config }) {
  return (
    <RouterProvider base={config.appBase ?? '/pflege'}>
      <ToastProvider>
        <Shell config={config} />
      </ToastProvider>
    </RouterProvider>
  );
}

function Shell({ config }) {
  const { path, navigate } = useRouter();
  const perms = config.permissions ?? {};
  const can = useMemo(() => (...codes) => codes.some((c) => perms[c]), [perms]);
  const admin = useMemo(() => createApi(config, config.adminApiBase), [config]);
  const sections = SECTIONS.filter((s) => can(...s.any));

  const route = ROUTES.map((r) => ({ r, params: matchPath(r.pattern, path) })).find((x) => x.params);
  const allowed = route && sections.some((s) => s.path === route.r.section);

  useEffect(() => {
    if ((!route || !allowed) && sections.length > 0) navigate(sections[0].path, { replace: true });
  }, [route, allowed, sections, navigate]);

  const ctx = { config, admin, can, P };

  return (
    <div className="flex h-full font-sans text-slate-900">
      <nav className="flex w-52 shrink-0 flex-col bg-slate-900 text-slate-300" aria-label="Hauptnavigation">
        <div className="px-4 py-4">
          <p className="truncate text-sm font-semibold text-white">{config.companyName || 'Einsatzplanung'}</p>
          <p className="text-xs text-slate-400">Pflegedienst</p>
        </div>
        <ul className="flex-1 space-y-0.5 px-2">
          {sections.map((s) => {
            const active = route?.r.section === s.path;
            return (
              <li key={s.path}>
                <Link
                  to={s.path}
                  className={`flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm ${active ? 'bg-slate-700 font-semibold text-white' : 'hover:bg-slate-800 hover:text-white'}`}
                  aria-current={active ? 'page' : undefined}
                >
                  <span className="w-4 text-center opacity-80" aria-hidden="true">{s.icon}</span>
                  {s.label}
                </Link>
              </li>
            );
          })}
        </ul>
        <div className="border-t border-slate-800 px-4 py-3 text-xs">
          <p className="truncate font-medium text-white">{config.member?.name}</p>
          <p className="truncate text-slate-400">{config.roleLabel ?? 'Administrator'}</p>
          <div className="mt-2 flex gap-3">
            {perms[P.MOBILE] && <a href={config.mobileUrl} className="hover:text-white">Mobil</a>}
            <a href={config.logoutUrl} className="hover:text-white">Abmelden</a>
          </div>
        </div>
      </nav>
      <div className="min-w-0 flex-1 overflow-auto bg-slate-50">
        {route && allowed ? route.r.render(ctx, route.params) : sections.length === 0 && (
          <p className="p-8 text-slate-600">Für dein Konto sind keine Bereiche freigeschaltet.</p>
        )}
      </div>
    </div>
  );
}
