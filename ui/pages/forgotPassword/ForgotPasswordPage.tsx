// @ts-nocheck
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, KeyRound, Mail } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { useForgotPasswordMutation } from '@/store/slices/api/user';

function getErrorMessage(error, fallback) {
	if (typeof error?.data?.message === 'string' && error.data.message) {
		return error.data.message;
	}

	if (typeof error?.error === 'string' && error.error) {
		return error.error;
	}

	return fallback;
}

function ForgotPasswordPage() {
	const [email, setEmail] = useState('');
	const [submittedEmail, setSubmittedEmail] = useState('');
	const [formError, setFormError] = useState('');
	const [isSubmitted, setIsSubmitted] = useState(false);
	const [forgotPassword, { isLoading }] = useForgotPasswordMutation();

	const handleSubmit = async (event) => {
		event.preventDefault();
		setFormError('');

		if (!email.trim()) {
			setFormError('Email is required.');
			return;
		}

		try {
			await forgotPassword({
				email: email.trim(),
			}).unwrap();
			setSubmittedEmail(email.trim());
			setIsSubmitted(true);
		} catch (error) {
			setFormError(
				getErrorMessage(
					error,
					'PeakURL could not process the password reset request.'
				)
			);
		}
	};

	return (
		<div className="min-h-screen bg-slate-950 px-6 py-10 text-white">
			<div className="mx-auto flex min-h-[calc(100vh-5rem)] max-w-5xl items-center justify-center">
				<div className="grid w-full gap-8 lg:grid-cols-[1.2fr_0.8fr]">
					<div className="hidden rounded-[28px] border border-white/10 bg-white/[0.04] p-10 shadow-2xl shadow-slate-950/30 lg:flex lg:flex-col lg:justify-between">
						<div>
							<div className="inline-flex items-center gap-2 rounded-full border border-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">
								<KeyRound size={14} />
								Account Recovery
							</div>
							<h1 className="mt-6 text-4xl font-semibold tracking-tight text-white">
								Reset access without leaving your dashboard workflow.
							</h1>
							<p className="mt-4 max-w-lg text-base leading-7 text-slate-400">
								Enter the email address linked to your PeakURL
								account and we&apos;ll send a secure reset link.
							</p>
						</div>

						<div className="rounded-2xl border border-white/10 bg-slate-900/60 p-5">
							<p className="text-sm font-medium text-white">
								Password reset links expire after 1 hour.
							</p>
							<p className="mt-2 text-sm leading-6 text-slate-400">
								If the account exists, PeakURL sends a single-use
								link and keeps your existing sessions revoked after
								the password is changed.
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
								Back to login
							</Link>
						</div>

						<h2 className="text-3xl font-semibold tracking-tight text-slate-950">
							Forgot your password?
						</h2>
						<p className="mt-3 text-sm leading-6 text-slate-500">
							Enter the account email and PeakURL will send a secure
							password reset link.
						</p>

						{isSubmitted ? (
							<div className="mt-8 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900">
								<p className="font-semibold">
									Check your inbox
								</p>
								<p className="mt-2 leading-6 opacity-80">
									If an account exists for{' '}
									<span className="font-medium">
										{submittedEmail}
									</span>
									, PeakURL has sent a password reset link.
								</p>
								<Link
									to="/login"
									className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-emerald-700 transition hover:text-emerald-900"
								>
									Return to login
									<ArrowLeft size={15} className="rotate-180" />
								</Link>
							</div>
						) : (
							<form className="mt-8 space-y-5" onSubmit={handleSubmit}>
								<Input
									label="Email address"
									type="email"
									icon={Mail}
									value={email}
									onChange={(event) =>
										setEmail(event.target.value)
									}
									placeholder="owner@example.com"
									autoComplete="email"
									required
									error={formError}
								/>
								<Button
									type="submit"
									size="md"
									loading={isLoading}
									className="w-full"
								>
									Send reset link
								</Button>
							</form>
						)}
					</div>
				</div>
			</div>
		</div>
	);
}

export default ForgotPasswordPage;
