import { DialogDescription, DialogTitle } from "@headlessui/react";
import { Check, Copy, Download, X } from "lucide-react";
import { useEffect, useState } from "react";
import QRCode from "qrcode";

import {
	Button,
	ReadOnlyValueBlock,
	Skeleton,
	useNotification,
} from "@/components";
import { useTemporaryState } from "@/hooks";
import { __ } from "@/i18n";
import { copyToClipboard } from "@/shared/browser";
import { getShortUrl } from "@/shared/links";

import { Modal } from "@/components/ui";
import type { QRCodeModalProps } from "../types";

const QR_EXPORT_SIZE = 400;
const QR_QUIET_ZONE = 4;

function QRCodeModal({ open, setOpen, link }: QRCodeModalProps) {
	const [result, setResult] = useState<{
		url: string;
		dataUrl: string | null;
	}>();
	const [attempt, setAttempt] = useState(0);
	const [copiedUrl, setCopiedUrl] = useTemporaryState("");
	const notification = useNotification();
	const shortUrl = link ? getShortUrl(link) : "";
	const current = result?.url === shortUrl ? result : undefined;
	const qrDataUrl = current?.dataUrl;
	const generationFailed = !shortUrl || current?.dataUrl === null;
	const copied = Boolean(shortUrl) && copiedUrl === shortUrl;

	useEffect(() => {
		if (!open || !shortUrl) return;

		let cancelled = false;
		void QRCode.toDataURL(shortUrl, {
			width: QR_EXPORT_SIZE,
			margin: QR_QUIET_ZONE,
		})
			.then((dataUrl) => {
				if (!cancelled) setResult({ url: shortUrl, dataUrl });
			})
			.catch(() => {
				if (!cancelled) setResult({ url: shortUrl, dataUrl: null });
			});

		return () => {
			cancelled = true;
		};
	}, [open, shortUrl, attempt]);

	const close = () => {
		setOpen(false);
		setCopiedUrl("", 0);
	};

	const download = () => {
		if (!qrDataUrl) return;
		const anchor = document.createElement("a");
		anchor.href = qrDataUrl;
		anchor.download = `qr-code-${link?.alias || link?.shortCode || "download"}.png`;
		anchor.click();
	};

	const copy = async () => {
		try {
			await copyToClipboard(shortUrl);
			setCopiedUrl(shortUrl, 2000);
		} catch {
			notification.error(__("Could not copy the short URL."));
		}
	};

	if (!link) return null;

	return (
		<Modal
			isOpen={open}
			onClose={close}
			panelClassName="links-modal-panel links-modal-panel-medium"
		>
			<div className="links-modal-header">
				<div className="min-w-0 space-y-1">
					<DialogTitle className="links-modal-title">
						{__("QR Code")}
					</DialogTitle>
					<DialogDescription className="text-sm text-text-muted">
						{__("Scan the code to open this short link.")}
					</DialogDescription>
				</div>
				<button
					type="button"
					onClick={close}
					className="links-modal-close"
					aria-label={__("Close")}
				>
					<X className="links-modal-close-icon" aria-hidden="true" />
				</button>
			</div>

			<div className="links-modal-content">
				<div className="links-qr-modal-preview">
					{qrDataUrl ? (
						<img
							src={qrDataUrl}
							alt={__("QR Code")}
							width={256}
							height={256}
							className="links-qr-modal-code"
						/>
					) : generationFailed ? (
						<div className="links-qr-modal-error">
							<p role="status">
								{__("The QR code could not be generated.")}
							</p>
							{shortUrl && (
								<Button
									size="sm"
									variant="secondary"
									onClick={() => {
										setResult(undefined);
										setAttempt((value) => value + 1);
									}}
								>
									{__("Try again")}
								</Button>
							)}
						</div>
					) : (
						<div
							className="links-qr-modal-placeholder"
							role="status"
						>
							<Skeleton
								className="size-full"
								aria-hidden="true"
							/>
							<span className="sr-only">
								{__("Generating QR code…")}
							</span>
						</div>
					)}
				</div>

				<div className="links-qr-modal-summary">
					<p className="links-qr-modal-summary-label">
						{__("Short URL")}
					</p>
					<ReadOnlyValueBlock
						value={shortUrl}
						className="links-readonly-reset"
						valueClassName="text-accent"
					/>
				</div>

				<div className="links-qr-modal-actions">
					<Button
						size="sm"
						icon={Download}
						disabled={!qrDataUrl}
						onClick={download}
					>
						{__("Download")}
					</Button>
					<Button
						size="sm"
						variant="secondary"
						icon={copied ? Check : Copy}
						disabled={!shortUrl}
						onClick={copy}
					>
						{copied ? __("Copied!") : __("Copy")}
					</Button>
				</div>
			</div>
		</Modal>
	);
}

export default QRCodeModal;
