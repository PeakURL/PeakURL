import { __ } from '@/i18n';
import { cn } from '@/utils';
import type { ComingSoonBadgeProps } from './types';

function ComingSoonBadge({ className = '' }: ComingSoonBadgeProps) {
	return (
		<span
			className={cn('plugins-coming-soon-badge', className)}
		>
			{__('Coming Soon')}
		</span>
	);
}

export default ComingSoonBadge;
