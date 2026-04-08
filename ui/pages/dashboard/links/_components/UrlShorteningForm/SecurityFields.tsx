import { Input } from '@/components/ui';
import { Lock, Tags } from 'lucide-react';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { SecurityFieldsProps } from '../types';

const SecurityFields = ({
	title,
	setTitle,
	password,
	setPassword,
}: SecurityFieldsProps) => {
	const isRtl = isDocumentRtl();
	return (
		<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
			<div>
				<label
					htmlFor="title"
					className="block text-sm font-medium text-heading mb-1.5"
				>
					<span
						dir={isRtl ? 'rtl' : 'ltr'}
						className="inline-flex items-center gap-2"
					>
						<Tags className="h-4 w-4 text-text-muted" />
						{__('Link Title (Optional)')}
					</span>
				</label>
				<Input
					type="text"
					id="title"
					value={title}
					onChange={(e) => setTitle(e.target.value)}
					placeholder={__('My Awesome Link')}
				/>
			</div>

			<div>
				<label
					htmlFor="password"
					className="block text-sm font-medium text-heading mb-1.5"
				>
					<span
						dir={isRtl ? 'rtl' : 'ltr'}
						className="inline-flex items-center gap-2"
					>
						<Lock className="h-4 w-4 text-text-muted" />
						{__('Password Protection (Optional)')}
					</span>
				</label>
				<Input
					type="password"
					id="password"
					value={password}
					onChange={(e) => setPassword(e.target.value)}
					placeholder={__('Enter password')}
				/>
			</div>
		</div>
	);
};

export default SecurityFields;
