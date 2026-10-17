import { api, unwrap } from './client';

/** A buyer's shortlist, offers, reservations and the recorded sale (v1 API). */
export type InterestStatus = 'shortlisted' | 'interested' | 'rejected';
export type InterestSource = 'manual' | 'matching' | 'viewing' | 'offer';
export type ReservationStatus = 'offered' | 'reserved' | 'completed' | 'closed';

export interface Interest {
  id: string;
  leadId: string;
  propertyId: string;
  status: InterestStatus;
  source: InterestSource;
  matchScore: number | null;
  note: string | null;
  createdAt: string;
  property: { id: string; title: string; status: string; price: number; currency: string; location: string | null; bedrooms: number | null; propertyType: string; archived: boolean };
  deal: { id: string; status: 'offered' | 'reserved' } | null;
}

export interface Reservation {
  id: string;
  leadId: string;
  propertyId: string;
  status: ReservationStatus;
  offerPrice: number | null;
  agreedPrice: number | null;
  tokenAmount: number | null;
  dealValue: number | null;
  currency: string;
  holdUntil: string | null;
  holdOverdue: boolean;
  note: string | null;
  closedReason: { code: string; label: string } | null;
  closedNote: string | null;
  offeredAt: string;
  reservedAt: string | null;
  completedAt: string | null;
  closedAt: string | null;
  version: number;
  createdAt: string;
  lead: { id: string; name: string; ownerId: string | null; ownerName: string | null };
  property: { id: string; title: string; status: string; price: number; listingType: string };
  allowed: Array<'reserve' | 'complete' | 'close' | 'edit' | 'reverse'>;
}
export interface ReservationResult {
  reservation: Reservation;
  leadStage: { moved: boolean; stageName: string | null; note: string | null };
}
export interface Holder {
  propertyId: string;
  status: string;
  openOffers: number;
  holder: null | {
    reservationId: string | null;
    leadId: string | null;
    buyer: string | null;
    agent: string | null;
    reservedAt: string | null;
    holdUntil: string | null;
    holdOverdue: boolean;
    tokenAmount: number | null;
    agreedPrice: number | null;
    currency: string;
    visible: boolean;
  };
}
export interface CloseReason {
  code: string;
  label: string;
}

const clean = (o: Record<string, unknown>) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== ''));
const ifMatch = (version: number) => ({ headers: { 'If-Match': `"${version}"` } });

export const shortlistApi = {
  list: (leadId: string) => unwrap(api.get<Interest[]>('/api/v1/shortlist', { query: { leadId } })),
  add: (input: { leadId: string; propertyId: string; source?: InterestSource; matchScore?: number | null; note?: string | null }) => unwrap(api.post<Interest>('/api/v1/shortlist', input)),
  update: (id: string, patch: { status?: InterestStatus; note?: string | null }) => unwrap(api.patch<Interest>(`/api/v1/shortlist/${id}`, patch)),
  remove: (id: string) => unwrap(api.delete(`/api/v1/shortlist/${id}`)),
};

export const dealsApi = {
  list: (f: { leadId?: string; propertyId?: string; status?: string; limit?: number; completedFrom?: string; completedTo?: string; test?: 'exclude' }, cursor?: string) => api.get<Reservation[]>('/api/v1/reservations', { query: { limit: 50, ...clean(f), cursor } }),
  get: (id: string) => unwrap(api.get<Reservation>(`/api/v1/reservations/${id}`)),
  holder: (propertyId: string) => unwrap(api.get<Holder>(`/api/v1/reservations/by-property/${propertyId}`)),
  closeReasons: () => unwrap(api.get<CloseReason[]>('/api/v1/reservations/close-reasons')),
  offer: (input: { leadId: string; propertyId: string; offerPrice?: number | null; holdUntil?: string | null; note?: string | null }) => unwrap(api.post<ReservationResult>('/api/v1/reservations', input)),
  update: (id: string, version: number, patch: { offerPrice?: number | null; agreedPrice?: number | null; holdUntil?: string | null; note?: string | null }) => unwrap(api.patch<Reservation>(`/api/v1/reservations/${id}`, patch, ifMatch(version))),
  reserve: (id: string, version: number, input: { tokenAmount: number; agreedPrice: number; holdUntil?: string | null; note?: string | null }) => unwrap(api.post<ReservationResult>(`/api/v1/reservations/${id}/reserve`, input, ifMatch(version))),
  complete: (id: string, version: number, input: { dealValue: number }) => unwrap(api.post<ReservationResult>(`/api/v1/reservations/${id}/complete`, input, ifMatch(version))),
  close: (id: string, version: number, input: { reason: string; note?: string | null }) => unwrap(api.post<ReservationResult>(`/api/v1/reservations/${id}/close`, input, ifMatch(version))),
};
