import { __ } from '@/i18n';

const Header = () => {
	return (
		<div>
			<h1 className="text-2xl font-bold text-heading mb-1">
				{__('Import')}
			</h1>
			<p className="text-sm text-text-muted">
				{__('Import links from files, pasted URLs, or API payloads')}
			</p>
		</div>
	);
};

export default Header;
