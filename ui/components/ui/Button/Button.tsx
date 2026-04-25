import { Loader2 } from "lucide-react";
import { isDocumentRtl } from "@/i18n/direction";
import { cn } from "@/utils";
import type {
	ButtonGroupProps,
	ButtonProps,
	ButtonSize,
	ButtonVariant,
	IconButtonProps,
} from "../types";
export type {
	ButtonGroupProps,
	ButtonIcon,
	ButtonProps,
	ButtonSize,
	ButtonVariant,
	IconButtonProps,
	IconPosition,
} from "../types";

/**
 * Button component with visual variants, sizing, optional icons, and loading state.
 */
export function Button({
	children,
	variant = "primary",
	size = "md",
	className = "",
	disabled = false,
	loading = false,
	onClick,
	type = "button",
	icon: Icon,
	iconPosition = "left",
	...props
}: ButtonProps) {
	const isRtl = isDocumentRtl();
	const variantClassNames: Record<ButtonVariant, string> = {
		primary: "button-primary",
		secondary: "button-secondary",
		danger: "button-danger",
		success: "button-success",
		warning: "button-warning",
		ghost: "button-ghost",
		outline: "button-outline",
	};

	const sizeClassNames: Record<ButtonSize, string> = {
		xs: "button-xs",
		sm: "button-sm",
		md: "button-md",
		lg: "button-lg",
		xl: "button-xl",
	};

	const iconSizes: Record<ButtonSize, number> = {
		xs: 14,
		sm: 16,
		md: 16,
		lg: 18,
		xl: 20,
	};

	const disabledStyles = disabled || loading ? "button-disabled" : false;
	const resolvedIconPosition = isRtl
		? "left" === iconPosition
			? "right"
			: "right" === iconPosition
				? "left"
				: iconPosition
		: iconPosition;
	const iconNode = loading ? (
		<Loader2 size={16} className="button-loading-icon" />
	) : Icon ? (
		<Icon size={iconSizes[size]} />
	) : null;

	return (
		<button
			type={type}
			className={cn(
				"button",
				variantClassNames[variant],
				sizeClassNames[size],
				disabledStyles,
				className
			)}
			disabled={disabled || loading}
			onClick={onClick}
			{...props}
		>
			<>
				{"left" === resolvedIconPosition ? iconNode : null}
				{children}
				{"right" === resolvedIconPosition ? iconNode : null}
			</>
		</button>
	);
}

/**
 * ButtonGroup wraps related buttons in a single bordered inline container.
 */
export function ButtonGroup({
	children,
	className = "",
	...props
}: ButtonGroupProps) {
	return (
		<div className={cn("button-group", className)} role="group" {...props}>
			{children}
		</div>
	);
}

/**
 * IconButton renders a square button that only contains an icon.
 */
export function IconButton({
	icon: IconComponent,
	size = "md",
	variant = "ghost",
	className = "",
	...props
}: IconButtonProps) {
	const iconButtonSizeClassNames: Record<ButtonSize, string> = {
		xs: "button-icon-only-xs",
		sm: "button-icon-only-sm",
		md: "button-icon-only-md",
		lg: "button-icon-only-lg",
		xl: "button-icon-only-xl",
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
			className={cn(
				"button-icon-only",
				iconButtonSizeClassNames[size],
				className
			)}
			{...props}
		>
			<IconComponent size={iconSizes[size]} />
		</Button>
	);
}
