// @ts-nocheck
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, KeyRound, LockKeyhole } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { useResetPasswordMutation } from '@/store/slices/api/user';
import { __ } from '@/i18n';

function getErrorMessage(error, fallback) {
	if (typeof error?.data?.message === 'string' && error.data.message) {
		return error.data.message;
	}

	if (typeof error?.error === 'string' && error.error) {
		return error.error;
	}

	return fallback;
}

const submitFormOnEnter = (event) => {
	if ('Enter' !== event.key) {
		return;
	}

	event.preventDefault();
	event.currentTarget.form?.requestSubmit();
};

function ResetPasswordPage() {
	const navigate = useNavigate();
	const { token = '' } = useParams();
	const [password, setPassword] = useState('');
	const [confirmPassword, setConfirmPassword] = useState('');
	const [formError, setFormError] = useState('');
	const [isCompleted, setIsCompleted] = useState(false);
	const [resetPassword, { isLoading }] = useResetPasswordMutation();

	const handleSubmit = async (event) => {
		event.preventDefault();
		setFormError('');

		if (!token.trim()) {
			setFormError(__('The password reset link is invalid.'));
			return;
		}

		if (password.length < 8) {
			setFormError(__('Password must be at least 8 characters.'));
			return;
		}

		if (password !== confirmPassword) {
			setFormError(__('Passwords do not match.'));
			return;
		}

		try {
			await resetPassword({
				token: token.trim(),
				password,
			}).unwrap();
			setIsCompleted(true);
			window.setTimeout(() => {
				navigate('/login', { replace: true });
			}, 1600);
		} catch (error) {
			setFormError(
				getErrorMessage(
					error,
					__('PeakURL could not reset the password with that link.')
				)
			);
		}
	};

	return (
		<div className="min-h-screen bg-slate-950 px-6 py-10 text-white">
			<div className="mx-auto flex min-h-[calc(100vh-5rem)] max-w-5xl items-center justify-center">
				<div className="grid w-full gap-8 lg:grid-cols-[1.15fr_0.85fr]">
					<div className="hidden rounded-[28px] border border-white/10 bg-white/[0.04] p-10 shadow-2xl shadow-slate-950/30 lg:flex lg:flex-col lg:justify-between">
						<div>
							<div className="inline-flex items-center gap-2 rounded-full border border-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">
								<LockKeyhole size={14} />
								{__('Secure Reset')}
							</div>
							<h1 className="mt-6 text-4xl font-semibold tracking-tight text-white">
								{__('Choose a new password and get back to work.')}
							</h1>
							<p className="mt-4 max-w-lg text-base leading-7 text-slate-400">
								{__(
									'Once the password changes, PeakURL revokes the old sessions so only the new login remains valid.'
								)}
							</p>
						</div>

						<div className="rounded-2xl border border-white/10 bg-slate-900/60 p-5">
							<p className="text-sm font-medium text-white">
								{__('Use at least 8 characters.')}
							</p>
							<p className="mt-2 text-sm leading-6 text-slate-400">
								{__(
									'For best results, combine uppercase, lowercase, numbers, and a unique phrase you do not reuse elsewhere.'
								)}
							</p>
						</div>
					</div>

					<div className="rounded-[28px] border border-slate-200 bg-white p-8 shadow-2xl shadow-slate-950/10">
						<div className="mb-6 flex items-center justify-between">
							<div className="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">
								<span className="h-2 w-2 rounded-full bg-emerald-500" />
								PeakURL
							</div>
							<Link
								to="/login"
								className="inline-flex items-center gap-2 text-sm font-medium text-slate-500 transition hover:text-slate-900"
							>
								<ArrowLeft size={16} />
								{__('Back to login')}
							</Link>
						</div>

						<h2 className="text-3xl font-semibold tracking-tight text-slate-950">
							{__('Reset your password')}
						</h2>
						<p className="mt-3 text-sm leading-6 text-slate-500">
							{__(
								'Set a new account password, then sign in again with the updated credentials.'
							)}
						</p>

						{isCompleted ? (
							<div className="mt-8 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900">
								<div className="flex items-start gap-3">
									<CheckCircle2 size={18} className="mt-0.5" />
									<div>
										<p className="font-semibold">
											{__('Password updated')}
										</p>
										<p className="mt-2 leading-6 opacity-80">
											{__(
												'PeakURL updated your password and revoked the older sessions. Redirecting you to the login page now.'
											)}
										</p>
									</div>
								</div>
							</div>
						) : (
							<form className="mt-8 space-y-5" onSubmit={handleSubmit}>
								<Input
									label={__('New password')}
									type="password"
									icon={KeyRound}
									value={password}
									name="password"
									onChange={(event) =>
										setPassword(event.target.value)
									}
									autoFocus
									placeholder={__('Enter your new password')}
									autoComplete="new-password"
									required
									error={formError}
								/>
								<Input
									label={__('Confirm new password')}
									type="password"
									icon={LockKeyhole}
									value={confirmPassword}
									name="confirmPassword"
									onChange={(event) =>
										setConfirmPassword(event.target.value)
									}
									onKeyDown={submitFormOnEnter}
									enterKeyHint="go"
									placeholder={__('Confirm your new password')}
									autoComplete="new-password"
									required
								/>
								<Button
									type="submit"
									size="md"
									loading={isLoading}
									className="w-full"
								>
									{__('Reset password')}
								</Button>
							</form>
						)}
					</div>
				</div>
			</div>
		</div>
	);
}

export default ResetPasswordPage;
