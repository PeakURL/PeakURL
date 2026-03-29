// @ts-nocheck
import { Button } from '@/components/ui';
import { CircleCheckBig } from 'lucide-react';

function ImportSummary({ results, onReset }) {
	const successCount = results.filter((r) => r.status === 'success').length;
	const errorCount = results.filter((r) => r.status === 'error').length;

	return (
		<div className="text-center py-8">
			<CircleCheckBig className="mb-3 h-8 w-8 text-emerald-500 mx-auto" />
			<h3 className="text-base font-medium text-heading mb-1">
				Import Completed!
			</h3>
			<p className="text-sm text-text-muted mb-4">
				Successfully processed {successCount} URLs.{' '}
				{errorCount > 0 && `${errorCount} failed.`}
			</p>
			<Button size="sm" onClick={onReset}>
				Import Another File
			</Button>
		</div>
	);
}

export default ImportSummary;
