// @ts-nocheck
import { Button } from '@/components/ui';
import { useGetUserProfileQuery } from '@/store/slices/api/user';
import { Link } from 'react-router-dom';
import { ExternalLink } from 'lucide-react';

const ApiImport = () => {
	const { data: userData } = useGetUserProfileQuery();
	const apiKey = userData?.data?.apiKey || 'YOUR_API_KEY';

	return (
		<div className="bg-surface border border-stroke rounded-lg p-5">
			<h2 className="text-base font-semibold text-heading mb-3">
				API Bulk Import
			</h2>
			<p className="text-sm text-text-muted mb-5">
				Use our API to programmatically import multiple URLs. Perfect
				for integrations and automated workflows.
			</p>
			<div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
				<div>
					<h3 className="font-medium text-sm text-heading mb-3">
						Example Request
					</h3>
					<pre className="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs border border-gray-700">
						<code>{`POST /v1/urls/bulk
Authorization: Bearer ${apiKey}
Content-Type: application/json

{
  "urls": [
    {
      "destinationUrl": "https://example.com/page1",
      "alias": "page1",
      "title": "Marketing Page",
      "password": "optional-password",
      "expiresAt": "2025-12-31"
    },
    {
      "destinationUrl": "https://example.com/page2",
      "alias": "page2"
    }
  ]
}`}</code>
					</pre>
				</div>
				<div>
					<h3 className="font-medium text-sm text-heading mb-3">
						Response
					</h3>
					<pre className="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs border border-gray-700">
						<code>{`{
  "success": true,
  "data": {
    "created": 2,
    "failed": 0,
    "results": [
      {
        "destinationUrl": "https://example.com/page1",
        "shortCode": "AbCd1",
        "alias": "page1",
        ...
      },
      ...
    ],
    "errors": []
  }
}`}</code>
					</pre>
				</div>
			</div>
			<div className="mt-5">
				<Link to="/dashboard/api-docs">
					<Button size="sm">
						<ExternalLink className="mr-2 h-4 w-4" />
						View API Documentation
					</Button>
				</Link>
			</div>
		</div>
	);
};

export default ApiImport;
