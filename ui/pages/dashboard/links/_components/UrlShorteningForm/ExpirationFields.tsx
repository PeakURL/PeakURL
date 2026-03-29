// @ts-nocheck
import { Input } from '@/components/ui';
import { Calendar } from 'lucide-react';
import { getLocalDateValue } from '@/utils';

const ExpirationFields = ({
	expirationDate,
	setExpirationDate,
	expirationTime,
	setExpirationTime,
}) => {
	return (
		<div>
			<label className="block text-sm font-medium text-heading mb-1.5">
				<Calendar className="w-4 h-4 inline mr-2 text-text-muted" />
				Expiration (Optional)
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
