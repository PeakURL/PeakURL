import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';

const Header = () => {
	const isRtl = isDocumentRtl();

	return (
		<div className={isRtl ? 'text-right' : 'text-left'}>
			<h1 className="text-2xl font-bold text-heading mb-1">
				{__('Account Settings')}
			</h1>
			<p className="text-sm text-text-muted">
				{__('Manage your account preferences and configurations')}
			</p>
		</div>
	);
};

export default Header;
