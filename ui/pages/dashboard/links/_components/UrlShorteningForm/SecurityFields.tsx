import { Input } from '@/components';
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
	const direction = isDocumentRtl() ? 'rtl' : 'ltr';
	return (
		<div className="links-form-section-grid">
			<div>
				<label htmlFor="title" className="links-form-section-label">
					<span
						dir={direction}
						className="links-form-section-label-content"
					>
						<Tags className="links-form-section-label-icon" />
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
				<label htmlFor="password" className="links-form-section-label">
					<span
						dir={direction}
						className="links-form-section-label-content"
					>
						<Lock className="links-form-section-label-icon" />
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
