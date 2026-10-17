import { Dialog, DialogContent, DialogTitle, Typography } from '@mui/material';
import { ActivityForm, type LogTarget, type LogType } from './ActivityForm';

export type { LogType };

/** The log-activity form in a pop-up: for leads shown in a list (All leads, "Needs you today"). */
export function LogActivityDialog({ open, onClose, clientId, lead, initialType = 'call', onLogged }: { open: boolean; onClose: () => void; clientId: string; lead: LogTarget | null; initialType?: LogType; onLogged?: () => void }) {
  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm" aria-labelledby="log-activity-title">
      <DialogTitle id="log-activity-title" sx={{ pb: 0.5 }}>
        Log activity
        {lead?.name && <Typography component="div" variant="body2" color="text.secondary">{lead.name}</Typography>}
      </DialogTitle>
      <DialogContent sx={{ pt: '12px !important' }}>
        {lead && (
          <ActivityForm
            clientId={clientId}
            lead={lead}
            initialType={initialType}
            autoFocus
            onCancel={onClose}
            onDone={() => {
              onLogged?.();
              onClose();
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}
