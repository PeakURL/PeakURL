import { Link } from 'react-router-dom';
import { AlertCircle, CheckCircle2, Info, TriangleAlert } from 'lucide-react';
import { useGetAdminNoticesQuery } from '@/store/slices/api';
import { isDocumentRtl } from '@/i18n/direction';
import { cn } from '@/utils';
import type {
	AdminNoticeItem,
	NoticeActionProps,
	NoticeTone,
} from './types';

const NOTICE_STYLES = {
	error: {
		containerClassName: 'dashboard-notice-error',
		actionClassName: 'dashboard-notice-action-error',
		icon: AlertCircle,
	},
	warning: {
		containerClassName: 'dashboard-notice-warning',
		actionClassName: 'dashboard-notice-action-warning',
		icon: TriangleAlert,
	},
	success: {
		containerClassName: 'dashboard-notice-success',
		actionClassName: 'dashboard-notice-action-success',
		icon: CheckCircle2,
	},
	info: {
		containerClassName: 'dashboard-notice-info',
		actionClassName: 'dashboard-notice-action-info',
		icon: Info,
	},
};

function NoticeAction({ action, actionClassName }: NoticeActionProps) {
	if (!action?.label || !action?.url) {
		return null;
	}

	const actionClasses = cn(
		'dashboard-notice-action',
		actionClassName
	);

	if (action.url.startsWith('/')) {
		return (
			<Link to={action.url} className={actionClasses}>
				{action.label}
			</Link>
		);
	}

	return (
		<a
			href={action.url}
			className={actionClasses}
			target="_blank"
			rel="noreferrer"
		>
			{action.label}
		</a>
	);
}

export const AdminNotices = () => {
	const isRtl = isDocumentRtl();
	const direction = isRtl ? 'rtl' : 'ltr';
	const { data } = useGetAdminNoticesQuery(undefined);
	const notices = data?.data?.items ?? [];

	if (!notices.length) {
		return null;
	}

	return (
		<div className="dashboard-notices">
			{notices.map((notice: AdminNoticeItem) => {
				const tone =
					NOTICE_STYLES[(notice?.type as NoticeTone) || 'info'] ||
					NOTICE_STYLES.info;
				const Icon = tone.icon;

				return (
					<div
						key={notice?.id || notice?.title}
						className={cn(
							'dashboard-notice',
							tone.containerClassName
						)}
					>
						<div
							dir={direction}
							className="dashboard-notice-layout"
						>
							<div className="dashboard-notice-content">
								<div className="dashboard-notice-icon">
									<Icon size={18} />
								</div>
								<div className="dashboard-notice-copy">
									{notice?.title ? (
										<p className="dashboard-notice-title">
											{notice.title}
										</p>
									) : null}
									{notice?.message ? (
										<p className="dashboard-notice-message">
											{notice.message}
										</p>
									) : null}
								</div>
							</div>

							<NoticeAction
								action={notice?.action}
								actionClassName={tone.actionClassName}
							/>
						</div>
					</div>
				);
			})}
		</div>
	);
};

export default AdminNotices;
