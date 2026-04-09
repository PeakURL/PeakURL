import { Loader2 } from 'lucide-react';
import { isDocumentRtl } from '@/i18n/direction';
import type {
	ButtonGroupProps,
	ButtonProps,
	ButtonSize,
	ButtonVariant,
	IconButtonProps,
} from './types';
export type {
	ButtonGroupProps,
	ButtonIcon,
	ButtonProps,
	ButtonSize,
	ButtonVariant,
	IconButtonProps,
	IconPosition,
} from './types';

/**
 * Button component with visual variants, sizing, optional icons, and loading state.
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
	icon: Icon,
	iconPosition = 'left',
	...props
}: ButtonProps) {
	const isRtl = isDocumentRtl();
	const baseStyles =
		'relative inline-flex items-center justify-center font-semibold rounded-md transition-all duration-200 select-none disabled:opacity-60 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent/50';

	const variants: Record<ButtonVariant, string> = {
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

	const sizes: Record<ButtonSize, string> = {
		xs: 'px-3 py-1.5 text-xs gap-1.5',
		sm: 'px-4 py-2 text-sm gap-2',
		md: 'px-5 py-2.5 text-sm gap-2',
		lg: 'px-7 py-3.5 text-base gap-2.5',
		xl: 'px-9 py-4 text-lg gap-3',
	};

	const iconSizes: Record<ButtonSize, number> = {
		xs: 14,
		sm: 16,
		md: 16,
		lg: 18,
		xl: 20,
	};

	const disabledStyles = disabled || loading ? 'pointer-events-none' : '';
	const resolvedIconPosition = isRtl
		? 'left' === iconPosition
			? 'right'
			: 'right' === iconPosition
			? 'left'
			: iconPosition
		: iconPosition;
	const iconNode = loading ? (
		<Loader2 size={16} className="animate-spin" />
	) : Icon ? (
		<Icon size={iconSizes[size]} />
	) : null;

	return (
		<button
			type={type}
			className={`${baseStyles} ${variants[variant]} ${sizes[size]} ${disabledStyles} ${className}`}
			disabled={disabled || loading}
			onClick={onClick}
			{...props}
		>
			<>
				{'left' === resolvedIconPosition ? iconNode : null}
				{children}
				{'right' === resolvedIconPosition ? iconNode : null}
			</>
		</button>
	);
}

/**
 * ButtonGroup wraps related buttons in a single bordered inline container.
 */
export function ButtonGroup({
	children,
	className = '',
	...props
}: ButtonGroupProps) {
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
 * IconButton renders a square button that only contains an icon.
 */
export function IconButton({
	icon: IconComponent,
	size = 'md',
	variant = 'ghost',
	className = '',
	...props
}: IconButtonProps) {
	const sizes: Record<ButtonSize, string> = {
		xs: 'w-7 h-7',
		sm: 'w-8 h-8',
		md: 'w-9 h-9',
		lg: 'w-10 h-10',
		xl: 'w-12 h-12',
	};

	const iconSizes: Record<ButtonSize, number> = {
		xs: 14,
		sm: 16,
		md: 18,
		lg: 20,
		xl: 24,
	};

	return (
		<Button
			variant={variant}
			size={size}
			className={`${sizes[size]} p-0! ${className}`}
			{...props}
		>
			<IconComponent size={iconSizes[size]} />
		</Button>
	);
}
