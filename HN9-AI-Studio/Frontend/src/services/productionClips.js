/** Presentation for the scene production workspace. Slots, versions and readiness come from the server. */

export const NOT_CONFIGURED = 'Video generation is not configured yet.';
export const PARTS_NOT_READY = 'Some video parts are not ready yet.';
export const BUILD_FAILED = "We couldn't build this scene. Please try again.";
export const GENERATE_FAILED = "We couldn't create this video. You can try again.";
export const UNSUPPORTED = "This video setup isn't supported by the connected services.";

const ACTIVE = new Set(['queued', 'submitted', 'processing']);

export const CLIP_MODES = [
  { value: 'text_to_video', flag: 'text', label: 'From a description', hint: 'The clip is made from this scene’s description.' },
  { value: 'image_to_video', flag: 'image', label: 'From a picture', hint: 'Starts from an approved picture already chosen in Cast & Look.' },
  { value: 'reference_to_video', flag: 'reference', label: 'From my character/style', hint: 'Keeps the approved character and look already chosen for this story.' },
];

export function isActiveStatus(status) {
  return ACTIVE.has(status);
}

/** The visible time range is the slot the server already stored. */
export function clipWindow(clip) {
  const start = Number(clip?.start_second);
  const end = Number(clip?.end_second);
  if (!Number.isFinite(start) || !Number.isFinite(end)) return '';
  return `${start}–${end} sec`;
}

export function nextClipIntent(versionCount) {
  const count = Number.isFinite(Number(versionCount)) ? Number(versionCount) : 0;
  return `clip-${count + 1}`;
}

export function availableModes(connections, pictureCount) {
  return CLIP_MODES.filter((mode) => {
    if (!connections?.video?.[mode.flag]) return false;
    if (mode.value === 'text_to_video') return true;
    return Number(pictureCount) > 0;
  });
}

export function clipState(clip) {
  const versions = Array.isArray(clip?.versions) ? clip.versions : [];
  if (clip?.active_generation && isActiveStatus(clip.active_generation.status)) {
    return {
      key: 'generating',
      label: 'Generating',
      detail: 'Generating your video... This can take a little while.',
    };
  }
  const selected = versions.find((version) => version.selected);
  if (selected) {
    return { key: 'selected', label: `${versionName(selected)} — Selected`, version: selected };
  }
  const approved = [...versions].reverse().find((version) => version.approved || version.status === 'approved');
  if (approved) {
    return { key: 'approved', label: `${versionName(approved)} — Approved`, version: approved };
  }
  const review = versions.find((version) => version.status === 'pending_review');
  if (review) {
    return { key: 'review', label: `${versionName(review)} — Ready for review`, version: review };
  }
  const changes = versions.find((version) => version.status === 'needs_rework');
  if (changes) {
    return { key: 'changes', label: 'Changes requested', version: changes };
  }
  if (/failed/i.test(String(clip?.message || ''))) {
    return { key: 'failed', label: 'Generation failed', detail: GENERATE_FAILED };
  }
  return { key: 'empty', label: 'No video yet', detail: clip?.message || 'No video yet' };
}

export function versionActions(version) {
  const pending = version?.status === 'pending_review';
  const approved = Boolean(version?.approved) || version?.status === 'approved';
  const selected = version?.selected === true;
  const preview = version?.preview_available === true;
  return {
    preview,
    approve: pending && preview && !approved,
    requestChanges: pending && preview && !approved,
    select: approved && preview && !selected,
    selected,
  };
}

export function sceneSummary(clips, assemblies) {
  const list = Array.isArray(clips) ? clips : [];
  const rows = Array.isArray(assemblies) ? assemblies : [];
  const selected = list.filter((clip) => (clip.versions || []).some((version) => version.selected)).length;
  const approved = list.filter((clip) => (clip.versions || []).some((version) => version.approved || version.status === 'approved')).length;
  const generating = list.filter((clip) => clip.active_generation && isActiveStatus(clip.active_generation.status)).length;
  const ready =
    list.length > 0 &&
    list.every((clip) => {
      const chosen = (clip.versions || []).find((version) => version.selected);
      return Boolean(chosen && (chosen.approved || chosen.status === 'approved') && chosen.preview_available);
    });
  const running = rows.some((row) => isActiveStatus(row.status));
  const current = [...rows].reverse().find((row) => row.status === 'completed' && row.output_available) || null;
  const latestFailed = [...rows].reverse().find((row) => row.status === 'failed') || null;
  let sceneVideo = 'Not ready';
  if (running) sceneVideo = 'Building';
  else if (current) sceneVideo = 'Ready';
  else if (latestFailed) sceneVideo = 'Could not be built';

  return {
    total: list.length,
    selected,
    approved,
    generating,
    ready,
    sceneVideo,
    current,
    failed: latestFailed,
    assemblies: rows,
  };
}

export function boardStatus(planScene) {
  const production = planScene?.production;
  if (!production || !production.clip_count) return null;
  if (production.scene_video === 'building' || production.generating_count > 0) {
    return production.scene_video === 'building'
      ? { label: 'Building scene', tone: 'progress' }
      : { label: 'Generating clips', tone: 'progress' };
  }
  if (production.changes_count > 0 && production.selected_count < production.clip_count) {
    return { label: 'Changes requested', tone: 'warning' };
  }
  if (production.review_count > 0 && production.selected_count < production.clip_count) {
    return { label: 'Needs review', tone: 'warning' };
  }
  if (production.scene_video === 'ready') return { label: 'Scene ready', tone: 'success' };
  if (production.scene_video === 'failed') return { label: 'Could not be built', tone: 'danger' };
  if (production.selected_count === production.clip_count) return { label: 'Ready to build', tone: 'success' };
  if (production.failed_count > 0 && production.selected_count === 0 && production.review_count === 0) {
    return { label: 'Generation failed', tone: 'danger' };
  }
  if (production.selected_count > 0) {
    return { label: `${production.selected_count} of ${production.clip_count} clips ready`, tone: 'progress' };
  }
  return { label: 'Ready to create', tone: 'neutral' };
}

export function soundSummary(sounds) {
  const rows = Array.isArray(sounds) ? sounds : [];
  if (!rows.length) return 'Not added';
  if (rows.some((sound) => isActiveStatus(sound.status))) return 'Generating';
  const files = rows.filter((sound) => sound.has_file);
  if (!files.length) return 'Not added';
  if (files.some((sound) => sound.approved_label || sound.review_status === 'approved')) return 'Approved';
  if (files.some((sound) => sound.review_status === 'pending_review')) return 'Ready for review';
  return 'Added';
}

export function plainFailure(message, fallback) {
  if (typeof message !== 'string' || message.trim() === '') return fallback;
  if (/[/\\]|\.mp4|ffmpeg|sqlstate|exception|stack trace|\buuid\b/i.test(message)) return fallback;
  return message.trim();
}

export function historyText(item) {
  if (!item) return '';
  const version = typeof item.version_label === 'string' && item.version_label && !/^[0-9a-f-]{16,}$/i.test(item.version_label) ? item.version_label : '';
  if (item.kind === 'unit_version' && item.event === 'approved') return version ? `${version} approved` : 'Version approved';
  if (item.kind === 'unit_version' && item.event === 'selected') return version ? `${version} selected` : 'Version selected';
  if (item.kind === 'unit_version' && item.event === 'changes_requested') return version ? `Changes requested for ${version}` : 'Changes requested';
  if (item.kind === 'unit_version' && item.event === 'version_created') return version ? `${version} created` : 'Clip generated';
  if (item.kind === 'unit_generation' && (item.status === 'completed' || item.event === 'completed')) return 'Clip generated';
  if (item.kind === 'scene_assembly' && item.event === 'completed') return 'Scene built';
  if (item.kind === 'scene_assembly' && item.event === 'version_created') return version ? `Scene video ${version}` : 'Scene rebuilt';
  if (item.kind === 'scene_assembly' && item.event === 'failed') return 'Build failed';
  if (item.kind === 'scene_assembly' && item.event === 'retried') return 'Build tried again';
  return '';
}

export function sceneHistory(items, sceneId) {
  return (Array.isArray(items) ? items : [])
    .filter((item) => item.scene_id === sceneId && ['unit_version', 'scene_assembly', 'unit_generation'].includes(item.kind))
    .map((item) => ({ text: historyText(item), at: item.created_at || '' }))
    .filter((row) => row.text)
    .slice(-6)
    .reverse();
}

function versionName(version) {
  return version?.label || (version?.version ? `Version ${version.version}` : 'Version');
}
