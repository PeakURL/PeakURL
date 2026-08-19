import { BookOpen, Sparkles, TextAlignJustify } from "lucide-react";
import { Modal } from "@/components";
import { useGetReleaseNotesQuery } from "@/store/slices/api/system";
import { formatRelativeTime } from "@/utils";
import { __, sprintf } from "@/i18n";

export interface UpdateDetailsModalProps {
	open: boolean;
	setOpen: (open: boolean) => void;
}

function UpdateDetailsModal({ open, setOpen }: UpdateDetailsModalProps) {
	const { data: releaseNotesData } = useGetReleaseNotesQuery(undefined, {
		skip: !open,
	});

	let releaseNote = null;
	if (releaseNotesData?.data?.releases?.length) {
		const releases = releaseNotesData.data.releases;
		// For the notice, we usually want the latest release available
		releaseNote = releases[0];
	}

	return (
		<Modal
			isOpen={open}
			onClose={() => setOpen(false)}
			title={__("Update Details")}
			size="md"
		>
			<div className="update-details-modal">
				{!releaseNote ? (
					<div className="update-details-modal-loading">
						<div className="update-details-modal-spinner" />
					</div>
				) : (
					<>
						<div>
							<div className="update-details-modal-meta">
								<Sparkles className="update-details-modal-meta-icon" />
								<span>
									{__("Version")} {releaseNote.version}
								</span>
								{releaseNote.releaseDate && (
									<>
										<span className="update-details-modal-meta-separator">
											•
										</span>
										<span className="update-details-modal-meta-date">
											{sprintf(
												__("Released %s"),
												formatRelativeTime(
													releaseNote.releaseDate
												)
											)}
										</span>
									</>
								)}
							</div>
							<h3 className="update-details-modal-title">
								{releaseNote.title}
							</h3>
							<p className="update-details-modal-summary">
								{releaseNote.summary}
							</p>
						</div>

						{releaseNote.highlights?.length > 0 && (
							<div className="update-details-modal-highlights">
								{releaseNote.highlights.map(
									(highlight: string, idx: number) => (
										<div
											key={idx}
											className="update-details-modal-highlight-item"
										>
											<span className="update-details-modal-highlight-bullet">
												•
											</span>
											<span className="update-details-modal-highlight-text">
												{highlight}
											</span>
										</div>
									)
								)}
							</div>
						)}

						<div className="update-details-modal-footer">
							<a
								href="https://go.peakurl.org/release-notes"
								target="_blank"
								rel="noopener noreferrer"
								className="update-details-modal-footer-link update-details-modal-footer-link-primary"
							>
								<BookOpen className="update-details-modal-footer-link-icon" />
								{__("For all release notes see the main page")}
							</a>
							<a
								href="https://go.peakurl.org/release-notes-txt"
								target="_blank"
								rel="noopener noreferrer"
								className="update-details-modal-footer-link update-details-modal-footer-link-secondary"
							>
								<TextAlignJustify className="update-details-modal-footer-link-icon" />
								{__("Plain text format")}
							</a>
						</div>
					</>
				)}
			</div>
		</Modal>
	);
}

export default UpdateDetailsModal;
