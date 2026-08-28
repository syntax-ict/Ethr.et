FROM node:22-alpine AS base

FROM base AS deps
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci

FROM base AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules

# Cache-buster for the source copy below.
#
# Docker keys the `COPY . .` layer on file metadata, and on Docker Desktop for
# Windows that metadata is served through a filesystem bridge that has twice
# reported a cache hit for a source tree that had genuinely changed — producing
# a container that silently served the *previous* build. Every verification run
# against it was measuring stale code, which is an expensive kind of wrong: the
# fix looks broken and you go and change working code.
#
# Passing `--build-arg CACHEBUST=$(date +%s)` invalidates this one layer and
# nothing above it, so a guaranteed-fresh image still reuses the `npm ci`
# cache. `npm run docker:refresh` in src/package.json does exactly that.
ARG CACHEBUST=0
RUN echo "cachebust=${CACHEBUST}" > /tmp/.cachebust

COPY . .

# Next.js inlines NEXT_PUBLIC_* vars into the compiled output at build time —
# setting them via `docker-compose environment:`/`env_file:` at container
# runtime has no effect. These build args (ENV takes precedence over any
# .env.production file also present in the build context) are the only way
# to control them per environment.
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_APP_NAME
ARG NEXT_PUBLIC_APP_URL
ARG NEXT_PUBLIC_REVERB_HOST
ARG NEXT_PUBLIC_REVERB_PORT
ARG NEXT_PUBLIC_REVERB_SCHEME
# The tenant selector. Empty in single-host dev (localhost), so hostContext()
# falls back to "unknown" and the login stays in dev mode. Set per environment
# (docker-compose.prod.yml passes it) it becomes authoritative: the apex,
# admin.<root> and {tenant}.<root> are told apart, which is the whole basis of
# the hostname-aware login and the /admin host guard in middleware.ts. Without
# this line the build arg the prod compose passes is silently dropped and that
# entire system is inert once deployed.
ARG NEXT_PUBLIC_ROOT_DOMAIN
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
ENV NEXT_PUBLIC_APP_NAME=${NEXT_PUBLIC_APP_NAME}
ENV NEXT_PUBLIC_APP_URL=${NEXT_PUBLIC_APP_URL}
ENV NEXT_PUBLIC_REVERB_HOST=${NEXT_PUBLIC_REVERB_HOST}
ENV NEXT_PUBLIC_REVERB_PORT=${NEXT_PUBLIC_REVERB_PORT}
ENV NEXT_PUBLIC_REVERB_SCHEME=${NEXT_PUBLIC_REVERB_SCHEME}
ENV NEXT_PUBLIC_ROOT_DOMAIN=${NEXT_PUBLIC_ROOT_DOMAIN}
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

FROM base AS runner
WORKDIR /app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

RUN addgroup --system --gid 1001 nodejs && \
    adduser --system --uid 1001 nextjs

COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

CMD ["node", "server.js"]
