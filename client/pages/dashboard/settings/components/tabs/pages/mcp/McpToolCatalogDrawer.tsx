import { useState } from "react";
import { DialogBackdrop, DialogPanel, DialogTitle } from "@headlessui/react";
import { Code, FileText, PenTool, Shield, Wrench, X } from "lucide-react";

import { DrawerDialog, Button, SearchInput } from "@/components";
import type { McpTool } from "@/state/slices/api";
import { __, sprintf } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";
import { cn } from "@/shared/formatting";

interface McpToolCatalogDrawerProps {
	isOpen: boolean;
	onClose: () => void;
	tools: McpTool[];
	isLoading?: boolean;
}

export function McpToolCatalogDrawer({
	isOpen,
	onClose,
	tools,
	isLoading = false,
}: McpToolCatalogDrawerProps) {
	const isRtl = isDocumentRtl();
	const [searchQuery, setSearchQuery] = useState("");

	const filteredTools = tools.filter((tool) => {
		const query = searchQuery.trim().toLowerCase();
		if (!query) {
			return true;
		}
		return (
			tool.name.toLowerCase().includes(query) ||
			tool.description.toLowerCase().includes(query) ||
			(tool.scope && tool.scope.toLowerCase().includes(query))
		);
	});

	const readCount = tools.filter((tool) => !tool.isWrite).length;
	const writeCount = tools.filter((tool) => tool.isWrite).length;

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
								"drawer-panel drawer-transition mcp-tool-drawer-panel",
								isRtl
									? "data-closed:-translate-x-full"
									: "data-closed:translate-x-full"
							)}
						>
							{/* Drawer Header */}
							<div className="drawer-header">
								<div className="drawer-heading-group">
									<div className="drawer-title-icon">
										<Wrench />
									</div>
									<div className="min-w-0 flex-1">
										<DialogTitle
											as="h3"
											className="drawer-title"
										>
											{__("Registered MCP Tools")}
										</DialogTitle>
										<p className="drawer-subtitle">
											{sprintf(
												/* translators: 1: total tool count, 2: read tool count, 3: write tool count */
												__(
													"%1$d registered tools (%2$d read-only queries, %3$d write actions)."
												),
												tools.length,
												readCount,
												writeCount
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
								{/* Search Filter */}
								<SearchInput
									value={searchQuery}
									onChange={setSearchQuery}
									placeholder={__(
										"Filter tools by name, description, or scope..."
									)}
									aria-label={__("Filter tools")}
								/>

								{isLoading ? (
									<div className="mcp-modal-loading">
										<span className="mcp-loading-spinner" />
										<p>
											{__(
												"Loading registered MCP tools..."
											)}
										</p>
									</div>
								) : filteredTools.length === 0 ? (
									<div className="mcp-modal-empty">
										<p>
											{__(
												"No tools match your search criteria."
											)}
										</p>
									</div>
								) : (
									<div className="mcp-tool-list">
										{filteredTools.map((tool) => {
											const properties =
												tool.inputSchema?.properties ||
												{};
											const requiredFields = new Set(
												tool.inputSchema?.required || []
											);
											const propertyKeys =
												Object.keys(properties);
											const isWrite = Boolean(
												tool.isWrite
											);

											return (
												<div
													key={tool.name}
													className="mcp-tool-card"
												>
													<div className="mcp-tool-card-header">
														<div className="mcp-tool-title-row">
															<span className="mcp-tool-name font-mono">
																{tool.name}
															</span>
															<span
																className={cn(
																	"mcp-badge",
																	isWrite
																		? "mcp-badge-warning"
																		: "mcp-badge-neutral"
																)}
															>
																{isWrite ? (
																	<>
																		<PenTool
																			size={
																				11
																			}
																			className="shrink-0"
																		/>
																		{__(
																			"Write Action"
																		)}
																	</>
																) : (
																	<>
																		<FileText
																			size={
																				11
																			}
																			className="shrink-0"
																		/>
																		{__(
																			"Read-Only Query"
																		)}
																	</>
																)}
															</span>
														</div>

														<p className="mcp-tool-description">
															{tool.description}
														</p>
													</div>

													<div className="mcp-tool-card-body">
														{tool.scope && (
															<div className="mcp-tool-meta-row">
																<span className="mcp-tool-meta-label">
																	<Shield
																		size={
																			12
																		}
																		className="shrink-0"
																	/>
																	{__(
																		"Required Scope:"
																	)}
																</span>
																<code className="mcp-tool-meta-code font-mono">
																	{tool.scope}
																</code>
															</div>
														)}

														<div className="mcp-tool-schema-section">
															<div className="mcp-tool-schema-header">
																<Code
																	size={13}
																	className="shrink-0"
																/>
																<span>
																	{__(
																		"Input Parameters"
																	)}
																</span>
															</div>

															{propertyKeys.length ===
															0 ? (
																<p className="mcp-tool-no-params">
																	{__(
																		"No arguments required."
																	)}
																</p>
															) : (
																<div className="mcp-tool-params-table-wrapper">
																	<table className="mcp-tool-params-table">
																		<thead>
																			<tr>
																				<th className="w-1/4">
																					{__(
																						"Parameter"
																					)}
																				</th>
																				<th className="w-1/5">
																					{__(
																						"Type"
																					)}
																				</th>
																				<th>
																					{__(
																						"Description"
																					)}
																				</th>
																			</tr>
																		</thead>
																		<tbody>
																			{propertyKeys.map(
																				(
																					propName
																				) => {
																					const prop =
																						properties[
																							propName
																						] as {
																							type?: string;
																							description?: string;
																						};
																					const isRequired =
																						requiredFields.has(
																							propName
																						);

																					return (
																						<tr
																							key={
																								propName
																							}
																						>
																							<td>
																								<div className="flex items-center gap-1 font-mono text-xs">
																									<span className="mcp-param-name">
																										{
																											propName
																										}
																									</span>
																									{isRequired && (
																										<span
																											className="mcp-param-required"
																											title={__(
																												"Required parameter"
																											)}
																										>
																											*
																										</span>
																									)}
																								</div>
																							</td>
																							<td>
																								<span className="mcp-param-type font-mono">
																									{prop.type ||
																										"any"}
																								</span>
																							</td>
																							<td className="mcp-param-desc">
																								{prop.description ||
																									"—"}
																							</td>
																						</tr>
																					);
																				}
																			)}
																		</tbody>
																	</table>
																</div>
															)}
														</div>
													</div>
												</div>
											);
										})}
									</div>
								)}
							</div>

							{/* Drawer Footer */}
							<div className="drawer-footer">
								<span className="text-xs text-text-muted">
									{sprintf(
										__("%d tools registered"),
										filteredTools.length
									)}
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

export default McpToolCatalogDrawer;
