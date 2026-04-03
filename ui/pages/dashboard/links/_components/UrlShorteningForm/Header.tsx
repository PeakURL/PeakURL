// @ts-nocheck
import { Scissors } from 'lucide-react';
import { __ } from '@/i18n';

const Header = () => {
	return (
		<div className="flex items-center gap-4 mb-6">
			<div className="w-12 h-12 rounded-xl bg-accent flex items-center justify-center shrink-0 shadow-md">
				<Scissors className="text-white" size={20} />
			</div>
			<div>
				<h3 className="text-lg font-bold text-heading">
					{__('Create Short Link')}
				</h3>
				<p className="text-sm text-text-muted">
					{__('Transform your long URL into a short, shareable link')}
				</p>
			</div>
		</div>
	);
};

export default Header;
