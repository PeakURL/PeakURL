import { forwardRef, useId } from 'react';
import { Info } from 'lucide-react';
import { getDocumentDirection, isDocumentRtl, resolveFieldDirection } from '@/i18n/direction';
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
	const isRtl = isDocumentRtl();
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
					className="block text-sm font-semibold text-heading"
					style={{ textAlign: 'start' }}
				>
					{label}
					{props.required && (
						<span
							className={`text-red-500 ${
								isRtl ? 'mr-1' : 'ml-1'
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
						error
							? 'border-red-500 focus:ring-red-500 focus:border-red-500'
							: ''
					} ${className}`}
					style={{
						textAlign:
							'rtl' === chromeDirection ? 'right' : 'left',
					}}
					{...props}
				/>
			</div>
			{error && (
				<p
					id={errorId}
					className="text-sm text-red-600 dark:text-red-400 font-medium flex items-center gap-1.5"
					style={{ textAlign: 'start' }}
				>
					<Info size={14} />
					{error}
				</p>
			)}
			{helperText && !error && (
				<p
					id={helperId}
					className="text-xs text-muted"
					style={{ textAlign: 'start' }}
				>
					{helperText}
				</p>
			)}
		</div>
	);
});

Input.displayName = 'Input';
