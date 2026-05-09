import SecurityFields from "./SecurityFields";
import ExpirationFields from "./ExpirationFields";
import UTMFields from "./UTMFields";
import SocialPreviewFields from "./SocialPreviewFields";
import type { AdvancedOptionsProps } from "../types";

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
	socialTitle,
	setSocialTitle,
	socialDescription,
	setSocialDescription,
	socialImageFile,
	setSocialImageFile,
	socialImagePreviewUrl,
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
			<SocialPreviewFields
				socialTitle={socialTitle}
				setSocialTitle={setSocialTitle}
				socialDescription={socialDescription}
				setSocialDescription={setSocialDescription}
				socialImageFile={socialImageFile}
				setSocialImageFile={setSocialImageFile}
				socialImagePreviewUrl={socialImagePreviewUrl}
			/>
		</div>
	);
};

export default AdvancedOptions;
