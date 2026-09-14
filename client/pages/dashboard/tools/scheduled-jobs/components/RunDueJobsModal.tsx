import { Play } from "lucide-react";

import { Button, Modal } from "@/components";
import { __ } from "@/i18n";
import type { RunDueJobsModalProps } from "../types";

export function RunDueJobsModal({
	isOpen,
	onClose,
	onConfirm,
	isExecuting,
}: RunDueJobsModalProps) {
	return (
		<Modal
			isOpen={isOpen}
			onClose={() => {
				if (!isExecuting) {
					onClose();
				}
			}}
			title={__("Run Due Jobs")}
			size="sm"
		>
			<div className="scheduled-jobs-confirm-modal">
				<div className="flex items-start gap-3">
					<div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent/10 text-accent">
						<Play size={18} />
					</div>
					<div className="space-y-1.5 text-start">
						<p className="text-sm font-medium text-heading">
							{__("Execute currently due background jobs?")}
						</p>
						<p className="text-xs text-text-muted leading-relaxed">
							{__(
								"This will trigger immediate execution of any background jobs whose scheduled interval has elapsed or claim lease has expired. Jobs that are not currently due will remain on their normal schedule."
							)}
						</p>
					</div>
				</div>

				<div className="mt-6 flex items-center justify-end gap-2.5">
					<Button
						variant="outline"
						size="sm"
						onClick={onClose}
						disabled={isExecuting}
					>
						{__("Cancel")}
					</Button>
					<Button
						variant="primary"
						size="sm"
						onClick={onConfirm}
						loading={isExecuting}
						disabled={isExecuting}
					>
						{__("Run Due Jobs")}
					</Button>
				</div>
			</div>
		</Modal>
	);
}

export default RunDueJobsModal;
