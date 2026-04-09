import { ArrowLeft, ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import { isDocumentRtl } from '@/i18n/direction';
import { __ } from '@/i18n';
import type { AuthLayoutProps } from './types';

function AuthLayout({
	backLabel = __('Back to login'),
	backTo = '/login',
	badgeIcon: BadgeIcon,
	badgeLabel,
	cardCopy,
	cardTitle,
	children,
	containerClassName = 'auth-page-container auth-page-container-compact',
	noteCopy,
	noteTitle,
	pageClassName = 'auth-page auth-page-recovery',
	showcaseCopy,
	showcaseTitle,
}: AuthLayoutProps) {
	const isRtl = isDocumentRtl();
	const BackArrow = isRtl ? ArrowRight : ArrowLeft;

	return (
		<main id="page-container" className={pageClassName}>
			<div className={containerClassName}>
				<div className="auth-page-layout">
					<aside className="auth-page-showcase" aria-hidden="true">
						<div className="auth-page-showcase-header">
							<div className="auth-page-badge">
								<BadgeIcon size={14} />
								{badgeLabel}
							</div>
							<h1 className="auth-page-showcase-title">
								{showcaseTitle}
							</h1>
							<p className="auth-page-showcase-copy">
								{showcaseCopy}
							</p>
						</div>

						<div className="auth-page-note">
							<p className="auth-page-note-title">{noteTitle}</p>
							<p className="auth-page-note-copy">{noteCopy}</p>
						</div>
					</aside>

					<section
						className="auth-page-card"
						aria-labelledby="page-heading"
					>
						<header className="auth-page-card-header">
							<div className="auth-page-brand">
								<span className="auth-page-brand-dot" />
								PeakURL
							</div>
							<Link to={backTo} className="auth-page-back-link">
								<BackArrow size={16} />
								{backLabel}
							</Link>
						</header>

						<h2 id="page-heading" className="auth-page-card-title">
							{cardTitle}
						</h2>
						<p className="auth-page-card-copy">{cardCopy}</p>

						{children}
					</section>
				</div>
			</div>
		</main>
	);
}

export default AuthLayout;
