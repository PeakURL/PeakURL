<?php
/**
 * Scheduler bootstrap factory.
 *
 * @package PeakURL\Core\Scheduler
 * @since 1.7.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Scheduler;

use PeakURL\Api\LinksApi;
use PeakURL\Api\SettingsApi;
use PeakURL\Database\SchedulerRepository;
use PeakURL\Features\Analytics\Jobs\AnalyticsRetentionJob;
use PeakURL\Features\Auth\Jobs\SessionCleanupJob;
use PeakURL\Features\Links\Jobs\ExpiredLinksJob;
use PeakURL\Features\Links\Jobs\ImportExportJob;
use PeakURL\Features\Links\Jobs\LinkHealthCheckJob;
use PeakURL\Features\System\Jobs\CacheCleanupJob;
use PeakURL\Features\System\Jobs\GeoipUpdateJob;
use PeakURL\Features\System\Jobs\VersionCheckJob;
use PeakURL\Features\Webhooks\Jobs\WebhookDeliveryJob;
use PeakURL\Features\Webhooks\Service as WebhooksService;
use PeakURL\Services\Cache\CacheInterface;
use PeakURL\Services\Database\PeakURL_DB;
use PeakURL\Services\Geoip;
use PeakURL\Services\Update\Manager as UpdateManager;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * SchedulerFactory — builds and configures the default Scheduler instance.
 *
 * @since 1.7.0
 */
class SchedulerFactory {

	/**
	 * Build a fully configured Scheduler instance with all built-in maintenance jobs.
	 *
	 * @param PeakURL_DB           $db               Database query wrapper.
	 * @param array<string, mixed> $config           Application configuration.
	 * @param SettingsApi          $settings_api     Settings API.
	 * @param CacheInterface       $cache_service    Cache service.
	 * @param Geoip                $geoip_service    GeoIP service.
	 * @param WebhooksService      $webhooks_service Webhooks service.
	 * @param UpdateManager        $update_manager   Update manager.
	 * @param callable|null        $logger           Optional progress logger callback.
	 * @return Scheduler Configured scheduler instance.
	 * @since 1.7.0
	 */
	public static function create(
		PeakURL_DB $db,
		array $config,
		SettingsApi $settings_api,
		CacheInterface $cache_service,
		Geoip $geoip_service,
		WebhooksService $webhooks_service,
		UpdateManager $update_manager,
		?callable $logger = null,
		?LinksApi $links_api = null
	): Scheduler {
		$registry   = new JobRegistry();
		$repository = new SchedulerRepository( $db );

		// 1. Session cleanup (Daily).
		$registry->register(
			new JobDefinition(
				'peakurl_session_cleanup',
				'Session Cleanup',
				86400,
				new SessionCleanupJob( $db, $config )
			)
		);

		// 2. GeoIP update (Weekly).
		$registry->register(
			new JobDefinition(
				'peakurl_geoip_update',
				'GeoIP Database Refresh',
				604800,
				new GeoipUpdateJob( $geoip_service )
			)
		);

		// 3. Expired links processing (Hourly).
		$registry->register(
			new JobDefinition(
				'peakurl_expired_links',
				'Expired Links Processing',
				3600,
				new ExpiredLinksJob( $db, $cache_service, 100, $links_api )
			)
		);

		// 4. Cache cleanup (Daily).
		$registry->register(
			new JobDefinition(
				'peakurl_cache_cleanup',
				'Cache Cleanup',
				86400,
				new CacheCleanupJob( $cache_service )
			)
		);

		// 5. Analytics retention (Daily).
		$registry->register(
			new JobDefinition(
				'peakurl_analytics_retention',
				'Analytics & Trash Retention',
				86400,
				new AnalyticsRetentionJob( $db, $settings_api )
			)
		);

		// 6. Version check (Every 12 hours).
		$registry->register(
			new JobDefinition(
				'peakurl_version_check',
				'PeakURL Version Check',
				43200,
				new VersionCheckJob( $settings_api, $update_manager )
			)
		);

		// 7. Webhook delivery & health (Every 5 minutes).
		$registry->register(
			new JobDefinition(
				'peakurl_webhook_delivery',
				'Webhook Delivery & Health',
				300,
				new WebhookDeliveryJob( $db, $webhooks_service )
			)
		);

		// 8. Import/export scratch cleanup (Hourly).
		$registry->register(
			new JobDefinition(
				'peakurl_import_export',
				'Import & Export Scratch Cleanup',
				3600,
				new ImportExportJob( $config )
			)
		);

		// 9. Link health check (Daily).
		$registry->register(
			new JobDefinition(
				'peakurl_link_health_check',
				'Link Destination Health Check',
				86400,
				new LinkHealthCheckJob( $db )
			)
		);

		// Extensibility hook: allow plugins to register custom jobs.
		if ( function_exists( 'do_action' ) ) {
			\do_action( 'peakurl_register_cron_jobs', $registry );
		}

		return new Scheduler( $registry, $repository, $logger );
	}
}
