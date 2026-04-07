import { __ } from '@/i18n';
import type { ComingSoonBadgeProps } from './types';

function ComingSoonBadge({ className = '' }: ComingSoonBadgeProps) {
	return (
		<span
			className={`inline-flex items-center rounded-full border border-amber-500/20 bg-amber-500/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-700 dark:text-amber-300 ${className}`}
		>
			{__('Coming Soon')}
		</span>
	);
}

export default ComingSoonBadge;
