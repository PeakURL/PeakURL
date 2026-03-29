// @ts-nocheck
'use client';

import { Link } from 'react-router-dom';
import { MapPin } from 'lucide-react';
import { useAdminAccess } from '@/hooks';
import { useGetGeoipStatusQuery } from '@/store/slices/api/system';

export const GeoipNotice = () => {
	const { canManageLocationData } = useAdminAccess();
	const { data: geoipStatusResponse } = useGetGeoipStatusQuery(undefined, {
		skip: !canManageLocationData,
	});
	const geoipStatus = geoipStatusResponse?.data || null;
	const showNotice =
		canManageLocationData &&
		geoipStatus &&
		!geoipStatus.locationAnalyticsReady;

	if (!showNotice) {
		return null;
	}

	return (
		<div className="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
			<div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
				<div className="flex items-start gap-3">
					<div className="mt-0.5 shrink-0">
						<MapPin size={18} />
					</div>
					<div className="space-y-1">
						<p className="font-semibold">
							Location analytics is not ready yet.
						</p>
						<p className="leading-6 opacity-80">
							Add your MaxMind credentials and download the
							GeoLite2 City database to enable visitor country and
							city reporting across the dashboard.
						</p>
					</div>
				</div>
				<Link
					to="/dashboard/settings/location"
					className="inline-flex shrink-0 items-center justify-center rounded-lg border border-current/20 px-3 py-2 text-sm font-medium transition hover:bg-white/40 dark:hover:bg-white/5"
				>
					Open Location Data
				</Link>
			</div>
		</div>
	);
};

export default GeoipNotice;
