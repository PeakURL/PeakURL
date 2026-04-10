import { useState } from 'react';
import { __ } from '@/i18n';
import { cn, getAvatarInitials, getGravatarUrl } from '@/utils';
import type { AvatarProps } from './types';
export type { AvatarProps, AvatarSize } from './types';

const SIZE_STYLES = {
	sm: {
		wrapper: 'avatar-sm',
		text: 'avatar-text-sm',
		image: 64,
	},
	md: {
		wrapper: 'avatar-md',
		text: 'avatar-text-md',
		image: 96,
	},
};

export const Avatar = ({
	email,
	firstName,
	lastName,
	fallbackName,
	size = 'sm',
	className = '',
}: AvatarProps) => {
	const sizeStyle = SIZE_STYLES[size];
	const normalizedEmail = email ?? '';
	const normalizedFirstName = firstName ?? '';
	const normalizedLastName = lastName ?? '';
	const normalizedFallbackName = fallbackName ?? '';
	const imageUrl = getGravatarUrl(normalizedEmail, sizeStyle.image);
	const initials = getAvatarInitials(
		normalizedFirstName,
		normalizedLastName,
		normalizedFallbackName
	);
	const label =
		`${normalizedFirstName} ${normalizedLastName}`.trim() ||
		normalizedFallbackName ||
		__('User avatar');
	const [failedImageUrl, setFailedImageUrl] = useState('');
	const showImage = Boolean(imageUrl) && failedImageUrl !== imageUrl;

	return (
		<div
			className={cn(
				'avatar',
				sizeStyle.wrapper,
				className
			)}
		>
			{showImage ? (
				<img
					src={imageUrl}
					alt={label}
					className="avatar-image"
					onError={() => setFailedImageUrl(imageUrl)}
					referrerPolicy="no-referrer"
				/>
			) : (
				<span className={cn('avatar-text', sizeStyle.text)}>
					{initials}
				</span>
			)}
		</div>
	);
};
