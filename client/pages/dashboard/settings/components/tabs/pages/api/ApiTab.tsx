import { Plus, Info, Key, Copy, Globe, KeyRound } from "lucide-react";

import { Button, Input } from "@/components";
import { DocumentationLink } from "@/pages/dashboard/settings/components/TabHeader";
import { SectionTitle } from "@/pages/dashboard/settings/components/SectionTitle";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { ApiKeyItem } from "./ApiKeyItem";

import type { ApiTabProps } from "../types";

function ApiTab({
	user,
	baseApiUrl,
	copyToClipboard,
	isGeneratingKey,
	isDeletingKey,
	onDeleteKey,
	setShowCreateModal,
}: ApiTabProps) {
	const direction = isDocumentRtl() ? "rtl" : "ltr";
	return (
		<div className="settings-api">
			{baseApiUrl && (
				<section className="settings-section settings-api-endpoint-section">
					<div className="settings-section-header settings-section-heading">
						<div className="settings-section-copy">
							<SectionTitle icon={Globe}>
								{__("Base API URL")}
							</SectionTitle>
							<p className="settings-group-description">
								{__(
									"Use this endpoint with WordPress, browser extensions, scripts, and other API clients."
								)}
							</p>
						</div>
						<DocumentationLink href="https://go.peakurl.org/d373f6" />
					</div>
					<div className="settings-api-endpoint">
						<Input
							aria-label={__("Base API URL")}
							value={baseApiUrl}
							valueDirection="ltr"
							readOnly
							className="settings-api-endpoint-value"
						/>
						<button
							type="button"
							className="settings-api-endpoint-copy-button"
							aria-label={__("Copy API URL")}
							title={__("Copy API URL")}
							onClick={() =>
								copyToClipboard(
									baseApiUrl,
									__("Base API URL copied to clipboard")
								)
							}
						>
							<Copy size={18} aria-hidden="true" />
						</button>
					</div>
				</section>
			)}

			<section className="settings-section">
				<div className="settings-section-header settings-section-heading">
					<div className="settings-section-copy">
						<SectionTitle icon={KeyRound}>
							{__("API Keys")}
						</SectionTitle>
						<p className="settings-group-description">
							{__(
								"Create separate keys for your scripts and integrations."
							)}
						</p>
					</div>
					<Button
						size="sm"
						icon={Plus}
						loading={isGeneratingKey}
						onClick={() => setShowCreateModal(true)}
					>
						{__("Create New Key")}
					</Button>
				</div>

				{user?.apiKeys && user.apiKeys.length > 0 ? (
					<div className="settings-api-list-wrap">
						<ul
							className="settings-api-keys-list"
							aria-label={__("Saved API keys")}
							dir={direction}
						>
							{user.apiKeys.map((apiKey) => (
								<ApiKeyItem
									key={apiKey.id}
									apiKey={apiKey}
									isDeleting={isDeletingKey}
									onDelete={onDeleteKey}
								/>
							))}
						</ul>
					</div>
				) : (
					<div className="settings-api-empty">
						<div className="settings-api-empty-icon">
							<Key size={22} aria-hidden="true" />
						</div>
						<div>
							<h3 className="settings-api-empty-title">
								{__("No API keys yet")}
							</h3>
							<p className="settings-api-empty-text">
								{__(
									"Create your first key to connect an integration or use the API."
								)}
							</p>
						</div>
					</div>
				)}
			</section>

			<div className="settings-notice settings-notice-info">
				<div className="settings-notice-layout">
					<Info size={18} className="settings-notice-icon" />
					<div className="settings-notice-content">
						<p className="settings-notice-title">
							{__("Keep your API keys secure")}
						</p>
						<p className="settings-notice-text">
							{__(
								"PeakURL only shows the full token once, at creation time. Store it in a password manager or secret store, and revoke it immediately if you think it has been exposed."
							)}
						</p>
					</div>
				</div>
			</div>
		</div>
	);
}

export default ApiTab;
