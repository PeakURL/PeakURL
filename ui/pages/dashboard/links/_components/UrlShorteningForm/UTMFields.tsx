// @ts-nocheck
import { Input } from '@/components/ui';
import { BarChart3 } from 'lucide-react';

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
}) => {
	return (
		<div>
			<label className="block text-sm font-medium text-heading mb-1.5">
				<BarChart3 className="w-4 h-4 inline mr-2 text-text-muted" />
				UTM Parameters (Optional)
			</label>
			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				<Input
					type="text"
					value={utmSource}
					onChange={(e) => setUtmSource(e.target.value)}
					placeholder="utm_source (e.g., google)"
				/>
				<Input
					type="text"
					value={utmMedium}
					onChange={(e) => setUtmMedium(e.target.value)}
					placeholder="utm_medium (e.g., email)"
				/>
				<Input
					type="text"
					value={utmCampaign}
					onChange={(e) => setUtmCampaign(e.target.value)}
					placeholder="utm_campaign (e.g., sale)"
				/>
				<Input
					type="text"
					value={utmTerm}
					onChange={(e) => setUtmTerm(e.target.value)}
					placeholder="utm_term (e.g., running)"
				/>
				<Input
					type="text"
					value={utmContent}
					onChange={(e) => setUtmContent(e.target.value)}
					placeholder="utm_content (e.g., banner)"
					className="md:col-span-2"
				/>
			</div>
		</div>
	);
};

export default UTMFields;
