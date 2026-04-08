import {
	Listbox,
	ListboxButton,
	ListboxOption,
	ListboxOptions,
} from '@headlessui/react';
import { Check, ChevronDown } from 'lucide-react';
import { isDocumentRtl } from '@/i18n/direction';
import type { SelectProps, SelectValue } from './types';

export type { SelectOption, SelectProps, SelectValue } from './types';

export function Select<T extends SelectValue>({
	id,
	value,
	options,
	onChange,
	disabled = false,
	ariaLabel,
	className = '',
	buttonClassName = '',
	optionsClassName = '',
	optionClassName = '',
	...props
}: SelectProps<T>) {
	const isRtl = isDocumentRtl();
	const selectedOption =
		options.find((option) => option.value === value) ?? options[0];

	return (
		<div className={className} {...props}>
			<Listbox value={value} onChange={onChange} disabled={disabled}>
				{({ open }) => (
					<>
						<ListboxButton
							id={id}
							aria-label={ariaLabel}
							className={`flex w-full items-center justify-between gap-3 rounded-md border bg-surface px-4 py-2 text-sm text-heading outline-none transition-all focus:border-accent focus:ring-2 focus:ring-accent/20 disabled:cursor-not-allowed disabled:opacity-60 ${
								open
									? 'border-accent ring-2 ring-accent/20'
									: 'border-stroke'
							} ${isRtl ? 'text-right' : 'text-left'} ${buttonClassName}`}
						>
							<span className="truncate">
								{selectedOption?.label}
							</span>
							<ChevronDown className="h-4 w-4 shrink-0 text-text-muted" />
						</ListboxButton>

						<ListboxOptions
							anchor={{ to: 'bottom start', gap: 6, padding: 12 }}
							modal={false}
							transition
							className={`z-50 max-h-64 w-(--button-width) overflow-auto rounded-lg border border-stroke bg-surface p-1 shadow-xl transition duration-150 ease-out focus:outline-none data-closed:scale-95 data-closed:opacity-0 ${optionsClassName}`}
						>
							{options.map((option) => (
								<ListboxOption
									key={String(option.value)}
									value={option.value}
									disabled={option.disabled}
									className="focus:outline-none"
								>
									{({
										focus,
										selected,
										disabled: optionDisabled,
									}) => (
										<div
											className={`flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm ${
												focus ? 'bg-surface-alt' : ''
											} ${
												selected
													? 'text-accent'
													: 'text-heading'
											} ${
												optionDisabled
													? 'cursor-not-allowed opacity-50'
													: 'cursor-pointer'
											} ${optionClassName}`}
										>
											<span className="truncate">
												{option.label}
											</span>
											<Check
												className={`h-4 w-4 shrink-0 ${
													selected
														? 'opacity-100'
														: 'opacity-0'
												}`}
											/>
										</div>
									)}
								</ListboxOption>
							))}
						</ListboxOptions>
					</>
				)}
			</Listbox>
		</div>
	);
}

export default Select;
