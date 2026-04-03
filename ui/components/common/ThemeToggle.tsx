// @ts-nocheck
'use client';
import { Moon, Sun } from 'lucide-react';
import { useTheme } from '../providers/ThemeProvider';
import { __ } from '@/i18n';

export const ThemeToggle = () => {
	const { theme, toggleTheme } = useTheme();

	return (
		<button
			onClick={toggleTheme}
			className="relative p-2.5 rounded-xl bg-surface-alt hover:bg-stroke transition-all duration-200 active:scale-95"
			aria-label={__('Toggle theme')}
		>
			<div className="relative w-5 h-5">
				<Sun
					className={`absolute inset-0 w-5 h-5 text-heading transition-all duration-300 ${
						theme === 'dark'
							? 'opacity-0 rotate-90 scale-0'
							: 'opacity-100 rotate-0 scale-100'
					}`}
				/>
				<Moon
					className={`absolute inset-0 w-5 h-5 text-heading transition-all duration-300 ${
						theme === 'dark'
							? 'opacity-100 rotate-0 scale-100'
							: 'opacity-0 -rotate-90 scale-0'
					}`}
				/>
			</div>
		</button>
	);
};
