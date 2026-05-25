# ============================================================
# WhatsApp CRM Engine — Hugging Face Spaces
# Uses Puppeteer's bundled Chromium — most reliable on HF
# ============================================================

FROM node:18-slim

ENV DEBIAN_FRONTEND=noninteractive

# Minimal deps for Puppeteer bundled Chromium
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
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
        libx11-6 \
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
        fonts-liberation \
        wget \
    && rm -rf /var/lib/apt/lists/*

# Let Puppeteer download its own Chromium (skip system chromium)
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=false
ENV CHROMIUM_FLAGS="--no-sandbox --disable-setuid-sandbox --disable-dev-shm-usage"

WORKDIR /app

RUN mkdir -p /app/wa_session /app/logs /app/tmp

COPY package*.json ./

# Install with Puppeteer's Chromium download enabled
RUN npm ci --only=production && npm cache clean --force

COPY . .

RUN chown -R node:node /app

USER node

EXPOSE 7860

CMD ["node", "server.js"]
