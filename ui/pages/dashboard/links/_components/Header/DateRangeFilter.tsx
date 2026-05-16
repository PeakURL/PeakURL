import { useState } from "react";
import { Popover, PopoverButton, PopoverPanel } from "@headlessui/react";
import { CalendarDays } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { cn, formatDateOnly } from "@/utils";
import type { LinksCustomDateRange, LinksDateRange } from "../types";

interface DateRangeFilterProps {
	clickRange: LinksDateRange;
	customClickRange: LinksCustomDateRange;
	onClickRangeChange: (range: LinksDateRange) => void;
	onCustomClickRangeChange: (range: LinksCustomDateRange) => void;
}

const RANGE_OPTIONS: Array<{
	value: Exclude<LinksDateRange, "custom">;
	label: string;
}> = [
	{ value: "all", label: __("All") },
	{ value: "24h", label: __("24h") },
	{ value: "7d", label: __("7d") },
	{ value: "30d", label: __("30d") },
];

function getRangeButtonClassName(isCurrent: boolean): string {
	return cn(
		"links-date-range-button",
		isCurrent && "links-date-range-button-current",
		!isCurrent && "links-date-range-button-idle"
	);
}

function formatDateLabel(value: string): string {
	if (!value) {
		return __("Not selected");
	}

	return formatDateOnly(value, {
		month: "short",
		day: "numeric",
		year: "numeric",
	}) || value;
}

const DateRangeFilter = ({
	clickRange,
	customClickRange,
	onClickRangeChange,
	onCustomClickRangeChange,
}: DateRangeFilterProps) => {
	const [draftRange, setDraftRange] =
		useState<LinksCustomDateRange>(customClickRange);
	const canApply = Boolean(draftRange.from && draftRange.to);

	const updateDraftRange = (
		key: keyof LinksCustomDateRange,
		value: string
	) => {
		setDraftRange((currentRange) => ({
			...currentRange,
			[key]: value,
		}));
	};

	const applyCustomRange = () => {
		if (!canApply) {
			return;
		}

		onCustomClickRangeChange(draftRange);
		onClickRangeChange("custom");
	};

	return (
		<div className="links-date-range">
			{RANGE_OPTIONS.map((range) => (
				<button
					key={range.value}
					type="button"
					className={getRangeButtonClassName(
						clickRange === range.value
					)}
					onClick={() => onClickRangeChange(range.value)}
				>
					{range.label}
				</button>
			))}

			<Popover className="links-date-range-custom">
				{({ close }) => (
					<>
						<PopoverButton
							type="button"
							className={getRangeButtonClassName(
								"custom" === clickRange
							)}
							onClick={() => setDraftRange(customClickRange)}
						>
							<CalendarDays className="links-date-range-icon" />
							<span>{__("Custom")}</span>
						</PopoverButton>
						<PopoverPanel
							anchor={{ to: "bottom end", gap: 8, padding: 16 }}
							portal
							className="links-date-range-panel"
						>
							<h4 className="links-date-range-title">
								{__("Custom date range")}
							</h4>
							<div className="links-date-range-fields">
								<label className="links-date-range-field">
									<span>{__("From")}</span>
									<input
										type="date"
										value={draftRange.from}
										max={draftRange.to}
										onChange={(event) =>
											updateDraftRange(
												"from",
												event.target.value
											)
										}
									/>
								</label>
								<label className="links-date-range-field">
									<span>{__("To")}</span>
									<input
										type="date"
										value={draftRange.to}
										min={draftRange.from}
										onChange={(event) =>
											updateDraftRange(
												"to",
												event.target.value
											)
										}
									/>
								</label>
							</div>
							<p className="links-date-range-summary">
								{sprintf(__("Showing %1$s to %2$s"), [
									formatDateLabel(draftRange.from),
									formatDateLabel(draftRange.to),
								])}
							</p>
							<div className="links-date-range-actions">
								<button
									type="button"
									className="links-date-range-apply"
									disabled={!canApply}
									onClick={() => {
										applyCustomRange();
										close();
									}}
								>
									{__("Apply")}
								</button>
							</div>
						</PopoverPanel>
					</>
				)}
			</Popover>
		</div>
	);
};

export default DateRangeFilter;
