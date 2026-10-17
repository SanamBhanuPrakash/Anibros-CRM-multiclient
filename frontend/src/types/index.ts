export type OrganisationType = 'platform_owner' | 'agency' | 'client_business';
export type TenantStatus = 'active' | 'suspended' | 'offboarded';
export type MembershipStatus = 'active' | 'invited' | 'revoked';
export type OrgRole = 'platform_owner' | 'platform_admin' | 'agency_owner' | 'agency_admin';
export type TenantRole =
  | 'tenant_owner'
  | 'tenant_admin'
  | 'sales_manager'
  | 'sales_agent'
  | 'marketing_manager'
  | 'marketing_analyst'
  | 'executive_readonly'
  | 'auditor';

export interface Organisation {
  id: string;
  name: string;
  type: OrganisationType;
  parent_organisation_id: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface Tenant {
  id: string;
  organisation_id: string;
  name: string;
  slug: string;
  status: TenantStatus;
  settings: Record<string, unknown>;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface Branch {
  id: string;
  tenant_id: string;
  name: string;
  location: string | null;
  created_at: string;
  updated_at: string;
}

export interface OrganisationMembership {
  id: string;
  user_id: string;
  organisation_id: string;
  role: OrgRole;
  status: MembershipStatus;
  created_at: string;
  updated_at: string;
}

export interface User {
  id: string;
  email?: string | null;
  full_name?: string | null;
  phone?: string | null;
  role?: string;
  created_at?: string;
  user_metadata?: Record<string, any>;
  app_metadata?: Record<string, any>;
}

export interface TenantMembership {
  id: string;
  user_id: string;
  tenant_id: string;
  role: TenantRole;
  branch_id: string | null;
  status: MembershipStatus;
  created_at: string;
  updated_at: string;
}

export type LeadSource =
  | 'google_ads' | 'meta_lead_ads' | 'website_form' | 'manual'
  | 'csv_import' | 'whatsapp' | 'referral' | 'other';

export type QualificationStatus = 'unqualified' | 'pending' | 'qualified' | 'disqualified';
export type TransactionIntent = 'buy' | 'rent' | 'lease' | 'invest';
export type ExpectedTimeline = 'immediate' | '1_month' | '3_months' | '6_months' | '12_months' | 'exploring';
export type FinancingStatus = 'pre_approved' | 'arranging' | 'self_funded' | 'not_discussed';
export type NurtureStatus = 'none' | 'active_nurture' | 'paused' | 'completed';

export interface Contact {
  id: string;
  tenant_id: string;
  first_name: string;
  last_name: string | null;
  email: string | null;
  email_normalized: string | null;
  phone: string | null;
  phone_normalized: string | null;
  communication_consent: Record<string, unknown>;
  contact_preferences: Record<string, unknown>;
  source_summary: Record<string, unknown>;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface Pipeline {
  id: string;
  tenant_id: string;
  name: string;
  is_default: boolean;
  sort_order: number;
  created_at: string;
  updated_at: string;
}

export type StageType = 'active' | 'won' | 'lost' | 'terminal';

export interface PipelineStage {
  id: string;
  tenant_id: string;
  pipeline_id: string;
  name: string;
  stage_key: string;
  stage_type: StageType;
  sort_order: number;
  is_won_stage: boolean;
  is_lost_stage: boolean;
  requires_loss_reason: boolean;
  color: string;
  created_at: string;
  updated_at: string;
}

export interface Lead {
  id: string;
  tenant_id: string;
  contact_id: string;
  pipeline_id: string;
  pipeline_stage_id: string | null;
  stage: string;
  lead_source: LeadSource;
  integration_id: string | null;
  provider_lead_id: string | null;
  campaign_id: string | null;
  ad_group_id: string | null;
  ad_id: string | null;
  form_id: string | null;
  landing_page_url: string | null;
  gclid: string | null;
  gbraid: string | null;
  wbraid: string | null;
  fbclid: string | null;
  utm_source: string | null;
  utm_medium: string | null;
  utm_campaign: string | null;
  utm_term: string | null;
  utm_content: string | null;
  assigned_to: string | null;
  assigned_at: string | null;
  qualification_status: QualificationStatus | null;
  qualification_notes: string | null;
  budget_min: number | null;
  budget_max: number | null;
  desired_location: string | null;
  property_type_preference: string[] | null;
  bedroom_preference: number[] | null;
  transaction_intent: TransactionIntent | null;
  expected_timeline: ExpectedTimeline | null;
  financing_status: FinancingStatus | null;
  loss_reason: string | null;
  nurture_status: NurtureStatus;
  lead_score: number;
  response_sla_due_at: string | null;
  first_contacted_at: string | null;
  last_activity_at: string | null;
  title: string | null;
  description: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface LeadWithContact extends Lead {
  contact?: Contact;
  assigned_user?: { id: string; email: string } | null;
}

export type PropertyTypeEnum =
  | 'apartment' | 'villa' | 'plot' | 'independent_house'
  | 'commercial' | 'shop' | 'office' | 'land';
export type TransactionTypeEnum = 'sale' | 'rent' | 'lease';
export type ListingTypeEnum = 'new_booking' | 'resale' | 'rental';
export type AvailabilityStatus =
  | 'available' | 'reserved' | 'soft_hold' | 'under_negotiation'
  | 'booked' | 'sold' | 'off_market';
export type FurnishingType = 'unfurnished' | 'semi_furnished' | 'fully_furnished';

export interface Property {
  id: string;
  tenant_id: string;
  project_id: string | null;
  name: string;
  property_type: PropertyTypeEnum;
  transaction_type: TransactionTypeEnum;
  listing_type: ListingTypeEnum;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  state: string | null;
  postal_code: string | null;
  locality: string | null;
  latitude: number | null;
  longitude: number | null;
  bedrooms: number | null;
  bathrooms: number | null;
  built_up_area_sqft: number | null;
  carpet_area_sqft: number | null;
  floor_number: number | null;
  total_floors: number | null;
  facing: string | null;
  furnishing: FurnishingType | null;
  amenities: string[];
  price: number | null;
  currency: string;
  price_per_sqft: number | null;
  availability_status: AvailabilityStatus;
  availability_expires_at: string | null;
  owner_name: string | null;
  commission_percentage: number | null;
  notes: string | null;
  images: Array<{ url: string; caption?: string; sort_order?: number; is_primary?: boolean }>;
  documents: Array<{ url: string; type?: string; name?: string }>;
  status: string;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface Project {
  id: string;
  tenant_id: string;
  name: string;
  developer: string | null;
  location: string | null;
  rera_number: string | null;
  status: 'planned' | 'under_construction' | 'ready' | 'completed';
  created_at: string;
  updated_at: string;
}

export type ActivityType =
  | 'call' | 'email' | 'whatsapp' | 'meeting' | 'viewing'
  | 'note' | 'system' | 'stage_change' | 'assignment_change';

export interface Activity {
  id: string;
  tenant_id: string;
  lead_id: string | null;
  contact_id: string | null;
  type: ActivityType;
  direction: 'inbound' | 'outbound' | 'internal' | null;
  subject: string | null;
  content: string | null;
  performed_by: string | null;
  performed_at: string;
  metadata: Record<string, unknown>;
  is_internal: boolean;
  created_at: string;
}

export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';
export type TaskStatus = 'open' | 'in_progress' | 'completed' | 'cancelled' | 'overdue';

export interface Task {
  id: string;
  tenant_id: string;
  lead_id: string | null;
  assigned_to: string;
  title: string;
  description: string | null;
  due_date: string | null;
  due_time: string | null;
  priority: TaskPriority;
  status: TaskStatus;
  completed_at: string | null;
  created_by: string | null;
  created_at: string;
  updated_at: string;
}

export type ViewingStatus = 'scheduled' | 'confirmed' | 'completed' | 'no_show' | 'cancelled' | 'rescheduled';
export type ViewingOutcome = 'interested' | 'not_interested' | 'undecided' | 'no_feedback';

export interface Viewing {
  id: string;
  tenant_id: string;
  lead_id: string;
  property_id: string | null;
  assigned_to: string;
  scheduled_date: string;
  scheduled_time: string;
  duration_minutes: number;
  timezone: string;
  status: ViewingStatus;
  notes: string | null;
  feedback: string | null;
  outcome: ViewingOutcome | null;
  cancelled_reason: string | null;
  cancelled_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface AuditLog {
  id: string;
  tenant_id: string | null;
  user_id: string | null;
  action: string;
  entity_type: string;
  entity_id: string | null;
  old_value: Record<string, unknown> | null;
  new_value: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
}

export interface LeadStageHistory {
  id: string;
  tenant_id: string;
  lead_id: string;
  from_stage: string | null;
  to_stage: string;
  changed_by: string | null;
  changed_at: string;
  reason: string | null;
  metadata: Record<string, unknown>;
}

export type IntegrationProvider =
  | 'google_ads' | 'meta_lead_ads' | 'meta_conversions'
  | 'google_enhanced_conversions' | 'whatsapp' | 'email'
  | 'website_form' | 'manual' | 'csv_import';

export type IntegrationStatus = 'active' | 'paused' | 'error' | 'disconnected';

export interface Integration {
  id: string;
  tenant_id: string;
  provider: IntegrationProvider;
  display_name: string;
  status: IntegrationStatus;
  config: Record<string, unknown>;
  credential_ref: string | null;
  last_health_check_at: string | null;
  last_success_at: string | null;
  last_sync_at?: string | null;
  last_error_at: string | null;
  last_error_detail: string | null;
  created_at: string;
  updated_at: string;
}

export type ConsentChannel = 'email' | 'whatsapp' | 'sms' | 'call';
export type ConsentStatus = 'opted_in' | 'opted_out' | 'pending';
export type ConsentSource = 'lead_form' | 'explicit' | 'imported' | 'inferred_from_interaction';

export interface CommunicationConsent {
  id: string;
  tenant_id: string;
  contact_id: string;
  channel: ConsentChannel;
  status: ConsentStatus;
  consent_source: ConsentSource | null;
  consented_at: string | null;
  withdrawn_at: string | null;
  created_at: string;
  updated_at: string;
}

export type CustomFieldType = 'text' | 'number' | 'date' | 'select' | 'multiselect' | 'boolean';

export interface CustomFieldDefinition {
  id: string;
  tenant_id: string;
  entity_type: 'lead' | 'property' | 'contact';
  field_key: string;
  field_label: string;
  field_type: CustomFieldType;
  field_options: string[];
  is_required: boolean;
  is_searchable: boolean;
  sort_order: number;
  created_at: string;
  updated_at: string;
}

export interface CustomFieldValue {
  id: string;
  tenant_id: string;
  field_definition_id: string;
  entity_id: string;
  value: unknown;
  created_at: string;
  updated_at: string;
}

export interface AutomationRule {
  id: string;
  tenant_id: string;
  name: string;
  description: string | null;
  trigger_type: 'lead_created' | 'stage_changed' | 'task_overdue' | 'inactivity' | 'property_match' | 'schedule';
  trigger_config: Record<string, unknown>;
  action_type: 'assign_lead' | 'send_notification' | 'create_task' | 'update_field' | 'send_whatsapp' | 'send_email' | 'change_stage';
  action_config: Record<string, unknown>;
  is_active: boolean;
  priority: number;
  created_at: string;
  updated_at: string;
}

// Subscription/billing types intentionally removed — see docs/SUBSCRIPTION_DESIGN.md

export type LogSeverity = 'debug' | 'info' | 'warn' | 'error' | 'fatal';
export type LogComponent = 'edge_function' | 'database' | 'webhook' | 'outbox' | 'auth' | 'frontend' | 'background_job';

export interface OperationalLog {
  id: string;
  tenant_id: string | null;
  severity: LogSeverity;
  component: LogComponent;
  event_name: string;
  message: string;
  correlation_id: string | null;
  trace_id: string | null;
  duration_ms: number | null;
  error_code: string | null;
  context: Record<string, unknown>;
  environment: string;
  created_at: string;
}

export type HealthStatus = 'healthy' | 'degraded' | 'failed' | 'unknown';
export type HealthCheckType = 'connectivity' | 'configuration' | 'recent_delivery' | 'provider_api' | 'queue_lag' | 'database';

export interface SystemHealth {
  id: string;
  tenant_id: string | null;
  component: string;
  component_id: string | null;
  status: HealthStatus;
  check_type: HealthCheckType;
  last_checked_at: string;
  last_success_at: string | null;
  last_error_at: string | null;
  error_detail: string | null;
  metadata: Record<string, unknown>;
}

export type IncidentSeverity = 'info' | 'warning' | 'error' | 'critical';
export type IncidentStatus = 'open' | 'investigating' | 'resolved' | 'dismissed';

export interface Incident {
  id: string;
  tenant_id: string | null;
  title: string;
  description: string;
  severity: IncidentSeverity;
  status: IncidentStatus;
  component: string | null;
  correlation_id: string | null;
  affected_count: number;
  timeline: Array<{ timestamp: string; event: string; detail?: string }>;
  resolution: string | null;
  detected_at: string;
  resolved_at: string | null;
}

export type NotificationType =
  | 'lead_assigned'
  | 'viewing_scheduled'
  | 'task_due'
  | 'integration_alert'
  | 'incident_created'
  | 'system';

export interface AppNotification {
  id: string;
  tenant_id: string;
  user_id: string | null;
  title: string;
  message: string;
  type: NotificationType;
  link: string | null;
  read: boolean;
  read_at: string | null;
  metadata: Record<string, unknown>;
  created_at: string;
}

