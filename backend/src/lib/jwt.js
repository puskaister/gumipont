import jwt from 'jsonwebtoken';

function secret() {
  const value = process.env.JWT_SECRET;
  if (!value) throw new Error('JWT_SECRET environment variable is required');
  return value;
}

export function signToken(payload) {
  return jwt.sign(payload, secret(), { expiresIn: process.env.JWT_EXPIRES_IN || '15m' });
}

export function verifyToken(token) {
  return jwt.verify(token, secret());
}
