/** @type {import('next').NextConfig} */
const allowedOrigins = (process.env.ADMIN_ALLOWED_ORIGINS ?? '')
  .split(',')
  .map((origin) => origin.trim())
  .filter(Boolean);

const config = {
  output: 'standalone',
  experimental: {
    serverActions: {
      allowedOrigins,
    },
  },
};

export default config;
