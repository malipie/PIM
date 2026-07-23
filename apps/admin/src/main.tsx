import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource/inter/300.css';
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
import '@fontsource/inter/800.css';
import '@fontsource/jetbrains-mono/400.css';
import '@fontsource/jetbrains-mono/500.css';
import './index.css';
import './lib/i18n';
import App from './App.tsx';

// AUD-049 (W2-12) — a React error boundary only catches render/lifecycle
// errors; an unhandled promise rejection (failed fetch, bad await) never
// reaches it. Log it globally so a rejected promise leaves a DevTools
// breadcrumb instead of a silent failure, and prevent the default
// "Uncaught (in promise)" noise from masking the real cause.
window.addEventListener('unhandledrejection', (event) => {
  console.error('[unhandledrejection] Unhandled promise rejection:', event.reason);
});

const rootElement = document.getElementById('root');
if (!rootElement) {
  throw new Error('Failed to mount admin: #root element not found in index.html');
}

createRoot(rootElement).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
