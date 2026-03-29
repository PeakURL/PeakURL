// @ts-nocheck
'use client';
import { forwardRef } from 'react';
import { Info } from 'lucide-react';

/**
 * Input Component
 * A styled text input with label, error, and icon support.
 * Uses forwardRef to pass ref to the underlying input element.
 * @param {Object} props
 * @param {string} props.label - Input label text
 * @param {string} props.error - Error message to display
 * @param {React.ElementType} props.icon - Icon component
 * @param {string} [props.className=''] - Additional class names
 * @param {string} [props.type='text'] - Input type attribute
 * @param {string} props.helperText - Helper text to display below the input
 */
export const Input = forwardRef(
	(
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
	) => {
		return (
			<div className="space-y-2">
				{/* Input Label */}
				{label && (
					<label className="block text-sm font-semibold text-heading">
						{label}
						{props.required && (
							<span className="text-red-500 ml-1">*</span>
						)}
					</label>
				)}
				<div className="relative">
					{/* Icon */}
					{IconComponent && (
						<div className="absolute left-4 top-1/2 -translate-y-1/2 text-muted pointer-events-none">
							<IconComponent size={18} />
						</div>
					)}
					{/* Input Element */}
					<input
						ref={ref}
						type={type}
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
				{/* Error Message */}
				{error && (
					<p className="text-sm text-red-600 dark:text-red-400 font-medium flex items-center gap-1.5">
						<Info size={14} />
						{error}
					</p>
				)}
				{/* Helper Text */}
				{helperText && !error && (
					<p className="text-xs text-muted">{helperText}</p>
				)}
			</div>
		);
	}
);

Input.displayName = 'Input';
