<?php
/**
 * PeakURL database repair metadata.
 *
 * @package PeakURL\Database
 * @since 1.0.14
 */

declare(strict_types=1);

namespace PeakURL\Database;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * RepairSpecs — canonical managed repair metadata.
 *
 * Keeps non-structural schema repair rules in one place so status and upgrade
 * flows can share the same repair targets without scattering those details
 * across the runtime service layer.
 *
 * @since 1.0.14
 */
class RepairSpecs {

	/**
	 * Return managed opaque-ID normalization rules.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.14
	 */
	public static function opaque_id_repairs(): array {
		return array(
			array(
				'table'        => 'clicks',
				'prefix'       => 'click_',
				'issue_id'     => 'click-id-prefixes',
				'issue_label'  => __( 'The clicks table still contains prefixed row IDs and should be normalized.', 'peakurl' ),
				'change_label' => __( 'Normalized click IDs to the current opaque format.', 'peakurl' ),
			),
			array(
				'table'        => 'webhooks',
				'prefix'       => 'webhook_',
				'issue_id'     => 'webhook-id-prefixes',
				'issue_label'  => __( 'The webhooks table still contains prefixed row IDs and should be normalized.', 'peakurl' ),
				'change_label' => __( 'Normalized webhook IDs to the current opaque format.', 'peakurl' ),
			),
		);
	}

	/**
	 * Return managed orphan cleanup queries.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.14
	 */
	public static function orphan_cleanup_queries(): array {
		return array(
			array(
				'label' => __( 'Removed orphaned API keys.', 'peakurl' ),
				'sql'   => 'DELETE ak
					FROM api_keys AS ak
					LEFT JOIN users AS u ON ak.user_id = u.id
					WHERE u.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned sessions.', 'peakurl' ),
				'sql'   => 'DELETE s
					FROM sessions AS s
					LEFT JOIN users AS u ON s.user_id = u.id
					WHERE u.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned URLs.', 'peakurl' ),
				'sql'   => 'DELETE url
					FROM urls AS url
					LEFT JOIN users AS u ON url.user_id = u.id
					WHERE u.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned clicks.', 'peakurl' ),
				'sql'   => 'DELETE c
					FROM clicks AS c
					LEFT JOIN urls AS url ON c.url_id = url.id
					WHERE url.id IS NULL',
			),
			array(
				'label' => __( 'Cleared orphaned audit-log users.', 'peakurl' ),
				'sql'   => 'UPDATE audit_logs AS al
					LEFT JOIN users AS u ON al.user_id = u.id
					SET al.user_id = NULL
					WHERE al.user_id IS NOT NULL
					AND u.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned audit-log links.', 'peakurl' ),
				'sql'   => 'DELETE al
					FROM audit_logs AS al
					LEFT JOIN urls AS url ON al.link_id = url.id
					WHERE al.link_id IS NOT NULL
					AND url.id IS NULL',
			),
			array(
				'label' => __( 'Removed orphaned webhooks.', 'peakurl' ),
				'sql'   => 'DELETE w
					FROM webhooks AS w
					LEFT JOIN users AS u ON w.user_id = u.id
					WHERE u.id IS NULL',
			),
		);
	}
}
