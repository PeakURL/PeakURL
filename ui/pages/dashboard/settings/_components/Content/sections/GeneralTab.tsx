// @ts-nocheck
import { useState } from 'react';
import { Input, Button } from '@/components/ui';

function GeneralTab({ initialForm, onSubmit, isUpdating }) {
	const [generalForm, setGeneralForm] = useState(initialForm);

	const handleChange = (e) => {
		const { name, value } = e.target;
		setGeneralForm((prev) => ({ ...prev, [name]: value }));
	};

	return (
		<div className="space-y-5">
			<div className="bg-surface border border-(--color-stroke) rounded-lg p-5">
				<h2 className="text-base font-semibold text-heading mb-5">
					Profile Information
				</h2>
				<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
					<Input
						label="First Name"
						name="firstName"
						value={generalForm.firstName}
						onChange={handleChange}
						required
					/>
					<Input
						label="Last Name"
						name="lastName"
						value={generalForm.lastName}
						onChange={handleChange}
						required
					/>
					<Input
						label="Username"
						name="username"
						value={generalForm.username}
						onChange={handleChange}
						required
					/>
					<Input
						label="Email Address"
						type="email"
						name="email"
						value={generalForm.email}
						onChange={handleChange}
						required
					/>
					<Input
						label="Phone Number"
						name="phoneNumber"
						value={generalForm.phoneNumber}
						onChange={handleChange}
					/>
					<Input
						label="Company"
						name="company"
						value={generalForm.company}
						onChange={handleChange}
					/>
					<Input
						label="Job Title"
						name="jobTitle"
						value={generalForm.jobTitle}
						onChange={handleChange}
					/>
					<div className="md:col-span-2 space-y-2">
						<label className="block text-sm font-semibold text-heading">
							Bio
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
						onClick={() => onSubmit(generalForm)}
						disabled={isUpdating}
					>
						{isUpdating ? 'Saving...' : 'Save Changes'}
					</Button>
				</div>
			</div>
		</div>
	);
}

export default GeneralTab;
