const nextJest = require('next/jest');

const createJestConfig = nextJest({ dir: './' });

/** @type {import('jest').Config} */
const config = {
  displayName: 'admin',
  testEnvironment: 'jsdom',
  setupFilesAfterEnv: ['<rootDir>/__tests__/support/setup.ts'],
  moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx', 'json'],
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/$1',
  },
  testMatch: ['<rootDir>/__tests__/**/*.test.{ts,tsx}'],
  collectCoverageFrom: [
    'src/**/*.{ts,tsx}',
    'components/**/*.{ts,tsx}',
    'lib/**/*.{ts,tsx}',
    '!**/*.d.ts',
  ],
};

// Async wrapper allows extending the resolved Next.js jest config
const createFinalConfig = async () => {
  const nextConfig = await createJestConfig(config)();
  return {
    ...nextConfig,
    // Transform lucide-react (ESM package) so Jest can process its imports
    transformIgnorePatterns: [
      '/node_modules/(?!(lucide-react)/)',
    ],
  };
};

module.exports = createFinalConfig;
