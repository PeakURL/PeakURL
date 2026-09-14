import type { CronJob } from "@/api";

export interface ScheduledJobsPageProps {
	className?: string;
}

export interface JobStatusBadgeProps {
	status: string;
	attempts?: number;
	maxAttempts?: number;
	className?: string;
}

export interface JobHistoryDrawerProps {
	job: CronJob | null;
	isOpen: boolean;
	onClose: () => void;
	onRefresh?: () => void;
	onRunJob?: (job: CronJob) => void;
	isJobRunning?: boolean;
	canManage?: boolean;
}

export interface RunDueJobsModalProps {
	isOpen: boolean;
	onClose: () => void;
	onConfirm: () => Promise<void> | void;
	isExecuting: boolean;
}

export interface JobsTableProps {
	jobs: CronJob[];
	runningJobId: string | null;
	onViewHistory: (job: CronJob) => void;
	onRunJob: (job: CronJob) => void;
	canManage: boolean;
}
