import { staticExportIdParams } from "@/lib/static-export";

import { PayrollRunDetail } from "./payroll-run-detail";

/**
 * Exists so this route can be prerendered at all.
 *
 * `PayrollRunDetail` is a client component, and Next refuses
 * `generateStaticParams` on one — which is why
 * `docs/deployment/shared-hosting/DEPLOYMENT.md` §5 step 4 could not be
 * followed as written. A server component wrapper is the whole fix for that
 * half; `@/lib/static-export` explains the other half, which is that the
 * payroll run id arrives in the URL rather than in the build.
 */
export function generateStaticParams() {
  return staticExportIdParams();
}

export default async function Page({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <PayrollRunDetail routeId={id} />;
}
