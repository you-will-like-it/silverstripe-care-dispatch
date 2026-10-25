import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

/**
 * Minimal history-API router. Paths are relative to config.appBase (/pflege).
 * Patterns: "/patienten/:id".
 */
const RouterContext = createContext(null);

export function RouterProvider({ base, children }) {
  const normalizedBase = base.replace(/\/$/, '');
  const read = useCallback(() => {
    const path = window.location.pathname;
    const rel = path.startsWith(normalizedBase) ? path.slice(normalizedBase.length) : path;
    return { path: rel === '' ? '/' : rel, search: new URLSearchParams(window.location.search) };
  }, [normalizedBase]);
  const [location, setLocation] = useState(read);

  useEffect(() => {
    const onPop = () => setLocation(read());
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [read]);

  const navigate = useCallback((to, { replace = false } = {}) => {
    const url = normalizedBase + (to.startsWith('/') ? to : `/${to}`);
    if (replace) window.history.replaceState(null, '', url);
    else window.history.pushState(null, '', url);
    setLocation(read());
    window.scrollTo(0, 0);
  }, [normalizedBase, read]);

  const value = useMemo(() => ({ ...location, navigate, base: normalizedBase }), [location, navigate, normalizedBase]);
  return <RouterContext.Provider value={value}>{children}</RouterContext.Provider>;
}

export function useRouter() {
  return useContext(RouterContext);
}

/** Returns params if `pattern` matches `path`, else null. */
export function matchPath(pattern, path) {
  const p = pattern.split('/').filter(Boolean);
  const s = path.split('/').filter(Boolean);
  if (p.length !== s.length) return null;
  const params = {};
  for (let i = 0; i < p.length; i++) {
    if (p[i].startsWith(':')) params[p[i].slice(1)] = decodeURIComponent(s[i]);
    else if (p[i] !== s[i]) return null;
  }
  return params;
}

export function Link({ to, className, children, ...rest }) {
  const { navigate, base } = useRouter();
  return (
    <a
      href={base + to}
      className={className}
      onClick={(e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        navigate(to);
      }}
      {...rest}
    >
      {children}
    </a>
  );
}
