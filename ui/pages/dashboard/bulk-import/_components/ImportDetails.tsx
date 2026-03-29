// @ts-nocheck
import { Button } from '@/components/ui';
import { CircleAlert, CircleCheckBig, Download } from 'lucide-react';

function ImportDetails({ results }) {
	const successCount = results.filter((r) => r.status === 'success').length;
	const errorCount = results.filter((r) => r.status === 'error').length;

	return (
		<div className="bg-surface border border-stroke rounded-lg p-5">
			<h3 className="text-base font-semibold text-heading mb-4">
				Import Results
			</h3>
			<div className="space-y-2 max-h-96 overflow-y-auto">
				{results.map((result, index) => (
					<div
						key={index}
						className={`flex items-center gap-3 p-3 rounded-lg ${
							result.status === 'success'
								? 'bg-emerald-500/10 dark:bg-emerald-500/20'
								: 'bg-red-500/10 dark:bg-red-500/20'
						}`}
					>
						{result.status === 'success' ? (
							<CircleCheckBig className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
						) : (
							<CircleAlert className="h-4 w-4 text-red-600 dark:text-red-400" />
						)}
						<div className="flex-1 min-w-0">
							<div className="text-sm font-medium text-heading truncate">
								{result.url}
							</div>
							{result.status === 'success' ? (
								<div className="text-xs text-emerald-600 dark:text-emerald-400">
									{result.shortUrl}
								</div>
							) : (
								<div className="text-xs text-red-600 dark:text-red-400">
									{result.error}
								</div>
							)}
						</div>
					</div>
				))}
			</div>
			<div className="mt-4 pt-4 border-t border-stroke flex items-center justify-between text-sm">
				<span className="text-text-muted">
					{successCount} successful, {errorCount} failed
				</span>
				<Button variant="ghost" size="sm">
					<Download className="mr-2 h-4 w-4" />
					Export Results
				</Button>
			</div>
		</div>
	);
}

export default ImportDetails;
