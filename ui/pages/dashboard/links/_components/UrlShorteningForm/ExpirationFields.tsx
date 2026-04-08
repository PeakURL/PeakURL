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
				<Calendar
					className={`inline h-4 w-4 text-text-muted ${
						isRtl ? 'ml-2' : 'mr-2'
					}`}
				/>
				{__('Expiration Date (Optional)')}
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
