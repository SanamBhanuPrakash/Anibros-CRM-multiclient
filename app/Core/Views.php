<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Row-level security for MySQL / MariaDB, built from views (see PROJECT_LOG section 1.1).
 *
 * Application code never touches a workspace table directly: it reads and writes through `v_<table>`, which only contains the rows of the
 * current workspace (`WHERE tenant_id = app_tenant()`) and refuses writes that would leave it (WITH CHECK OPTION). The current
 * workspace / user live in session variables that only the data layer sets, per transaction. Identity tables follow the visibility
 * rules of migration 034 of the Node project (workspace / user context), with separate write views (`w_<table>`) where the write rule
 * is narrower than the read rule. The users views never expose password hashes.
 *
 * Only three kinds of routines are created, all without SUPER: app_tenant()/app_user() (NO SQL) and a few READS SQL DATA helpers.
 * Triggers and procedures are not used (cPanel hosts with binary logging refuse them to non-SUPER accounts).
 */
final class Views
{
    private const ID = 'CHAR(36) CHARACTER SET ascii COLLATE ascii_bin';

    /** @return string[] */
    public static function statements(): array
    {
        $id = self::ID;
        $s = [];
        foreach (['app_tenant', 'app_user', 'app_admin_orgs', 'app_tenant_org', 'app_is_member_of', 'app_is_org_admin', 'app_org_visible', 'app_user_visible'] as $f) $s[] = "DROP FUNCTION IF EXISTS $f";
        $s[] = "CREATE FUNCTION app_tenant() RETURNS $id DETERMINISTIC NO SQL RETURN @app_tenant";
        $s[] = "CREATE FUNCTION app_user() RETURNS $id DETERMINISTIC NO SQL RETURN @app_user";
        // Organisations the current user owns/administers (comma list, computed by the data layer when the context is set). The WRITE views
        // use it instead of a lookup function: MariaDB refuses WITH CHECK OPTION on a view whose condition reads the view's own table.
        $s[] = "CREATE FUNCTION app_admin_orgs() RETURNS VARCHAR(1000) CHARACTER SET ascii COLLATE ascii_bin DETERMINISTIC NO SQL RETURN COALESCE(@app_admin_orgs, '')";
        $s[] = "CREATE FUNCTION app_tenant_org(t $id) RETURNS $id READS SQL DATA RETURN (SELECT organisation_id FROM tenants WHERE id = t)";
        $s[] = "CREATE FUNCTION app_is_member_of(t $id) RETURNS TINYINT(1) READS SQL DATA RETURN (t IS NOT NULL AND app_user() IS NOT NULL AND EXISTS (SELECT 1 FROM tenant_memberships m WHERE m.tenant_id = t AND m.user_id = app_user() AND m.status = 'active' AND m.is_active = 1))";
        $s[] = "CREATE FUNCTION app_is_org_admin(o $id) RETURNS TINYINT(1) READS SQL DATA RETURN (o IS NOT NULL AND app_user() IS NOT NULL AND EXISTS (SELECT 1 FROM tenant_memberships m JOIN tenants h ON h.id = m.tenant_id WHERE h.organisation_id = o AND h.kind = 'agency_hq' AND m.user_id = app_user() AND m.role IN ('tenant_owner', 'tenant_admin') AND m.status = 'active' AND m.is_active = 1))";
        $s[] = "CREATE FUNCTION app_org_visible(o $id) RETURNS TINYINT(1) READS SQL DATA RETURN (o = app_tenant_org(app_tenant()) OR (app_user() IS NOT NULL AND EXISTS (SELECT 1 FROM tenant_memberships m JOIN tenants t ON t.id = m.tenant_id WHERE t.organisation_id = o AND m.user_id = app_user() AND m.status = 'active' AND m.is_active = 1)))";
        $s[] = "CREATE FUNCTION app_user_visible(u $id) RETURNS TINYINT(1) READS SQL DATA RETURN (u = app_user() OR EXISTS (SELECT 1 FROM tenant_memberships m WHERE m.user_id = u AND m.tenant_id = app_tenant()) OR (app_user() IS NOT NULL AND EXISTS (SELECT 1 FROM tenant_memberships a JOIN tenant_memberships b ON b.tenant_id = a.tenant_id WHERE a.user_id = u AND b.user_id = app_user() AND b.status = 'active' AND b.is_active = 1)))";

        foreach (Schema::TENANT as $t) {
            if ($t === 'system_health') continue;
            $s[] = "CREATE OR REPLACE VIEW v_$t AS SELECT * FROM $t WHERE tenant_id = app_tenant() WITH CASCADED CHECK OPTION";
        }
        $s[] = "CREATE OR REPLACE VIEW v_system_health AS SELECT * FROM system_health WHERE tenant_id = app_tenant() OR (tenant_id IS NULL AND component = 'worker')";
        $s[] = "CREATE OR REPLACE VIEW w_system_health AS SELECT * FROM system_health WHERE tenant_id = app_tenant() WITH CASCADED CHECK OPTION";
        // A workspace may queue platform jobs (a mail has no workspace) but only ever reads its own
        $s[] = 'CREATE OR REPLACE VIEW w_jobs AS SELECT * FROM jobs WHERE tenant_id = app_tenant() OR tenant_id IS NULL WITH CASCADED CHECK OPTION';

        $ucols = implode(', ', Schema::USER_PUBLIC_COLUMNS);
        $s[] = "CREATE OR REPLACE VIEW v_users AS SELECT $ucols FROM users WHERE id = app_user() OR app_user_visible(id)";
        $s[] = "CREATE OR REPLACE VIEW w_users AS SELECT $ucols FROM users WHERE id = app_user() WITH CASCADED CHECK OPTION";
        $s[] = 'CREATE OR REPLACE VIEW v_organisations AS SELECT * FROM organisations WHERE app_org_visible(id)';
        $s[] = 'CREATE OR REPLACE VIEW w_organisations AS SELECT * FROM organisations WHERE FIND_IN_SET(id, app_admin_orgs()) > 0 WITH CASCADED CHECK OPTION';
        $s[] = 'CREATE OR REPLACE VIEW v_tenants AS SELECT * FROM tenants WHERE id = app_tenant() OR app_is_member_of(id) OR app_is_org_admin(organisation_id)';
        $s[] = "CREATE OR REPLACE VIEW w_tenants AS SELECT * FROM tenants WHERE id = app_tenant() OR (kind = 'client' AND FIND_IN_SET(organisation_id, app_admin_orgs()) > 0) WITH CASCADED CHECK OPTION";
        $s[] = 'CREATE OR REPLACE VIEW v_tenant_memberships AS SELECT * FROM tenant_memberships WHERE tenant_id = app_tenant() OR user_id = app_user() OR app_is_member_of(tenant_id) OR app_is_org_admin(app_tenant_org(tenant_id))';
        $s[] = 'CREATE OR REPLACE VIEW w_tenant_memberships AS SELECT * FROM tenant_memberships WHERE tenant_id = app_tenant() OR FIND_IN_SET(app_tenant_org(tenant_id), app_admin_orgs()) > 0 WITH CASCADED CHECK OPTION';
        $s[] = 'CREATE OR REPLACE VIEW v_notification_reads AS SELECT * FROM notification_reads WHERE user_id = app_user() WITH CASCADED CHECK OPTION';
        return $s;
    }

    /** (Re)create every function and view. Idempotent; run after every schema change. @return int number of statements run */
    public static function apply(\PDO $pdo): int
    {
        $n = 0;
        foreach (self::statements() as $sql) {
            try {
                $pdo->exec($sql);
                $n++;
            } catch (\PDOException $e) {
                throw new \RuntimeException('Could not create the isolation views: ' . $e->getMessage() . ' [' . substr($sql, 0, 90) . ']', 0, $e);
            }
        }
        return $n;
    }
}
