# ============================================================
# WhatsApp CRM Engine — Dockerfile for Hugging Face Spaces
# Base: Node.js 20 + Chromium (for whatsapp-web.js / Puppeteer)
# ============================================================

FROM node:20-slim

# ── System metadata ─────────────────────────────────────────
LABEL maintainer="WhatsApp CRM OS"
LABEL description="WhatsApp CRM Engine — HF Spaces Deployment"

# ── Install Chromium + system dependencies for Puppeteer ────
RUN apt-get update && apt-get install -y \
    chromium \
    chromium-sandbox \
    ca-certificates \
    fonts-liberation \
    fonts-noto-color-emoji \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libc6 \
    libcairo2 \
    libcups2 \
    libdbus-1-3 \
    libexpat1 \
    libfontconfig1 \
    libgbm1 \
    libgcc1 \
    libglib2.0-0 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libpango-1.0-0 \
    libpangocairo-1.0-0 \
    libstdc++6 \
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
    lsb-release \
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

# ── Create session persistence directory ────────────────────
RUN mkdir -p /app/wa_session \
    && mkdir -p /app/logs \
    && mkdir -p /app/tmp

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
