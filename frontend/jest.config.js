module.exports = {
  preset: 'jest-preset-angular',
  setupFilesAfterEnv: ['<rootDir>/setup-jest.ts'],
  // e2e/ belongs to Playwright. Its files are named *.e2e.ts precisely so
  // jest's default testMatch never claims them, and this ignore is the second
  // lock: a suite that ran a browser journey inside jsdom would fail in a way
  // that says nothing useful.
  testPathIgnorePatterns: ['<rootDir>/node_modules/', '<rootDir>/dist/', '<rootDir>/e2e/'],
  transformIgnorePatterns: ['node_modules/(?!.*\\.mjs$)']
};
