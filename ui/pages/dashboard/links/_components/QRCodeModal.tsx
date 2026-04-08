import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { X, Download, Copy, Check } from 'lucide-react';
import { useState, useEffect } from 'react';
import QRCode from 'qrcode';
import { buildShortUrl, copyToClipboard } from '@/utils';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { QRCodeModalProps } from './types';

function QRCodeModal({ open, setOpen, link }: QRCodeModalProps) {
	const isRtl = isDocumentRtl();
	const [qrDataUrl, setQrDataUrl] = useState('');
	const [copied, setCopied] = useState(false);
	const shortUrl = link ? buildShortUrl(link) : '';

	useEffect(() => {
		if (link && open && shortUrl) {
			QRCode.toDataURL(
				shortUrl,
				{
					width: 400,
					margin: 2,
					color: {
						dark: '#000000',
						light: '#FFFFFF',
					},
				},
				(err: Error | null | undefined, url: string) => {
					if (err) {
						console.error('QR Code generation error:', err);
						return;
					}
					setQrDataUrl(url);
				}
			);
		}
	}, [link, open, shortUrl]);

	const handleDownload = () => {
		if (!qrDataUrl) return;

		const downloadLink = document.createElement('a');
		downloadLink.href = qrDataUrl;
		downloadLink.download = `qr-code-${link?.alias || link?.shortCode || 'download'}.png`;
		document.body.appendChild(downloadLink);
		downloadLink.click();
		document.body.removeChild(downloadLink);
	};

	const handleCopyUrl = async () => {
		try {
			await copyToClipboard(shortUrl);
			setCopied(true);
			setTimeout(() => setCopied(false), 2000);
		} catch (err) {
			console.error('Failed to copy URL:', err);
		}
	};

	if (!link) return null;

	return (
		<Dialog open={open} onClose={setOpen} className="relative z-50">
			<div className="fixed inset-0 bg-black/30" aria-hidden="true" />

			<div className="fixed inset-0 flex items-center justify-center p-4">
				<DialogPanel
					dir={isRtl ? 'rtl' : 'ltr'}
					className="logical-text-start mx-auto max-w-md w-full rounded-lg bg-surface shadow-xl"
				>
					{/* Header */}
					<div className="flex items-center justify-between p-6 border-b border-stroke">
						<DialogTitle className="text-lg font-semibold text-heading">
							{__('QR Code')}
						</DialogTitle>
						<button
							onClick={() => setOpen(false)}
							className="rounded-lg text-text-muted hover:text-heading hover:bg-surface-alt p-2 transition-all"
						>
							<X className="w-5 h-5" />
						</button>
					</div>

					{/* Content */}
					<div className="p-6 space-y-4">
						{/* QR Code Display */}
						<div className="flex justify-center bg-white rounded-lg p-6">
							{qrDataUrl ? (
								<img
									src={qrDataUrl}
									alt="QR Code"
									width={256}
									height={256}
									loading="lazy"
									className="w-64 h-64"
								/>
							) : (
								<div className="w-64 h-64 flex items-center justify-center">
									<div className="animate-spin rounded-full h-12 w-12 border-b-2 border-accent"></div>
								</div>
							)}
						</div>

						{/* Link Info */}
						<div className="bg-surface-alt border border-stroke rounded-lg p-4">
							<p className="text-xs font-medium text-text-muted mb-1">
								{__('Short URL')}
							</p>
							<code
								className="ltr-literal-value text-sm text-accent break-all"
							>
								{shortUrl}
							</code>
						</div>

						{/* Action Buttons */}
						<div className="flex gap-3">
							<button
								onClick={handleDownload}
								disabled={!qrDataUrl}
								className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-accent hover:bg-accent/90 disabled:bg-gray-400 text-white rounded-lg transition-all font-medium"
							>
								<Download className="w-4 h-4" />
								{__('Download')}
							</button>
							<button
								onClick={handleCopyUrl}
								className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-surface border border-stroke hover:bg-surface-alt text-heading rounded-lg transition-all font-medium"
							>
								{copied ? (
									<>
										<Check className="w-4 h-4 text-success" />
										{__('Copied!')}
									</>
								) : (
									<>
										<Copy className="w-4 h-4" />
										{__('Copy')}
									</>
								)}
							</button>
						</div>
					</div>
				</DialogPanel>
			</div>
		</Dialog>
	);
}

export default QRCodeModal;
