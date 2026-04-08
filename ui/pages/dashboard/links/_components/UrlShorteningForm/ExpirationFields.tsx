import { Input } from '@/components/ui';
import { Calendar } from 'lucide-react';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import { getLocalDateValue } from '@/utils';
import type { ExpirationFieldsProps } from '../types';

const ExpirationFields = ({
	expirationDate,
	setExpirationDate,
	expirationTime,
	setExpirationTime,
}: ExpirationFieldsProps) => {
	const isRtl = isDocumentRtl();
	return (
		<div>
			<label className="block text-sm font-medium text-heading mb-1.5">
				<span
					dir={isRtl ? 'rtl' : 'ltr'}
					className="inline-flex items-center gap-2"
				>
					<Calendar className="h-4 w-4 text-text-muted" />
					{__('Expiration Date (Optional)')}
				</span>
			</label>
			<div className="grid grid-cols-2 gap-3">
				<Input
					type="date"
					value={expirationDate}
					onChange={(e) => setExpirationDate(e.target.value)}
					min={getLocalDateValue()}
				/>
				<Input
					type="time"
					value={expirationTime}
					onChange={(e) => setExpirationTime(e.target.value)}
				/>
			</div>
		</div>
	);
};

export default ExpirationFields;
