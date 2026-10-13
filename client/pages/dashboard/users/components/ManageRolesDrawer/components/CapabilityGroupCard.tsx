import {
	BarChart3,
	Globe,
	Link2,
	Lock,
	ShieldAlert,
	SlidersHorizontal,
	Users,
} from "lucide-react";

import type { RoleCapability } from "@/api";
import { __, sprintf } from "@/i18n";
import { cn } from "@/shared/formatting";

export interface CapabilityGroupCardProps {
	groupKey: string;
	groupLabel: string;
	capabilities: RoleCapability[];
	draftValues: Record<string, boolean>;
	onToggleCapability: (capId: string, nextValue: boolean) => void;
	disabled?: boolean;
}

function renderGroupIcon(group: string) {
	const iconProps = { size: 15, className: "text-accent shrink-0" };
	switch (group) {
		case "user_access":
			return <Users {...iconProps} />;
		case "site_integrations":
			return <Globe {...iconProps} />;
		case "links":
			return <Link2 {...iconProps} />;
		case "analytics":
			return <BarChart3 {...iconProps} />;
		default:
			return <SlidersHorizontal {...iconProps} />;
	}
}

export function CapabilityGroupCard({
	groupKey,
	groupLabel,
	capabilities,
	draftValues,
	onToggleCapability,
	disabled,
}: CapabilityGroupCardProps) {
	const enabledCount = capabilities.filter((c) =>
		c.applicable !== false ? Boolean(draftValues[c.id]) : false
	).length;

	return (
		<div className="rounded-xl border border-stroke bg-surface overflow-hidden shadow-2xs">
			{/* Group Header */}
			<div className="users-roles-group-header">
				<div className="flex items-center gap-2">
					{renderGroupIcon(groupKey)}
					<h3 className="text-xs font-semibold uppercase tracking-wider text-heading">
						{groupLabel}
					</h3>
				</div>
				<span className="inline-flex items-center rounded-full bg-accent/10 px-2 py-0.5 text-[11px] font-semibold text-accent">
					{sprintf(
						/* translators: %1$d is enabled count, %2$d is total count */
						__("%1$d / %2$d enabled"),
						enabledCount,
						capabilities.length
					)}
				</span>
			</div>

			{/* Capability Rows */}
			<div className="divide-y divide-stroke/50">
				{capabilities.map((cap) => {
					const isApplicable = cap.applicable !== false;
					const isProtected = cap.protected;
					const isEnabled = isApplicable
						? Boolean(draftValues[cap.id])
						: false;
					const isToggleDisabled =
						!isApplicable || isProtected || Boolean(disabled);

					return (
						<div
							key={cap.id}
							className={cn(
								"users-roles-capability-card",
								(!isApplicable || isProtected) &&
									"bg-surface-alt/30"
							)}
						>
							<div className="min-w-0 flex-1 text-start pr-3">
								<div className="flex flex-wrap items-center gap-2">
									<label
										htmlFor={`cap-toggle-${cap.id}`}
										className={cn(
											"text-xs font-semibold text-heading",
											!isToggleDisabled &&
												"cursor-pointer hover:text-accent",
											isToggleDisabled && "cursor-default"
										)}
									>
										{cap.label}
									</label>

									{!isApplicable ? (
										<span className="inline-flex items-center gap-1 rounded-full bg-slate-500/10 px-2 py-0.5 text-[10px] font-bold text-text-muted border border-stroke">
											<Lock size={10} />
											{__("Admin only")}
										</span>
									) : isProtected ? (
										<span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-600 dark:text-amber-400 border border-amber-500/20">
											<ShieldAlert size={10} />
											{__("Protected")}
										</span>
									) : null}

									{isApplicable && cap.default ? (
										<span className="text-[10px] text-text-muted">
											({__("default")})
										</span>
									) : null}
								</div>

								<p className="mt-0.5 text-xs text-text-muted leading-relaxed">
									{cap.description}
								</p>

								{!isApplicable ? (
									<p className="mt-1 text-[11px] text-text-muted leading-tight">
										{cap.applicableReason ||
											__("Reserved for administrators.")}
									</p>
								) : isProtected && cap.protectedReason ? (
									<p className="mt-1 text-[11px] text-amber-600/90 dark:text-amber-400/90 leading-tight">
										{cap.protectedReason}
									</p>
								) : null}
							</div>

							<div className="shrink-0 self-center">
								<label
									className={cn(
										"relative inline-flex items-center select-none",
										isToggleDisabled
											? "cursor-not-allowed opacity-60"
											: "cursor-pointer"
									)}
								>
									<input
										id={`cap-toggle-${cap.id}`}
										type="checkbox"
										checked={isEnabled}
										disabled={isToggleDisabled}
										onChange={(e) =>
											onToggleCapability(
												cap.id,
												e.target.checked
											)
										}
										className="sr-only peer"
										aria-label={cap.label}
									/>
									<div
										className={cn(
											"w-9 h-5 bg-stroke peer-focus:outline-hidden rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:inset-s-0.5 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-accent",
											isProtected && "bg-accent",
											!isApplicable && "bg-stroke/60"
										)}
									/>
								</label>
							</div>
						</div>
					);
				})}
			</div>
		</div>
	);
}
