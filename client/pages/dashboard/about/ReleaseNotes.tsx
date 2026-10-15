import { FileWarning, LoaderCircle, RotateCw } from "lucide-react";
import { getPeakURLData } from "@/data";
import { __ } from "@/i18n";
import { formatDateOnly } from "@/shared/dates";
import { useGetReleaseNotesQuery } from "@/state/slices/api/system";

/** Load notes only when opened, and show the installed version rather than another release. */
export function ReleaseNotes() {
	const { version } = getPeakURLData();
	const { data, isFetching, isError, refetch } = useGetReleaseNotesQuery();
	const release = data?.data?.releases?.find(
		(item) => item.version === version
	);

	if (isFetching)
		return (
			<div className="about-release-state" role="status">
				<LoaderCircle
					size={20}
					className="about-loading-icon"
					aria-hidden="true"
				/>
				<p>{__("Loading release notes…")}</p>
			</div>
		);
	if (isError)
		return (
			<div className="about-release-state" role="alert">
				<FileWarning size={24} aria-hidden="true" />
				<h2>{__("Release notes could not be loaded.")}</h2>
				<p>
					{__(
						"You can keep using PeakURL. Try again when you're connected."
					)}
				</p>
				<button
					type="button"
					className="about-secondary-link"
					onClick={() => void refetch()}
				>
					<RotateCw size={14} aria-hidden="true" />
					{__("Try again")}
				</button>
			</div>
		);
	if (!release)
		return (
			<section>
				<h2>{__("Installed release")}</h2>
				<p className="about-section-intro">
					{__("Version")} {version}
				</p>
				<p className="about-section-intro">
					{__(
						"Release notes for this version are not available yet."
					)}
				</p>
			</section>
		);

	return (
		<section className="about-release">
			<div className="about-release-meta">
				<span>
					{__("Version")} {release.version}
				</span>
				{release.releaseDate && (
					<time dateTime={release.releaseDate}>
						{formatDateOnly(release.releaseDate.slice(0, 10))}
					</time>
				)}
			</div>
			<h2>{release.title}</h2>
			<p className="about-section-intro">{release.summary}</p>
			{release.highlights.length > 0 && (
				<ul className="about-release-highlights">
					{release.highlights.map((highlight, index) => (
						<li key={`${index}-${highlight}`}>{highlight}</li>
					))}
				</ul>
			)}
			<a
				href="https://go.peakurl.org/release-notes.txt"
				target="_blank"
				rel="noopener noreferrer"
				className="about-text-link"
			>
				{__("Read the plain text release notes")}
			</a>
		</section>
	);
}
