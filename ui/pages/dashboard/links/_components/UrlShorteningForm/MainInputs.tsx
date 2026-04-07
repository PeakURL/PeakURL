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
						value={alias}
						onChange={(e) => setAlias(e.target.value)}
						placeholder={__('Alias (optional)')}
						icon={Tag}
						className="h-11"
					/>
				</div>

				{/* Shorten Button */}
				<div className="lg:col-span-2">
					<Button
						type="submit"
						size="md"
						className="w-full h-11 bg-accent hover:bg-accent/90 text-white font-medium shadow-sm"
						disabled={isLoading}
					>
						{isLoading ? (
							<>
								<svg
									className="animate-spin h-4 w-4"
									xmlns="http://www.w3.org/2000/svg"
									fill="none"
									viewBox="0 0 24 24"
								>
									<circle
										className="opacity-25"
										cx="12"
										cy="12"
										r="10"
										stroke="currentColor"
										strokeWidth="4"
									></circle>
									<path
										className="opacity-75"
										fill="currentColor"
										d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
									></path>
								</svg>
								<span className="hidden xl:inline">
									{__('Shortening...')}
								</span>
							</>
						) : (
							<>
								<Scissors size={16} />
								<span className="hidden xl:inline">
									{__('Shorten')}
								</span>
							</>
						)}
					</Button>
				</div>
			</div>
		</div>
	);
};

export default MainInputs;
