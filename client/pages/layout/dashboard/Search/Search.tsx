import { lazy, Suspense, useEffect, useState } from "react";
import { Search as SearchIcon } from "lucide-react";
import { useLocation } from "react-router";

import { __ } from "@/i18n";
import { getSearchShortcutLabel } from "@/shared/browser";

const SearchDialog = lazy(() => import("./SearchDialog"));

/** One global search entry point shared by the header button and Cmd/Ctrl+K. */
export function Search() {
	const location = useLocation();
	const [openedAtLocation, setOpenedAtLocation] = useState<string | null>(
		null
	);
	// Route changes dismiss search, including navigation through browser history.
	if (openedAtLocation !== null && openedAtLocation !== location.key) {
		setOpenedAtLocation(null);
	}
	const isOpen = openedAtLocation === location.key;
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
				setOpenedAtLocation(location.key);
			}
		};
		window.addEventListener("keydown", openWithShortcut);
		return () => window.removeEventListener("keydown", openWithShortcut);
	}, [location.key]);

	return (
		<>
			<button
				type="button"
				className="dashboard-search-trigger"
				aria-label={__("Search the dashboard")}
				aria-haspopup="dialog"
				aria-expanded={isOpen}
				title={`${__("Search the dashboard")} (${shortcut})`}
				onClick={() => setOpenedAtLocation(location.key)}
			>
				<SearchIcon size={20} aria-hidden="true" />
			</button>
			{isOpen && (
				<Suspense fallback={null}>
					<SearchDialog onClose={() => setOpenedAtLocation(null)} />
				</Suspense>
			)}
		</>
	);
}

export default Search;
