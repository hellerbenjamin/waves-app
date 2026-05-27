import { describe, it, expect } from 'vitest';
import { typeLabel, typeOptions } from '@/lib/eventTypes';

describe('typeLabel', () => {
    it('maps known slugs to their human label', () => {
        expect(typeLabel('live_show')).toBe('Live show');
        expect(typeLabel('rehearsal')).toBe('Rehearsal');
        expect(typeLabel('studio_session')).toBe('Studio session');
        expect(typeLabel('other')).toBe('Other');
    });

    it('falls back to "Other" for unknown slugs', () => {
        expect(typeLabel('something_new')).toBe('Other');
        expect(typeLabel(undefined)).toBe('Other');
    });
});

describe('typeOptions', () => {
    it('shapes a slug list into Select options preserving order', () => {
        const opts = typeOptions(['live_show', 'rehearsal', 'studio_session']);
        expect(opts).toEqual([
            { value: 'live_show', label: 'Live show' },
            { value: 'rehearsal', label: 'Rehearsal' },
            { value: 'studio_session', label: 'Studio session' },
        ]);
    });
});
