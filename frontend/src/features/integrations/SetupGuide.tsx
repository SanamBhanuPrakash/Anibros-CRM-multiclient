import { useState } from 'react';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, Typography } from '@mui/material';
import type { LeadSourceEndpoint } from '@/api/inbound';
import { CopyButton } from '@/ui/display';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/ui/toast';
import { downloadText, leadSourceHandoff } from './handoff';
import { PROVIDER_INFO } from './sourceMeta';

const mono = { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace', fontSize: 13 } as const;

function Value({ label, value, secret }: { label: string; value: string; secret?: boolean }) {
  return (
    <Box sx={{ border: 1, borderColor: 'divider', borderRadius: 2, px: 1.5, py: 1, bgcolor: 'surface.sunken' }}>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Stack direction="row" sx={{ alignItems: 'center', gap: 1 }}>
        <Typography sx={{ ...mono, wordBreak: 'break-all', flex: 1, fontWeight: secret ? 600 : 400 }}>{value}</Typography>
        <CopyButton value={value} label={`Copy ${label.toLowerCase()}`} />
      </Stack>
    </Box>
  );
}

function Code({ children, label }: { children: string; label: string }) {
  return (
    <Box sx={{ position: 'relative', border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'surface.sunken' }}>
      <Box component="pre" tabIndex={0} aria-label={label} sx={{ m: 0, p: 1.5, pr: 5, overflowX: 'auto', ...mono }}>{children}</Box>
      <Box sx={{ position: 'absolute', top: 4, right: 4 }}><CopyButton value={children} label={`Copy ${label.toLowerCase()}`} /></Box>
    </Box>
  );
}

const Step = ({ n, title, children }: { n: number; title: string; children?: React.ReactNode }) => (
  <Stack direction="row" spacing={1.5} sx={{ alignItems: 'flex-start' }}>
    <Box sx={{ width: 26, height: 26, borderRadius: '50%', bgcolor: 'primary.main', color: 'primary.contrastText', display: 'grid', placeItems: 'center', typography: 'caption', fontWeight: 700, flexShrink: 0, mt: 0.25 }}>{n}</Box>
    <Stack spacing={1} sx={{ minWidth: 0, flex: 1 }}>
      <Typography variant="subtitle2">{title}</Typography>
      {children}
    </Stack>
  </Stack>
);

function websiteSnippet(url: string): string {
  return [
    '<form id="enquiry">',
    '  <input name="name" placeholder="Your name" required>',
    '  <input name="email" type="email" placeholder="Email">',
    '  <input name="phone" type="tel" placeholder="Phone">',
    '  <textarea name="message" placeholder="How can we help?"></textarea>',
    '  <!-- bot trap: people never see or fill this -->',
    '  <input name="_hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px">',
    '  <button>Send</button>',
    '</form>',
    '<script>',
    "document.getElementById('enquiry').addEventListener('submit', async (e) => {",
    '  e.preventDefault();',
    '  const body = Object.fromEntries(new FormData(e.target));',
    "  const r = await fetch('" + url + "', {",
    "    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),",
    '  });',
    "  alert(r.ok ? 'Thank you, we will call you soon.' : 'Sorry, please try again.');",
    '});',
    '</script>',
  ].join('\n');
}

const websiteScript = (url: string) => `<script src="${url}/capture.js" async></script>`;

function chatSnippet(): string {
  return [
    "AnibrosCRM.say('visitor', 'I want a 3 BHK in Whitefield');   // call for every line of the chat",
    "AnibrosCRM.say('bot', 'Great! What is your name and phone number?');",
    "AnibrosCRM.lead({ name: 'Asha Verma', phone: '+919876543210', email: 'asha@example.com' });  // the chat is attached for you",
  ].join('\n');
}

function chatbotPayload(): string {
  return JSON.stringify(
    {
      name: 'Asha Verma',
      phone: '+919876543210',
      email: 'asha@example.com',
      message: 'I want a 3 BHK in Whitefield under 1.2 cr',
      conversation: [
        { from: 'visitor', text: 'I want a 3 BHK in Whitefield' },
        { from: 'bot', text: 'Great! What is your name and phone number?' },
      ],
    },
    null,
    2
  );
}

function genericSnippet(url: string, secret: string): string {
  return [
    'BODY=\'{"name":"Asha Verma","phone":"+919876543210","email":"asha@example.com","message":"3 BHK in Whitefield"}\'',
    'T=$(date +%s)',
    'SECRET=\'' + secret + '\'',
    'SIG=$(printf \'%s.%s\' "$T" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed \'s/^.* //\')',
    '',
    "curl -X POST '" + url + "' \\",
    "  -H 'Content-Type: application/json' \\",
    '  -H "X-Anibros-Signature: t=$T,v1=$SIG" \\',
    '  -H "X-Event-Id: your-own-unique-id-1" \\',
    '  -d "$BODY"',
  ].join('\n');
}

/** Step-by-step setup for one source. The secret is only available right after creating or rotating it. */
export function SetupGuide({ open, source, secret, onClose, onTest }: { open: boolean; source: LeadSourceEndpoint | null; secret: string | null; onClose: () => void; onTest: () => void }) {
  const [showSecret, setShowSecret] = useState(true);
  const { tenant } = useAuth();
  const toast = useToast();
  if (!source) return null;
  const handoff = () => leadSourceHandoff(source, secret, tenant?.name ?? 'the client');
  const p = source.provider;
  const isLocal = /localhost|127\.0\.0\.1/.test(source.url);
  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md" scroll="paper" aria-labelledby="guide-title">
      <DialogTitle id="guide-title">Connect {PROVIDER_INFO[p].label} — {source.name}</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2.5}>
          {isLocal && (
            <Alert severity="info">
              This address only works on this computer. {PROVIDER_INFO[p].label.split(' ')[0]} can only call a public internet address, so use <b>Send test lead</b> here for now, or a tunnel tool while testing. The deployed server gets a public address automatically.
            </Alert>
          )}
          {secret && showSecret && (
            <Alert severity="warning" action={<Button color="inherit" size="small" onClick={() => setShowSecret(false)}>I’ve saved it</Button>}>
              Copy the secret below now. For your safety we show it only once; after this we keep only its last four characters.
            </Alert>
          )}

          <Step n={1} title="Your private receiving address">
            <Value label="Address" value={source.url} />
            <Typography variant="caption" color="text.secondary">Anyone with this address can <i>try</i> to send leads, but nothing is accepted without the proof below.</Typography>
          </Step>

          {p === 'meta' && (
            <>
              <Step n={2} title="Subscribe the webhook in Meta for Developers">
                <Typography variant="body2">Your app → <b>Webhooks</b> → choose <b>Page</b> → <b>Subscribe to this object</b>. Paste the address as <b>Callback URL</b> and this as <b>Verify token</b>, then press <b>Verify and save</b>.</Typography>
                {source.verifyToken && <Value label="Verify token" value={source.verifyToken} />}
              </Step>
              <Step n={3} title="Turn on lead notifications for your Page">
                <Typography variant="body2">In the same screen, for the <b>Page</b> object subscribe to the field <b>leadgen</b>. Then subscribe your Facebook Page to the app (Graph API Explorer: <span style={mono}>POST /{'{page-id}'}/subscribed_apps?subscribed_fields=leadgen</span>).</Typography>
              </Step>
              <Step n={4} title={source.hasAccessToken ? 'Page access token — stored ✓' : 'Add a Page access token'}>
                <Typography variant="body2">{source.hasAccessToken ? 'We can read what people typed. Replace it under Settings if it expires.' : 'Meta only sends us an id. To read the answers we need a long-lived Page access token that includes the leads_retrieval, pages_show_list and pages_read_engagement permissions. Add it under Settings; deliveries wait for it and nothing is lost.'}</Typography>
              </Step>
            </>
          )}

          {p === 'google' && (
            <>
              <Step n={2} title="Paste the key into Google Ads">
                <Typography variant="body2">Google Ads → <b>Assets</b> → your <b>Lead form</b> → <b>Lead delivery option</b> → <b>Webhook integration</b>. Use the address above as <b>Webhook URL</b> and this as the <b>Key</b>, then press <b>Send test data</b>.</Typography>
                {secret ? <Value label="Key" value={secret} secret /> : <Typography variant="body2" color="text.secondary">Key ends in <b>{source.secretHint}</b>. Use <b>Rotate secret</b> if you need a new one.</Typography>}
              </Step>
            </>
          )}

          {p === 'website' && (
            <>
              <Step n={2} title="Paste one line on your website">
                <Typography variant="body2">
                  Put it just before <span style={mono}>&lt;/body&gt;</span> on every page that has a contact or enquiry form (WordPress: a “header and footer scripts” plugin; Wix, Webflow, Shopify: the custom code box).
                  {' '}{source.config.allowedOrigins?.length ? <>Only <b>{source.config.allowedOrigins.join(', ')}</b> may send leads.</> : <>Any website may send leads; under Settings you can restrict it to yours.</>}
                </Typography>
                <Code label="Website script">{websiteScript(source.url)}</Code>
                <Typography variant="body2" color="text.secondary">
                  It finds every form on the page by itself, whatever the fields are called, and sends name, phone, e-mail, the message the visitor wrote and everything else when they press submit. It never changes how your form works, skips login, payment and search forms, never reads passwords or card numbers, and drops bots. Add <span style={mono}>data-anibros="ignore"</span> to a form you do not want sent.
                </Typography>
              </Step>
              <Step n={3} title="Got a chatbot on your website?">
                <Typography variant="body2">Chatbots from another company (Tidio, Crisp, Tawk.to, Intercom…) send their leads through <b>Chatbot or another tool</b>: add that as a second source. A chatbot you built yourself can talk to the script:</Typography>
                <Code label="Own chatbot code">{chatSnippet()}</Code>
              </Step>
              <Step n={4} title="Prefer to write the form yourself? (advanced)">
                <Code label="Website form code">{websiteSnippet(source.url)}</Code>
              </Step>
            </>
          )}

          {p === 'generic' && (
            <>
              <Step n={2} title="Chatbots and no-code tools: paste the web address">
                <Typography variant="body2">In Tidio, Crisp, Tawk.to, Intercom, Zapier, Make, WPForms and similar tools, add an action <b>“Send a webhook”</b> (or “Call a URL”) that fires when a visitor leaves their details. Use this address, method <b>POST</b>:</Typography>
                <Value label="Webhook address with key" value={`${source.url}?key=${secret ?? 'YOUR_KEY'}`} secret />
                {!secret && <Typography variant="caption" color="text.secondary">The key is shown in full only once. Use <b>Rotate secret</b> to get a new one (the key ends in <b>{source.secretHint}</b> today).</Typography>}
                <Typography variant="body2" color="text.secondary">
                  Send what the visitor typed as <span style={mono}>message</span> and a chat as <span style={mono}>conversation</span>; both show up as a note on the lead. Field names are recognised however they are spelled (name, your-name, visitor.email, Mobile No.). If the tool lets you set headers, send <span style={mono}>Authorization: Bearer &lt;key&gt;</span> instead of putting the key in the address. Treat the address like a password.
                </Typography>
                <Code label="What to send">{chatbotPayload()}</Code>
              </Step>
              <Step n={3} title="Developers: send signed JSON">
                <Typography variant="body2">Sign <span style={mono}>timestamp.body</span> with HMAC-SHA256 using the secret and send it in <span style={mono}>X-Anibros-Signature: t=…,v1=…</span>. Requests older than five minutes are refused. Repeat the same <span style={mono}>X-Event-Id</span> and we process it once.</Typography>
                {secret ? <Value label="Signing secret" value={secret} secret /> : <Typography variant="body2" color="text.secondary">Secret ends in <b>{source.secretHint}</b>. Use <b>Rotate secret</b> if you need a new one.</Typography>}
                <Code label="Example request">{genericSnippet(source.url, secret ?? 'YOUR_SECRET')}</Code>
              </Step>
            </>
          )}

          <Step n={p === 'meta' ? 5 : p === 'website' ? 5 : p === 'generic' ? 4 : 3} title="Try it">
            <Typography variant="body2">Press <b>Send test lead</b>. A lead tagged <i>test</i> appears in Leads within a few seconds and the delivery shows in the log. Delete the test lead afterwards.</Typography>
            <Box><Button variant="outlined" onClick={onTest}>Send test lead</Button></Box>
          </Step>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ flexWrap: 'wrap', gap: 1 }}>
        <Button onClick={async () => { try { await navigator.clipboard.writeText(handoff()); toast.success('Instructions copied. Paste them into an e-mail or chat to the client.'); } catch { toast.error('Copying is blocked here. Use Download instead.'); } }}>Copy instructions for the client</Button>
        <Button onClick={() => downloadText(`connect-${source.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.md`, handoff())}>Download</Button>
        <Box sx={{ flex: 1 }} />
        <Button onClick={onClose} variant="contained">Done</Button>
      </DialogActions>
    </Dialog>
  );
}
