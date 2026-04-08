import { Link } from 'react-router-dom';
import { AlertCircle, CheckCircle2, Info, TriangleAlert } from 'lucide-react';
import { useGetAdminNoticesQuery } from '@/store/slices/api';
import { isDocumentRtl } from '@/i18n/direction';
import type {
	AdminNoticeItem,
	NoticeActionProps,
	NoticeTone,
} from './types';

const NOTICE_STYLES = {
	error: {
		container:
			'border-red-200 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
		button: 'border-red-300/50 hover:bg-red-100/70 dark:border-red-500/30 dark:hover:bg-red-500/10',
		icon: AlertCircle,
	},
	warning: {
		container:
			'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
		button: 'border-amber-300/50 hover:bg-amber-100/70 dark:border-amber-500/30 dark:hover:bg-amber-500/10',
		icon: TriangleAlert,
	},
	success: {
		container:
			'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
		button: 'border-emerald-300/50 hover:bg-emerald-100/70 dark:border-emerald-500/30 dark:hover:bg-emerald-500/10',
		icon: CheckCircle2,
	},
	info: {
		container:
			'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
		button: 'border-blue-300/50 hover:bg-blue-100/70 dark:border-blue-500/30 dark:hover:bg-blue-500/10',
		icon: Info,
	},
};

function NoticeAction({ action, buttonClasses }: NoticeActionProps) {
	if (!action?.label || !action?.url) {
		return null;
	}

	const commonClasses = `inline-flex shrink-0 items-center justify-center rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${buttonClasses}`;

	if (action.url.startsWith('/')) {
		return (
			<Link to={action.url} className={commonClasses}>
				{action.label}
			</Link>
		);
	}

	return (
		<a
			href={action.url}
			className={commonClasses}
			target="_blank"
			rel="noreferrer"
		>
			{action.label}
		</a>
	);
}

export const AdminNotices = () => {
	const isRtl = isDocumentRtl();
	const { data } = useGetAdminNoticesQuery(undefined);
	const notices = data?.data?.items ?? [];

	if (!notices.length) {
		return null;
	}

	return (
		<div className="mb-5 space-y-4">
			{notices.map((notice: AdminNoticeItem) => {
				const tone =
					NOTICE_STYLES[(notice?.type as NoticeTone) || 'info'] ||
					NOTICE_STYLES.info;
				const Icon = tone.icon;

				return (
					<div
						key={notice?.id || notice?.title}
						className={`rounded-lg border p-4 text-sm ${tone.container}`}
					>
						<div
							dir={isRtl ? 'rtl' : 'ltr'}
							className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
						>
							<div className="flex items-start gap-3">
								<div className="mt-0.5 shrink-0">
									<Icon size={18} />
								</div>
								<div
									className="space-y-1"
									style={{ textAlign: 'start' }}
								>
									{notice?.title ? (
										<p className="font-semibold">
											{notice.title}
										</p>
									) : null}
									{notice?.message ? (
										<p className="leading-6 opacity-80">
											{notice.message}
										</p>
									) : null}
								</div>
							</div>

							<NoticeAction
								action={notice?.action}
								buttonClasses={tone.button}
							/>
						</div>
					</div>
				);
			})}
		</div>
	);
};

export default AdminNotices;
