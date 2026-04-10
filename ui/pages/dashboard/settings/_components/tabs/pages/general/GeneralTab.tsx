import type { ChangeEvent, SubmitEvent } from 'react';
import { useEffect, useState } from 'react';
import { Input, Button, Select } from '@/components/ui';
import { __ } from '@/i18n';
import { isDocumentRtl } from '@/i18n/direction';
import { getInstalledLanguageLabel } from '@/i18n/languages';
import { cn } from '@/utils';
import type { SelectOption } from '@/components/ui';
import type { GeneralFormState } from '../../types';
import type { GeneralTabProps } from '../types';

function GeneralTab({
	initialForm,
	onSubmit,
	isUpdating,
	siteSettings,
	isLoadingSiteSettings,
}: GeneralTabProps) {
	const isRtl = isDocumentRtl();
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
		<div className="settings-general">
			<form
				onSubmit={handleSubmit}
				className="settings-general-form"
			>
				<h2 className="settings-general-title">
					{__('Profile Information')}
				</h2>
				<div className="settings-general-grid">
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
						valueDirection="ltr"
						autoCapitalize="off"
						spellCheck={false}
						value={generalForm.username}
						onChange={handleChange}
						required
					/>
					<Input
						label={__('Email Address')}
						type="email"
						name="email"
						valueDirection="ltr"
						autoCapitalize="off"
						spellCheck={false}
						value={generalForm.email}
						onChange={handleChange}
						required
					/>
					<Input
						label={__('Phone Number')}
						type="tel"
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
					<div className="settings-general-field">
						<label className="settings-section-label">
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
					<div className="settings-general-bio-field">
						<label className="settings-section-label">
							{__('Bio')}
						</label>
						<textarea
							name="bio"
							rows={3}
							className="form-control-base form-control-accent-focus settings-general-bio-input"
							value={generalForm.bio}
							onChange={handleChange}
						/>
					</div>
				</div>
				<div
					className={cn(
						'settings-general-actions',
						isRtl
							? 'settings-general-actions-start'
							: 'settings-general-actions-end'
					)}
				>
					<Button size="sm" type="submit" disabled={isUpdating}>
						{isUpdating ? __('Saving...') : __('Save Changes')}
					</Button>
				</div>
			</form>
		</div>
	);
}

export default GeneralTab;
