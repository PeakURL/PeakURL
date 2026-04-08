import { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { __, sprintf } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import { formatLocalizedDateTime } from '@/utils';
import type { LinkStatsViewProps } from './types';

function BestDay({ link }: Pick<LinkStatsViewProps, 'link'>) {
	const isRtl = isDocumentRtl();
	const [showDetails, setShowDetails] = useState(true);
	const totalClicks = Number(link.clicks || 0);

	return (
		<div className="bg-surface-alt border border-stroke rounded-lg p-4">
			<h3 className="text-base font-semibold text-heading mb-3">
				{__('Best day')}
			</h3>
			<p className="text-sm text-heading mb-2">
				<span className="font-semibold">
					{sprintf(__('%s hits'), String(totalClicks))}
				</span>{' '}
				{__('on')}{' '}
				{formatLocalizedDateTime(new Date(), {
					year: 'numeric',
					month: 'long',
					day: 'numeric',
				})}
				.{' '}
				<button
					onClick={() => setShowDetails(!showDetails)}
					className="text-accent hover:underline inline-flex items-center gap-1"
				>
					{showDetails ? __('Hide details') : __('View details')}
					{showDetails ? (
						<ChevronUp className="w-3 h-3" />
					) : (
						<ChevronDown className="w-3 h-3" />
					)}
				</button>
			</p>

			{/* Year/Month/Day Breakdown */}
			{showDetails && (
				<div
					className={`mt-4 animate-in slide-in-from-top-2 duration-200 ${
						isRtl
							? 'border-r-2 border-accent/20 pr-4 text-right'
							: 'border-l-2 border-accent/20 pl-4 text-left'
					}`}
				>
					<div className="space-y-2">
						<div className="flex items-center gap-2">
							<span className="w-2 h-2 rounded-full bg-accent"></span>
							<span className="text-sm font-medium text-heading">
								{__('Year')} {new Date().getFullYear()}
							</span>
						</div>
						<div className={`${isRtl ? 'mr-4' : 'ml-4'} space-y-2`}>
							<div className="flex items-center gap-2">
								<span className="w-1.5 h-1.5 rounded-full bg-text-muted"></span>
								<span className="text-sm text-text-muted">
									{formatLocalizedDateTime(new Date(), {
										month: 'long',
									})}
								</span>
							</div>
							<div
								className={`flex items-center gap-2 ${
									isRtl ? 'mr-4' : 'ml-4'
								}`}
							>
								<span className="w-1 h-1 rounded-full bg-text-muted"></span>
								<span className="text-sm font-medium text-heading">
									{formatLocalizedDateTime(new Date(), {
										day: 'numeric',
									})}
									:{' '}
									{sprintf(
										__('%s hits'),
										String(totalClicks)
									)}
								</span>
							</div>
						</div>
					</div>
				</div>
			)}
		</div>
	);
}

export default BestDay;
