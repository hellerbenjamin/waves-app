// Human labels for the event `type` slugs defined on the Event model.
const LABELS = {
    live_show: 'Live show',
    rehearsal: 'Rehearsal',
    studio_session: 'Studio session',
    other: 'Other',
};

export const typeLabel = (slug) => LABELS[slug] ?? 'Other';

// Build { value, label } options for a Select from the server-provided slug list.
export const typeOptions = (types) => types.map((value) => ({ value, label: typeLabel(value) }));
