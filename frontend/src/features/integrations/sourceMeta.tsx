import FacebookRoundedIcon from '@mui/icons-material/FacebookRounded';
import AdsClickRoundedIcon from '@mui/icons-material/AdsClickRounded';
import LanguageRoundedIcon from '@mui/icons-material/LanguageRounded';
import WebhookRoundedIcon from '@mui/icons-material/WebhookRounded';
import { Chip } from '@mui/material';
import type { ReactElement } from 'react';
import type { DeliveryStatus, Provider } from '@/api/inbound';

export const PROVIDER_INFO: Record<Provider, { label: string; blurb: string; icon: ReactElement }> = {
  meta: { label: 'Meta Lead Ads', blurb: 'Facebook and Instagram lead forms. Every submission lands in Leads with its campaign, ad set and ad.', icon: <FacebookRoundedIcon /> },
  google: { label: 'Google Ads lead forms', blurb: 'Lead form assets on Search, YouTube and Display. Arrives with the campaign and click id.', icon: <AdsClickRoundedIcon /> },
  website: { label: 'Website form', blurb: 'Paste one line on your site: every contact or enquiry form on it becomes a lead source, whatever its fields. Keeps the visitor’s message.', icon: <LanguageRoundedIcon /> },
  generic: { label: 'Chatbot or another tool', blurb: 'Tidio, Crisp, Tawk.to, Zapier, Make, WPForms or your own software: send leads and chat messages to a private address.', icon: <WebhookRoundedIcon /> },
};

export const DELIVERY_LABEL: Record<DeliveryStatus, string> = {
  received: 'Waiting',
  created: 'Lead created',
  duplicate: 'Added to existing lead',
  needs_review: 'Needs attention',
  ignored: 'Ignored',
  failed: 'Failed',
};
const COLOR: Record<DeliveryStatus, 'default' | 'success' | 'warning' | 'error' | 'info'> = { received: 'info', created: 'success', duplicate: 'default', needs_review: 'warning', ignored: 'default', failed: 'error' };

export function DeliveryChip({ status }: { status: DeliveryStatus }) {
  return <Chip size="small" label={DELIVERY_LABEL[status]} color={COLOR[status]} variant={status === 'created' || status === 'duplicate' || status === 'ignored' ? 'outlined' : 'filled'} />;
}
