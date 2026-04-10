import { Button, Input } from '@/components/ui';
import { Link as LinkIcon, Tag, Scissors } from 'lucide-react';
import { __ } from '@/i18n';
import type { MainInputsProps } from '../types';

const MainInputs = ({
	destinationUrl,
	setDestinationUrl,
	alias,
	setAlias,
	isLoading,
}: MainInputsProps) => {
	return (
		<div className="links-form-main">
			{/* All inputs in one line on desktop */}
			<div className="links-form-main-grid">
				{/* Long URL Input - Takes most space */}
				<div className="links-form-main-destination">
					<label htmlFor="long-url" className="sr-only">
						{__('Destination URL')}
					</label>
					<Input
						type="url"
						id="long-url"
						valueDirection="ltr"
						value={destinationUrl}
						onChange={(e) => setDestinationUrl(e.target.value)}
						placeholder={__('Enter long link here...')}
						icon={LinkIcon}
						autoCapitalize="off"
						spellCheck={false}
						required
						className="links-form-main-input"
					/>
				</div>

				{/* Alias Input */}
				<div className="links-form-main-alias">
					<label htmlFor="alias" className="sr-only">
						{__('Alias (Optional)')}
					</label>
					<Input
						type="text"
						id="alias"
						valueDirection="ltr"
						value={alias}
						onChange={(e) => setAlias(e.target.value)}
						placeholder={__('Alias (optional)')}
						icon={Tag}
						autoCapitalize="off"
						spellCheck={false}
						className="links-form-main-input"
					/>
				</div>

				{/* Shorten Button */}
				<div className="links-form-main-submit">
					<Button
						type="submit"
						size="md"
						icon={Scissors}
						loading={isLoading}
						className="links-form-main-button"
					>
						<span className="links-form-main-button-label">
							{isLoading ? __('Shortening...') : __('Shorten')}
						</span>
					</Button>
				</div>
			</div>
		</div>
	);
};

export default MainInputs;
