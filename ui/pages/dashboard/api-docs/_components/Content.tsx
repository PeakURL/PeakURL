// @ts-nocheck
import { Info, Copy } from 'lucide-react';
import { PEAKURL_NAME } from '@constants';

const CodeBlock = ({ code }) => (
	<div className="relative">
		<pre className="bg-surface-alt text-heading p-4 rounded-lg overflow-x-auto text-xs border border-stroke">
			<code>{code}</code>
		</pre>
		<button
			className="absolute top-3 right-3 p-1.5 text-text-muted hover:text-heading transition-colors bg-surface rounded"
			aria-label="Copy"
			onClick={() => navigator.clipboard.writeText(code)}
		>
			<Copy size={14} />
		</button>
	</div>
);

const ExampleRequest = ({
	sectionKey,
	languages,
	selectedLanguage,
	setSelectedLanguage,
	codeExamples,
}) => (
	<div className="rounded-lg bg-surface border border-stroke p-5">
		<div className="flex items-center justify-between mb-4">
			<h3 className="text-base font-semibold text-heading">
				Request Example
			</h3>
			<div className="flex items-center gap-1">
				{languages.map((lang) => (
					<button
						key={lang}
						onClick={() => setSelectedLanguage(lang)}
						className={`px-3 py-1.5 text-xs font-medium rounded-md transition-all ${
							selectedLanguage === lang
								? 'bg-accent text-white'
								: 'bg-surface-alt text-text-muted hover:bg-stroke hover:text-heading'
						}`}
					>
						{lang.toUpperCase()}
					</button>
				))}
			</div>
		</div>
		<CodeBlock code={codeExamples[sectionKey][selectedLanguage]} />
	</div>
);

const Content = ({
	activeSection,
	languages,
	selectedLanguage,
	setSelectedLanguage,
	codeExamples,
}) => {
	return (
		<div className="lg:col-span-3 space-y-5">
			{activeSection === 'authentication' && (
				<div className="space-y-5">
					<div className="rounded-lg bg-surface border border-stroke p-5">
						<h2 className="text-lg font-semibold text-heading mb-3">
							Authentication
						</h2>
						<p className="text-sm text-text-muted mb-5">
							The {PEAKURL_NAME} API uses API keys for authentication.
							Include your API key in the Authorization header of
							every request.
						</p>
						<div className="bg-info/10 border border-info/20 rounded-lg p-4">
							<div className="flex items-start gap-2 mb-2">
								<Info size={16} className="text-info mt-0.5" />
								<span className="font-medium text-sm text-info">
									Keep your API key secure
								</span>
							</div>
							<p className="text-sm text-info pl-6">
								Your API key grants access to your account.
								Never share it publicly or commit it to version
								control.
							</p>
						</div>
					</div>

					<ExampleRequest
						sectionKey="authentication"
						languages={languages}
						selectedLanguage={selectedLanguage}
						setSelectedLanguage={setSelectedLanguage}
						codeExamples={codeExamples}
					/>

					<div className="rounded-lg bg-surface border border-stroke p-5">
						<h3 className="text-base font-semibold text-heading mb-3">
							Response Format
						</h3>
						<p className="text-sm text-text-muted mb-4">
							All API responses are returned in JSON format with
							consistent structure:
						</p>
						<CodeBlock
							code={`{
    "success": true,
    "data": { /* payload */ },
    "message": "Success message",
    "timestamp": "2024-03-15T10:30:00Z"
}`}
						/>
					</div>
				</div>
			)}

			{activeSection === 'links' && (
				<div className="space-y-5">
					<div className="rounded-lg bg-surface border border-stroke p-5">
						<h2 className="text-lg font-semibold text-heading mb-3">
							Links Management
						</h2>
						<p className="text-sm text-text-muted mb-5">
							Create, retrieve, update, and delete shortened links
							programmatically.
						</p>
						<div className="space-y-8">
							{/* Create Link */}
							<div>
								<div className="flex items-center gap-2.5 mb-3">
									<span className="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-success/10 text-success border border-success/20">
										POST
									</span>
									<code className="font-mono text-sm text-heading">
										/urls
									</code>
								</div>
								<p className="text-sm text-text-muted mb-4">
									Create a new shortened link.
								</p>
								<div className="mb-4">
									<h4 className="font-medium text-sm text-heading mb-2">
										Example
									</h4>
									<CodeBlock
										code={
											codeExamples.links.create[
												selectedLanguage
											]
										}
									/>
								</div>
							</div>

							{/* List Links */}
							<div className="pt-6 border-t border-stroke">
								<div className="flex items-center gap-2.5 mb-3">
									<span className="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-info/10 text-info border border-info/20">
										GET
									</span>
									<code className="font-mono text-sm text-heading">
										/urls
									</code>
								</div>
								<p className="text-sm text-text-muted mb-4">
									List all shortened links with pagination.
								</p>
								<div className="mb-4">
									<h4 className="font-medium text-sm text-heading mb-2">
										Example
									</h4>
									<CodeBlock
										code={
											codeExamples.links.list[
												selectedLanguage
											]
										}
									/>
								</div>
							</div>

							{/* Get Single Link */}
							<div className="pt-6 border-t border-stroke">
								<div className="flex items-center gap-2.5 mb-3">
									<span className="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-info/10 text-info border border-info/20">
										GET
									</span>
									<code className="font-mono text-sm text-heading">
										/urls/:id
									</code>
								</div>
								<p className="text-sm text-text-muted mb-4">
									Get details of a specific link.
								</p>
								<div className="mb-4">
									<h4 className="font-medium text-sm text-heading mb-2">
										Example
									</h4>
									<CodeBlock
										code={
											codeExamples.links.get[
												selectedLanguage
											]
										}
									/>
								</div>
							</div>

							{/* Update Link */}
							<div className="pt-6 border-t border-stroke">
								<div className="flex items-center gap-2.5 mb-3">
									<span className="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-warning/10 text-warning border border-warning/20">
										PUT
									</span>
									<code className="font-mono text-sm text-heading">
										/urls/:id
									</code>
								</div>
								<p className="text-sm text-text-muted mb-4">
									Update a link&apos;s details.
								</p>
								<div className="mb-4">
									<h4 className="font-medium text-sm text-heading mb-2">
										Example
									</h4>
									<CodeBlock
										code={
											codeExamples.links.update[
												selectedLanguage
											]
										}
									/>
								</div>
							</div>

							{/* Delete Link */}
							<div className="pt-6 border-t border-stroke">
								<div className="flex items-center gap-2.5 mb-3">
									<span className="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-error/10 text-error border border-error/20">
										DELETE
									</span>
									<code className="font-mono text-sm text-heading">
										/urls/:id
									</code>
								</div>
								<p className="text-sm text-text-muted mb-4">
									Permanently delete a link.
								</p>
								<div className="mb-4">
									<h4 className="font-medium text-sm text-heading mb-2">
										Example
									</h4>
									<CodeBlock
										code={
											codeExamples.links.delete[
												selectedLanguage
											]
										}
									/>
								</div>
							</div>
						</div>
					</div>
				</div>
			)}

			{activeSection === 'analytics' && (
				<div className="space-y-5">
					<div className="rounded-lg bg-surface border border-stroke p-5">
						<h2 className="text-lg font-semibold text-heading mb-3">
							Analytics
						</h2>
						<p className="text-sm text-text-muted mb-5">
							Retrieve detailed analytics for your shortened
							links.
						</p>

						<div className="space-y-6">
							<div>
								<div className="flex items-center gap-2.5 mb-3">
									<span className="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-info/10 text-info border border-info/20">
										GET
									</span>
									<code className="font-mono text-sm text-heading">
										/analytics/url/:id/stats
									</code>
								</div>
								<p className="text-sm text-text-muted mb-4">
									Get statistics for a specific link.
								</p>
								<div className="mb-4">
									<h4 className="font-medium text-sm text-heading mb-2">
										Example
									</h4>
									<CodeBlock
										code={
											codeExamples.analytics[
												selectedLanguage
											]
										}
									/>
								</div>
							</div>
						</div>
					</div>
				</div>
			)}

			{activeSection === 'qr-codes' && (
				<div className="space-y-5">
					<div className="rounded-lg bg-surface border border-stroke p-5">
						<h2 className="text-lg font-semibold text-heading mb-3">
							QR Codes
						</h2>
						<p className="text-sm text-text-muted mb-5">
							Generate QR codes for your links.
						</p>

						<div className="space-y-6">
							<div>
								<div className="flex items-center gap-2.5 mb-3">
									<span className="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-info/10 text-info border border-info/20">
										GET
									</span>
									<code className="font-mono text-sm text-heading">
										/urls/:id/qr
									</code>
								</div>
								<p className="text-sm text-text-muted mb-4">
									Get the QR code for a link as a Data URL.
								</p>
								<div className="mb-4">
									<h4 className="font-medium text-sm text-heading mb-2">
										Example
									</h4>
									<CodeBlock
										code={
											codeExamples['qr-codes'][
												selectedLanguage
											]
										}
									/>
								</div>
							</div>
						</div>
					</div>
				</div>
			)}

			{activeSection === 'webhooks' && (
				<div className="space-y-5">
					<div className="rounded-lg bg-surface border border-stroke p-5">
						<h2 className="text-lg font-semibold text-heading mb-3">
							Webhooks
						</h2>
						<p className="text-sm text-text-muted mb-5">
							Manage webhooks to receive real-time events.
						</p>

						<div className="space-y-6">
							<div>
								<div className="flex items-center gap-2.5 mb-3">
									<span className="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-md bg-success/10 text-success border border-success/20">
										POST
									</span>
									<code className="font-mono text-sm text-heading">
										/webhooks
									</code>
								</div>
								<p className="text-sm text-text-muted mb-4">
									Create a new webhook subscription.
								</p>
								<div className="mb-4">
									<h4 className="font-medium text-sm text-heading mb-2">
										Example
									</h4>
									<CodeBlock
										code={
											codeExamples.webhooks[
												selectedLanguage
											]
										}
									/>
								</div>
							</div>
						</div>
					</div>
				</div>
			)}
		</div>
	);
};

export default Content;
