import { ComboboxOption } from "@headlessui/react";
import {
	ExternalLink,
	ArrowLeft,
	ArrowRight,
	LoaderCircle,
	RotateCw,
} from "lucide-react";

import { __ } from "@/i18n";
import { isDocumentRtl } from "@/i18n/direction";

import type { SearchResultGroup } from "./types";

/** Result groups share one keyboard selection order, including the full links search. */
export function SearchResults({ groups }: { groups: SearchResultGroup[] }) {
	const DirectionArrow = isDocumentRtl() ? ArrowLeft : ArrowRight;
	return groups.map((group) => (
		<div
			key={group.id}
			role="group"
			aria-labelledby={`search-group-${group.id}`}
			className="dashboard-search-group"
		>
			<p
				id={`search-group-${group.id}`}
				className="dashboard-search-group-title"
			>
				{group.title}
			</p>
			{group.isLoading ? (
				<p className="dashboard-search-status">
					<LoaderCircle
						size={16}
						className="dashboard-search-loading"
						aria-hidden="true"
					/>
					{__("Searching…")}
				</p>
			) : group.isError ? (
				<div className="dashboard-search-status" role="alert">
					<span>{__("Results could not be loaded.")}</span>
					<button
						type="button"
						onClick={group.onRetry}
						className="dashboard-search-retry"
					>
						<RotateCw size={14} aria-hidden="true" />
						{__("Try again")}
					</button>
				</div>
			) : group.items.length === 0 ? (
				<p className="dashboard-search-status">
					{__("No matches found.")}
				</p>
			) : (
				group.items.map((item) => (
					<ComboboxOption
						key={item.id}
						value={item}
						className="dashboard-search-result"
					>
						<div className="dashboard-search-result-icon">
							<item.icon size={18} aria-hidden="true" />
						</div>
						<div className="dashboard-search-result-copy">
							<p className="dashboard-search-result-title">
								{item.title}
							</p>
							{item.description && (
								<p className="dashboard-search-result-description">
									{item.description}
								</p>
							)}
							{item.meta && (
								<p className="dashboard-search-result-meta">
									{item.meta}
								</p>
							)}
						</div>
						{item.external ? (
							<>
								<ExternalLink
									size={15}
									className="dashboard-search-result-arrow"
									aria-hidden="true"
								/>
								<span className="sr-only">
									{__("Opens in a new tab")}
								</span>
							</>
						) : (
							<DirectionArrow
								size={16}
								className="dashboard-search-result-arrow"
								aria-hidden="true"
							/>
						)}
					</ComboboxOption>
				))
			)}
		</div>
	));
}
