process.env.NODE_ENV = 'test';
process.env.JWT_SECRET = 'test-secret-key-for-vitest';
process.env.JWT_EXPIRES_IN = '15m';
process.env.ADMIN_REGISTRATION_CODE = 'test-admin-code';
process.env.FRONTEND_ORIGIN = 'http://localhost:5173';
process.env.DB_PATH = ':memory:';
