import { useState } from "react";
import {
	AlertCircle,
	BookOpen,
	Check,
	Code,
	Copy,
	Layers,
	PenTool,
	Plus,
	Shield,
	ShieldCheck,
	Trash2,
} from "lucide-react";

import { Button, Input, McpIcon } from "@/components";
import type { NotificationContextValue } from "@/components";
import {
	DocumentationLink,
	TabHeader,
} from "@/pages/dashboard/settings/components/TabHeader";
import { useTemporaryState } from "@/hooks";
import {
	useGetMcpSettingsQuery,
	useGetMcpToolsQuery,
	useUpdateMcpSettingsMutation,
} from "@/state/slices/api";
import { __, sprintf } from "@/i18n";
import { copyToClipboard as writeToClipboard } from "@/shared/browser";
import { getErrorMessage } from "@/shared/errors";

import McpConnectedClients from "./McpConnectedClients";
import McpSetupDrawer from "./McpSetupDrawer";
import McpToolCatalogDrawer from "./McpToolCatalogDrawer";

export interface McpTabProps {
	notification?: Pick<NotificationContextValue, "error" | "success"> | null;
}

export function McpTab({ notification }: McpTabProps) {
	const { data: settingsData, isLoading: isLoadingSettings } =
		useGetMcpSettingsQuery();
	const { data: toolsData, isLoading: isLoadingTools } =
		useGetMcpToolsQuery();
	const [updateSettings, { isLoading: isUpdating }] =
		useUpdateMcpSettingsMutation();

	const [isToolCatalogOpen, setIsToolCatalogOpen] = useState(false);
	const [isSetupGuideOpen, setIsSetupGuideOpen] = useState(false);
	const [newRedirectUri, setNewRedirectUri] = useState("");
	const [uriError, setUriError] = useState<string | null>(null);
	const [copiedEndpoint, setCopiedEndpoint] = useTemporaryState(false);

	const settings = settingsData?.data;
	const tools = toolsData?.data?.tools || [];

	const isEnabled = Boolean(settings?.enabled);
	const isWriteEnabled = Boolean(settings?.writeEnabled);
	const endpoint = settings?.endpoint || "";
	const isHttps = settings?.isHttps ?? false;
	const allowedRedirectUris = settings?.allowedRedirectUris || [];
	const protocolVersion = settings?.protocolVersion || "2025-03-26";

	const handleCopyEndpoint = async () => {
		if (!endpoint) {
			return;
		}
		try {
			await writeToClipboard(endpoint);
			setCopiedEndpoint(true, 2000);
		} catch {
			// Fallback handled by writeToClipboard.
		}
	};

	const handleToggleEnabled = async () => {
		try {
			await updateSettings({ enabled: !isEnabled }).unwrap();
			notification?.success(
				!isEnabled
					? __("MCP Server Enabled")
					: __("MCP Server Disabled"),
				!isEnabled
					? __(
							"PeakURL MCP Server is active and accepting connections."
						)
					: __(
							"PeakURL MCP Server is disabled. Incoming requests will receive 503 Service Unavailable."
						)
			);
		} catch (err) {
			notification?.error(
				__("Failed to update MCP settings"),
				getErrorMessage(
					err,
					__("An error occurred while updating the MCP status.")
				)
			);
		}
	};

	const handleToggleWrite = async () => {
		try {
			await updateSettings({ writeEnabled: !isWriteEnabled }).unwrap();
			notification?.success(
				!isWriteEnabled
					? __("Write Tools Enabled")
					: __("Write Tools Disabled"),
				!isWriteEnabled
					? __(
							"AI clients can now create and update short links if authorized."
						)
					: __(
							"Write operations disabled. MCP clients are restricted to read-only queries."
						)
			);
		} catch (err) {
			notification?.error(
				__("Failed to update MCP settings"),
				getErrorMessage(
					err,
					__(
						"An error occurred while updating write tool permissions."
					)
				)
			);
		}
	};

	const handleAddRedirectUri = async () => {
		const uri = newRedirectUri.trim();
		setUriError(null);

		if (!uri) {
			setUriError(__("Please enter a valid redirect URI."));
			return;
		}

		try {
			const parsed = new URL(uri);
			const isHttpLocal =
				parsed.protocol === "http:" &&
				(parsed.hostname === "localhost" ||
					parsed.hostname === "127.0.0.1" ||
					parsed.hostname === "[::1]");

			if (parsed.protocol !== "https:" && !isHttpLocal) {
				setUriError(
					__(
						"Redirect URIs must use HTTPS, except for localhost development."
					)
				);
				return;
			}
		} catch {
			setUriError(
				__("Invalid URL format. Include the protocol (https://).")
			);
			return;
		}

		if (allowedRedirectUris.includes(uri)) {
			setUriError(__("This redirect URI is already registered."));
			return;
		}

		const updatedUris = [...allowedRedirectUris, uri];

		try {
			await updateSettings({ allowedRedirectUris: updatedUris }).unwrap();
			setNewRedirectUri("");
			notification?.success(
				__("Redirect URI Added"),
				sprintf(
					/* translators: %s: URI */
					__(
						"Registered '%s' for OAuth 2.0 PKCE client authorization."
					),
					uri
				)
			);
		} catch (err) {
			notification?.error(
				__("Failed to add redirect URI"),
				getErrorMessage(
					err,
					__("An error occurred while registering the redirect URI.")
				)
			);
		}
	};

	const handleRemoveRedirectUri = async (uriToRemove: string) => {
		const updatedUris = allowedRedirectUris.filter(
			(u) => u !== uriToRemove
		);

		try {
			await updateSettings({ allowedRedirectUris: updatedUris }).unwrap();
			notification?.success(
				__("Redirect URI Removed"),
				sprintf(
					/* translators: %s: URI */
					__("Removed '%s' from registered OAuth redirect URIs."),
					uriToRemove
				)
			);
		} catch (err) {
			notification?.error(
				__("Failed to remove redirect URI"),
				getErrorMessage(
					err,
					__("An error occurred while removing the redirect URI.")
				)
			);
		}
	};

	return (
		<div className="mcp-tab-container">
			<section className="settings-section">
				<TabHeader
					icon={McpIcon}
					title={__("Model Context Protocol (MCP)")}
					description={__(
						"Connect AI clients directly to PeakURL using Model Context Protocol (MCP) and OAuth 2.0 PKCE."
					)}
					actions={
						<DocumentationLink
							href="https://peakurl.org/docs/mcp"
							label={__("MCP Documentation")}
						/>
					}
				/>
			</section>

			{/* Server Configuration Card */}
			<div className="mcp-card">
				<div className="mcp-card-header">
					<div className="mcp-card-title-group">
						<div className="mcp-card-title-row">
							<McpIcon
								size={18}
								className="text-accent shrink-0"
							/>
							<h3 className="mcp-card-title">
								{__("PeakURL MCP Server")}
							</h3>
						</div>
						<p className="mcp-card-description">
							{__(
								"PeakURL exposes a standard Model Context Protocol streamable endpoint for Claude Desktop, ChatGPT, Cursor, Windsurf, and custom AI clients."
							)}
						</p>
					</div>

					<div className="mcp-card-actions">
						<Button
							variant="outline"
							size="sm"
							onClick={() => setIsToolCatalogOpen(true)}
							className="mcp-action-btn"
						>
							<Code size={13} />
							{sprintf(
								/* translators: %d: tool count */
								__("Registered Tools (%d)"),
								tools.length
							)}
						</Button>
						<Button
							variant="outline"
							size="sm"
							onClick={() => setIsSetupGuideOpen(true)}
							className="mcp-action-btn"
						>
							<BookOpen size={13} />
							{__("Setup Guide")}
						</Button>
					</div>
				</div>

				<div className="mcp-card-body">
					{/* Endpoint Display */}
					<div className="mcp-endpoint-section">
						<label className="mcp-field-label">
							{__("Streamable MCP Endpoint")}
						</label>
						<div className="mcp-endpoint-row">
							<code className="mcp-endpoint-code font-mono">
								{endpoint || __("Loading endpoint...")}
							</code>
							<Button
								variant="outline"
								size="sm"
								onClick={handleCopyEndpoint}
								disabled={!endpoint}
								className="mcp-copy-endpoint-btn"
								aria-label={__("Copy MCP endpoint URL")}
							>
								{copiedEndpoint ? (
									<>
										<Check
											size={13}
											className="text-emerald-500"
										/>
										{__("Copied")}
									</>
								) : (
									<>
										<Copy size={13} />
										{__("Copy")}
									</>
								)}
							</Button>
						</div>

						<div className="mcp-endpoint-meta">
							<span className="mcp-badge mcp-badge-neutral">
								<Layers size={11} />
								{sprintf(__("Protocol: %s"), protocolVersion)}
							</span>

							{isHttps ? (
								<span className="mcp-badge mcp-badge-success">
									<ShieldCheck size={11} />
									{__("HTTPS enabled")}
								</span>
							) : (
								<span className="mcp-badge mcp-badge-warning">
									<AlertCircle size={11} />
									{__(
										"HTTP active — Local development only (OAuth requires HTTPS)"
									)}
								</span>
							)}
						</div>
					</div>

					{/* Toggles */}
					<div className="mcp-toggles-grid">
						{/* Master Enable/Disable */}
						<div className="mcp-toggle-card">
							<div className="mcp-toggle-info">
								<span className="mcp-toggle-label">
									{__("Enable MCP Server")}
								</span>
								<p className="mcp-toggle-desc">
									{__(
										"Accept JSON-RPC 2.0 tool discovery and execution requests from authorized AI clients."
									)}
								</p>
							</div>
							<input
								type="checkbox"
								id="mcp-enable-toggle"
								className="integrations-tab-event-checkbox cursor-pointer"
								checked={isEnabled}
								onChange={handleToggleEnabled}
								disabled={isLoadingSettings || isUpdating}
								aria-label={__("Enable MCP Server")}
							/>
						</div>

						{/* Write Operations */}
						<div className="mcp-toggle-card">
							<div className="mcp-toggle-info">
								<div className="flex items-center gap-1.5">
									<span className="mcp-toggle-label">
										{__("Allow Write Tools")}
									</span>
									<span className="mcp-badge mcp-badge-neutral">
										<PenTool size={10} />
										{__("create & update")}
									</span>
								</div>
								<p className="mcp-toggle-desc">
									{__(
										"When disabled, AI clients are restricted strictly to read-only queries (analytics, link lookups, activity feeds)."
									)}
								</p>
							</div>
							<input
								type="checkbox"
								id="mcp-write-toggle"
								className="integrations-tab-event-checkbox cursor-pointer"
								checked={isWriteEnabled}
								onChange={handleToggleWrite}
								disabled={
									isLoadingSettings ||
									isUpdating ||
									!isEnabled
								}
								aria-label={__("Allow Write Tools")}
							/>
						</div>
					</div>
				</div>
			</div>

			{/* Allowed OAuth Redirect URIs Card */}
			<div className="mcp-card">
				<div className="mcp-card-header">
					<div className="mcp-card-title-group">
						<div className="mcp-card-title-row">
							<Shield
								size={18}
								className="text-accent shrink-0"
							/>
							<h3 className="mcp-card-title">
								{__("Allowed OAuth Redirect URIs")}
							</h3>
						</div>
						<p className="mcp-card-description">
							{__(
								"For security, OAuth 2.0 PKCE consent flows will only redirect to verified URIs matching this allowlist."
							)}
						</p>
					</div>
				</div>

				<div className="mcp-card-body">
					<div className="mcp-uri-input-group">
						<div className="flex-1 min-w-0">
							<Input
								type="url"
								value={newRedirectUri}
								onChange={(e) => {
									setNewRedirectUri(e.target.value);
									if (uriError) {
										setUriError(null);
									}
								}}
								placeholder="https://chatgpt.com/api/actions/cb/..."
								error={uriError || undefined}
								aria-label={__("New redirect URI")}
							/>
						</div>
						<Button
							variant="primary"
							onClick={handleAddRedirectUri}
							disabled={isUpdating || !newRedirectUri.trim()}
							className="shrink-0"
						>
							<Plus size={14} />
							{__("Add URI")}
						</Button>
					</div>

					<div className="mcp-uri-list">
						{allowedRedirectUris.length === 0 ? (
							<p className="text-xs text-text-muted py-2">
								{__(
									"No redirect URIs configured. Add client callbacks (e.g. ChatGPT, Claude) to enable authorization."
								)}
							</p>
						) : (
							allowedRedirectUris.map((uri) => (
								<div key={uri} className="mcp-uri-item">
									<code className="mcp-uri-text font-mono">
										{uri}
									</code>
									<button
										type="button"
										onClick={() =>
											handleRemoveRedirectUri(uri)
										}
										disabled={isUpdating}
										className="mcp-uri-remove-btn"
										aria-label={sprintf(
											/* translators: %s: URI */
											__("Remove %s"),
											uri
										)}
									>
										<Trash2 size={13} />
									</button>
								</div>
							))
						)}
					</div>
				</div>
			</div>

			{/* Connected Clients Section */}
			<McpConnectedClients notification={notification} />

			{/* Registered Tools Drawer */}
			<McpToolCatalogDrawer
				isOpen={isToolCatalogOpen}
				onClose={() => setIsToolCatalogOpen(false)}
				tools={tools}
				isLoading={isLoadingTools}
			/>

			{/* Setup Guide Drawer */}
			<McpSetupDrawer
				isOpen={isSetupGuideOpen}
				onClose={() => setIsSetupGuideOpen(false)}
				endpoint={endpoint}
				isHttps={isHttps}
			/>
		</div>
	);
}

export default McpTab;
