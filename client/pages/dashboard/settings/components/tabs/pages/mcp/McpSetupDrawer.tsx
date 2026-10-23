import { useState } from "react";
import { DialogBackdrop, DialogPanel, DialogTitle } from "@headlessui/react";
import { AlertCircle, Check, Copy, ShieldCheck, X } from "lucide-react";

import { DrawerDialog, Button, McpIcon } from "@/components";
import { useTemporaryState } from "@/hooks";
import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { copyToClipboard as writeToClipboard } from "@/shared/browser";
import { cn } from "@/shared/formatting";

interface McpSetupDrawerProps {
	isOpen: boolean;
	onClose: () => void;
	endpoint: string;
	isHttps: boolean;
}

type GuideTab = "claude" | "chatgpt" | "cursor" | "vscode";

export function McpSetupDrawer({
	isOpen,
	onClose,
	endpoint,
	isHttps,
}: McpSetupDrawerProps) {
	const isRtl = isDocumentRtl();
	const [activeTab, setActiveTab] = useState<GuideTab>("claude");
	const [copiedKey, setCopiedKey] = useTemporaryState<string | null>(null);

	const siteUrl = endpoint.replace(/\/api\/mcp$/, "");
	const authUrl = `${siteUrl}/oauth/authorize`;
	const tokenUrl = `${siteUrl}/api/mcp/oauth/token`;

	const handleCopy = async (key: string, text: string) => {
		try {
			await writeToClipboard(text);
			setCopiedKey(key, 2000);
		} catch {
			// Clipboard fallback handled by writeToClipboard.
		}
	};

	const claudeConfig = JSON.stringify(
		{
			mcpServers: {
				peakurl: {
					url: endpoint,
				},
			},
		},
		null,
		2
	);

	const cursorConfig = JSON.stringify(
		{
			mcpServers: {
				peakurl: {
					url: endpoint,
				},
			},
		},
		null,
		2
	);

	const vsCodeConfig = JSON.stringify(
		{
			"mcp.servers": {
				peakurl: {
					url: endpoint,
					type: "streamable-http",
				},
			},
		},
		null,
		2
	);

	return (
		<DrawerDialog open={isOpen} onClose={onClose}>
			<DialogBackdrop transition className="dialog-backdrop" />

			<div className="fixed inset-0 overflow-hidden">
				<div className="absolute inset-0 overflow-hidden">
					<div
						className={cn(
							"mcp-drawer-layout",
							isRtl
								? "mcp-drawer-layout-rtl"
								: "mcp-drawer-layout-ltr"
						)}
					>
						<DialogPanel
							transition
							className={cn(
								"drawer-panel drawer-transition mcp-setup-drawer-panel",
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							)}
						>
							{/* Drawer Header */}
							<div className="drawer-header">
								<div className="drawer-heading-group">
									<div className="drawer-title-icon">
										<McpIcon size={18} />
									</div>
									<div className="min-w-0 flex-1">
										<DialogTitle
											as="h3"
											className="drawer-title"
										>
											{__("PeakURL MCP Server Setup")}
										</DialogTitle>
										<p className="drawer-subtitle">
											{__(
												"Connect AI clients and development tools."
											)}
										</p>
									</div>
								</div>
								<button
									type="button"
									onClick={onClose}
									className="drawer-icon-button"
									aria-label={__("Close panel")}
								>
									<X className="h-4 w-4" />
								</button>
							</div>

							{/* Drawer Content */}
							<div className="drawer-content p-4 sm:p-6 space-y-5">
								{!isHttps && (
									<div className="mcp-guide-banner mcp-guide-banner-warning">
										<AlertCircle
											size={16}
											className="shrink-0"
										/>
										<div className="text-xs">
											<strong>
												{__(
													"HTTP Connection Detected:"
												)}
											</strong>{" "}
											{__(
												"Local development works over plain HTTP. For production deployments and OAuth 2.0 PKCE integrations (such as ChatGPT), your PeakURL domain must use HTTPS."
											)}
										</div>
									</div>
								)}

								{/* Client Selection Tabs */}
								<div className="mcp-guide-nav">
									<button
										type="button"
										onClick={() => setActiveTab("claude")}
										className={cn(
											"mcp-guide-nav-tab",
											activeTab === "claude" &&
												"mcp-guide-nav-tab-active"
										)}
									>
										{__("Claude Desktop")}
									</button>
									<button
										type="button"
										onClick={() => setActiveTab("chatgpt")}
										className={cn(
											"mcp-guide-nav-tab",
											activeTab === "chatgpt" &&
												"mcp-guide-nav-tab-active"
										)}
									>
										{__("ChatGPT")}
									</button>
									<button
										type="button"
										onClick={() => setActiveTab("cursor")}
										className={cn(
											"mcp-guide-nav-tab",
											activeTab === "cursor" &&
												"mcp-guide-nav-tab-active"
										)}
									>
										{__("Cursor / Windsurf")}
									</button>
									<button
										type="button"
										onClick={() => setActiveTab("vscode")}
										className={cn(
											"mcp-guide-nav-tab",
											activeTab === "vscode" &&
												"mcp-guide-nav-tab-active"
										)}
									>
										{__("VS Code")}
									</button>
								</div>

								{/* Claude Desktop Guide */}
								{activeTab === "claude" && (
									<div className="mcp-guide-tab-content">
										<h4 className="mcp-guide-tab-title">
											{__(
												"Configuring Claude Desktop for PeakURL"
											)}
										</h4>
										<p className="mcp-guide-tab-desc">
											{__(
												"Add PeakURL's streamable HTTP endpoint to your Claude Desktop configuration file."
											)}
										</p>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												1
											</span>
											<div className="mcp-guide-step-body">
												<p className="font-medium text-xs text-heading">
													{__(
														"Open Claude Desktop configuration:"
													)}
												</p>
												<ul className="mcp-guide-path-list font-mono text-[11px] text-text-muted">
													<li>
														macOS:{" "}
														<code>
															~/Library/Application
															Support/Claude/claude_desktop_config.json
														</code>
													</li>
													<li>
														Windows:{" "}
														<code>
															%APPDATA%\Claude\claude_desktop_config.json
														</code>
													</li>
												</ul>
											</div>
										</div>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												2
											</span>
											<div className="mcp-guide-step-body">
												<div className="flex items-center justify-between mb-1.5">
													<p className="font-medium text-xs text-heading">
														{__(
															"Add this server definition:"
														)}
													</p>
													<button
														type="button"
														onClick={() =>
															handleCopy(
																"claude",
																claudeConfig
															)
														}
														className="mcp-guide-copy-btn"
													>
														{copiedKey ===
														"claude" ? (
															<>
																<Check
																	size={12}
																/>
																{__("Copied")}
															</>
														) : (
															<>
																<Copy
																	size={12}
																/>
																{__(
																	"Copy JSON"
																)}
															</>
														)}
													</button>
												</div>
												<pre className="mcp-guide-code-block font-mono">
													{claudeConfig}
												</pre>
											</div>
										</div>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												3
											</span>
											<div className="mcp-guide-step-body">
												<p className="font-medium text-xs text-heading">
													{__(
														"Restart Claude Desktop"
													)}
												</p>
												<p className="text-xs text-text-muted mt-0.5">
													{__(
														"Claude will discover registered PeakURL tools and request approval before calling actions."
													)}
												</p>
											</div>
										</div>
									</div>
								)}

								{/* ChatGPT MCP Connector Guide */}
								{activeTab === "chatgpt" && (
									<div className="mcp-guide-tab-content">
										<h4 className="mcp-guide-tab-title">
											{__(
												"Connecting ChatGPT via MCP Connector"
											)}
										</h4>
										<p className="mcp-guide-tab-desc">
											{__(
												"Connect ChatGPT directly to PeakURL using OpenAI's MCP Connector over Streamable HTTP with RFC 8414 OAuth discovery."
											)}
										</p>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												1
											</span>
											<div className="mcp-guide-step-body">
												<div className="flex items-center justify-between mb-1">
													<p className="font-medium text-xs text-heading">
														{__(
															"Streamable MCP Endpoint URL:"
														)}
													</p>
													<button
														type="button"
														onClick={() =>
															handleCopy(
																"endpoint",
																endpoint
															)
														}
														className="mcp-guide-copy-btn"
													>
														{copiedKey ===
														"endpoint" ? (
															<>
																<Check
																	size={12}
																/>
																{__("Copied")}
															</>
														) : (
															<>
																<Copy
																	size={12}
																/>
																{__("Copy")}
															</>
														)}
													</button>
												</div>
												<code className="mcp-guide-field-code font-mono">
													{endpoint}
												</code>
												<p className="text-xs text-text-muted mt-1">
													{__(
														"In ChatGPT, navigate to Settings > Connected Apps > Add MCP Server and enter this endpoint URL."
													)}
												</p>
											</div>
										</div>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												2
											</span>
											<div className="mcp-guide-step-body">
												<p className="font-medium text-xs text-heading mb-1.5">
													{__(
														"OAuth 2.0 PKCE Parameters (if manual entry is prompted):"
													)}
												</p>
												<div className="mcp-guide-fields-grid">
													<div className="mcp-guide-field-card">
														<div className="flex items-center justify-between mb-1">
															<span className="text-xs font-medium text-heading">
																{__(
																	"Authorization URL"
																)}
															</span>
															<button
																type="button"
																onClick={() =>
																	handleCopy(
																		"authUrl",
																		authUrl
																	)
																}
																className="mcp-guide-copy-btn"
															>
																{copiedKey ===
																"authUrl" ? (
																	<Check
																		size={
																			12
																		}
																	/>
																) : (
																	<Copy
																		size={
																			12
																		}
																	/>
																)}
															</button>
														</div>
														<code className="mcp-guide-field-code font-mono">
															{authUrl}
														</code>
													</div>

													<div className="mcp-guide-field-card">
														<div className="flex items-center justify-between mb-1">
															<span className="text-xs font-medium text-heading">
																{__(
																	"Token URL"
																)}
															</span>
															<button
																type="button"
																onClick={() =>
																	handleCopy(
																		"tokenUrl",
																		tokenUrl
																	)
																}
																className="mcp-guide-copy-btn"
															>
																{copiedKey ===
																"tokenUrl" ? (
																	<Check
																		size={
																			12
																		}
																	/>
																) : (
																	<Copy
																		size={
																			12
																		}
																	/>
																)}
															</button>
														</div>
														<code className="mcp-guide-field-code font-mono">
															{tokenUrl}
														</code>
													</div>

													<div className="mcp-guide-field-card">
														<div className="flex items-center justify-between mb-1">
															<span className="text-xs font-medium text-heading">
																{__("Scope")}
															</span>
															<button
																type="button"
																onClick={() =>
																	handleCopy(
																		"scope",
																		"peakurl:read peakurl:write"
																	)
																}
																className="mcp-guide-copy-btn"
															>
																{copiedKey ===
																"scope" ? (
																	<Check
																		size={
																			12
																		}
																	/>
																) : (
																	<Copy
																		size={
																			12
																		}
																	/>
																)}
															</button>
														</div>
														<code className="mcp-guide-field-code font-mono">
															peakurl:read
															peakurl:write
														</code>
													</div>

													<div className="mcp-guide-field-card">
														<div className="flex items-center justify-between mb-1">
															<span className="text-xs font-medium text-heading">
																{__(
																	"Client Authentication"
																)}
															</span>
														</div>
														<code className="mcp-guide-field-code font-mono">
															None (PKCE with
															S256)
														</code>
													</div>
												</div>
											</div>
										</div>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												3
											</span>
											<div className="mcp-guide-step-body">
												<div className="mcp-guide-note mt-0">
													<ShieldCheck
														size={16}
														className="shrink-0 text-accent"
													/>
													<div className="text-xs">
														<strong>
															{__(
																"Callback URI Registration:"
															)}
														</strong>{" "}
														{__(
															"Copy the redirect URI provided by ChatGPT (e.g. https://chatgpt.com/api/actions/cb/...) and register it in the Allowed OAuth Redirect URIs list in PeakURL Settings."
														)}
													</div>
												</div>
											</div>
										</div>
									</div>
								)}

								{/* Cursor / Windsurf Guide */}
								{activeTab === "cursor" && (
									<div className="mcp-guide-tab-content">
										<h4 className="mcp-guide-tab-title">
											{__(
												"Configuring Cursor or Windsurf"
											)}
										</h4>
										<p className="mcp-guide-tab-desc">
											{__(
												"Add PeakURL to your project's .cursor/mcp.json file for context-aware link management directly inside your editor."
											)}
										</p>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												1
											</span>
											<div className="mcp-guide-step-body">
												<p className="font-medium text-xs text-heading">
													{__(
														"Create or open .cursor/mcp.json in your workspace root:"
													)}
												</p>
											</div>
										</div>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												2
											</span>
											<div className="mcp-guide-step-body">
												<div className="flex items-center justify-between mb-1.5">
													<p className="font-medium text-xs text-heading">
														{__(
															"Paste the MCP configuration:"
														)}
													</p>
													<button
														type="button"
														onClick={() =>
															handleCopy(
																"cursor",
																cursorConfig
															)
														}
														className="mcp-guide-copy-btn"
													>
														{copiedKey ===
														"cursor" ? (
															<>
																<Check
																	size={12}
																/>
																{__("Copied")}
															</>
														) : (
															<>
																<Copy
																	size={12}
																/>
																{__(
																	"Copy JSON"
																)}
															</>
														)}
													</button>
												</div>
												<pre className="mcp-guide-code-block font-mono">
													{cursorConfig}
												</pre>
											</div>
										</div>
									</div>
								)}

								{/* VS Code Guide */}
								{activeTab === "vscode" && (
									<div className="mcp-guide-tab-content">
										<h4 className="mcp-guide-tab-title">
											{__(
												"Configuring Visual Studio Code"
											)}
										</h4>
										<p className="mcp-guide-tab-desc">
											{__(
												"Add PeakURL to your VS Code settings.json under the mcp.servers configuration block."
											)}
										</p>

										<div className="mcp-guide-step">
											<span className="mcp-guide-step-num">
												1
											</span>
											<div className="mcp-guide-step-body">
												<div className="flex items-center justify-between mb-1.5">
													<p className="font-medium text-xs text-heading">
														{__(
															"Add to settings.json:"
														)}
													</p>
													<button
														type="button"
														onClick={() =>
															handleCopy(
																"vscode",
																vsCodeConfig
															)
														}
														className="mcp-guide-copy-btn"
													>
														{copiedKey ===
														"vscode" ? (
															<>
																<Check
																	size={12}
																/>
																{__("Copied")}
															</>
														) : (
															<>
																<Copy
																	size={12}
																/>
																{__(
																	"Copy JSON"
																)}
															</>
														)}
													</button>
												</div>
												<pre className="mcp-guide-code-block font-mono">
													{vsCodeConfig}
												</pre>
											</div>
										</div>
									</div>
								)}
							</div>

							{/* Drawer Footer */}
							<div className="drawer-footer">
								<span className="text-xs text-text-muted">
									{endpoint}
								</span>
								<Button
									type="button"
									variant="secondary"
									onClick={onClose}
								>
									{__("Close")}
								</Button>
							</div>
						</DialogPanel>
					</div>
				</div>
			</div>
		</DrawerDialog>
	);
}

export default McpSetupDrawer;
