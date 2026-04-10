import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { X, Download, Copy, Check } from 'lucide-react';
import { useState, useEffect } from 'react';
import QRCode from 'qrcode';
import { ReadOnlyValueBlock } from '@/components/ui';
import { buildShortUrl, copyToClipboard } from '@/utils';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import type { QRCodeModalProps } from './types';

function QRCodeModal({ open, setOpen, link }: QRCodeModalProps) {
	const direction = isDocumentRtl() ? 'rtl' : 'ltr';
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
			<div className="links-modal-backdrop" aria-hidden="true" />

			<div className="links-modal-shell">
				<DialogPanel
					dir={direction}
					className="links-modal-panel links-modal-panel-medium"
				>
					{/* Header */}
					<div className="links-modal-header">
						<DialogTitle className="links-modal-title">
							{__('QR Code')}
						</DialogTitle>
						<button
							onClick={() => setOpen(false)}
							className="links-modal-close"
						>
							<X className="links-modal-close-icon" />
						</button>
					</div>

					{/* Content */}
					<div className="links-modal-content">
						{/* QR Code Display */}
						<div className="links-qr-modal-code-wrap">
							{qrDataUrl ? (
								<img
									src={qrDataUrl}
									alt="QR Code"
									width={256}
									height={256}
									loading="lazy"
									className="links-qr-modal-code"
								/>
							) : (
								<div className="links-qr-modal-loading">
									<div className="links-qr-modal-spinner"></div>
								</div>
							)}
						</div>

						{/* Link Info */}
						<div className="links-qr-modal-summary">
							<p className="links-qr-modal-summary-label">
								{__('Short URL')}
							</p>
							<ReadOnlyValueBlock
								value={shortUrl}
								className="links-readonly-reset"
								valueClassName="text-accent"
							/>
						</div>

						{/* Action Buttons */}
						<div className="links-qr-modal-actions">
							<button
								onClick={handleDownload}
								disabled={!qrDataUrl}
								className="links-modal-button links-modal-button-primary"
							>
								<span className="links-modal-button-content">
									<Download className="links-modal-button-icon" />
									{__('Download')}
								</span>
							</button>
							<button
								onClick={handleCopyUrl}
								className="links-modal-button links-modal-button-secondary"
							>
								{copied ? (
									<span className="links-modal-button-content">
										<Check className="links-modal-button-icon links-qr-modal-copy-success" />
										{__('Copied!')}
									</span>
								) : (
									<span className="links-modal-button-content">
										<Copy className="links-modal-button-icon" />
										{__('Copy')}
									</span>
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
