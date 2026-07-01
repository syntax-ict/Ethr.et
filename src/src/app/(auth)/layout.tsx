export default function AuthLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen">
      <div className="hidden w-1/2 bg-primary lg:flex lg:flex-col lg:items-center lg:justify-center lg:p-12">
        <div className="max-w-md text-center">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white/20">
            <span className="text-2xl font-bold text-white">E</span>
          </div>
          <h2 className="mt-8 text-3xl font-bold text-white">
            Ethiopian Workforce Operating System
          </h2>
          <p className="mt-4 text-lg text-white/80">
            Manage employees, attendance, payroll, and leave — all in one
            platform built for Ethiopian organizations.
          </p>
          <div className="mt-10 grid grid-cols-2 gap-4 text-left">
            {[
              "Multi-tenant SaaS",
              "Offline-first",
              "Ethiopian tax & pension",
              "Bilingual (EN + AM)",
              "Biometric devices",
              "6-month free trial",
            ].map((feature) => (
              <div
                key={feature}
                className="flex items-center gap-2 text-white/90"
              >
                <div className="h-1.5 w-1.5 rounded-full bg-white/60" />
                <span className="text-sm">{feature}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
      <div className="flex flex-1 items-center justify-center px-4 py-12">
        <div className="w-full max-w-md">{children}</div>
      </div>
    </div>
  );
}
