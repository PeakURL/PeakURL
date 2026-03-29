// @ts-nocheck
'use client';
import { Loader2 } from 'lucide-react';

/**
 * Button Component
 * A customizable button with variants, sizes, and loading state.
 * @param {Object} props
 * @param {React.ReactNode} props.children - Button content
 * @param {('primary'|'secondary'|'danger'|'success'|'warning'|'ghost'|'outline')} [props.variant='primary'] - Button style variant
 * @param {('xs'|'sm'|'md'|'lg'|'xl')} [props.size='md'] - Button size
 * @param {string} [props.className=''] - Additional class names
 * @param {boolean} [props.disabled=false] - Is the button disabled?
 * @param {boolean} [props.loading=false] - Is the button in a loading state?
 * @param {Function} props.onClick - Click handler
 * @param {('button'|'submit'|'reset')} [props.type='button'] - Button type attribute
 * @param {React.ElementType} props.icon - Icon component
 * @param {('left'|'right')} [props.iconPosition='left'] - Icon position relative to text
 */
export function Button({
	children,
	variant = 'primary',
	size = 'md',
	className = '',
	disabled = false,
	loading = false,
	onClick,
	type = 'button',
	icon,
	iconPosition = 'left',
	...props
}) {
	// Base styles
	const baseStyles =
		'relative inline-flex items-center justify-center font-semibold rounded-md transition-all duration-200 select-none disabled:opacity-60 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent/50';

	// Style variants
	const variants = {
		primary:
			'bg-accent text-white shadow-sm hover:shadow-md hover:bg-primary-600 active:scale-[0.98]',
		secondary:
			'bg-surface text-heading border border-stroke hover:bg-surface-alt hover:shadow-sm active:scale-[0.98]',
		danger: 'bg-red-600 text-white shadow-sm hover:shadow-md hover:bg-red-700 active:scale-[0.98]',
		success:
			'bg-emerald-600 text-white shadow-sm hover:shadow-md hover:bg-emerald-700 active:scale-[0.98]',
		warning:
			'bg-amber-600 text-white shadow-sm hover:shadow-md hover:bg-amber-700 active:scale-[0.98]',
		ghost: 'text-heading hover:bg-surface-alt active:scale-[0.98]',
		outline:
			'border border-stroke text-heading hover:border-accent hover:bg-accent/10 active:scale-[0.98]',
	};

	const sizes = {
		xs: 'px-3 py-1.5 text-xs gap-1.5',
		sm: 'px-4 py-2 text-sm gap-2',
		md: 'px-5 py-2.5 text-sm gap-2',
		lg: 'px-7 py-3.5 text-base gap-2.5',
		xl: 'px-9 py-4 text-lg gap-3',
	};

	const disabledStyles = disabled || loading ? 'pointer-events-none' : '';

	return (
		<button
			type={type}
			className={`${baseStyles} ${variants[variant]} ${sizes[size]} ${disabledStyles} ${className}`}
			disabled={disabled || loading}
			onClick={onClick}
			{...props}
		>
			{loading ? (
				<>
					<Loader2 size={16} className="animate-spin" />
					{children}
				</>
			) : (
				children
			)}
		</button>
	);
}

/**
 * ButtonGroup Component
 * Wraps multiple buttons in a container for related actions.
 * @param {Object} props
 * @param {React.ReactNode} props.children - Button components
 * @param {string} [props.className=''] - Additional class names
 */
export function ButtonGroup({ children, className = '', ...props }) {
	return (
		<div
			className={`inline-flex rounded-md shadow-sm overflow-hidden border border-stroke ${className}`}
			role="group"
			{...props}
		>
			{children}
		</div>
	);
}

/**
 * IconButton Component
 * A square button with only an icon.
 * @param {Object} props
 * @param {React.ElementType} props.icon - Icon component
 * @param {('xs'|'sm'|'md'|'lg'|'xl')} [props.size='md'] - Button size
 * @param {string} [props.variant='ghost'] - Button style variant
 * @param {string} [props.className=''] - Additional class names
 */
export function IconButton({
	icon: IconComponent,
	size = 'md',
	variant = 'ghost',
	className = '',
	...props
}) {
	const sizes = {
		xs: 'w-7 h-7',
		sm: 'w-8 h-8',
		md: 'w-9 h-9',
		lg: 'w-10 h-10',
		xl: 'w-12 h-12',
	};

	const iconSizes = {
		xs: 14,
		sm: 16,
		md: 18,
		lg: 20,
		xl: 24,
	};

	return (
		<Button
			variant={variant}
			className={`${sizes[size]} p-0! ${className}`}
			{...props}
		>
			<IconComponent size={iconSizes[size]} />
		</Button>
	);
}
