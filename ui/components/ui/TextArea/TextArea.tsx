import { forwardRef, useId } from "react";
import { Info } from "lucide-react";
import { getDocumentDirection, getFieldDirection } from "@/i18n/direction";
import { cn } from "@/utils";
import type { TextAreaProps } from "../types";

export type { TextAreaProps } from "../types";

export const TextArea = forwardRef<HTMLTextAreaElement, TextAreaProps>(
	function TextArea(
		{
			label,
			error,
			className = "",
			helperText,
			valueDirection,
			rows = 3,
			...props
		},
		ref
	) {
		const generatedId = useId();
		const chromeDirection = getDocumentDirection();
		const contentDirection = getFieldDirection({
			fallbackDirection: chromeDirection,
			valueDirection,
			explicitDirection: props.dir,
		});
		const textAreaId = props.id || generatedId;
		const helperId = helperText ? `${textAreaId}-helper` : undefined;
		const errorId = error ? `${textAreaId}-error` : undefined;
		const describedBy =
			[errorId, helperId].filter(Boolean).join(" ") || undefined;
		const placeholderFollowsPageDirection =
			Boolean(valueDirection) && valueDirection !== chromeDirection;

		return (
			<div className="form-field">
				{label && (
					<label htmlFor={textAreaId} className="form-field-label">
						{label}
						{props.required && (
							<span className="field-required-indicator">*</span>
						)}
					</label>
				)}
				<div className="form-field-control">
					<textarea
						ref={ref}
						id={textAreaId}
						rows={rows}
						dir={contentDirection}
						aria-invalid={Boolean(error)}
						aria-describedby={describedBy}
						className={cn(
							"form-control-base",
							"form-control-accent-focus",
							"text-page-start",
							"form-field-textarea",
							placeholderFollowsPageDirection &&
								"placeholder-follow-page-direction",
							error && "form-field-control-error",
							className
						)}
						{...props}
					/>
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
	}
);

TextArea.displayName = "TextArea";
