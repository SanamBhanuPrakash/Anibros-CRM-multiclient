import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
// Self-hosted Google Fonts (variable): no runtime requests to Google, works under a strict CSP and offline.
import '@fontsource-variable/geist';
import '@fontsource-variable/geist-mono';
import App from './App.tsx';
import './index.css';
import { initGlobalErrorTelemetry } from './lib/telemetry';

initGlobalErrorTelemetry();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>
);
