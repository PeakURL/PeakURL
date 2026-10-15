import {
	Combobox,
	ComboboxInput,
	ComboboxOptions,
	Dialog,
	DialogPanel,
	DialogTitle,
} from "@headlessui/react";
import { ArrowDown, ArrowUp, CornerDownLeft, Search, X } from "lucide-react";
import { useRef, useState } from "react";
import { useNavigate } from "react-router";

import { __ } from "@/i18n";
import {
	getSearchShortcutKeys,
	getSearchShortcutLabel,
} from "@/shared/browser";
import { getDocumentDirection } from "@/i18n/direction";

import { SearchResults } from "./SearchResults";
import { useSearchResults } from "./useSearchResults";
import type { SearchResult } from "./types";

/** Spotlight-style search with focus trapping and accessible result navigation. */
export default function SearchDialog({ onClose }: { onClose: () => void }) {
	const [query, setQuery] = useState("");
	const inputRef = useRef<HTMLInputElement>(null);
	const navigate = useNavigate();
	const { groups, isSearching } = useSearchResults(query);
	const direction = getDocumentDirection();
	const shortcutKeys = getSearchShortcutKeys();

	const selectResult = (result: SearchResult | null) => {
		if (!result) return;
		onClose();
		if (result.external) {
			window.open(result.href, "_blank", "noopener,noreferrer");
		} else {
			navigate(result.href);
		}
	};

	return (
		<Dialog
			open
			onClose={onClose}
			initialFocus={inputRef}
			onKeyDownCapture={(event) => {
				if (event.key === "Escape" && !event.nativeEvent.isComposing) {
					event.preventDefault();
					event.stopPropagation();
					onClose();
				}
			}}
			className="dashboard-search-dialog"
		>
			<div className="dialog-backdrop" aria-hidden="true" />
			<div
				className="dashboard-search-position"
				onPointerDown={(event) => {
					if (event.target === event.currentTarget) {
						event.preventDefault();
						onClose();
					}
				}}
			>
				<DialogPanel dir={direction} className="dashboard-search-panel">
					<DialogTitle className="sr-only">
						{__("Search the dashboard")}
					</DialogTitle>
					<Combobox<SearchResult | null>
						value={null}
						onChange={selectResult}
						immediate
					>
						<div className="dashboard-search-field">
							<Search size={22} aria-hidden="true" />
							<ComboboxInput
								ref={inputRef}
								value={query}
								onChange={(event) =>
									setQuery(event.target.value)
								}
								aria-label={__(
									"Search links, people, and pages"
								)}
								placeholder={__("Search PeakURL…")}
								dir={query ? "auto" : direction}
								autoComplete="off"
								spellCheck={false}
								className="dashboard-search-input"
							/>
							<kbd
								className="dashboard-search-shortcut"
								aria-label={getSearchShortcutLabel()}
								dir="ltr"
							>
								{shortcutKeys.map((key) => (
									<span key={key} aria-hidden="true">
										{key}
									</span>
								))}
							</kbd>
							<button
								type="button"
								onClick={onClose}
								className="dashboard-search-close"
								aria-label={__("Close search")}
							>
								<X size={18} aria-hidden="true" />
							</button>
						</div>
						<ComboboxOptions
							// The dialog already owns focus trapping and background inertness.
							modal={false}
							static
							className="dashboard-search-results"
							aria-label={__("Search results")}
						>
							<SearchResults groups={groups} />
						</ComboboxOptions>
					</Combobox>
					<footer className="dashboard-search-footer">
						<span
							className="dashboard-search-feedback"
							role="status"
						>
							{isSearching
								? __("Searching…")
								: query.trim()
									? __("Search across PeakURL")
									: __(
											"Jump to a page or start typing to search"
										)}
						</span>
						<span
							className="dashboard-search-keyboard"
							aria-hidden="true"
						>
							<kbd>
								<ArrowUp size={12} />
								<ArrowDown size={12} />
							</kbd>
							{__("Navigate")}
							<kbd>
								<CornerDownLeft size={12} />
							</kbd>
							{__("Open")}
							<kbd>esc</kbd>
							{__("Close")}
						</span>
					</footer>
				</DialogPanel>
			</div>
		</Dialog>
	);
}
