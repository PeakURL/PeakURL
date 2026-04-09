import { CheckCircle2, Clock3, LoaderCircle } from 'lucide-react';
import { getDocumentDirection } from '@/i18n/direction';
import type { ReleaseInstallProgressState } from './types';

interface ReleaseInstallProgressProps {
	progress: ReleaseInstallProgressState;
	compact?: boolean;
}

const stepStateStyles = {
	complete:
		'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
	current:
		'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
	upcoming:
		'border-stroke bg-bg text-text-muted',
} as const;

const stepTextStyles = {
	complete: 'text-emerald-800 dark:text-emerald-200',
	current: 'text-heading',
	upcoming: 'text-text-muted',
} as const;

function ReleaseInstallProgress({
	progress,
	compact = false,
}: ReleaseInstallProgressProps) {
	const direction = getDocumentDirection();

	return (
		<div
			dir={direction}
			className={`text-inline-start rounded-lg border border-stroke bg-bg ${
				compact ? 'p-4' : 'p-5'
			}`}
		>
			<div className={compact ? 'space-y-1.5' : 'space-y-2'}>
				<p className="text-sm font-semibold text-heading">{progress.title}</p>
				<p className="text-sm leading-6 text-text-muted">
					{progress.description}
				</p>
			</div>

			<ol className={compact ? 'mt-4 space-y-2.5' : 'mt-4 space-y-3'}>
				{progress.steps.map((step) => {
					const Icon =
						'complete' === step.state
							? CheckCircle2
							: 'current' === step.state
								? LoaderCircle
								: Clock3;

					return (
						<li key={step.id} className="flex items-center gap-3">
							<span
								className={`inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border ${stepStateStyles[step.state]}`}
							>
								<Icon
									size={16}
									className={
										'current' === step.state ? 'animate-spin' : ''
									}
								/>
							</span>
							<span className={`text-sm ${stepTextStyles[step.state]}`}>
								{step.label}
							</span>
						</li>
					);
				})}
			</ol>
		</div>
	);
}

export default ReleaseInstallProgress;
