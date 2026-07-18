module.exports = {
  ci: {
    collect: {
      url: [
        "http://demo.localhost:3000/login",
        "http://demo.localhost:3000/dashboard",
        "http://demo.localhost:3000/employees",
        "http://demo.localhost:3000/attendance",
        "http://demo.localhost:3000/payroll",
      ],
      numberOfRuns: 3,
      settings: {
        preset: "desktop",
        chromeFlags: "--no-sandbox --disable-gpu",
      },
    },
    assert: {
      assertions: {
        "categories:performance": ["error", { minScore: 0.8 }],
        "categories:accessibility": ["error", { minScore: 0.9 }],
        "categories:best-practices": ["warn", { minScore: 0.8 }],
        "categories:seo": ["warn", { minScore: 0.8 }],
        "first-contentful-paint": ["warn", { maxNumericValue: 1500 }],
        "largest-contentful-paint": ["warn", { maxNumericValue: 2500 }],
        "cumulative-layout-shift": ["error", { maxNumericValue: 0.1 }],
        "total-blocking-time": ["warn", { maxNumericValue: 300 }],
      },
    },
    upload: {
      target: "filesystem",
      outputDir: "./lighthouse-reports",
    },
  },
};
