import { __ } from '@/i18n';
import type { SampleDataProps } from './types';

function SampleData({ sampleData }: SampleDataProps) {
	return (
		<div className="import-panel import-sample-panel">
			<h3 className="import-panel-title import-sample-title">
				{__('Sample Data Structure')}
			</h3>
			<div className="import-sample-table-wrapper">
				<table className="import-sample-table">
					<thead>
						<tr className="import-sample-header-row">
							<th className="import-sample-header-cell">
								url
							</th>
							<th className="import-sample-header-cell">
								alias
							</th>
							<th className="import-sample-header-cell">
								title
							</th>
						</tr>
					</thead>
					<tbody>
						{sampleData.map((row, index) => (
							<tr
								key={index}
								className="import-sample-row"
							>
								<td className="import-sample-code">
									{row.url}
								</td>
								<td className="import-sample-cell">
									{row.alias}
								</td>
								<td className="import-sample-cell">
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
