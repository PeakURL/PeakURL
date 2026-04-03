// @ts-nocheck
import {
	AlertCircle,
	Gauge,
	ArrowLeft,
	Link2 as Link2Icon,
	Wrench,
	Settings,
	FileX,
} from 'lucide-react';
import { Button } from '@/components/ui';
import { Link, useNavigate } from 'react-router-dom';

const NotFoundPage = () => {
	const navigate = useNavigate();

	return (
		<div className="min-h-[calc(100vh-80px)] flex flex-col items-center justify-center p-4">
			<div className="text-center max-w-lg w-full">
				<div className="flex flex-col items-center justify-center">
					<div className="w-24 h-24 bg-error/10 rounded-3xl flex items-center justify-center mb-6 shadow-sm border border-error/20">
						<FileX
							size={40}
							className="text-error"
							strokeWidth={2}
						/>
					</div>

					<h1 className="text-3xl font-bold text-heading mb-3">
						Page Not Found
					</h1>

					<p className="text-lg text-text-muted mb-8 leading-relaxed max-w-md mx-auto">
						The admin page you&rsquo;re looking for doesn&rsquo;t
						exist. It might have been moved, deleted, or you may not
						have permission.
					</p>

					<div className="flex flex-col sm:flex-row gap-3 justify-center mb-12 w-full">
						<Button
							variant="secondary"
							onClick={() => navigate(-1)}
							className="w-full sm:w-auto"
						>
							<ArrowLeft size={16} className="mr-2" />
							Go Back
						</Button>
						<Button
							variant="primary"
							onClick={() => navigate('/dashboard')}
							className="w-full sm:w-auto"
						>
							<Gauge size={16} className="mr-2" />
							Go to Dashboard
						</Button>
					</div>

					<div className="border-t border-stroke pt-8 w-full">
						<p className="text-sm text-text-muted font-medium mb-4 uppercase tracking-wider">
							Popular Destinations
						</p>
						<div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
							<Link
								to="/dashboard/links"
								className="flex items-center justify-center gap-2 p-3 rounded-xl border border-stroke hover:border-accent/50 hover:bg-accent/5 transition-all text-sm font-medium text-text-muted hover:text-accent group"
							>
								<Link2Icon
									size={16}
									className="group-hover:scale-110 transition-transform"
								/>
								All Links
							</Link>
							<Link
								to="/dashboard/tools/import/file"
								className="flex items-center justify-center gap-2 p-3 rounded-xl border border-stroke hover:border-accent/50 hover:bg-accent/5 transition-all text-sm font-medium text-text-muted hover:text-accent group"
							>
								<Wrench
									size={16}
									className="group-hover:scale-110 transition-transform"
								/>
								Import
							</Link>
							<Link
								to="/dashboard/settings"
								className="flex items-center justify-center gap-2 p-3 rounded-xl border border-stroke hover:border-accent/50 hover:bg-accent/5 transition-all text-sm font-medium text-text-muted hover:text-accent group"
							>
								<Settings
									size={16}
									className="group-hover:scale-110 transition-transform"
								/>
								Settings
							</Link>
						</div>
					</div>
				</div>
			</div>
		</div>
	);
};

export default NotFoundPage;
