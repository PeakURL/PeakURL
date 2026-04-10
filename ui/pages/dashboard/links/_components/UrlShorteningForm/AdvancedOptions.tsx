import SecurityFields from './SecurityFields';
import ExpirationFields from './ExpirationFields';
import UTMFields from './UTMFields';
import type { AdvancedOptionsProps } from '../types';

const AdvancedOptions = ({
	title,
	setTitle,
	password,
	setPassword,
	expirationDate,
	setExpirationDate,
	expirationTime,
	setExpirationTime,
	utmSource,
	setUtmSource,
	utmMedium,
	setUtmMedium,
	utmCampaign,
	setUtmCampaign,
	utmTerm,
	setUtmTerm,
	utmContent,
	setUtmContent,
}: AdvancedOptionsProps) => {
	return (
		<div className="links-form-advanced-panel">
			<SecurityFields
				title={title}
				setTitle={setTitle}
				password={password}
				setPassword={setPassword}
			/>
			<ExpirationFields
				expirationDate={expirationDate}
				setExpirationDate={setExpirationDate}
				expirationTime={expirationTime}
				setExpirationTime={setExpirationTime}
			/>
			<UTMFields
				utmSource={utmSource}
				setUtmSource={setUtmSource}
				utmMedium={utmMedium}
				setUtmMedium={setUtmMedium}
				utmCampaign={utmCampaign}
				setUtmCampaign={setUtmCampaign}
				utmTerm={utmTerm}
				setUtmTerm={setUtmTerm}
				utmContent={utmContent}
				setUtmContent={setUtmContent}
			/>
		</div>
	);
};

export default AdvancedOptions;
