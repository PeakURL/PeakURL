import { __ } from '@/i18n';

const Header = () => {
	return (
		<div className="settings-page-header">
			<h1 className="settings-page-title">
				{__('Account Settings')}
			</h1>
			<p className="settings-page-description">
				{__('Manage your account preferences and configurations')}
			</p>
		</div>
	);
};

export default Header;
