<?php
/**
 * WA Atlas CRM – Database  v1.2.0
 * =================================
 * FIX #2 – Added table_count() helper.
 * FIX #2 – install() always calls dbDelta() so it is safe to call on every
 *           plugin activation and every version upgrade (idempotent).
 * The wacrm_db_version option is updated after install() so WACRM_Admin::maybe_upgrade()
 * only runs dbDelta when the version actually changes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_DB {

    // ── Table names ───────────────────────────────────────────────────────────
    public static function contacts()       { global $wpdb; return $wpdb->prefix . 'wacrm_contacts'; }
    public static function contact_fields() { global $wpdb; return $wpdb->prefix . 'wacrm_contact_fields'; }
    public static function contact_meta()   { global $wpdb; return $wpdb->prefix . 'wacrm_contact_meta'; }
    public static function lists()          { global $wpdb; return $wpdb->prefix . 'wacrm_lists'; }
    public static function list_contacts()  { global $wpdb; return $wpdb->prefix . 'wacrm_list_contacts'; }
    public static function campaigns()      { global $wpdb; return $wpdb->prefix . 'wacrm_campaigns'; }
    public static function campaign_steps() { global $wpdb; return $wpdb->prefix . 'wacrm_campaign_steps'; }
    public static function automations()    { global $wpdb; return $wpdb->prefix . 'wacrm_automations'; }
    public static function templates()      { global $wpdb; return $wpdb->prefix . 'wacrm_templates'; }
    public static function message_logs()   { global $wpdb; return $wpdb->prefix . 'wacrm_message_logs'; }
    public static function otp_logs()       { global $wpdb; return $wpdb->prefix . 'wacrm_otp_logs'; }
    public static function instances()      { global $wpdb; return $wpdb->prefix . 'wacrm_instances'; }
    public static function quota()          { global $wpdb; return $wpdb->prefix . 'wacrm_quota'; }
    public static function queue()          { global $wpdb; return $wpdb->prefix . 'wacrm_queue'; }

    /** Returns the number of plugin tables (for the reinstall confirmation message) */
    public static function table_count(): int { return 14; }

    // ── Install / upgrade all tables ──────────────────────────────────────────

    /**
     * Safe to call multiple times – dbDelta only creates or alters, never drops.
     * Called on activation hook AND on every version change via maybe_upgrade().
     */
    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = [];

        // contacts
        $tables[] = "CREATE TABLE " . self::contacts() . " (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name   VARCHAR(100)    NOT NULL DEFAULT '',
            last_name    VARCHAR(100)    NOT NULL DEFAULT '',
            phone        VARCHAR(30)     NOT NULL DEFAULT '',
            whatsapp     VARCHAR(30)     NOT NULL DEFAULT '',
            email        VARCHAR(150)    NOT NULL DEFAULT '',
            tags         TEXT,
            wa_status    VARCHAR(30)     NOT NULL DEFAULT 'unknown',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone (phone),
            KEY whatsapp (whatsapp)
        ) $charset;";

        // custom field definitions
        $tables[] = "CREATE TABLE " . self::contact_fields() . " (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            field_key    VARCHAR(80)     NOT NULL DEFAULT '',
            field_label  VARCHAR(150)    NOT NULL DEFAULT '',
            field_type   VARCHAR(30)     NOT NULL DEFAULT 'text',
            field_opts   TEXT,
            sort_order   INT             NOT NULL DEFAULT 0,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY field_key (field_key)
        ) $charset;";

        // custom field values per contact
        $tables[] = "CREATE TABLE " . self::contact_meta() . " (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id   BIGINT UNSIGNED NOT NULL,
            field_key    VARCHAR(80)     NOT NULL DEFAULT '',
            field_value  TEXT,
            PRIMARY KEY (id),
            KEY contact_id (contact_id),
            KEY field_key (field_key)
        ) $charset;";

        // lists
        $tables[] = "CREATE TABLE " . self::lists() . " (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_name    VARCHAR(150)    NOT NULL DEFAULT '',
            description  TEXT,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        // list <-> contact pivot
        $tables[] = "CREATE TABLE " . self::list_contacts() . " (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id      BIGINT UNSIGNED NOT NULL,
            contact_id   BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY list_contact (list_id, contact_id)
        ) $charset;";

        // campaigns
        $tables[] = "CREATE TABLE " . self::campaigns() . " (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_name   VARCHAR(200)    NOT NULL DEFAULT '',
            status          VARCHAR(30)     NOT NULL DEFAULT 'draft',
            target_lists    TEXT,
            target_filters  TEXT,
            filter_logic    VARCHAR(10)     NOT NULL DEFAULT 'AND',
            rate_per_hour   INT             NOT NULL DEFAULT 200,
            schedule_from   VARCHAR(5)      NOT NULL DEFAULT '09:00',
            schedule_to     VARCHAR(5)      NOT NULL DEFAULT '20:00',
            randomize_delay TINYINT         NOT NULL DEFAULT 1,
            started_at      DATETIME,
            finished_at     DATETIME,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        // campaign message steps
        $tables[] = "CREATE TABLE " . self::campaign_steps() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id   BIGINT UNSIGNED NOT NULL,
            step_order    INT             NOT NULL DEFAULT 0,
            message_type  VARCHAR(20)     NOT NULL DEFAULT 'text',
            template_id   BIGINT UNSIGNED,
            message_body  TEXT,
            media_url     VARCHAR(500),
            delay_seconds INT             NOT NULL DEFAULT 5,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id)
        ) $charset;";

        // automations
        $tables[] = "CREATE TABLE " . self::automations() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            auto_name     VARCHAR(200)    NOT NULL DEFAULT '',
            trigger_type  VARCHAR(50)     NOT NULL DEFAULT '',
            conditions    TEXT,
            actions       TEXT,
            status        VARCHAR(20)     NOT NULL DEFAULT 'active',
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        // message templates
        $tables[] = "CREATE TABLE " . self::templates() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tpl_name      VARCHAR(200)    NOT NULL DEFAULT '',
            category      VARCHAR(50)     NOT NULL DEFAULT 'manual',
            body          TEXT,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        // message logs
        $tables[] = "CREATE TABLE " . self::message_logs() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id    BIGINT UNSIGNED,
            phone         VARCHAR(30)     NOT NULL DEFAULT '',
            instance_name VARCHAR(80),
            message_type  VARCHAR(20)     NOT NULL DEFAULT 'text',
            campaign_id   BIGINT UNSIGNED,
            automation_id BIGINT UNSIGNED,
            status        VARCHAR(30)     NOT NULL DEFAULT 'pending',
            error_msg     TEXT,
            sent_at       DATETIME,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone (phone),
            KEY sent_at (sent_at),
            KEY status (status)
        ) $charset;";

        // otp logs
        $tables[] = "CREATE TABLE " . self::otp_logs() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone         VARCHAR(30)     NOT NULL,
            otp_code      VARCHAR(10)     NOT NULL,
            attempts      TINYINT         NOT NULL DEFAULT 0,
            verified      TINYINT         NOT NULL DEFAULT 0,
            expires_at    DATETIME        NOT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone (phone)
        ) $charset;";

        // Evolution instances cache
        $tables[] = "CREATE TABLE " . self::instances() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_name VARCHAR(80)     NOT NULL,
            status        VARCHAR(30)     NOT NULL DEFAULT 'pending',
            qrcode        TEXT,
            connected_num VARCHAR(30),
            enabled       TINYINT         NOT NULL DEFAULT 1,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY instance_name (instance_name)
        ) $charset;";

        // quota tracking
        $tables[] = "CREATE TABLE " . self::quota() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key   VARCHAR(200)    NOT NULL DEFAULT '',
            messages_sent INT             NOT NULL DEFAULT 0,
            period_start  DATETIME,
            PRIMARY KEY (id)
        ) $charset;";

        // message queue
        $tables[] = "CREATE TABLE " . self::queue() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone         VARCHAR(30)     NOT NULL,
            instance_name VARCHAR(80),
            message_type  VARCHAR(20)     NOT NULL DEFAULT 'text',
            payload       LONGTEXT,
            campaign_id   BIGINT UNSIGNED,
            automation_id BIGINT UNSIGNED,
            contact_id    BIGINT UNSIGNED,
            step_order    INT             NOT NULL DEFAULT 0,
            scheduled_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
            attempts      TINYINT         NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY status_sched (status, scheduled_at)
        ) $charset;";

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        // Update stored version so maybe_upgrade() doesn't re-run unnecessarily
        update_option( 'wacrm_db_version', WACRM_VERSION );
    }
}