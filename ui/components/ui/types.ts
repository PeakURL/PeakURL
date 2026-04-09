import type {
	ButtonHTMLAttributes,
	ComponentPropsWithoutRef,
	ComponentType,
	HTMLAttributes,
	KeyboardEvent,
	InputHTMLAttributes,
	ReactNode,
} from 'react';

/**
 * Supported avatar size tokens for the shared avatar component.
 */
export type AvatarSize = 'sm' | 'md';

/**
 * Props for rendering a user avatar with name and email fallbacks.
 */
export interface AvatarProps {
	email?: string | null;
	firstName?: string | null;
	lastName?: string | null;
	fallbackName?: string | null;
	size?: AvatarSize;
	className?: string;
}

/**
 * Visual style variants supported by the shared button component.
 */
export type ButtonVariant =
	| 'primary'
	| 'secondary'
	| 'danger'
	| 'success'
	| 'warning'
	| 'ghost'
	| 'outline';

/**
 * Size presets shared by button-like controls.
 */
export type ButtonSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

/**
 * Icon placement options for buttons that render both text and an icon.
 */
export type IconPosition = 'left' | 'right';

/**
 * Icon component contract used across button surfaces.
 */
export type ButtonIcon = ComponentType<{ className?: string; size?: number }>;

/**
 * Props for the shared button component.
 */
export interface ButtonProps
	extends ButtonHTMLAttributes<HTMLButtonElement> {
	children?: ReactNode;
	variant?: ButtonVariant;
	size?: ButtonSize;
	loading?: boolean;
	icon?: ButtonIcon;
	iconPosition?: IconPosition;
}

/**
 * Props for grouping adjacent buttons into a single visual control.
 */
export interface ButtonGroupProps extends HTMLAttributes<HTMLDivElement> {
	children?: ReactNode;
}

/**
 * Props for an icon-only button.
 */
export interface IconButtonProps
	extends Omit<ButtonProps, 'children' | 'icon'> {
	icon: ButtonIcon;
}

/**
 * Confirm action variants reuse the shared button variants.
 */
export type ConfirmVariant = ButtonVariant;

/**
 * Props for the reusable confirmation dialog.
 */
export interface ConfirmDialogProps {
	open: boolean;
	onClose: () => void;
	title: string;
	description?: string;
	children?: ReactNode;
	confirmText?: string;
	cancelText?: string;
	onConfirm: () => void | Promise<void>;
	confirmVariant?: ConfirmVariant;
	loading?: boolean;
}

/**
 * Optional icon contract for text inputs.
 */
export type InputIcon = ComponentType<{ size?: number; className?: string }>;

/**
 * Props for the shared input component.
 */
export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
	label?: string;
	error?: string;
	icon?: InputIcon;
	helperText?: string;
	valueDirection?: 'ltr' | 'rtl';
}

/**
 * Primitive values supported by the shared select component.
 */
export type SelectValue = string | number;

/**
 * Option shape consumed by the shared select component.
 */
export interface SelectOption<T extends SelectValue = SelectValue> {
	label: ReactNode;
	value: T;
	disabled?: boolean;
}

/**
 * Props for the shared anchored select component.
 */
export interface SelectProps<T extends SelectValue = SelectValue>
	extends Omit<HTMLAttributes<HTMLDivElement>, 'children' | 'onChange'> {
	id?: string;
	value: T;
	options: SelectOption<T>[];
	onChange: (value: T) => void;
	disabled?: boolean;
	ariaLabel?: string;
	buttonClassName?: string;
	optionsClassName?: string;
	optionClassName?: string;
}

/**
 * Supported loading spinner size tokens.
 */
export type LoadingSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

/**
 * Accent colors available to the pulse-dot status indicator.
 */
export type PulseDotColor = 'green' | 'red' | 'yellow' | 'blue' | 'purple';

/**
 * Size presets for pulse-dot indicators.
 */
export type PulseDotSize = 'xs' | 'sm' | 'md' | 'lg';

/**
 * Supported progress-bar color themes.
 */
export type ProgressColor =
	| 'blue'
	| 'green'
	| 'red'
	| 'yellow'
	| 'purple'
	| 'accent';

/**
 * Props for the inline loading spinner component.
 */
export interface LoadingSpinnerProps {
	size?: LoadingSize;
	className?: string;
}

/**
 * Pass-through props for the generic skeleton loader block.
 */
export type SkeletonLoaderProps = ComponentPropsWithoutRef<'div'>;

/**
 * Props for rendering a skeleton table row.
 */
export interface TableRowSkeletonProps {
	columns?: number;
}

/**
 * Props for the pulse-dot status indicator.
 */
export interface PulseDotProps {
	color?: PulseDotColor;
	size?: PulseDotSize;
	animated?: boolean;
}

/**
 * Props for the shared progress bar component.
 */
export interface ProgressBarProps {
	progress?: number;
	color?: ProgressColor;
	className?: string;
	showLabel?: boolean;
}

/**
 * Props for the small inline loader glyph.
 */
export interface InlineLoaderProps {
	className?: string;
}

/**
 * Width presets for the shared modal component.
 */
export type ModalSize = 'sm' | 'md' | 'lg' | 'xl';

/**
 * Props for the shared modal surface.
 */
export interface ModalProps {
	isOpen: boolean;
	onClose: () => void;
	title?: string;
	children: ReactNode;
	size?: ModalSize;
}

/**
 * Visual variants supported by dashboard notifications.
 */
export type NotificationType = 'success' | 'error' | 'warning' | 'info';

/**
 * Public payload shape used to enqueue a notification.
 */
export interface NotificationPayload {
	type: NotificationType;
	title: string;
	message?: string;
	duration?: number;
	className?: string;
}

/**
 * Notification payload extended with a stable runtime identifier.
 */
export interface NotificationItem extends NotificationPayload {
	id: number;
}

/**
 * Props for rendering a single notification.
 */
export interface NotificationProps extends NotificationItem {
	onClose?: (id: number) => void;
}

/**
 * Props for the fixed notification stack container.
 */
export interface NotificationContainerProps {
	notifications?: NotificationItem[];
	onRemoveNotification?: (id: number) => void;
}

/**
 * Props for the multi-field verification code input.
 */
export interface VerificationCodeInputProps {
	length?: number;
	value?: string;
	onChange?: (value: string) => void;
	onComplete?: (value: string) => void;
	onEnter?: (event: KeyboardEvent<HTMLInputElement>) => void;
	disabled?: boolean;
	className?: string;
}
