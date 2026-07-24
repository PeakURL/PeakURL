import type { ChangeEvent, SubmitEvent } from "react";
import { useEffect, useRef, useState } from "react";

import type { SelectOption } from "@/components";
import { __ } from "@/i18n";
import { useUpdateUrlMutation } from "@/store/slices/api";
import {
	getErrorMessage,
	getShortUrl,
	isFutureLocalDateTime,
	normalizeLinkTitle,
	sanitizeUrl,
	isRelativeUrl,
	toIsoFromLocalDateTime,
	toLocalDateTimeValue,
} from "@/utils";

import type { EditableLink, LinkStatus, UpdateUrlPayload } from "../../types";
import { isSocialPreviewImageFile } from "./helpers";

export function useEditLinkForm(
	link: EditableLink,
	setOpen: (open: boolean) => void
) {
	const [destinationUrl, setDestinationUrl] = useState(
		() => link.destinationUrl || ""
	);
	const [title, setTitle] = useState(() => normalizeLinkTitle(link.title));
	const [socialTitle, setSocialTitle] = useState(
		() => link.socialPreview?.title || ""
	);
	const [socialDescription, setSocialDescription] = useState(
		() => link.socialPreview?.description || ""
	);
	const [socialImageFile, setSocialImageFile] = useState<File | null>(null);
	const [socialImagePreviewUrl, setSocialImagePreviewUrl] = useState("");
	const [removeSocialImage, setRemoveSocialImage] = useState(false);
	const [status, setStatus] = useState<LinkStatus>(
		() => link.status || "active"
	);
	const [password, setPassword] = useState("");
	const [clearPassword, setClearPassword] = useState(false);
	const [expiresAt, setExpiresAt] = useState(() =>
		toLocalDateTimeValue(link.expiresAt)
	);
	const [error, setError] = useState("");
	const [selectedTab, setSelectedTab] = useState(0);
	const fileInputRef = useRef<HTMLInputElement | null>(null);
	const socialImagePreviewRef = useRef("");
	const [updateUrl, { isLoading }] = useUpdateUrlMutation();

	const statusOptions: SelectOption<LinkStatus>[] = [
		{ value: "active", label: __("Active") },
		{ value: "inactive", label: __("Inactive") },
		{ value: "expired", label: __("Expired") },
	];
	const shortUrl = getShortUrl(link);
	const storedSocialImageUrl = link.socialPreview?.imageUrl || "";
	const socialPreviewUrl = removeSocialImage
		? ""
		: socialImagePreviewUrl || storedSocialImageUrl;
	const showSocialImageRemove =
		Boolean(socialImageFile) ||
		(!removeSocialImage && Boolean(storedSocialImageUrl));

	useEffect(
		() => () => {
			if (socialImagePreviewRef.current) {
				URL.revokeObjectURL(socialImagePreviewRef.current);
			}
		},
		[]
	);

	const updateSocialImagePreview = (file: File | null) => {
		if (socialImagePreviewRef.current) {
			URL.revokeObjectURL(socialImagePreviewRef.current);
			socialImagePreviewRef.current = "";
		}

		if (file) {
			socialImagePreviewRef.current = URL.createObjectURL(file);
		}

		setSocialImageFile(file);
		setSocialImagePreviewUrl(socialImagePreviewRef.current);
	};

	const handleClose = () => setOpen(false);

	const handleSocialImageChange = (event: ChangeEvent<HTMLInputElement>) => {
		const nextFile = event.target.files?.[0] || null;

		updateSocialImagePreview(
			isSocialPreviewImageFile(nextFile) ? nextFile : null
		);
		setRemoveSocialImage(false);
	};

	const handleRemoveSocialImage = () => {
		const hasPendingUpload = Boolean(socialImageFile);

		updateSocialImagePreview(null);
		setRemoveSocialImage(
			!hasPendingUpload && Boolean(storedSocialImageUrl)
		);

		if (fileInputRef.current) {
			fileInputRef.current.value = "";
		}
	};

	const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		setError("");

		const trimmedDestinationUrl = destinationUrl.trim();

		if (!trimmedDestinationUrl) {
			setError(__("Please enter a URL"));
			return;
		}

		const normalizedDestinationUrl = sanitizeUrl(trimmedDestinationUrl);

		if (
			!normalizedDestinationUrl ||
			isRelativeUrl(normalizedDestinationUrl)
		) {
			setError(
				__(
					"Please enter a valid URL (must include http:// or https://)"
				)
			);
			return;
		}

		if (expiresAt && !isFutureLocalDateTime(expiresAt)) {
			setError(__("Expiration time must be in the future."));
			return;
		}

		try {
			const payload: UpdateUrlPayload = {
				id: link.id,
				title: title.trim() || undefined,
				status,
				expiresAt: expiresAt ? toIsoFromLocalDateTime(expiresAt) : null,
				socialTitle: socialTitle.trim(),
				socialDescription: socialDescription.trim(),
				socialImageFile,
				removeSocialImage,
			};

			if (trimmedDestinationUrl !== link.destinationUrl) {
				payload.destinationUrl = normalizedDestinationUrl;
			}

			if (clearPassword) {
				payload.clearPassword = true;
			} else if (password.trim()) {
				payload.password = password.trim();
			}

			await updateUrl(payload).unwrap();

			handleClose();
		} catch (error) {
			setError(getErrorMessage(error, __("Failed to update link")));
		}
	};

	return {
		clearPassword,
		error,
		expiresAt,
		fileInputRef,
		handleClose,
		destinationUrl,
		handleRemoveSocialImage,
		handleSocialImageChange,
		handleSubmit,
		hasExistingPassword: Boolean(link.hasPassword),
		isLoading,
		password,
		selectedTab,
		setClearPassword,
		setDestinationUrl,
		setExpiresAt,
		setPassword,
		setSelectedTab,
		setSocialDescription,
		setSocialTitle,
		setStatus,
		setTitle,
		shortUrl,
		showSocialImageRemove,
		socialDescription,
		socialImageFile,
		socialPreviewUrl,
		socialTitle,
		status,
		statusOptions,
		title,
	};
}
