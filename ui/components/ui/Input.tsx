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
		<div className="space-y-2">
			{label && (
				<label
					htmlFor={inputId}
					className="text-inline-start block text-sm font-semibold text-heading"
				>
					{label}
					{props.required && (
						<span className="field-required-indicator text-red-500">
							*
						</span>
					)}
				</label>
			)}
			<div className="relative">
				{IconComponent && (
					<div className="inline-start-icon-slot pointer-events-none absolute inset-y-0 flex items-center text-muted">
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
					className={`text-page-start w-full rounded-md border border-stroke bg-surface py-2 text-heading placeholder:text-muted outline-none transition-all focus:border-accent focus:ring-2 focus:ring-accent ${
						hasInlineStartIcon
							? 'field-with-inline-start-icon'
							: 'px-4'
					} ${
						placeholderFollowsPageDirection
							? 'placeholder-follow-page-direction'
							: ''
					} ${
						error
							? 'border-red-500 focus:ring-red-500 focus:border-red-500'
							: ''
					} ${className}`}
					{...props}
				/>
			</div>
			{error && (
				<p
					id={errorId}
					className="text-inline-start text-sm text-red-600 dark:text-red-400 font-medium flex items-center gap-1.5"
				>
					<Info size={14} />
					{error}
				</p>
			)}
			{helperText && !error && (
				<p
					id={helperId}
					className="text-inline-start text-xs text-muted"
				>
					{helperText}
				</p>
			)}
		</div>
	);
});

Input.displayName = 'Input';
