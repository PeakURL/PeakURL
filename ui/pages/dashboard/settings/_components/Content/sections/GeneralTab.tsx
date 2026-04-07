import type { ChangeEvent, SubmitEvent } from 'react';
import { useEffect, useState } from 'react';
import { Input, Button, Select } from '@/components/ui';
import { __ } from '@/i18n';
import { getInstalledLanguageLabel } from '@/i18n/languages';
import type { GeneralFormState } from '../types';
import type { GeneralTabProps } from './types';
import type { SelectOption } from '@/components/ui';

function GeneralTab({
	initialForm,
	onSubmit,
	isUpdating,
	siteSettings,
	isLoadingSiteSettings,
}: GeneralTabProps) {
	const availableLanguages = siteSettings?.availableLanguages || [];
	const [generalForm, setGeneralForm] =
		useState<GeneralFormState>(initialForm);
	const [siteLanguage, setSiteLanguage] = useState(
		siteSettings?.siteLanguage || 'en_US'
	);

	useEffect(() => {
		setGeneralForm(initialForm);
	}, [initialForm]);

	useEffect(() => {
		setSiteLanguage(siteSettings?.siteLanguage || 'en_US');
	}, [siteSettings?.siteLanguage]);

	const handleChange = (
		event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>
	) => {
		const { name, value } = event.target;
		setGeneralForm((previous) => ({
			...previous,
			[name]: value,
		}));
	};

	const handleSubmit = (event: SubmitEvent<HTMLFormElement>) => {
		event.preventDefault();
		onSubmit({
			...generalForm,
			siteLanguage,
		});
	};
	const availableLanguageOptions = availableLanguages.reduce<
		SelectOption<string>[]
	>((options, language) => {
		const locale = language.locale?.trim();

		if (!locale) {
			return options;
		}

		options.push({
			value: locale,
			label: getInstalledLanguageLabel(language, availableLanguages),
		});

		return options;
	}, []);
	const languageOptions: SelectOption<string>[] =
		isLoadingSiteSettings &&
		(!siteSettings?.availableLanguages ||
			0 === siteSettings.availableLanguages.length)
			? [{ value: siteLanguage, label: __('Loading languages...') }]
			: availableLanguageOptions.length > 0
				? availableLanguageOptions
				: [{ value: siteLanguage, label: siteLanguage }];

	return (
		<div className="space-y-5">
			<form
				onSubmit={handleSubmit}
				className="bg-surface border border-(--color-stroke) rounded-lg p-5"
			>
				<h2 className="text-base font-semibold text-heading mb-5">
					{__('Profile Information')}
				</h2>
				<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
					<Input
						label={__('First Name')}
						name="firstName"
						value={generalForm.firstName}
						onChange={handleChange}
						required
					/>
					<Input
						label={__('Last Name')}
						name="lastName"
						value={generalForm.lastName}
						onChange={handleChange}
						required
					/>
					<Input
						label={__('Username')}
						name="username"
						value={generalForm.username}
						onChange={handleChange}
						required
					/>
					<Input
						label={__('Email Address')}
						type="email"
						name="email"
						value={generalForm.email}
						onChange={handleChange}
						required
					/>
					<Input
						label={__('Phone Number')}
						name="phoneNumber"
						value={generalForm.phoneNumber}
						onChange={handleChange}
					/>
					<Input
						label={__('Company')}
						name="company"
						value={generalForm.company}
						onChange={handleChange}
					/>
					<Input
						label={__('Job Title')}
						name="jobTitle"
						value={generalForm.jobTitle}
						onChange={handleChange}
					/>
					<div className="space-y-2">
						<label className="block text-sm font-semibold text-heading">
							{__('Site Language')}
						</label>
						<Select
							value={siteLanguage}
							onChange={setSiteLanguage}
							options={languageOptions}
							disabled={
								isLoadingSiteSettings ||
								!siteSettings?.canManageSiteSettings ||
								isUpdating
							}
							ariaLabel={__('Site language')}
						/>
					</div>
					<div className="md:col-span-2 space-y-2">
						<label className="block text-sm font-semibold text-heading">
							{__('Bio')}
						</label>
						<textarea
							name="bio"
							rows={3}
							className="w-full px-4 py-2 bg-surface border border-stroke rounded-md text-heading placeholder:text-text-muted outline-none transition-all focus:ring-2 focus:ring-accent focus:border-accent"
							value={generalForm.bio}
							onChange={handleChange}
						/>
					</div>
				</div>
				<div className="flex justify-end mt-5">
					<Button size="sm" type="submit" disabled={isUpdating}>
						{isUpdating ? __('Saving...') : __('Save Changes')}
					</Button>
				</div>
			</form>
		</div>
	);
}

export default GeneralTab;
