import { Button } from '@/components/ui';
import { CircleCheckBig } from 'lucide-react';
import { __, sprintf } from '@/i18n';
import type { ImportSummaryProps } from './types';

function ImportSummary({ results, onReset }: ImportSummaryProps) {
	const successCount = results.filter((r) => r.status === 'success').length;
	const errorCount = results.filter((r) => r.status === 'error').length;

	return (
		<div className="text-center py-8">
			<CircleCheckBig className="mb-3 h-8 w-8 text-emerald-500 mx-auto" />
			<h3 className="text-base font-medium text-heading mb-1">
				{__('Import Completed!')}
			</h3>
			<p className="text-sm text-text-muted mb-4">
				{sprintf(
					/* translators: %s: number of URLs processed */
					__('Successfully processed %s URLs.'),
					String(successCount)
				)}{' '}
				{errorCount > 0 &&
					sprintf(
						/* translators: %s: number of failures */
						__('%s failed.'),
						String(errorCount)
					)}
			</p>
			<Button size="sm" onClick={onReset}>
				{__('Import Another File')}
			</Button>
		</div>
	);
}

export default ImportSummary;
