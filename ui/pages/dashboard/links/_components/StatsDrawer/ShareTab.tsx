import { useState } from 'react';
import {
	Copy,
	Check,
	Users,
	Send,
	BriefcaseBusiness,
	Mail,
} from 'lucide-react';
import { __ } from '@/i18n';
import { copyToClipboard, getLinkDisplayTitle } from '@/utils';
import type { SharePlatform, ShareTabProps } from './types';

function ShareTab({ link, shortUrl }: ShareTabProps) {
	const [copied, setCopied] = useState(false);

	const handleCopy = async () => {
		try {
			await copyToClipboard(shortUrl);
			setCopied(true);
			setTimeout(() => setCopied(false), 2000);
		} catch (err) {
			console.error('Failed to copy:', err);
		}
	};

	const handleShare = (platform: SharePlatform) => {
		const url = encodeURIComponent(shortUrl);
		const title = encodeURIComponent(
			getLinkDisplayTitle(link.title, __('Check out this link'))
		);

		const shareUrls: Record<SharePlatform, string> = {
			facebook: `https://www.facebook.com/sharer/sharer.php?u=${url}`,
			twitter: `https://twitter.com/intent/tweet?url=${url}&text=${title}`,
			linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${url}`,
			email: `mailto:?subject=${title}&body=${url}`,
		};

		window.open(shareUrls[platform], '_blank', 'width=600,height=400');
	};

	return (
		<div className="space-y-6">
			{/* Short URL */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<label className="block text-xs font-medium text-text-muted mb-2">
					{__('Short URL')}
				</label>
				<div className="flex items-center gap-2">
					<code
						className="ltr-literal-value flex-1 font-mono text-sm text-accent bg-surface px-3 py-2 rounded-lg border border-stroke break-all"
					>
						{shortUrl}
					</code>
					<button
						onClick={handleCopy}
						className="p-2 rounded-lg bg-accent hover:bg-accent/90 text-white transition-all shrink-0"
						title={copied ? __('Copied!') : __('Copy')}
					>
						{copied ? (
							<Check className="w-4 h-4" />
						) : (
							<Copy className="w-4 h-4" />
						)}
					</button>
				</div>
			</div>

			{/* Destination URL */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<label className="block text-xs font-medium text-text-muted mb-2">
					{__('Destination URL')}
				</label>
				<div
					className="ltr-literal-value text-sm text-heading break-all"
				>
					{link.destinationUrl}
				</div>
			</div>

			{/* Quick Share */}
			<div className="bg-surface-alt border border-stroke rounded-lg p-4">
				<h3 className="text-sm font-semibold text-heading mb-4">
					{__('Quick Share')}
				</h3>
				<div className="grid grid-cols-2 gap-3">
					<button
						onClick={() => handleShare('facebook')}
						className="flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-[#1877F2] hover:bg-[#166FE5] text-white transition-all"
					>
						<Users className="w-5 h-5" />
						<span className="text-sm font-medium">
							{__('Facebook')}
						</span>
					</button>
					<button
						onClick={() => handleShare('twitter')}
						className="flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-[#1DA1F2] hover:bg-[#1A94DA] text-white transition-all"
					>
						<Send className="w-5 h-5" />
						<span className="text-sm font-medium">
							{__('Twitter')}
						</span>
					</button>
					<button
						onClick={() => handleShare('linkedin')}
						className="flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-[#0A66C2] hover:bg-[#095196] text-white transition-all"
					>
						<BriefcaseBusiness className="w-5 h-5" />
						<span className="text-sm font-medium">
							{__('LinkedIn')}
						</span>
					</button>
					<button
						onClick={() => handleShare('email')}
						className="flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-surface border border-stroke hover:bg-surface-alt text-heading transition-all"
					>
						<Mail className="w-5 h-5" />
						<span className="text-sm font-medium">
							{__('Email')}
						</span>
					</button>
				</div>
			</div>
		</div>
	);
}

export default ShareTab;
