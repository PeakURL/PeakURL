import { forwardRef, useId } from 'react';
import { Info } from 'lucide-react';
import type { InputProps } from './types';
export type { InputIcon, InputProps } from './types';

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
		...props
	},
	ref
) {
	const generatedId = useId();
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
				>
					{label}
					{props.required && (
						<span className="text-red-500 ml-1">*</span>
					)}
				</label>
			)}
			<div className="relative">
				{IconComponent && (
					<div className="absolute left-4 top-1/2 -translate-y-1/2 text-muted pointer-events-none">
						<IconComponent size={18} />
					</div>
				)}
				<input
					ref={ref}
					id={inputId}
					type={type}
					aria-invalid={Boolean(error)}
					aria-describedby={describedBy}
					className={`w-full px-4 py-2 bg-surface border border-stroke rounded-md text-heading placeholder:text-muted outline-none transition-all focus:ring-2 focus:ring-accent focus:border-accent ${
						IconComponent ? 'pl-11' : ''
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
					className="text-sm text-red-600 dark:text-red-400 font-medium flex items-center gap-1.5"
				>
					<Info size={14} />
					{error}
				</p>
			)}
			{helperText && !error && (
				<p id={helperId} className="text-xs text-muted">
					{helperText}
				</p>
			)}
		</div>
	);
});

Input.displayName = 'Input';
