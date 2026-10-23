import { useState } from "react";
import { Clock, Key, Shield, Trash2, User } from "lucide-react";

import { Button, ConfirmDialog, McpIcon } from "@/components";
import type { NotificationContextValue } from "@/components";
import {
	useGetMcpConnectionsQuery,
	useRevokeAllMcpConnectionsMutation,
	useRevokeMcpConnectionMutation,
} from "@/state/slices/api";
import { __, sprintf } from "@/i18n";
import { formatLocalizedDateTime } from "@/shared/dates";
import { getErrorMessage } from "@/shared/errors";

interface McpConnectedClientsProps {
	notification?: Pick<NotificationContextValue, "error" | "success"> | null;
}

export function McpConnectedClients({
	notification,
}: McpConnectedClientsProps) {
	const { data, isLoading, refetch } = useGetMcpConnectionsQuery(undefined, {
		refetchOnFocus: true,
	});

	const [revokeConnection, { isLoading: isRevokingOne }] =
		useRevokeMcpConnectionMutation();
	const [revokeAllConnections, { isLoading: isRevokingAll }] =
		useRevokeAllMcpConnectionsMutation();

	const [connectionToRevoke, setConnectionToRevoke] = useState<{
		id: string;
		clientName: string;
	} | null>(null);
	const [isRevokeAllOpen, setIsRevokeAllOpen] = useState(false);

	const connections = data?.data?.connections || [];
	const total = data?.data?.total || connections.length;

	const handleConfirmRevokeOne = async () => {
		if (!connectionToRevoke) {
			return;
		}

		try {
			await revokeConnection(connectionToRevoke.id).unwrap();
			notification?.success(
				__("Connection Revoked"),
				sprintf(
					/* translators: %s: client name */
					__("Authorization for '%s' was revoked successfully."),
					connectionToRevoke.clientName
				)
			);
			setConnectionToRevoke(null);
			refetch();
		} catch (err) {
			notification?.error(
				__("Failed to Revoke Connection"),
				getErrorMessage(
					err,
					__(
						"An error occurred while revoking the client connection."
					)
				)
			);
		}
	};

	const handleConfirmRevokeAll = async () => {
		try {
			const res = await revokeAllConnections().unwrap();
			const count = res.data?.revokedCount ?? total;
			notification?.success(
				__("All Connections Revoked"),
				sprintf(
					/* translators: %d: count of revoked connections */
					__(
						"%d active MCP client authorization(s) have been revoked."
					),
					count
				)
			);
			setIsRevokeAllOpen(false);
			refetch();
		} catch (err) {
			notification?.error(
				__("Failed to Revoke All Connections"),
				getErrorMessage(
					err,
					__(
						"An error occurred while revoking all client connections."
					)
				)
			);
		}
	};

	return (
		<div className="mcp-connected-clients-section">
			<div className="mcp-connected-clients-header">
				<div className="mcp-clients-title-group">
					<h3 className="mcp-clients-heading">
						<McpIcon size={18} className="text-accent shrink-0" />
						{__("Authorized Client Applications")}
					</h3>
					<span className="mcp-badge mcp-badge-neutral">
						{sprintf(
							/* translators: %d: count */
							__("%d active"),
							total
						)}
					</span>
				</div>

				{connections.length > 0 && (
					<Button
						variant="outline"
						size="sm"
						onClick={() => setIsRevokeAllOpen(true)}
						disabled={isRevokingAll}
						className="mcp-revoke-all-btn"
					>
						<Trash2 size={13} className="text-red-500" />
						{__("Revoke All Clients")}
					</Button>
				)}
			</div>

			<p className="mcp-clients-subtitle">
				{__(
					"AI clients and IDE extensions authorized to access PeakURL via OAuth 2.0 PKCE."
				)}
			</p>

			{isLoading ? (
				<div className="mcp-clients-loading">
					<span className="mcp-loading-spinner" />
					<p>{__("Loading authorized clients...")}</p>
				</div>
			) : connections.length === 0 ? (
				<div className="mcp-clients-empty-card">
					<McpIcon size={28} className="text-text-muted mb-2" />
					<p className="font-semibold text-heading text-sm">
						{__("No Active MCP Connections")}
					</p>
					<p className="text-xs text-text-muted max-w-sm mt-1">
						{__(
							"When an AI client completes the OAuth 2.0 PKCE flow, its authorized connection will appear here with granular scopes and usage tracking."
						)}
					</p>
				</div>
			) : (
				<div className="mcp-clients-list">
					{connections.map((conn) => {
						const lastUsed = conn.lastUsedAt
							? formatLocalizedDateTime(conn.lastUsedAt)
							: __("Never");
						const authorizedAt = conn.createdAt
							? formatLocalizedDateTime(conn.createdAt)
							: __("Unknown");

						return (
							<div key={conn.id} className="mcp-client-card">
								<div className="mcp-client-header">
									<div className="mcp-client-main-info">
										<div className="mcp-client-name-row">
											<span className="mcp-client-name">
												{conn.clientName ||
													__("Unnamed Client")}
											</span>
											<span className="mcp-badge mcp-badge-success">
												{__("Active")}
											</span>
										</div>

										<div className="mcp-client-meta-row">
											<span className="mcp-client-meta-item">
												<User size={12} />
												{conn.displayName ||
													conn.username}
											</span>
											<span className="mcp-client-meta-item">
												<Clock size={12} />
												{sprintf(
													__("Issued: %s"),
													authorizedAt
												)}
											</span>
											<span className="mcp-client-meta-item">
												<Key size={12} />
												{sprintf(
													__("Last used: %s"),
													lastUsed
												)}
											</span>
										</div>
									</div>

									<Button
										variant="outline"
										size="sm"
										onClick={() =>
											setConnectionToRevoke({
												id: conn.id,
												clientName:
													conn.clientName ||
													__("Client"),
											})
										}
										className="mcp-client-revoke-btn"
										aria-label={sprintf(
											/* translators: %s: client name */
											__("Revoke %s authorization"),
											conn.clientName
										)}
									>
										<Trash2
											size={13}
											className="text-red-500"
										/>
										{__("Revoke")}
									</Button>
								</div>

								{conn.scopes && conn.scopes.length > 0 && (
									<div className="mcp-client-scopes-row">
										<span className="mcp-client-scopes-label">
											<Shield size={11} />
											{__("Scopes:")}
										</span>
										<div className="mcp-client-scopes-list">
											{conn.scopes.map((scope) => (
												<span
													key={scope}
													className="mcp-scope-pill font-mono"
												>
													{scope}
												</span>
											))}
										</div>
									</div>
								)}
							</div>
						);
					})}
				</div>
			)}

			{/* Confirm Revoke Single Connection */}
			<ConfirmDialog
				open={Boolean(connectionToRevoke)}
				onClose={() => setConnectionToRevoke(null)}
				title={__("Revoke Client Authorization")}
				description={sprintf(
					/* translators: %s: client name */
					__(
						"Are you sure you want to revoke authorization for '%s'? The client will immediately lose access to PeakURL until re-authorized."
					),
					connectionToRevoke?.clientName || ""
				)}
				confirmText={__("Revoke Authorization")}
				confirmVariant="danger"
				loading={isRevokingOne}
				onConfirm={handleConfirmRevokeOne}
			/>

			{/* Confirm Revoke All Connections */}
			<ConfirmDialog
				open={isRevokeAllOpen}
				onClose={() => setIsRevokeAllOpen(false)}
				title={__("Revoke All Client Authorizations")}
				description={__(
					"Are you sure you want to revoke ALL active MCP client authorizations? Every connected AI client will immediately lose access and must be re-authorized."
				)}
				confirmText={__("Revoke All")}
				confirmVariant="danger"
				loading={isRevokingAll}
				onConfirm={handleConfirmRevokeAll}
			/>
		</div>
	);
}

export default McpConnectedClients;
