import { forwardRef, useCallback, useRef, type KeyboardEvent } from "react";
import { Search, X } from "lucide-react";

import { __ } from "@/i18n";
import { cn } from "@/shared/formatting";

export interface SearchInputProps extends Omit<
	React.InputHTMLAttributes<HTMLInputElement>,
	"onChange" | "value"
> {
	value: string;
	onChange: (value: string) => void;
	onClear?: () => void;
	placeholder?: string;
	className?: string;
	inputClassName?: string;
	wrapperClassName?: string;
}

/**
 * Reusable table and toolbar search input with icon, clear action, and logical focus handling.
 */
export const SearchInput = forwardRef<HTMLInputElement, SearchInputProps>(
	function SearchInput(
		{
			value,
			onChange,
			onClear,
			placeholder = __("Search..."),
			className,
			inputClassName,
			wrapperClassName,
			disabled = false,
			id,
			"aria-label": ariaLabel = __("Search"),
			onKeyDown,
			...props
		},
		ref
	) {
		const internalInputRef = useRef<HTMLInputElement | null>(null);

		const handleRef = useCallback(
			(node: HTMLInputElement | null) => {
				internalInputRef.current = node;
				if (typeof ref === "function") {
					ref(node);
				} else if (ref) {
					ref.current = node;
				}
			},
			[ref]
		);

		const handleClear = useCallback(() => {
			onChange("");
			onClear?.();
			internalInputRef.current?.focus();
		}, [onChange, onClear]);

		const handleKeyDown = useCallback(
			(event: KeyboardEvent<HTMLInputElement>) => {
				if (event.key === "Escape" && value) {
					event.preventDefault();
					event.stopPropagation();
					handleClear();
				}
				onKeyDown?.(event);
			},
			[value, handleClear, onKeyDown]
		);

		return (
			<div className={cn("search-input-wrap", wrapperClassName)}>
				<Search
					size={14}
					className="search-input-icon"
					aria-hidden="true"
				/>
				<input
					ref={handleRef}
					id={id}
					type="text"
					value={value}
					onChange={(e) => onChange(e.target.value)}
					onKeyDown={handleKeyDown}
					placeholder={placeholder}
					disabled={disabled}
					aria-label={ariaLabel}
					className={cn(
						"form-control-base search-input-field",
						inputClassName,
						className
					)}
					{...props}
				/>
				{value && !disabled ? (
					<button
						type="button"
						onClick={handleClear}
						className="search-input-clear"
						aria-label={__("Clear search")}
						tabIndex={-1}
					>
						<X size={13} aria-hidden="true" />
					</button>
				) : null}
			</div>
		);
	}
);
