# syntax=docker/dockerfile:1.7
# React + Vite frontend. Build context: ./frontend
#   dev        – Vite dev server (used by docker-compose.dev.yml)
#   nginx-base – plain nginx used in development (config mounted)
#   prod       – nginx serving the production build

FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

FROM deps AS dev
COPY . .
EXPOSE 5173
CMD ["sh", "-c", "npm install --no-audit --no-fund && npm run dev -- --host 0.0.0.0 --port 5173"]

FROM deps AS build
COPY . .
RUN npm run build

FROM nginx:1.27-alpine AS nginx-base
RUN rm -f /etc/nginx/conf.d/default.conf && mkdir -p /etc/nginx/snippets

FROM nginx-base AS prod
COPY --from=build /app/dist /usr/share/nginx/html
