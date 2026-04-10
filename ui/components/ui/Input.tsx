import { forwardRef, useId } from 'react';
import { Info } from 'lucide-react';
import { getDocumentDirection, getFieldDirection } from '@/i18n/direction';
import type { InputProps } from './types';
export type { InputIcon, InputProps } from './types';

const LTR_INPUT_TYPES = new Set([
	'date',
	'datetime-local',
	'email',
	'month',
	'number',
	'tel',
	'time',
	'url',
	'week',
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
		className = '',
		type = 'text',
		helperText,
		valueDirection,
		...props
	},
	ref
) {
	const generatedId = useId();
	const chromeDirection = getDocumentDirection();
	const preferredValueDirection =
		valueDirection || (LTR_INPUT_TYPES.has(type) ? 'ltr' : undefined);
	const contentDirection = getFieldDirection({
		fallbackDirection: chromeDirection,
		valueDirection: preferredValueDirection,
		explicitDirection: props.dir,
	});
	const inputId = props.id || generatedId;
	const helperId = helperText ? `${inputId}-helper` : undefined;
	const errorId = error ? `${inputId}-error` : undefined;
	const describedBy =
		[errorId, helperId].filter(Boolean).join(' ') || undefined;
	const hasInlineStartIcon = Boolean(IconComponent);
	const placeholderFollowsPageDirection =
		Boolean(preferredValueDirection) &&
		preferredValueDirection !== chromeDirection;

	return (
		<div className="form-field">
			{label && (
				<label
					htmlFor={inputId}
					className="form-field-label"
				>
					{label}
					{props.required && (
						<span className="field-required-indicator text-red-500">
							*
						</span>
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
					type={type}
					dir={contentDirection}
					aria-invalid={Boolean(error)}
					aria-describedby={describedBy}
					className={`form-control-base form-control-accent-focus text-page-start form-field-input ${
						hasInlineStartIcon
							? 'field-with-inline-start-icon'
							: 'px-4'
					} ${
						placeholderFollowsPageDirection
							? 'placeholder-follow-page-direction'
							: ''
					} ${
						error ? 'form-field-control-error' : ''
					} ${className}`}
					{...props}
				/>
			</div>
			{error && (
				<p
					id={errorId}
					className="form-field-error"
				>
					<Info size={14} />
					{error}
				</p>
			)}
			{helperText && !error && (
				<p
					id={helperId}
					className="form-field-helper"
				>
					{helperText}
				</p>
			)}
		</div>
	);
});

Input.displayName = 'Input';
