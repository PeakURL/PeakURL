import { useRef, useState, type SubmitEvent } from "react";

/** Lock a form through validation, CAPTCHA and its request. */
export function useFormSubmission(submit: () => Promise<void>) {
	const pending = useRef(false);
	const [isSubmitting, setIsSubmitting] = useState(false);

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		if (pending.current) return;

		pending.current = true;
		setIsSubmitting(true);
		try {
			await submit();
		} finally {
			pending.current = false;
			setIsSubmitting(false);
		}
	};

	return { handleSubmit, isSubmitting };
}
