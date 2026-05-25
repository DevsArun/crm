# ============================================================
# WhatsApp CRM Engine — Dockerfile for Hugging Face Spaces
# Base: Node.js 20 Bullseye (Debian 11) — stable Chromium support
# ============================================================

FROM node:20-bullseye-slim

# ── System metadata ─────────────────────────────────────────
LABEL maintainer="WhatsApp CRM OS"
LABEL description="WhatsApp CRM Engine — HF Spaces Deployment"

# ── Install Chromium + system dependencies for Puppeteer ────
# Using bullseye (Debian 11) — chromium-sandbox is included in chromium package
RUN apt-get update && apt-get install -y \
    chromium \
    ca-certificates \
    fonts-liberation \
    fonts-noto-color-emoji \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcairo2 \
    libcups2 \
    libdbus-1-3 \
    libexpat1 \
    libfontconfig1 \
    libgbm1 \
    libglib2.0-0 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libpango-1.0-0 \
    libpangocairo-1.0-0 \
    libx11-6 \
    libx11-xcb1 \
    libxcb1 \
    libxcomposite1 \
    libxcursor1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxi6 \
    libxrandr2 \
    libxrender1 \
    libxss1 \
    libxtst6 \
    wget \
    xdg-utils \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# ── Tell Puppeteer to use installed Chromium ────────────────
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
ENV CHROMIUM_PATH=/usr/bin/chromium

# ── Create app directory ─────────────────────────────────────
WORKDIR /app

# ── Create required directories ─────────────────────────────
RUN mkdir -p /app/wa_session /app/logs /app/tmp

# ── Copy dependency files first (layer cache optimization) ──
COPY package*.json ./

# ── Install Node.js dependencies ────────────────────────────
RUN npm ci --only=production && npm cache clean --force

# ── Copy application source ──────────────────────────────────
COPY . .

# ── Set correct permissions ──────────────────────────────────
RUN chmod -R 755 /app \
    && chown -R node:node /app

# ── Switch to non-root user (security) ──────────────────────
USER node

# ── Hugging Face Spaces requires port 7860 ──────────────────
EXPOSE 7860

# ── Health check ─────────────────────────────────────────────
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD node -e "require('http').get('http://localhost:7860/health', (r) => { process.exit(r.statusCode === 200 ? 0 : 1) }).on('error', () => process.exit(1))"

# ── Start command ─────────────────────────────────────────────
CMD ["node", "server.js"]
