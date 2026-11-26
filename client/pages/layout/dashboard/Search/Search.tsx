import { lazy, Suspense, useEffect, useState } from "react";
import { Search as SearchIcon } from "lucide-react";
import { useLocation } from "react-router";

import { __ } from "@/i18n";
import { getSearchShortcutLabel } from "@/shared/browser";

const SearchDialog = lazy(() => import("./SearchDialog"));

/** One global search entry point shared by the header button and Cmd/Ctrl+K. */
export function Search() {
	const { key: locationKey } = useLocation();
	const [openedAtLocation, setOpenedAtLocation] = useState<string | null>();
	// Forget the previous entry so history navigation cannot reopen search.
	if (
		typeof openedAtLocation === "string" &&
		openedAtLocation !== locationKey
	) {
		setOpenedAtLocation(null);
	}
	const isOpen = openedAtLocation === locationKey;
	const shortcut = getSearchShortcutLabel();

	useEffect(() => {
		const openWithShortcut = (event: KeyboardEvent) => {
			if (
				(event.metaKey || event.ctrlKey) &&
				!event.altKey &&
				!event.shiftKey &&
				!event.isComposing &&
				event.key.toLowerCase() === "k"
			) {
				event.preventDefault();
				setOpenedAtLocation(locationKey);
			}
		};
		window.addEventListener("keydown", openWithShortcut);
		return () => window.removeEventListener("keydown", openWithShortcut);
	}, [locationKey]);

	return (
		<>
			<button
				type="button"
				className="dashboard-search-trigger"
				aria-label={__("Search the dashboard")}
				aria-haspopup="dialog"
				aria-expanded={isOpen}
				title={`${__("Search the dashboard")} (${shortcut})`}
				onClick={() => setOpenedAtLocation(locationKey)}
			>
				<SearchIcon size={20} aria-hidden="true" />
			</button>
			{openedAtLocation !== undefined && (
				<Suspense fallback={null}>
					<SearchDialog
						open={isOpen}
						onClose={() => setOpenedAtLocation(null)}
					/>
				</Suspense>
			)}
		</>
	);
}

export default Search;
