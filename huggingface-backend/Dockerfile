# ============================================================
# WhatsApp CRM Engine — Hugging Face Spaces
# node:18-bullseye has chromium available via apt
# ============================================================

FROM node:18-bullseye-slim

ENV DEBIAN_FRONTEND=noninteractive \
    PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    NODE_ENV=production

RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    ca-certificates \
    fonts-liberation \
    libgbm1 \
    libnss3 \
    libatk-bridge2.0-0 \
    libgtk-3-0 \
    libxss1 \
    libasound2 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

RUN mkdir -p wa_session logs tmp

COPY package*.json ./

RUN npm ci --only=production --ignore-scripts && npm cache clean --force

COPY . .

RUN chown -R node:node /app

USER node

EXPOSE 7860

CMD ["node", "server.js"]
