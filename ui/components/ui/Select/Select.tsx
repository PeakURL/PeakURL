import {
	Listbox,
	ListboxButton,
	ListboxOption,
	ListboxOptions,
} from "@headlessui/react";
import { Check, ChevronDown } from "lucide-react";
import { getDocumentDirection } from "@/i18n/direction";
import { cn } from "@/utils";
import type { SelectProps, SelectValue } from "../types";

export type { SelectOption, SelectProps, SelectValue } from "../types";

export function Select<T extends SelectValue>({
	id,
	value,
	options,
	onChange,
	disabled = false,
	ariaLabel,
	className = "",
	buttonClassName = "",
	optionsClassName = "",
	optionClassName = "",
	...props
}: SelectProps<T>) {
	const direction = getDocumentDirection();
	const selectedOption =
		options.find((option) => option.value === value) ?? options[0];

	return (
		<div className={cn(className)} {...props}>
			<Listbox value={value} onChange={onChange} disabled={disabled}>
				{({ open }) => (
					<>
						<ListboxButton
							id={id}
							aria-label={ariaLabel}
							dir={direction}
							className={cn(
								"form-control-base",
								"select-trigger",
								open
									? "select-trigger-open"
									: "form-control-accent-focus",
								buttonClassName
							)}
						>
							<span className="select-label">
								{selectedOption?.label}
							</span>
							<ChevronDown className="select-chevron" />
						</ListboxButton>

						<ListboxOptions
							dir={direction}
							anchor={{ to: "bottom start", gap: 6, padding: 12 }}
							modal={false}
							transition
							className={cn("select-options", optionsClassName)}
						>
							{options.map((option) => (
								<ListboxOption
									key={String(option.value)}
									value={option.value}
									disabled={option.disabled}
									className="select-option-reset"
								>
									{({
										focus,
										selected,
										disabled: optionDisabled,
									}) => (
										<div
											className={cn(
												"select-option",
												focus && "select-option-focus",
												selected
													? "select-option-selected"
													: "select-option-default",
												optionDisabled
													? "select-option-disabled"
													: "select-option-enabled",
												optionClassName
											)}
										>
											<span className="select-label">
												{option.label}
											</span>
											<Check
												className={cn(
													"select-option-check",
													selected
														? "opacity-100"
														: "opacity-0"
												)}
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
