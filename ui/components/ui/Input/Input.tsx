import { forwardRef, useId, useState } from "react";
import { Info, Eye, EyeOff } from "lucide-react";

import { getDocumentDirection, getFieldDirection } from "@/i18n/direction";
import { cn } from "@/utils";

import type { InputProps } from "../types";

export type { InputIcon, InputProps } from "../types";

const LTR_INPUT_TYPES = new Set([
	"date",
	"datetime-local",
	"email",
	"month",
	"number",
	"tel",
	"time",
	"url",
	"week",
]);

/**
 * Input component with label, helper text, validation state, and optional icon.
 *
 * @param props Input props
 * @param props.label Input label text
 * @param props.error Error message shown below the field
 * @param props.icon Optional leading icon
 * @param props.helperText Helper text shown when there is no error
 */
export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
	{
		label,
		error,
		icon: IconComponent,
		className = "",
		type = "text",
		helperText,
		valueDirection,
		...props
	},
	ref
) {
	const [showPassword, setShowPassword] = useState(false);
	const isPasswordField = type === "password";
	const actualType = isPasswordField
		? showPassword
			? "text"
			: "password"
		: type;

	const generatedId = useId();
	const chromeDirection = getDocumentDirection();
	const preferredValueDirection =
		valueDirection || (LTR_INPUT_TYPES.has(actualType) ? "ltr" : undefined);
	const contentDirection = getFieldDirection({
		fallbackDirection: chromeDirection,
		valueDirection: preferredValueDirection,
		explicitDirection: props.dir,
	});
	const inputId = props.id || generatedId;
	const helperId = helperText ? `${inputId}-helper` : undefined;
	const errorId = error ? `${inputId}-error` : undefined;
	const describedBy =
		[errorId, helperId].filter(Boolean).join(" ") || undefined;
	const hasInlineStartIcon = Boolean(IconComponent);
	const placeholderFollowsPageDirection =
		Boolean(preferredValueDirection) &&
		preferredValueDirection !== chromeDirection;

	return (
		<div className="form-field">
			{label && (
				<label htmlFor={inputId} className="form-field-label">
					{label}
					{props.required && (
						<span className="field-required-indicator">*</span>
					)}
				</label>
			)}
			<div className="form-field-control">
				{IconComponent && (
					<div className="form-field-icon inline-start-icon-slot">
						<IconComponent size={18} />
					</div>
				)}
				<input
					ref={ref}
					id={inputId}
					type={actualType}
					dir={contentDirection}
					aria-invalid={Boolean(error)}
					aria-describedby={describedBy}
					className={cn(
						"form-control-base",
						"form-control-accent-focus",
						"text-page-start",
						"form-field-input",
						hasInlineStartIcon && "field-with-inline-start-icon",
						isPasswordField && "field-with-inline-end-icon",
						!hasInlineStartIcon &&
							!isPasswordField &&
							"form-field-input-no-icon",
						placeholderFollowsPageDirection &&
							"placeholder-follow-page-direction",
						error && "form-field-control-error",
						className
					)}
					{...props}
				/>
				{isPasswordField && (
					<button
						type="button"
						className="form-field-icon pointer-events-auto cursor-pointer inline-end-icon-slot text-slate-400 hover:text-slate-600 focus:text-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded-full p-2 absolute transition-colors"
						onClick={() => setShowPassword(!showPassword)}
						aria-label={
							showPassword ? "Hide password" : "Show password"
						}
						aria-pressed={showPassword}
					>
						{showPassword ? (
							<EyeOff size={18} />
						) : (
							<Eye size={18} />
						)}
					</button>
				)}
			</div>
			{error && (
				<p id={errorId} className="form-field-error">
					<Info size={14} />
					{error}
				</p>
			)}
			{helperText && !error && (
				<p id={helperId} className="form-field-helper">
					{helperText}
				</p>
			)}
		</div>
	);
});

Input.displayName = "Input";
