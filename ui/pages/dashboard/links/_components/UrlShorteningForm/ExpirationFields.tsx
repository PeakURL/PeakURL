import { Input } from '@/components';
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
	const direction = isDocumentRtl() ? 'rtl' : 'ltr';
	return (
		<div>
			<label className="links-form-section-label">
				<span
					dir={direction}
					className="links-form-section-label-content"
				>
					<Calendar className="links-form-section-label-icon" />
					{__('Expiration Date (Optional)')}
				</span>
			</label>
			<div className="links-form-expiration-grid">
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
