<?php
/**
 * PeakURL database schema metadata.
 *
 * @package PeakURL\Database
 * @since 1.0.3
 */

declare(strict_types=1);

namespace PeakURL\Database;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SchemaSpecs — canonical managed database table metadata.
 *
 * Keeps table, column, index, and foreign-key definitions in one place so
 * schema inspection and upgrade flows do not duplicate static structure data.
 *
 * @since 1.0.3
 */
class SchemaSpecs {

	/**
	 * Return the list of PeakURL-managed tables.
	 *
	 * @return array<int, string>
	 * @since 1.0.3
	 */
	public static function managed_tables(): array {
		return array(
			'settings',
			'users',
			'api_keys',
			'sessions',
			'urls',
			'clicks',
			'audit_logs',
			'webhooks',
		);
	}

	/**
	 * Return additive column specs keyed by table name.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 * @since 1.0.3
	 */
	public static function column_specs(): array {
		return array(
			'settings'   => array(
				array(
					'name'       => 'autoload',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 1',
				),
				array(
					'name'       => 'updated_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			'users'      => array(
				array(
					'name'       => 'phone_number',
					'definition' => 'VARCHAR(40) DEFAULT NULL',
				),
				array(
					'name'       => 'company',
					'definition' => 'VARCHAR(190) DEFAULT NULL',
				),
				array(
					'name'       => 'job_title',
					'definition' => 'VARCHAR(190) DEFAULT NULL',
				),
				array(
					'name'       => 'bio',
					'definition' => 'TEXT DEFAULT NULL',
				),
				array(
					'name'       => 'role',
					'definition' => "VARCHAR(32) NOT NULL DEFAULT 'editor'",
				),
				array(
					'name'       => 'is_email_verified',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 0',
				),
				array(
					'name'       => 'email_verified_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'email_verification_token',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'email_verification_expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'password_reset_token',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'password_reset_expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'two_factor_enabled',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 0',
				),
				array(
					'name'       => 'two_factor_secret',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'two_factor_pending_secret',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'backup_codes_json',
					'definition' => 'LONGTEXT DEFAULT NULL',
				),
				array(
					'name'       => 'backup_codes_generated_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'last_login_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			'api_keys'   => array(
				array(
					'name'       => 'key_hash',
					'definition' => 'CHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'key_prefix',
					'definition' => 'VARCHAR(16) DEFAULT NULL',
				),
				array(
					'name'       => 'key_last_four',
					'definition' => 'CHAR(4) DEFAULT NULL',
				),
				array(
					'name'       => 'created_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
			),
			'sessions'   => array(
				array(
					'name'       => 'browser',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'operating_system',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'device',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'revoked_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'revoked_reason',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
			),
			'urls'       => array(
				array(
					'name'       => 'title',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'password_value',
					'definition' => 'VARCHAR(255) DEFAULT NULL',
				),
				array(
					'name'       => 'expires_at',
					'definition' => 'DATETIME DEFAULT NULL',
				),
				array(
					'name'       => 'status',
					'definition' => "VARCHAR(32) NOT NULL DEFAULT 'active'",
				),
				array(
					'name'       => 'utm_source',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_medium',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_campaign',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_term',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_content',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
			),
			'clicks'     => array(
				array(
					'name'       => 'visitor_hash',
					'definition' => 'CHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'country_code',
					'definition' => 'VARCHAR(8) DEFAULT NULL',
				),
				array(
					'name'       => 'country_name',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'city_name',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'device',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'browser',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'operating_system',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_name',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_domain',
					'definition' => 'VARCHAR(191) DEFAULT NULL',
				),
				array(
					'name'       => 'referrer_category',
					'definition' => 'VARCHAR(64) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_source',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_medium',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_campaign',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_term',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'utm_content',
					'definition' => 'VARCHAR(120) DEFAULT NULL',
				),
				array(
					'name'       => 'user_agent',
					'definition' => 'TEXT DEFAULT NULL',
				),
			),
			'audit_logs' => array(
				array(
					'name'       => 'link_id',
					'definition' => 'VARCHAR(40) DEFAULT NULL',
				),
				array(
					'name'       => 'metadata',
					'definition' => 'LONGTEXT DEFAULT NULL',
				),
			),
			'webhooks'   => array(
				array(
					'name'       => 'is_active',
					'definition' => 'TINYINT(1) NOT NULL DEFAULT 1',
				),
			),
		);
	}

	/**
	 * Return additive index specs keyed by table name.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 * @since 1.0.3
	 */
	public static function index_specs(): array {
		return array(
			'settings'   => array(
				array(
					'name'    => 'idx_settings_autoload',
					'type'    => 'index',
					'columns' => '(autoload)',
				),
			),
			'users'      => array(
				array(
					'name'    => 'idx_users_role',
					'type'    => 'index',
					'columns' => '(role)',
				),
			),
			'api_keys'   => array(
				array(
					'name'    => 'uniq_api_keys_key_hash',
					'type'    => 'unique',
					'columns' => '(key_hash)',
				),
				array(
					'name'    => 'idx_api_keys_user_created_at',
					'type'    => 'index',
					'columns' => '(user_id, created_at)',
				),
			),
			'sessions'   => array(
				array(
					'name'    => 'idx_sessions_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_sessions_token_hash',
					'type'    => 'index',
					'columns' => '(token_hash)',
				),
				array(
					'name'    => 'idx_sessions_user_active',
					'type'    => 'index',
					'columns' => '(user_id, revoked_at, last_active_at)',
				),
			),
			'urls'       => array(
				array(
					'name'    => 'idx_urls_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_urls_user_status',
					'type'    => 'index',
					'columns' => '(user_id, status)',
				),
				array(
					'name'    => 'idx_urls_status',
					'type'    => 'index',
					'columns' => '(status)',
				),
				array(
					'name'    => 'idx_urls_created_at',
					'type'    => 'index',
					'columns' => '(created_at)',
				),
			),
			'clicks'     => array(
				array(
					'name'    => 'idx_clicks_url_id',
					'type'    => 'index',
					'columns' => '(url_id)',
				),
				array(
					'name'    => 'idx_clicks_clicked_at',
					'type'    => 'index',
					'columns' => '(clicked_at)',
				),
				array(
					'name'    => 'idx_clicks_url_clicked_at',
					'type'    => 'index',
					'columns' => '(url_id, clicked_at)',
				),
			),
			'audit_logs' => array(
				array(
					'name'    => 'idx_audit_logs_created_at',
					'type'    => 'index',
					'columns' => '(created_at)',
				),
				array(
					'name'    => 'idx_audit_logs_user_created_at',
					'type'    => 'index',
					'columns' => '(user_id, created_at)',
				),
				array(
					'name'    => 'idx_audit_logs_link_id',
					'type'    => 'index',
					'columns' => '(link_id)',
				),
			),
			'webhooks'   => array(
				array(
					'name'    => 'idx_webhooks_user_id',
					'type'    => 'index',
					'columns' => '(user_id)',
				),
				array(
					'name'    => 'idx_webhooks_user_active',
					'type'    => 'index',
					'columns' => '(user_id, is_active)',
				),
			),
		);
	}

	/**
	 * Return foreign-key specs keyed by table name.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 * @since 1.0.3
	 */
	public static function foreign_key_specs(): array {
		return array(
			'api_keys'   => array(
				array(
					'name'       => 'fk_api_keys_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
			),
			'sessions'   => array(
				array(
					'name'       => 'fk_sessions_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
			),
			'urls'       => array(
				array(
					'name'       => 'fk_urls_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
			),
			'clicks'     => array(
				array(
					'name'       => 'fk_clicks_url_id',
					'definition' => 'FOREIGN KEY (url_id) REFERENCES urls (id) ON DELETE CASCADE',
				),
			),
			'audit_logs' => array(
				array(
					'name'       => 'fk_audit_logs_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
				),
				array(
					'name'       => 'fk_audit_logs_link_id',
					'definition' => 'FOREIGN KEY (link_id) REFERENCES urls (id) ON DELETE CASCADE',
				),
			),
			'webhooks'   => array(
				array(
					'name'       => 'fk_webhooks_user_id',
					'definition' => 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
				),
			),
		);
	}
}
