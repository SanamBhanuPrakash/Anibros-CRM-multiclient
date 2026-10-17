import { describe, it, expect } from 'vitest';
import { formatCurrency, formatDate, formatNumber } from './utils';

describe('formatCurrency', () => {
  it('formats positive numbers as INR currency by default', () => {
    const formatted = formatCurrency(500000);
    expect(formatted).toContain('5,00,000');
  });

  it('handles null and undefined gracefully', () => {
    expect(formatCurrency(null)).toBe('—');
    expect(formatCurrency(undefined)).toBe('—');
  });

  it('formats zero correctly', () => {
    const formatted = formatCurrency(0);
    expect(formatted).toContain('0');
  });
});

describe('formatNumber', () => {
  it('formats numbers with Indian numbering grouping (en-IN)', () => {
    expect(formatNumber(1000)).toBe('1,000');
    expect(formatNumber(1000000)).toBe('10,00,000');
  });

  it('handles null and undefined', () => {
    expect(formatNumber(null)).toBe('—');
    expect(formatNumber(undefined)).toBe('—');
  });
});

describe('formatDate', () => {
  it('formats valid dates nicely', () => {
    const result = formatDate('2026-10-12T10:00:00Z');
    expect(result).toBeTruthy();
    expect(result).not.toBe('—');
  });

  it('handles null and undefined', () => {
    expect(formatDate(null)).toBe('—');
    expect(formatDate(undefined)).toBe('—');
  });
});
