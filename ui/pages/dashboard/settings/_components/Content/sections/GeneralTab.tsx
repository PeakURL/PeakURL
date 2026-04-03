// @ts-nocheck
import { useEffect, useState } from 'react';
import { Input, Button } from '@/components/ui';
import { __ } from '@/i18n';
import { getInstalledLanguageLabel } from '@/i18n/languages';

function GeneralTab({
	initialForm,
	onSubmit,
	isUpdating,
	siteSettings,
	isLoadingSiteSettings,
}) {
	const availableLanguages = siteSettings?.availableLanguages || [];
	const [generalForm, setGeneralForm] = useState(initialForm);
	const [siteLanguage, setSiteLanguage] = useState(
		siteSettings?.siteLanguage || 'en_US'
	);

	useEffect(() => {
		setGeneralForm(initialForm);
	}, [initialForm]);

	useEffect(() => {
		setSiteLanguage(siteSettings?.siteLanguage || 'en_US');
	}, [siteSettings?.siteLanguage]);

	const handleChange = (e) => {
		const { name, value } = e.target;
		setGeneralForm((prev) => ({ ...prev, [name]: value }));
	};

	return (
		<div className="space-y-5">
			<div className="bg-surface border border-(--color-stroke) rounded-lg p-5">
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
						<select
							value={siteLanguage}
							onChange={(event) =>
								setSiteLanguage(event.target.value)
							}
							disabled={
								isLoadingSiteSettings ||
								!siteSettings?.canManageSiteSettings ||
								isUpdating
							}
							className="w-full px-4 py-2 bg-surface border border-stroke rounded-md text-heading outline-none transition-all focus:ring-2 focus:ring-accent focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
						>
							{isLoadingSiteSettings &&
							(!siteSettings?.availableLanguages ||
								siteSettings.availableLanguages.length ===
									0) ? (
								<option value={siteLanguage}>
									{__('Loading languages...')}
								</option>
							) : (
								availableLanguages.map(
									(language) => (
										<option
											key={language.locale}
											value={language.locale}
										>
											{getInstalledLanguageLabel(
												language,
												availableLanguages
											)}
										</option>
									)
								)
							)}
						</select>
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
					<Button
						size="sm"
						onClick={() =>
							onSubmit({
								...generalForm,
								siteLanguage,
							})
						}
						disabled={isUpdating}
					>
						{isUpdating ? __('Saving...') : __('Save Changes')}
					</Button>
				</div>
			</div>
		</div>
	);
}

export default GeneralTab;
