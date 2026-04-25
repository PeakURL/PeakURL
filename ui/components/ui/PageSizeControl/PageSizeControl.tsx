import { type HTMLAttributes, useEffect, useId, useRef, useState } from "react";
import { Check, X } from "lucide-react";
import { __, sprintf } from "@/i18n";
import { cn } from "@/utils";
import { Select } from "../Select";
import type { SelectOption } from "../types";

export const DEFAULT_PAGE_SIZE_OPTIONS: readonly number[] = [25, 50, 100, 150];
export const DEFAULT_PAGE_SIZE_MAX = 250;

export function normalizePageSize(
	value: number | string | null | undefined,
	fallback = DEFAULT_PAGE_SIZE_OPTIONS[0],
	max = DEFAULT_PAGE_SIZE_MAX
): number {
	const parsed = Number(value);

	if (!Number.isFinite(parsed) || parsed < 1) {
		return fallback;
	}

	return Math.min(max, Math.max(1, Math.round(parsed)));
}

interface PageSizeControlProps extends Omit<
	HTMLAttributes<HTMLDivElement>,
	"onChange"
> {
	value: number;
	onChange: (value: number) => void;
	options?: readonly number[];
	max?: number;
	ariaLabel?: string;
}

export function PageSizeControl({
	value,
	onChange,
	options = DEFAULT_PAGE_SIZE_OPTIONS,
	max = DEFAULT_PAGE_SIZE_MAX,
	ariaLabel = __("Rows per page"),
	className = "",
	...props
}: PageSizeControlProps) {
	const inputId = useId();
	const inputRef = useRef<HTMLInputElement | null>(null);
	const fallbackValue = options[0] ?? DEFAULT_PAGE_SIZE_OPTIONS[0];
	const normalizedValue = normalizePageSize(value, fallbackValue, max);
	const [isEditingCustom, setIsEditingCustom] = useState(false);
	const [draftValue, setDraftValue] = useState(String(normalizedValue));
	const hasPresetValue = options.includes(normalizedValue);
	const selectValue = hasPresetValue
		? String(normalizedValue)
		: `current:${normalizedValue}`;
	const selectOptions: SelectOption<string>[] = [
		...(hasPresetValue
			? []
			: [
					{
						value: `current:${normalizedValue}`,
						label: sprintf(
							__("Show: %s rows"),
							String(normalizedValue)
						),
					},
				]),
		...options.map((option) => ({
			value: String(option),
			label: sprintf(__("Show: %s rows"), String(option)),
		})),
		{
			value: "custom",
			label: __("Custom…"),
		},
	];

	useEffect(() => {
		setDraftValue(String(normalizedValue));
	}, [normalizedValue]);

	useEffect(() => {
		if (!isEditingCustom || !inputRef.current) {
			return;
		}

		const frameId = window.requestAnimationFrame(() => {
			inputRef.current?.focus();
			inputRef.current?.select();
		});

		return () => window.cancelAnimationFrame(frameId);
	}, [isEditingCustom]);

	const handleApply = () => {
		const nextValue = normalizePageSize(draftValue, normalizedValue, max);

		onChange(nextValue);
		setDraftValue(String(nextValue));
		setIsEditingCustom(false);
	};

	const handleCancel = () => {
		setDraftValue(String(normalizedValue));
		setIsEditingCustom(false);
	};

	return (
		<div className={cn("page-size-control", className)} {...props}>
			{isEditingCustom ? (
				<div className="page-size-control-editor">
					<label htmlFor={inputId} className="sr-only">
						{ariaLabel}
					</label>
					<input
						ref={inputRef}
						id={inputId}
						type="number"
						min={1}
						max={max}
						inputMode="numeric"
						dir="ltr"
						value={draftValue}
						onChange={(event) => setDraftValue(event.target.value)}
						onKeyDown={(event) => {
							if ("Enter" === event.key) {
								event.preventDefault();
								handleApply();
							}

							if ("Escape" === event.key) {
								event.preventDefault();
								handleCancel();
							}
						}}
						placeholder={__("Rows")}
						className="page-size-control-input"
					/>
					<button
						type="button"
						onClick={handleApply}
						className="page-size-control-action page-size-control-action-apply"
						aria-label={__("Apply custom page size")}
						title={__("Apply custom page size")}
					>
						<Check size={15} />
					</button>
					<button
						type="button"
						onClick={handleCancel}
						className="page-size-control-action page-size-control-action-cancel"
						aria-label={__("Cancel custom page size")}
						title={__("Cancel custom page size")}
					>
						<X size={15} />
					</button>
				</div>
			) : (
				<Select
					value={selectValue}
					onChange={(nextValue) => {
						if ("custom" === nextValue) {
							setDraftValue(String(normalizedValue));
							setIsEditingCustom(true);
							return;
						}

						if (nextValue.startsWith("current:")) {
							return;
						}

						onChange(
							normalizePageSize(nextValue, normalizedValue, max)
						);
					}}
					options={selectOptions}
					className="page-size-control-select"
					ariaLabel={ariaLabel}
					buttonClassName="form-control-surface-alt form-control-compact"
				/>
			)}
		</div>
	);
}

export default PageSizeControl;
