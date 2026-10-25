import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './app.css';
import App from './App.jsx';
import { readConfig } from '../shared/api.js';

const root = document.getElementById('dispatch-app-root');
if (root) {
  createRoot(root).render(
    <StrictMode>
      <App config={readConfig(root)} />
    </StrictMode>,
  );
}
