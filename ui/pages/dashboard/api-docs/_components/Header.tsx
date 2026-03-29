// @ts-nocheck
import { PEAKURL_NAME } from '@constants';

const Header = () => {
	return (
		<div className="mb-6">
			<h1 className="text-2xl font-bold text-heading mb-1">
				API Documentation
			</h1>
			<p className="text-sm text-text-muted">
				Complete reference for the {PEAKURL_NAME} API
			</p>
		</div>
	);
};

export default Header;
