import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Alert, Button, Snackbar, type AlertColor } from '@mui/material';

export interface ToastAction {
  label: string;
  onClick: () => void;
}
interface Toast {
  id: number;
  message: string;
  severity: AlertColor;
  action?: ToastAction;
}
interface ToastApi {
  success(message: string, action?: ToastAction): void;
  error(message: string): void;
  info(message: string): void;
}
const Ctx = createContext<ToastApi | null>(null);

/** Queue of one: a new toast replaces the visible one (no stacks of stale messages). Errors stay longer. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toast, setToast] = useState<Toast | null>(null);
  const [open, setOpen] = useState(false);
  // MUI marks everything outside an open Dialog/Drawer aria-hidden, which would silence a toast fired from inside one
  // (e.g. "Merged 1 contact — Undo"). Toasts live in their own root that we keep exposed to assistive technology.
  const [root, setRoot] = useState<HTMLElement | null>(null);
  useEffect(() => {
    const el = document.createElement('div');
    el.id = 'toast-root';
    document.body.appendChild(el);
    const keepExposed = new MutationObserver(() => el.hasAttribute('aria-hidden') && el.removeAttribute('aria-hidden'));
    keepExposed.observe(el, { attributes: true, attributeFilter: ['aria-hidden'] });
    setRoot(el);
    return () => {
      keepExposed.disconnect();
      el.remove();
    };
  }, []);
  const show = useCallback((severity: AlertColor, message: string, action?: ToastAction) => {
    setToast({ id: Date.now(), severity, message, action });
    setOpen(true);
  }, []);
  const api = useMemo<ToastApi>(() => ({ success: (m, a) => show('success', m, a), error: (m) => show('error', m), info: (m) => show('info', m) }), [show]);
  return (
    <Ctx.Provider value={api}>
      {children}
      {root && createPortal(
        <Snackbar
          key={toast?.id}
          open={open}
          autoHideDuration={toast?.severity === 'error' ? 8000 : toast?.action ? 8000 : 4000}
          onClose={(_, reason) => reason !== 'clickaway' && setOpen(false)}
          anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
        >
          {toast ? (
            <Alert severity={toast.severity} variant="filled" onClose={() => setOpen(false)}
              action={toast.action && <Button color="inherit" size="small" onClick={() => { toast.action?.onClick(); setOpen(false); }}>{toast.action.label}</Button>}
              sx={{ minWidth: 280, alignItems: 'center' }} role={toast.severity === 'error' ? 'alert' : 'status'}>
              {toast.message}
            </Alert>
          ) : undefined}
        </Snackbar>,
        root)}
    </Ctx.Provider>
  );
}

export function useToast(): ToastApi {
  const v = useContext(Ctx);
  if (!v) throw new Error('useToast must be used inside <ToastProvider>');
  return v;
}
