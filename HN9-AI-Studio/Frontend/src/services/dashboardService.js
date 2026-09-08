import { apiRequest } from './apiClient';

export function getDashboardSummary() {
  return apiRequest('/dashboard/summary').then(normalizeSummary);
}

export function getDashboardAnalytics({ from = '', to = '' } = {}) {
  const params = new URLSearchParams();
  if (from) {
    params.set('from', from);
  }
  if (to) {
    params.set('to', to);
  }
  const query = params.toString();
  const path = query ? `/dashboard/analytics?${query}` : '/dashboard/analytics';

  return apiRequest(path).then(normalizeAnalytics);
}

export function getDashboardUsage(filters = {}) {
  return apiRequest(withLedgerQuery('/dashboard/usage', filters)).then(normalizeUsage);
}

export function getDashboardCosts(filters = {}) {
  return apiRequest(withLedgerQuery('/dashboard/costs', filters)).then(normalizeCosts);
}

function withLedgerQuery(path, { from = '', to = '', project = '', provider = '' } = {}) {
  const params = new URLSearchParams();
  if (from) params.set('from', from);
  if (to) params.set('to', to);
  if (project) params.set('project', project);
  if (provider) params.set('provider', provider);
  const query = params.toString();
  return query ? `${path}?${query}` : path;
}

function normalizeSummary(raw) {
  const data = raw && typeof raw === 'object' && raw.projects ? raw : {};

  return {
    projects: {
      total: numberOrZero(data.projects?.total),
      draft: numberOrZero(data.projects?.draft),
      active: numberOrZero(data.projects?.active),
      completed: numberOrZero(data.projects?.completed),
      archived: numberOrZero(data.projects?.archived),
    },
    scripts: normalizeBreakdown(data.scripts),
    images: normalizeBreakdown(data.images),
    videos: normalizeBreakdown(data.videos),
    assets: normalizeBreakdown(data.assets),
    recent_projects: Array.isArray(data.recent_projects) ? data.recent_projects : [],
    recent_activity: Array.isArray(data.recent_activity) ? data.recent_activity : [],
  };
}

function normalizeAnalytics(raw) {
  const data = raw && typeof raw === 'object' && raw.projects ? raw : {};
  const content = data.content && typeof data.content === 'object' ? data.content : {};
  const activity = data.activity && typeof data.activity === 'object' ? data.activity : {};

  return {
    projects: {
      total: numberOrZero(data.projects?.total),
      draft: numberOrZero(data.projects?.draft),
      active: numberOrZero(data.projects?.active),
      completed: numberOrZero(data.projects?.completed),
      archived: numberOrZero(data.projects?.archived),
      timeline: Array.isArray(data.projects?.timeline) ? data.projects.timeline : [],
    },
    content: {
      scripts: normalizeFlatCounts(content.scripts),
      images: normalizeFlatCounts(content.images),
      videos: normalizeFlatCounts(content.videos),
      assets: normalizeFlatCounts(content.assets),
    },
    creation_timeline: Array.isArray(data.creation_timeline) ? data.creation_timeline : [],
    activity: {
      total: numberOrZero(activity.total),
      recent_count: numberOrZero(activity.recent_count),
      by_module: activity.by_module && typeof activity.by_module === 'object' ? activity.by_module : {},
      by_action: activity.by_action && typeof activity.by_action === 'object' ? activity.by_action : {},
    },
    project_productivity: Array.isArray(data.project_productivity) ? data.project_productivity : [],
  };
}

function normalizeBreakdown(value) {
  return {
    total: numberOrZero(value?.total),
    by_status: value?.by_status && typeof value.by_status === 'object' ? value.by_status : {},
  };
}

function normalizeFlatCounts(value) {
  if (!value || typeof value !== 'object') {
    return { total: 0 };
  }

  const next = {};
  Object.entries(value).forEach(([key, count]) => {
    next[key] = numberOrZero(count);
  });
  return next;
}

function normalizeUsage(raw) {
  const data = raw && typeof raw === 'object' ? raw : {};
  const tokens = data.tokens && typeof data.tokens === 'object' ? data.tokens : {};

  return {
    operations: numberOrZero(data.operations),
    tokens: {
      input: nullableNumber(tokens.input),
      output: nullableNumber(tokens.output),
      total: nullableNumber(tokens.total),
      operations_with_tokens: numberOrZero(tokens.operations_with_tokens),
      operations_without_tokens: numberOrZero(tokens.operations_without_tokens),
    },
    by_provider: Array.isArray(data.by_provider) ? data.by_provider : [],
    by_model: Array.isArray(data.by_model) ? data.by_model : [],
    timeline: Array.isArray(data.timeline) ? data.timeline : [],
  };
}

function normalizeCosts(raw) {
  const data = raw && typeof raw === 'object' ? raw : {};

  return {
    has_records: Boolean(data.has_records),
    message: data.message || 'Cost data is not available yet. No provider costs have been recorded.',
    totals: Array.isArray(data.totals) ? data.totals : [],
    by_provider: Array.isArray(data.by_provider) ? data.by_provider : [],
    by_model: Array.isArray(data.by_model) ? data.by_model : [],
    timeline: Array.isArray(data.timeline) ? data.timeline : [],
  };
}

function nullableNumber(value) {
  if (value === null || value === undefined || value === '') {
    return null;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

function numberOrZero(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}
