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
		<div className="space-y-4">
			{/* All inputs in one line on desktop */}
			<div className="grid grid-cols-1 lg:grid-cols-12 gap-3">
				{/* Long URL Input - Takes most space */}
				<div className="lg:col-span-7">
					<label htmlFor="long-url" className="sr-only">
						{__('Destination URL')}
					</label>
					<Input
						type="url"
						id="long-url"
						value={destinationUrl}
						onChange={(e) => setDestinationUrl(e.target.value)}
						placeholder={__('Enter long link here...')}
						icon={LinkIcon}
						autoCapitalize="off"
						spellCheck={false}
						required
						className="h-11"
					/>
				</div>

				{/* Alias Input */}
				<div className="lg:col-span-3">
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
						className="h-11"
					/>
				</div>

				{/* Shorten Button */}
				<div className="lg:col-span-2">
					<Button
						type="submit"
						size="md"
						icon={Scissors}
						loading={isLoading}
						className="w-full h-11 bg-accent hover:bg-accent/90 text-white font-medium shadow-sm"
					>
						<span className="hidden xl:inline">
							{isLoading ? __('Shortening...') : __('Shorten')}
						</span>
					</Button>
				</div>
			</div>
		</div>
	);
};

export default MainInputs;
