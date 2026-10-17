import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react';
import { Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle } from '@mui/material';

interface ConfirmOptions {
  title: string;
  body?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Destructive actions are red and never the autofocus default. */
  destructive?: boolean;
}
type Confirm = (opts: ConfirmOptions) => Promise<boolean>;
const Ctx = createContext<Confirm | null>(null);

/** Promise-based confirmation: `if (await confirm({ title: 'Remove Sam?' })) …` — one accessible dialog for the whole app. */
export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [opts, setOpts] = useState<ConfirmOptions | null>(null);
  const resolver = useRef<(v: boolean) => void>();
  const confirm = useCallback<Confirm>((o) => new Promise<boolean>((resolve) => {
    resolver.current = resolve;
    setOpts(o);
  }), []);
  const close = (v: boolean) => {
    resolver.current?.(v);
    setOpts(null);
  };
  return (
    <Ctx.Provider value={confirm}>
      {children}
      <Dialog open={Boolean(opts)} onClose={() => close(false)} maxWidth="xs" aria-labelledby="confirm-title">
        <DialogTitle id="confirm-title">{opts?.title}</DialogTitle>
        {opts?.body && (
          <DialogContent>
            <DialogContentText component="div">{opts.body}</DialogContentText>
          </DialogContent>
        )}
        <DialogActions>
          <Button onClick={() => close(false)} autoFocus={opts?.destructive}>{opts?.cancelLabel ?? 'Cancel'}</Button>
          <Button variant="contained" color={opts?.destructive ? 'error' : 'primary'} onClick={() => close(true)} autoFocus={!opts?.destructive}>
            {opts?.confirmLabel ?? 'Confirm'}
          </Button>
        </DialogActions>
      </Dialog>
    </Ctx.Provider>
  );
}

export function useConfirm(): Confirm {
  const v = useContext(Ctx);
  if (!v) throw new Error('useConfirm must be used inside <ConfirmProvider>');
  return v;
}
