Understand the whole architecture before coding.
Never generate placeholder code.
Never skip validation.
Implement complete production-ready features.
Run automated tests after every change.
Build incrementally, one module at a time.
Maintain documentation as the project evolves.
Keep APIs versioned and backward compatible.
Preserve tenant isolation and security.
Automatic Environment Detection




Technology Stack
continue only free version no need of api payments for tech stacks. 
Backend
Laravel 11
Composer version 2.9.8 2026-05-13 09:28:38
PHP version 8.2.12 (C:\xampp\php\php.exe)
PHP 8.2.12 (cli) (built: Oct 24 2023 21:15:15) (ZTS Visual C++ 2019 x64)
Copyright (c) The PHP Group
Zend Engine v4.2.12, Copyright (c) Zend Technologies
Docker version 29.4.3, build 055a478
Docker Compose version v5.1.3

Redis
Horizon
Reverb
Sanctum
Docker
Frontend
Next.js 15
React
TypeScript
Tailwind CSS
shadcn/ui
TanStack Query
React Hook Form
Zod
Mobile
Flutter
Android
iOS
Infrastructure
Docker
Nginx
Redis
MongoDB Replica Set
Queue Workers
Scheduler
MinIO (S3-compatible object storage)
Monitoring
Centralized Logging
Claude Code Build Rules

Every implementation should follow the same lifecycle.

Read documentation

↓

Understand architecture

↓

Analyze dependencies

↓

Create implementation plan

↓

Generate code

↓

Run formatter

↓

Run static analysis

↓

Run unit tests

↓

Run feature tests

↓

Launch browser

↓

Verify UI

↓

Fix issues

↓

Update documentation

↓

Commit-ready state

Claude should not mark a task complete until it passes this cycle. This aligns with Anthropic's guidance to give Claude a way to verify its own work by running tests and checking outcomes.

Browser Testing

Require Claude to verify every UI module in a browser.

For each completed feature it should:

Start backend services.
Start frontend development server.
Open the application in a browser.
Navigate through the implemented feature.
Validate layouts, forms, and responsive behavior.
Check console output.
Fix runtime errors.
Re-test until clean.

Verification should include:

Desktop
Tablet
Mobile viewport
Dark mode
Light mode
Production Requirements

Every feature should include:

Validation
Authorization
Localization
Error handling
Loading states
Empty states
Retry handling
Offline behavior (where applicable)
Audit logging
Accessibility
Tests
Documentation
Ethiopian Hosting Compatibility

Design for environments commonly available from Ethiopian hosting providers or local VPS deployments.

The deployment should support:

Ubuntu Server
Docker Engine
Docker Compose
Nginx reverse proxy
Let's Encrypt TLS
MongoDB
Redis
Supervisor (or containerized process management)
PHP-FPM
Queue workers
Scheduled jobs

Avoid dependencies on cloud-provider-specific services so the platform can run on:

Local VPS
Private data centers
Ethiopian hosting providers
International cloud providers
Deployment Pipeline

Claude should generate:

Development

↓

Local Docker

↓

Staging

↓

Production

↓

Blue/Green Deployment

↓

Health Check

↓

Rollback

↓

Monitoring
Enterprise Quality Gates

No module is considered complete until it satisfies all of these:

Architecture review
Type checking
Static analysis
Unit tests
Integration tests
End-to-end tests
API validation
Mobile responsiveness
Accessibility
Security review
Performance review
Documentation update
Browser verification
Production deployment validation
Repository Standards
backend/

frontend/

mobile/

docker/

docs/

tests/

scripts/

.github/

infrastructure/

packages/

shared/