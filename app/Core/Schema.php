<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Table registry: which database context may touch which table (the data-isolation map, see PROJECT_LOG section 1.1).
 *
 *  TENANT      tables with a tenant_id: reached through v_<table> views that filter on the current workspace.
 *  IDENTITY_V  identity tables with their own visibility rules (read view v_*, and a write view w_* where write rules differ).
 *  IDENTITY    tables only the sign-in / sign-up / session / token code may touch (Db::identity()).
 *  SYSTEM      plumbing no request code may touch (Db::system(): worker, installer).
 */
final class Schema
{
    public const TENANT = [
        'tenant_invitations', 'branches', 'contacts', 'contact_identifiers', 'contact_merges', 'communication_consents', 'pipelines',
        'pipeline_stages', 'pipeline_stage_transitions', 'leads', 'lead_handlers', 'lead_stage_history', 'activities', 'saved_views',
        'lead_scoring_config', 'import_jobs', 'custom_field_definitions', 'custom_field_values', 'projects', 'properties',
        'property_price_history', 'property_status_history', 'files', 'lead_properties', 'tasks', 'viewings', 'reservations',
        'notifications', 'marketing_attribution', 'marketing_spend', 'channel_mappings', 'automation_rules', 'automation_executions',
        'integrations', 'integration_webhook_endpoints', 'inbound_endpoints', 'inbound_events', 'outbound_endpoints',
        'outbound_deliveries', 'messaging_accounts', 'api_keys', 'audit_logs', 'domain_events', 'jobs', 'outbox_events', 'webhook_inbox',
        'idempotency_keys', 'operational_logs', 'system_health',
    ];

    /** Identity tables visible in workspace / user contexts (read view v_<t>). */
    public const IDENTITY_V = ['users', 'organisations', 'tenants', 'tenant_memberships', 'notification_reads'];

    /** Subset of IDENTITY_V that also has a separate, narrower write view w_<t>. */
    public const WRITE_VIEWS = ['users', 'organisations', 'tenants', 'tenant_memberships'];

    public const IDENTITY = ['sessions', 'login_attempts', 'email_verification_tokens', 'password_reset_tokens', 'mfa_factors', 'mfa_recovery_codes'];

    public const SYSTEM = ['event_consumers', 'rate_buckets', 'schema_migrations'];

    /** Tables without a UUID `id` column (Tx::insert does not invent one). domain_events uses AUTO_INCREMENT. */
    public const NO_UUID_ID = ['channel_mappings', 'idempotency_keys', 'lead_handlers', 'domain_events', 'event_consumers', 'rate_buckets', 'schema_migrations'];

    /** Columns of users that are exposed through views (never password_hash). */
    public const USER_PUBLIC_COLUMNS = ['id', 'email', 'name', 'avatar_url', 'phone', 'email_verified', 'is_active', 'created_at', 'updated_at', 'timezone', 'locale', 'password_changed_at'];

    public static function isTenant(string $t): bool
    {
        return in_array($t, self::TENANT, true);
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_merge(self::TENANT, self::IDENTITY_V, self::IDENTITY, self::SYSTEM);
    }
}
