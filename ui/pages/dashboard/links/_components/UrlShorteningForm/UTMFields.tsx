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
	const isRtl = isDocumentRtl();
	return (
		<div>
			<label className="block text-sm font-medium text-heading mb-1.5">
				<span
					dir={isRtl ? 'rtl' : 'ltr'}
					className="inline-flex items-center gap-2"
				>
					<BarChart3 className="h-4 w-4 text-text-muted" />
					{__('UTM Parameters (Optional)')}
				</span>
			</label>
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
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
					className="md:col-span-2"
				/>
			</div>
		</div>
	);
};

export default UTMFields;
