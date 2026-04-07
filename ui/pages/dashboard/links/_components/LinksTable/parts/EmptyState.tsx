import { Link2 } from 'lucide-react';
import { __ } from '@/i18n';

function EmptyState() {
	return (
		<div className="bg-surface rounded-lg border border-stroke p-16 text-center">
			<div className="w-16 h-16 rounded-full bg-accent/10 flex items-center justify-center mx-auto mb-4">
				<Link2 className="h-8 w-8 text-accent" />
			</div>
			<h3 className="text-base font-semibold text-heading mb-1">
				{__('No links yet')}
			</h3>
			<p className="text-sm text-text-muted">
				{__('Create your first shortened link to get started')}
			</p>
		</div>
	);
}

export default EmptyState;
