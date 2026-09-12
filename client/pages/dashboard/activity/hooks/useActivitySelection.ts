import { useCallback, useState } from "react";

import type { RecentActivity } from "../types";

export function useActivitySelection(visibleItems: RecentActivity[]) {
	const [selectedActivityIds, setSelectedActivityIds] = useState<string[]>(
		[]
	);

	const visibleIds = visibleItems
		.map((item) => item.id)
		.filter((id): id is string => Boolean(id));

	const isAllSelected =
		visibleIds.length > 0 &&
		visibleIds.every((id) => selectedActivityIds.includes(id));

	const isIndeterminate =
		visibleIds.some((id) => selectedActivityIds.includes(id)) &&
		!isAllSelected;

	const toggleSelectAll = useCallback(() => {
		if (isAllSelected) {
			setSelectedActivityIds((prev) =>
				prev.filter((id) => !visibleIds.includes(id))
			);
		} else {
			setSelectedActivityIds((prev) => {
				const set = new Set([...prev, ...visibleIds]);
				return Array.from(set);
			});
		}
	}, [isAllSelected, visibleIds]);

	const toggleSelectOne = useCallback((id: string) => {
		setSelectedActivityIds((prev) =>
			prev.includes(id)
				? prev.filter((item) => item !== id)
				: [...prev, id]
		);
	}, []);

	const clearSelection = useCallback(() => {
		setSelectedActivityIds([]);
	}, []);

	const isSelected = useCallback(
		(id: string) => selectedActivityIds.includes(id),
		[selectedActivityIds]
	);

	return {
		selectedActivityIds,
		setSelectedActivityIds,
		isAllSelected,
		isIndeterminate,
		toggleSelectAll,
		toggleSelectOne,
		clearSelection,
		isSelected,
		selectedCount: selectedActivityIds.length,
		hasSelection: selectedActivityIds.length > 0,
	};
}
