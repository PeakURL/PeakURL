// @ts-nocheck
'use client';

import { format } from 'date-fns';
import { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';

function BestDay({ link }) {
	const [showDetails, setShowDetails] = useState(true);

	return (
		<div className="bg-surface-alt border border-stroke rounded-lg p-4">
			<h3 className="text-base font-semibold text-heading mb-3">
				Best day
			</h3>
			<p className="text-sm text-heading mb-2">
				<span className="font-semibold">{link.clicks || 0} hits</span>{' '}
				on {format(new Date(), 'MMMM d, yyyy')}.{' '}
				<button
					onClick={() => setShowDetails(!showDetails)}
					className="text-accent hover:underline inline-flex items-center gap-1"
				>
					{showDetails ? 'Hide details' : 'View details'}
					{showDetails ? (
						<ChevronUp className="w-3 h-3" />
					) : (
						<ChevronDown className="w-3 h-3" />
					)}
				</button>
			</p>

			{/* Year/Month/Day Breakdown */}
			{showDetails && (
				<div className="mt-4 pl-4 border-l-2 border-accent/20 animate-in slide-in-from-top-2 duration-200">
					<div className="space-y-2">
						<div className="flex items-center gap-2">
							<span className="w-2 h-2 rounded-full bg-accent"></span>
							<span className="text-sm font-medium text-heading">
								Year {new Date().getFullYear()}
							</span>
						</div>
						<div className="ml-4 space-y-2">
							<div className="flex items-center gap-2">
								<span className="w-1.5 h-1.5 rounded-full bg-text-muted"></span>
								<span className="text-sm text-text-muted">
									{format(new Date(), 'MMMM')}
								</span>
							</div>
							<div className="ml-4 flex items-center gap-2">
								<span className="w-1 h-1 rounded-full bg-text-muted"></span>
								<span className="text-sm font-medium text-heading">
									{format(new Date(), 'd')}:{' '}
									{link.clicks || 0} hits
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
