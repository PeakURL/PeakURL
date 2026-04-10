import { Input } from '@/components/ui';
import { BarChart3 } from 'lucide-react';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { UTMFieldsProps } from '../types';

const UTMFields = ({
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
}: UTMFieldsProps) => {
	const direction = isDocumentRtl() ? 'rtl' : 'ltr';
	return (
		<div>
			<label className="links-form-section-label">
				<span
					dir={direction}
					className="links-form-section-label-content"
				>
					<BarChart3 className="links-form-section-label-icon" />
					{__('UTM Parameters (Optional)')}
				</span>
			</label>
			<div className="links-form-utm-grid">
				<Input
					type="text"
					valueDirection="ltr"
					value={utmSource}
					onChange={(e) => setUtmSource(e.target.value)}
					placeholder={__('utm_source (e.g., google)')}
					autoCapitalize="off"
					spellCheck={false}
				/>
				<Input
					type="text"
					valueDirection="ltr"
					value={utmMedium}
					onChange={(e) => setUtmMedium(e.target.value)}
					placeholder={__('utm_medium (e.g., email)')}
					autoCapitalize="off"
					spellCheck={false}
				/>
				<Input
					type="text"
					valueDirection="ltr"
					value={utmCampaign}
					onChange={(e) => setUtmCampaign(e.target.value)}
					placeholder={__('utm_campaign (e.g., sale)')}
					autoCapitalize="off"
					spellCheck={false}
				/>
				<Input
					type="text"
					valueDirection="ltr"
					value={utmTerm}
					onChange={(e) => setUtmTerm(e.target.value)}
					placeholder={__('utm_term (e.g., running)')}
					autoCapitalize="off"
					spellCheck={false}
				/>
				<Input
					type="text"
					valueDirection="ltr"
					value={utmContent}
					onChange={(e) => setUtmContent(e.target.value)}
					placeholder={__('utm_content (e.g., banner)')}
					autoCapitalize="off"
					spellCheck={false}
					className="links-form-utm-wide"
				/>
			</div>
		</div>
	);
};

export default UTMFields;
