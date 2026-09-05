# Frontend

Client-facing web application for **HN9 AI Studio**.

## Stack

- React + Vite
- React Router
- Bootstrap 5
- Laravel `/api/v1` with Sanctum Bearer tokens

## Local development

```bash
cp .env.example .env
npm install
npm run dev
```

The API base URL is `VITE_API_BASE_URL` (default `http://127.0.0.1:8000/api/v1`).
The Laravel backend must be running separately.

## Location

`HN9-AI-Studio/Frontend`
