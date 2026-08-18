# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
FROM node:20-alpine
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY server.js ./
EXPOSE 8790
CMD ["node", "server.js"]
