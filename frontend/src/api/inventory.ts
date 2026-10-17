import { api, unwrap } from './client';

/** Properties, projects and files (v1 API). */
export type PropertyStatus = 'available' | 'under_offer' | 'reserved' | 'sold' | 'rented' | 'inactive';
export type PropertyType = 'apartment' | 'villa' | 'townhouse' | 'penthouse' | 'office' | 'retail' | 'plot' | 'other';
export type ListingType = 'sale' | 'rent' | 'lease';
export type Furnishing = 'unfurnished' | 'semi_furnished' | 'furnished';
export type ProjectStatus = 'upcoming' | 'active' | 'on_hold' | 'completed';

export interface Property {
  id: string;
  projectId: string | null;
  projectName: string | null;
  title: string;
  description: string | null;
  propertyType: PropertyType;
  listingType: ListingType;
  status: PropertyStatus;
  price: number;
  currency: string;
  pricePerSqft: number | null;
  negotiable: boolean;
  bedrooms: number | null;
  bathrooms: number | null;
  areaSqft: number | null;
  carpetAreaSqft: number | null;
  unitNumber: string | null;
  tower: string | null;
  floor: number | null;
  facing: string | null;
  furnishing: Furnishing | null;
  address: string | null;
  city: string | null;
  locality: string | null;
  location: string | null;
  possessionDate: string | null;
  reraId: string | null;
  amenities: string[];
  tags: string[];
  ownerId: string | null;
  ownerName: string | null;
  coverFileId: string | null;
  imageCount: number;
  statusChangedAt: string;
  version: number;
  createdAt: string;
  updatedAt: string;
}
export interface FileItem {
  id: string;
  kind: 'image' | 'document';
  name: string;
  mime: string;
  sizeBytes: number;
  sortOrder: number;
  createdAt: string;
}
export interface PropertyDetail extends Property {
  images: FileItem[];
  documents: FileItem[];
  priceHistory: Array<{ price: number; currency: string; at: string; changedBy: string | null }>;
  statusHistory: Array<{ from: PropertyStatus | null; to: PropertyStatus; reason: string | null; at: string; changedBy: string | null }>;
  allowedStatuses: PropertyStatus[];
  /** Why the status buttons are missing: a deal owns the status right now. */
  statusLock: string | null;
}
export interface PropertyInput {
  projectId?: string | null;
  title: string;
  description?: string | null;
  propertyType?: PropertyType;
  listingType?: ListingType;
  price: number;
  negotiable?: boolean;
  bedrooms?: number | null;
  bathrooms?: number | null;
  areaSqft?: number | null;
  carpetAreaSqft?: number | null;
  unitNumber?: string | null;
  tower?: string | null;
  floor?: number | null;
  facing?: string | null;
  furnishing?: Furnishing | null;
  address?: string | null;
  city?: string | null;
  locality?: string | null;
  possessionDate?: string | null;
  reraId?: string | null;
  amenities?: string[];
  tags?: string[];
  ownerId?: string | null;
}
export interface PropertyFilters {
  q?: string;
  projectId?: string;
  status?: string;
  propertyType?: PropertyType;
  listingType?: ListingType;
  bedroomsMin?: number;
  priceMin?: number;
  priceMax?: number;
  city?: string;
  sort?: 'created' | 'updated' | 'price' | 'area';
  order?: 'asc' | 'desc';
}
export interface PropertySummary {
  total: number;
  byStatus: Record<PropertyStatus, number>;
  byType: Array<{ type: string; n: number }>;
  available: { count: number; value: number; avgPricePerSqft: number | null };
}

export interface Project {
  id: string;
  name: string;
  description: string | null;
  developer: string | null;
  city: string | null;
  locality: string | null;
  address: string | null;
  reraId: string | null;
  possessionDate: string | null;
  amenities: string[];
  status: ProjectStatus;
  units: { total: number; available: number; held: number; closed: number; inactive: number; minPrice: number | null; maxPrice: number | null; availableValue: number };
  version: number;
  createdAt: string;
  updatedAt: string;
}
export interface ProjectInput {
  name: string;
  description?: string | null;
  developer?: string | null;
  city?: string | null;
  locality?: string | null;
  address?: string | null;
  reraId?: string | null;
  possessionDate?: string | null;
  amenities?: string[];
  status?: ProjectStatus;
}

const clean = <T extends object>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '')) as T;

export const fileUrl = (id: string) => `/api/v1/files/${id}`;

export const propertiesApi = {
  list: (f: PropertyFilters, cursor?: string) => api.get<Property[]>('/api/v1/properties', { query: { limit: 24, ...clean(f), cursor } }),
  summary: () => unwrap(api.get<PropertySummary>('/api/v1/properties/summary')),
  get: (id: string) => unwrap(api.get<PropertyDetail>(`/api/v1/properties/${id}`)),
  create: (input: PropertyInput) => unwrap(api.post<Property>('/api/v1/properties', input)),
  update: (id: string, version: number, patch: Partial<PropertyInput>) => unwrap(api.patch<Property>(`/api/v1/properties/${id}`, patch, { headers: { 'If-Match': `"${version}"` } })),
  changeStatus: (id: string, status: PropertyStatus, reason?: string) => unwrap(api.post<Property>(`/api/v1/properties/${id}/status`, { status, reason })),
  archive: (id: string) => unwrap(api.delete(`/api/v1/properties/${id}`)),
  restore: (id: string) => unwrap(api.post<Property>(`/api/v1/properties/${id}/restore`)),
  uploadFile: (id: string, file: File) => unwrap(api.upload<FileItem>(`/api/v1/properties/${id}/files`, file, { name: file.name })),
  setCover: (id: string, fileId: string | null) => unwrap(api.put<{ coverFileId: string | null }>(`/api/v1/properties/${id}/cover`, { fileId })),
};

export const projectsApi = {
  list: (f: { q?: string; status?: ProjectStatus; sort?: 'name' | 'created' } = {}, cursor?: string) => api.get<Project[]>('/api/v1/projects', { query: { limit: 50, ...clean(f), cursor } }),
  get: (id: string) => unwrap(api.get<Project>(`/api/v1/projects/${id}`)),
  create: (input: ProjectInput) => unwrap(api.post<Project>('/api/v1/projects', input)),
  update: (id: string, version: number, patch: Partial<ProjectInput>) => unwrap(api.patch<Project>(`/api/v1/projects/${id}`, patch, { headers: { 'If-Match': `"${version}"` } })),
  archive: (id: string) => unwrap(api.delete(`/api/v1/projects/${id}`)),
  files: (id: string) => unwrap(api.get<FileItem[]>(`/api/v1/projects/${id}/files`)),
  uploadFile: (id: string, file: File) => unwrap(api.upload<FileItem>(`/api/v1/projects/${id}/files`, file, { name: file.name })),
};

export const filesApi = {
  remove: (id: string) => unwrap(api.delete(`/api/v1/files/${id}`)),
};
