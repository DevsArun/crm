'use strict';

// ============================================================
// Logger Service — Winston structured logging
// Handles: file rotation, console output, log levels
// ============================================================

const { createLogger, format, transports } = require('winston');
const DailyRotateFile = require('winston-daily-rotate-file');
const path = require('path');
const fs = require('fs');

// ── Ensure log directory exists ──────────────────────────────
const LOG_DIR = process.env.LOG_DIR || './logs';
if (!fs.existsSync(LOG_DIR)) {
  fs.mkdirSync(LOG_DIR, { recursive: true });
}

// ── Custom format: timestamp + level + message + meta ────────
const customFormat = format.combine(
  format.timestamp({ format: 'YYYY-MM-DD HH:mm:ss' }),
  format.errors({ stack: true }),
  format.splat(),
  format.json()
);

// ── Console format: colorized, readable ──────────────────────
const consoleFormat = format.combine(
  format.colorize({ all: true }),
  format.timestamp({ format: 'HH:mm:ss' }),
  format.printf(({ timestamp, level, message, ...meta }) => {
    const metaStr = Object.keys(meta).length
      ? ' ' + JSON.stringify(meta, null, 0)
      : '';
    return `[${timestamp}] ${level}: ${message}${metaStr}`;
  })
);

// ── Daily rotate transport (info+) ───────────────────────────
const infoRotate = new DailyRotateFile({
  filename:     path.join(LOG_DIR, 'app-%DATE%.log'),
  datePattern:  'YYYY-MM-DD',
  zippedArchive: true,
  maxSize:      process.env.LOG_MAX_SIZE  || '10m',
  maxFiles:     process.env.LOG_MAX_FILES || '7d',
  level:        'info',
  format:       customFormat,
});

// ── Daily rotate transport (errors only) ─────────────────────
const errorRotate = new DailyRotateFile({
  filename:     path.join(LOG_DIR, 'error-%DATE%.log'),
  datePattern:  'YYYY-MM-DD',
  zippedArchive: true,
  maxSize:      process.env.LOG_MAX_SIZE  || '10m',
  maxFiles:     process.env.LOG_MAX_FILES || '7d',
  level:        'error',
  format:       customFormat,
});

// ── Build logger instance ─────────────────────────────────────
const logger = createLogger({
  level:       process.env.LOG_LEVEL || 'info',
  exitOnError: false,
  transports:  [
    new transports.Console({ format: consoleFormat }),
    infoRotate,
    errorRotate,
  ],
});

// ── Emit rotate events to console ────────────────────────────
infoRotate.on('rotate', (oldFile, newFile) => {
  logger.info('Log rotated', { oldFile, newFile });
});

// ── Helper: log with source context ──────────────────────────
const createContextLogger = (source) => ({
  info:  (message, meta = {}) => logger.info(message,  { source, ...meta }),
  warn:  (message, meta = {}) => logger.warn(message,  { source, ...meta }),
  error: (message, meta = {}) => logger.error(message, { source, ...meta }),
  debug: (message, meta = {}) => logger.debug(message, { source, ...meta }),
});

// ── In-memory ring buffer for /health + socket broadcast ─────
const MAX_BUFFER = 100;
const logBuffer  = [];

const originalWrite = logger.write
  ? logger.write.bind(logger)
  : null;

// Patch transports to also push to buffer
logger.on('data', (chunk) => {
  try {
    const entry = typeof chunk === 'string' ? JSON.parse(chunk) : chunk;
    logBuffer.unshift(entry);
    if (logBuffer.length > MAX_BUFFER) logBuffer.pop();
  } catch (_) {
    // silently ignore parse errors in log buffer
  }
});

/**
 * Get recent log entries from in-memory buffer
 * @param {number} count - how many entries to return
 * @returns {Array}
 */
const getRecentLogs = (count = 50) => logBuffer.slice(0, count);

module.exports = {
  logger,
  createContextLogger,
  getRecentLogs,
  // Convenience shortcuts
  info:  (msg, meta = {}) => logger.info(msg,  meta),
  warn:  (msg, meta = {}) => logger.warn(msg,  meta),
  error: (msg, meta = {}) => logger.error(msg, meta),
  debug: (msg, meta = {}) => logger.debug(msg, meta),
};
