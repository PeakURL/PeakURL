import { CircleAlert, CircleCheckBig } from 'lucide-react';
import type { StatusMessagesProps } from '../types';

const StatusMessages = ({ error, success }: StatusMessagesProps) => {
	if (!error && !success) return null;
	return (
		<>
			{error && (
				<div className="mb-4 p-3 bg-error/10 border border-error/20 rounded-lg">
					<p className="text-sm text-error flex items-center gap-2">
						<CircleAlert className="h-4 w-4" />
						{error}
					</p>
				</div>
			)}
			{success && (
				<div className="mb-4 p-3 bg-success/10 border border-success/20 rounded-lg">
					<p className="text-sm text-success flex items-center gap-2">
						<CircleCheckBig className="h-4 w-4" />
						{success}
					</p>
				</div>
			)}
		</>
	);
};

export default StatusMessages;
