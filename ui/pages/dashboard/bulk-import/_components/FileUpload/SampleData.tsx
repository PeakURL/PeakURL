import { __ } from '@/i18n';
import type { SampleDataProps } from './types';

function SampleData({ sampleData }: SampleDataProps) {
	return (
		<div className="bg-surface border border-stroke rounded-lg p-5">
			<h3 className="text-base font-semibold text-heading mb-4">
				{__('Sample Data Structure')}
			</h3>
			<div className="overflow-x-auto">
				<table className="w-full text-sm">
					<thead>
						<tr className="text-inline-start border-b border-stroke">
							<th className="py-2 px-3 text-xs font-semibold text-heading bg-surface-alt">
								url
							</th>
							<th className="py-2 px-3 text-xs font-semibold text-heading bg-surface-alt">
								alias
							</th>
							<th className="py-2 px-3 text-xs font-semibold text-heading bg-surface-alt">
								title
							</th>
						</tr>
					</thead>
					<tbody>
						{sampleData.map((row, index) => (
							<tr
								key={index}
								className="border-b border-stroke last:border-b-0"
							>
								<td className="py-2 px-3 font-mono text-xs text-heading">
									{row.url}
								</td>
								<td className="py-2 px-3 text-xs text-heading">
									{row.alias}
								</td>
								<td className="py-2 px-3 text-xs text-heading">
									{row.title}
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>
		</div>
	);
}

export default SampleData;
