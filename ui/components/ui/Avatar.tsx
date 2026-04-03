// @ts-nocheck
'use client';

import { useState } from 'react';
import { __ } from '@/i18n';
import { cn, getAvatarInitials, getGravatarUrl } from '@/utils';

const SIZE_STYLES = {
	sm: {
		wrapper: 'h-8 w-8 rounded-lg',
		text: 'text-sm',
		image: 64,
	},
	md: {
		wrapper: 'h-11 w-11 rounded-xl',
		text: 'text-base',
		image: 96,
	},
};

export const Avatar = ({
	email = '',
	firstName = '',
	lastName = '',
	fallbackName = '',
	size = 'sm',
	className = '',
}) => {
	const sizeStyle = SIZE_STYLES[size] || SIZE_STYLES.sm;
	const imageUrl = getGravatarUrl(email, sizeStyle.image);
	const initials = getAvatarInitials(firstName, lastName, fallbackName);
	const label =
		`${firstName} ${lastName}`.trim() || fallbackName || __('User avatar');
	const [failedImageUrl, setFailedImageUrl] = useState('');
	const showImage = Boolean(imageUrl) && failedImageUrl !== imageUrl;

	return (
		<div
			className={cn(
				'relative inline-flex shrink-0 items-center justify-center overflow-hidden bg-primary-600 font-semibold text-white',
				sizeStyle.wrapper,
				className
			)}
		>
			{showImage ? (
				<img
					src={imageUrl}
					alt={label}
					className="h-full w-full object-cover"
					onError={() => setFailedImageUrl(imageUrl)}
					referrerPolicy="no-referrer"
				/>
			) : (
				<span className={cn('select-none', sizeStyle.text)}>
					{initials}
				</span>
			)}
		</div>
	);
};
