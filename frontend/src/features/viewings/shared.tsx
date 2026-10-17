import { Chip } from '@mui/material';
import { format, isSameDay } from 'date-fns';
import type { Viewing, ViewingStatus } from '@/api/viewings';

export const VISIT_STATUS_LABEL: Record<ViewingStatus, string> = { scheduled: 'Booked', confirmed: 'Confirmed', completed: 'Done', cancelled: 'Cancelled', no_show: 'No-show' };
const COLOR: Record<ViewingStatus, 'default' | 'primary' | 'success' | 'warning' | 'error'> = { scheduled: 'primary', confirmed: 'primary', completed: 'success', cancelled: 'default', no_show: 'warning' };

export function VisitStatusChip({ status }: { status: ViewingStatus }) {
  return <Chip size="small" label={VISIT_STATUS_LABEL[status]} color={COLOR[status]} variant={status === 'scheduled' || status === 'confirmed' ? 'filled' : 'outlined'} />;
}

export const isOpenVisit = (v: Pick<Viewing, 'status'>) => v.status === 'scheduled' || v.status === 'confirmed';
export const buyerOf = (v: Pick<Viewing, 'leadContact'>) => [v.leadContact.firstName, v.leadContact.lastName].filter(Boolean).join(' ') || 'Buyer';

/** "Tue 14 Oct, 16:30" (or "Today, 16:30"), in the viewer's own time zone. */
export function whenOf(iso: string, now = new Date()): string {
  const d = new Date(iso);
  return isSameDay(d, now) ? `Today, ${format(d, 'HH:mm')}` : format(d, 'EEE d MMM, HH:mm');
}

/** `<input type="datetime-local">` speaks local time without an offset; the API wants an instant. */
export const toLocalInput = (iso: string | Date): string => format(new Date(iso), "yyyy-MM-dd'T'HH:mm");
export const fromLocalInput = (v: string): string => new Date(v).toISOString();

export const DURATIONS = [15, 30, 45, 60, 90, 120];

/** Has this open visit already ended without being recorded? */
export const visitEnded = (v: Pick<Viewing, 'scheduledAt' | 'durationMinutes'>, now = Date.now()) => new Date(v.scheduledAt).getTime() + v.durationMinutes * 60_000 < now;
