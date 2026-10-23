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
import { formatLocalizedDateTime, formatRelativeTime } from "@/shared/dates";
import { getErrorMessage } from "@/shared/errors";
import { cn } from "@/shared/formatting";

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
	const activeCount = connections.filter((conn) =>
		conn.status ? conn.status === "active" : conn.isActive !== false
	).length;

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
			const count = res.data?.revokedCount ?? activeCount;
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
							activeCount
						)}
					</span>
				</div>

				{activeCount > 0 && (
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
							? `${formatRelativeTime(conn.lastUsedAt)} • ${formatLocalizedDateTime(conn.lastUsedAt)}`
							: __("Never");
						const authorizedAt = conn.createdAt
							? `${formatRelativeTime(conn.createdAt)} • ${formatLocalizedDateTime(conn.createdAt)}`
							: __("Unknown");

						const status =
							conn.status ??
							(conn.isActive !== false ? "active" : "revoked");
						const isActive = status === "active";
						const isExpired = status === "expired";
						const isRevoked = status === "revoked";

						return (
							<div
								key={conn.id}
								className={cn(
									"mcp-client-card",
									!isActive && "opacity-75 bg-surface-alt/10"
								)}
							>
								<div className="mcp-client-header">
									<div className="mcp-client-main-info">
										<div className="mcp-client-name-row">
											<span className="mcp-client-name">
												{conn.clientName ||
													__("Unnamed Client")}
											</span>
											{isActive && (
												<span className="mcp-badge mcp-badge-success">
													{__("Active")}
												</span>
											)}
											{isExpired && (
												<span className="mcp-badge mcp-badge-warning">
													{__("Expired")}
												</span>
											)}
											{isRevoked && (
												<span className="mcp-badge mcp-badge-neutral">
													{__("Revoked")}
												</span>
											)}
										</div>

										<div className="mcp-client-meta-row">
											<span className="mcp-client-meta-item">
												<User
													size={12}
													className="shrink-0"
												/>
												{conn.displayName ||
													conn.username}
											</span>
											<span className="mcp-client-meta-item">
												<Clock
													size={12}
													className="shrink-0"
												/>
												{sprintf(
													/* translators: %s: relative and full issue timestamp */
													__("Issued: %s"),
													authorizedAt
												)}
											</span>
											<span className="mcp-client-meta-item">
												<Key
													size={12}
													className="shrink-0"
												/>
												{sprintf(
													/* translators: %s: relative and full last used timestamp */
													__("Last used: %s"),
													lastUsed
												)}
											</span>
											{isExpired && conn.expiresAt && (
												<span className="mcp-client-meta-item text-amber-600/80 dark:text-amber-400/80">
													<Clock
														size={12}
														className="shrink-0"
													/>
													{sprintf(
														/* translators: %s: relative and full expired timestamp */
														__("Expired: %s"),
														`${formatRelativeTime(conn.expiresAt)} • ${formatLocalizedDateTime(conn.expiresAt)}`
													)}
												</span>
											)}
											{isRevoked && conn.revokedAt && (
												<span className="mcp-client-meta-item text-red-500/80">
													<Clock
														size={12}
														className="shrink-0"
													/>
													{sprintf(
														/* translators: %s: relative and full revoked timestamp */
														__("Revoked: %s"),
														`${formatRelativeTime(conn.revokedAt)} • ${formatLocalizedDateTime(conn.revokedAt)}`
													)}
												</span>
											)}
										</div>
									</div>

									{isActive && (
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
									)}
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
