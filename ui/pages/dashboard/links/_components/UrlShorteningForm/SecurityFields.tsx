// @ts-nocheck
import { Input } from '@/components/ui';
import { Lock, Tags } from 'lucide-react';

const SecurityFields = ({ title, setTitle, password, setPassword }) => {
	return (
		<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
			<div>
				<label
					htmlFor="title"
					className="block text-sm font-medium text-heading mb-1.5"
				>
					<Tags className="mr-2 inline h-4 w-4 text-text-muted" />
					Link Title (Optional)
				</label>
				<Input
					type="text"
					id="title"
					value={title}
					onChange={(e) => setTitle(e.target.value)}
					placeholder="My Awesome Link"
				/>
			</div>

			<div>
				<label
					htmlFor="password"
					className="block text-sm font-medium text-heading mb-1.5"
				>
					<Lock className="w-4 h-4 inline mr-2 text-text-muted" />
					Password Protection (Optional)
				</label>
				<Input
					type="password"
					id="password"
					value={password}
					onChange={(e) => setPassword(e.target.value)}
					placeholder="Enter password"
				/>
			</div>
		</div>
	);
};

export default SecurityFields;
