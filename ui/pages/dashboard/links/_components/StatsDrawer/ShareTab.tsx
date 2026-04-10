import { useState } from 'react';
import {
	Copy,
	Check,
	Users,
	Send,
	BriefcaseBusiness,
	Mail,
} from 'lucide-react';
import { ReadOnlyValueBlock } from '@/components/ui';
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
		<div className="links-share-tab">
			{/* Short URL */}
			<div className="links-share-panel">
				<label className="links-share-label">
					{__('Short URL')}
				</label>
				<ReadOnlyValueBlock
					value={shortUrl}
					onCopy={handleCopy}
					copyButtonLabel={copied ? __('Copied!') : __('Copy')}
					copyButtonClassName="rounded-lg bg-accent p-2 text-white hover:bg-accent/90 hover:text-white"
					copyButtonContent={
						copied ? (
							<Check className="h-4 w-4" />
						) : (
							<Copy className="h-4 w-4" />
						)
					}
					className="links-share-readonly"
					valueClassName="text-accent"
				/>
			</div>

			{/* Destination URL */}
			<div className="links-share-panel">
				<label className="links-share-label">
					{__('Destination URL')}
				</label>
				<ReadOnlyValueBlock
					value={link.destinationUrl}
					className="links-readonly-reset"
					monospace={false}
					valueClassName="text-heading"
				/>
			</div>

			{/* Quick Share */}
			<div className="links-share-panel">
				<h3 className="links-share-title">
					{__('Quick Share')}
				</h3>
				<div className="links-share-grid">
					<button
						onClick={() => handleShare('facebook')}
						className="links-share-button links-share-button-facebook"
					>
						<Users className="w-5 h-5" />
						<span className="links-share-button-label">
							{__('Facebook')}
						</span>
					</button>
					<button
						onClick={() => handleShare('twitter')}
						className="links-share-button links-share-button-twitter"
					>
						<Send className="w-5 h-5" />
						<span className="links-share-button-label">
							{__('Twitter')}
						</span>
					</button>
					<button
						onClick={() => handleShare('linkedin')}
						className="links-share-button links-share-button-linkedin"
					>
						<BriefcaseBusiness className="w-5 h-5" />
						<span className="links-share-button-label">
							{__('LinkedIn')}
						</span>
					</button>
					<button
						onClick={() => handleShare('email')}
						className="links-share-button links-share-button-email"
					>
						<Mail className="w-5 h-5" />
						<span className="links-share-button-label">
							{__('Email')}
						</span>
					</button>
				</div>
			</div>
		</div>
	);
}

export default ShareTab;
