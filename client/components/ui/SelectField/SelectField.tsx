import { useId } from "react";
import { Description, Field } from "@headlessui/react";

import { cn } from "@/shared/formatting";

import { Select } from "../Select";
import type { SelectProps, SelectValue } from "../types";

export type SelectFieldProps<T extends SelectValue> = SelectProps<T> & {
	label: string;
	helperText?: string;
};

/** Labeled select with the same field spacing and help text as Input. */
export function SelectField<T extends SelectValue>({
	label,
	helperText,
	id,
	className,
	ariaLabel,
	...props
}: SelectFieldProps<T>) {
	const generatedId = useId();
	const fieldId = id || generatedId;
	const helperId = helperText ? `${fieldId}-helper` : undefined;

	return (
		<Field className={cn("form-field", className)}>
			<label htmlFor={fieldId} className="form-field-label">
				{label}
			</label>
			<Select {...props} id={fieldId} ariaLabel={ariaLabel || label} />
			{helperText && (
				<Description id={helperId} className="form-field-helper">
					{helperText}
				</Description>
			)}
		</Field>
	);
}
