import { __ } from "@/i18n";

import type { SampleDataProps } from "./types";

function SampleData({ sampleData }: SampleDataProps) {
	return (
		<div className="import-panel import-sample-panel">
			<h3 className="import-panel-title import-sample-title">
				{__("Sample Data Structure")}
			</h3>
			<div className="import-sample-table-wrapper">
				<table className="import-sample-table">
					<thead>
						<tr className="import-sample-header-row">
							<th className="import-sample-header-cell">
								destinationUrl
							</th>
							<th className="import-sample-header-cell">alias</th>
							<th className="import-sample-header-cell">title</th>
							<th className="import-sample-header-cell">
								status
							</th>
							<th className="import-sample-header-cell">
								utmSource
							</th>
						</tr>
					</thead>
					<tbody>
						{sampleData.map((row, index) => (
							<tr key={index} className="import-sample-row">
								<td className="import-sample-code">
									{row.destinationUrl}
								</td>
								<td className="import-sample-cell">
									{row.alias}
								</td>
								<td className="import-sample-cell">
									{row.title}
								</td>
								<td className="import-sample-cell">
									{row.status || "active"}
								</td>
								<td className="import-sample-cell">
									{row.utmSource || ""}
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
