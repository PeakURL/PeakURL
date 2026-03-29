// @ts-nocheck
'use client';

import { useEffect, useRef, useState } from 'react';

const toDigits = (value, length) => {
	const sanitized = String(value || '')
		.replace(/\D/g, '')
		.slice(0, length);

	return Array.from({ length }, (_, index) => sanitized[index] || '');
};

export function VerificationCodeInput({
	length = 6,
	value = '',
	onChange,
	onComplete,
	disabled = false,
	className = '',
}) {
	const [digits, setDigits] = useState(() => toDigits(value, length));
	const inputsRef = useRef([]);
	const nextEmptyIndex = digits.findIndex((digit) => !digit);
	const highlightedIndex =
		nextEmptyIndex === -1 ? length - 1 : nextEmptyIndex;

	useEffect(() => {
		setDigits(toDigits(value, length));
	}, [length, value]);

	useEffect(() => {
		if (!disabled) {
			inputsRef.current[0]?.focus();
		}
	}, [disabled]);

	const updateDigits = (nextDigits) => {
		setDigits(nextDigits);

		const nextValue = nextDigits.join('');
		onChange?.(nextValue);

		if (nextDigits.every(Boolean)) {
			onComplete?.(nextValue);
		}
	};

	const handleChange = (event, index) => {
		const sanitized = event.target.value.replace(/\D/g, '');

		if (!sanitized) {
			const nextDigits = [...digits];
			nextDigits[index] = '';
			updateDigits(nextDigits);
			return;
		}

		const nextDigits = [...digits];
		const nextValues = sanitized.slice(0, length - index).split('');

		nextValues.forEach((digit, offset) => {
			nextDigits[index + offset] = digit;
		});

		updateDigits(nextDigits);

		const nextIndex = Math.min(index + nextValues.length, length - 1);
		inputsRef.current[nextIndex]?.focus();
	};

	const handleKeyDown = (event, index) => {
		if (event.key === 'Backspace') {
			event.preventDefault();

			if (digits[index]) {
				const nextDigits = [...digits];
				nextDigits[index] = '';
				updateDigits(nextDigits);
				return;
			}

			if (index > 0) {
				const previousIndex = index - 1;
				const nextDigits = [...digits];
				nextDigits[previousIndex] = '';
				updateDigits(nextDigits);
				inputsRef.current[previousIndex]?.focus();
			}
			return;
		}

		if (event.key === 'ArrowLeft' && index > 0) {
			event.preventDefault();
			inputsRef.current[index - 1]?.focus();
		}

		if (event.key === 'ArrowRight' && index < length - 1) {
			event.preventDefault();
			inputsRef.current[index + 1]?.focus();
		}
	};

	const handlePaste = (event) => {
		event.preventDefault();

		const pastedDigits = event.clipboardData
			.getData('text')
			.replace(/\D/g, '')
			.slice(0, length);

		if (!pastedDigits) {
			return;
		}

		const nextDigits = toDigits(pastedDigits, length);
		updateDigits(nextDigits);
		inputsRef.current[Math.min(pastedDigits.length, length) - 1]?.focus();
	};

	return (
		<div
			className={`flex w-full items-center justify-center gap-2 sm:gap-2.5 ${className}`}
			onPaste={handlePaste}
		>
			{digits.map((digit, index) => (
				<input
					key={index}
					ref={(element) => {
						inputsRef.current[index] = element;
					}}
					type="text"
					inputMode="numeric"
					autoComplete={index === 0 ? 'one-time-code' : 'off'}
					maxLength={1}
					value={digit}
					disabled={disabled}
					onChange={(event) => handleChange(event, index)}
					onKeyDown={(event) => handleKeyDown(event, index)}
					className={`h-12 w-0 min-w-0 flex-1 rounded-xl border text-center text-lg font-semibold tabular-nums text-heading outline-none transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-60 sm:h-14 sm:text-xl ${
						digit
							? 'border-accent/30 bg-accent/[0.08] shadow-[0_10px_24px_rgba(99,102,241,0.12)]'
							: index === highlightedIndex && !disabled
								? 'border-accent/25 bg-surface shadow-[0_8px_20px_rgba(15,23,42,0.05)]'
								: 'border-stroke bg-surface'
					} focus:border-accent focus:bg-surface focus:shadow-[0_0_0_4px_rgba(99,102,241,0.12),0_14px_28px_rgba(99,102,241,0.16)]`}
				/>
			))}
		</div>
	);
}
