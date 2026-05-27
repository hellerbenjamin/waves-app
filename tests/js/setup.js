// Laravel's Ziggy ships `route()` as a global injected at runtime via the @routes
// Blade directive; tests run outside that pipeline, so stub it as identity-ish
// to keep imports happy. Override per-test when an exact URL matters.
globalThis.route = (name, params) => {
    if (params == null) return `/__test/${name}`;
    if (Array.isArray(params)) return `/__test/${name}/${params.join('/')}`;
    if (typeof params === 'object') return `/__test/${name}/${Object.values(params).join('/')}`;
    return `/__test/${name}/${params}`;
};
