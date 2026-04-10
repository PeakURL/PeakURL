import { Cog, LoaderCircle } from 'lucide-react';
import { __ } from '@/i18n';
import type { ProcessingStatusProps } from './types';

function ProcessingStatus({ status, progress }: ProcessingStatusProps) {
	if (status === 'uploading') {
		return (
			<div className="text-center py-8">
				<LoaderCircle className="mx-auto mb-3 h-10 w-10 animate-spin text-accent" />
				<h3 className="text-base font-medium text-heading mb-1">
					{__('Uploading file...')}
				</h3>
				<p className="text-sm text-text-muted">
					{__('Please wait while we process your file')}
				</p>
				{progress ? (
					<p className="mt-2 text-xs text-text-muted">
						{`${progress}%`}
					</p>
				) : null}
			</div>
		);
	}
	if (status === 'processing') {
		return (
			<div className="py-8">
				<div className="text-center mb-5">
					<Cog className="mx-auto mb-3 h-8 w-8 text-primary-500" />
					<h3 className="text-base font-medium text-heading mb-1">
						{__('Processing URLs')}
					</h3>
					<p className="text-sm text-text-muted">
						{__('Creating short links...')}
					</p>
				</div>
				<div className="w-full bg-surface-alt rounded-full h-2 animate-pulse">
					<div className="bg-accent h-2 rounded-full w-full"></div>
				</div>
			</div>
		);
	}
	return null;
}

export default ProcessingStatus;
