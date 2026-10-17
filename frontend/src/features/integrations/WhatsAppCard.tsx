import { useState } from 'react';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, TextField, Typography } from '@mui/material';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { messagingApi } from '@/api/outbound';
import { Card, Pill, When } from '@/ui/kit';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';

/** This client's own WhatsApp Business number, so messages can be sent from a lead without leaving the CRM. */
export function WhatsAppCard({ canManage }: { canManage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [open, setOpen] = useState(false);
  const [phoneNumberId, setId] = useState('');
  const [accessToken, setToken] = useState('');
  const [error, setError] = useState<string | null>(null);
  const q = useQuery({ queryKey: ['messaging', 'whatsapp'], queryFn: () => messagingApi.whatsapp(), retry: false });
  const connect = useMutation({
    mutationFn: () => messagingApi.connectWhatsapp({ phoneNumberId: phoneNumberId.trim(), accessToken: accessToken.trim() }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['messaging'] }); setOpen(false); setId(''); setToken(''); toast.success('WhatsApp is connected. You can now message leads from their page.'); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'That didn’t work. Please try again.'),
  });
  const disconnect = useMutation({
    mutationFn: messagingApi.disconnectWhatsapp,
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['messaging'] }); toast.success('WhatsApp is disconnected'); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work'),
  });
  const a = q.data;
  if (q.isError) return null; // not allowed to see it: nothing to show
  return (
    <Card
      title={<Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}><WhatsAppIcon sx={{ color: '#25B866' }} fontSize="small" /><span>WhatsApp Business number</span></Stack>}
      subtitle="Send WhatsApp messages to leads from inside the CRM, from this client’s own number"
      actions={canManage && (a?.connected ? (
        <Stack direction="row" spacing={1}>
          <Button size="small" color="inherit" onClick={() => setOpen(true)}>Replace token</Button>
          <Button size="small" color="error" onClick={async () => { if (await confirm({ title: 'Disconnect WhatsApp?', body: 'Messages can no longer be sent from the CRM for this client. Earlier messages stay on the leads.', confirmLabel: 'Disconnect', destructive: true })) disconnect.mutate(); }}>Disconnect</Button>
        </Stack>
      ) : <Button size="small" variant="outlined" onClick={() => setOpen(true)}>Connect number</Button>)}
    >
      {!a ? null : a.connected ? (
        <Stack direction="row" spacing={3} sx={{ flexWrap: 'wrap', rowGap: 1 }}>
          <Box><Typography variant="caption" color="text.secondary" component="div">Number</Typography><Typography variant="subtitle2">{a.displayPhone ?? a.phoneNumberId}</Typography></Box>
          <Box><Typography variant="caption" color="text.secondary" component="div">Status</Typography>{a.lastError ? <Pill tone="warning" title={a.lastError}>Needs attention</Pill> : <Pill tone="success">Connected</Pill>}</Box>
          <Box><Typography variant="caption" color="text.secondary" component="div">Last message sent</Typography><Typography variant="body2"><When value={a.lastSentAt} fallback="None yet" /></Typography></Box>
          <Box><Typography variant="caption" color="text.secondary" component="div">Token</Typography><Typography variant="mono">••••{a.tokenHint}</Typography></Box>
          {a.lastError && <Typography variant="caption" sx={{ color: 'warning.dark', width: '100%' }}>Last problem: {a.lastError}</Typography>}
        </Stack>
      ) : (
        <Typography variant="body2" color="text.secondary">Not connected. Until it is, the WhatsApp button on a lead opens WhatsApp on your own phone or computer instead. You need the client’s WhatsApp Business (Cloud API) phone number id and a permanent access token from Meta’s WhatsApp setup page.</Typography>
      )}
      <Dialog open={open} onClose={() => setOpen(false)} maxWidth="sm" aria-labelledby="wa-title">
        <DialogTitle id="wa-title">Connect a WhatsApp Business number</DialogTitle>
        <DialogContent>
          <Stack spacing={2.25}>
            {error && <Alert severity="error">{error}</Alert>}
            <Typography variant="body2" color="text.secondary">In Meta for Developers, open your app’s WhatsApp → API setup page. Copy the <b>Phone number ID</b> and a <b>permanent access token</b> (from a System User, not the 24-hour test token). We check both with Meta before saving.</Typography>
            <TextField label="Phone number ID" value={phoneNumberId} onChange={(e) => setId(e.target.value)} placeholder="e.g. 109876543210987" slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
            <TextField label="Permanent access token" type="password" autoComplete="off" value={accessToken} onChange={(e) => setToken(e.target.value)} helperText="Stored encrypted. It is never shown again." />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button variant="contained" disabled={!phoneNumberId.trim() || accessToken.trim().length < 20 || connect.isPending} onClick={() => (setError(null), connect.mutate())}>{connect.isPending ? 'Checking with Meta…' : 'Connect'}</Button>
        </DialogActions>
      </Dialog>
    </Card>
  );
}
