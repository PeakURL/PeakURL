import { forwardRef, useId } from 'react';
import { Info } from 'lucide-react';
import { getDocumentDirection, resolveFieldDirection } from '@/i18n/direction';
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
	const contentDirection = resolveFieldDirection({
		value: props.value,
		defaultValue: props.defaultValue,
		fallbackDirection: chromeDirection,
		valueDirection: preferredValueDirection,
		explicitDirection: props.dir,
	});
	const inputId = props.id || generatedId;
	const helperId = helperText ? `${inputId}-helper` : undefined;
	const errorId = error ? `${inputId}-error` : undefined;
	const describedBy =
		[errorId, helperId].filter(Boolean).join(' ') || undefined;

	return (
		<div className="space-y-2">
			{label && (
				<label
					htmlFor={inputId}
					className="logical-text-start block text-sm font-semibold text-heading"
				>
					{label}
					{props.required && (
						<span
							className={`text-red-500 ${
								'rtl' === chromeDirection ? 'mr-1' : 'ml-1'
							}`}
						>
							*
						</span>
					)}
				</label>
			)}
			<div className="relative">
				{IconComponent && (
					<div
						className={`absolute top-1/2 -translate-y-1/2 text-muted pointer-events-none ${
							'rtl' === chromeDirection ? 'right-4' : 'left-4'
						}`}
					>
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
					className={`w-full px-4 py-2 bg-surface border border-stroke rounded-md text-heading placeholder:text-muted outline-none transition-all focus:ring-2 focus:ring-accent focus:border-accent ${
						IconComponent
							? 'rtl' === chromeDirection
								? 'pr-11'
								: 'pl-11'
							: ''
					} ${
						'rtl' === chromeDirection ? 'text-right' : 'text-left'
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
					className="logical-text-start text-sm text-red-600 dark:text-red-400 font-medium flex items-center gap-1.5"
				>
					<Info size={14} />
					{error}
				</p>
			)}
			{helperText && !error && (
				<p
					id={helperId}
					className="logical-text-start text-xs text-muted"
				>
					{helperText}
				</p>
			)}
		</div>
	);
});

Input.displayName = 'Input';
