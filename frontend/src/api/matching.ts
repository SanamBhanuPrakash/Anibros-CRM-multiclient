import { api, unwrap } from './client';

/** Property matching (v1 API): rule-based and explainable. Every score lists the reasons behind it; this is not AI. */
export interface MatchCriteria {
  budgetMin?: number;
  budgetMax?: number;
  location?: string;
  propertyType?: string;
  bedrooms?: number;
  listingType?: string;
}
export interface Match {
  property: {
    id: string;
    title: string;
    description: string | null;
    propertyType: string;
    listingType: string;
    status: string;
    price: number;
    currency: string;
    bedrooms: number | null;
    bathrooms: number | null;
    areaSqft: number | null;
    location: string | null;
  };
  score: number;
  matchReasons: string[];
}

export const matchingApi = {
  match: (criteria: MatchCriteria) => unwrap(api.post<{ matches: Match[] }>('/api/v1/matching/properties', criteria)).then((r) => r.matches),
};
