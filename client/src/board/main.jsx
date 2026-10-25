import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './board.css';
import DispatchBoard from './DispatchBoard.jsx';
import { readConfig } from '../shared/api.js';

const root = document.getElementById('dispatch-app-root');
if (root) {
  createRoot(root).render(
    <StrictMode>
      <DispatchBoard config={readConfig(root)} />
    </StrictMode>,
  );
}
