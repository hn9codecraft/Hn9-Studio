import assert from 'node:assert/strict';
import test from 'node:test';
import {
  availableModes,
  boardStatus,
  BUILD_FAILED,
  clipState,
  clipWindow,
  GENERATE_FAILED,
  historyText,
  nextClipIntent,
  NOT_CONFIGURED,
  PARTS_NOT_READY,
  plainFailure,
  sceneHistory,
  sceneSummary,
  soundSummary,
  versionActions,
} from './productionClips.js';

function slots(durations) {
  let start = 0;
  return durations.map((duration, index) => {
    const clip = {
      sequence: index + 1,
      start_second: start,
      duration_seconds: duration,
      end_second: start + duration,
      versions: [],
      message: 'No video versions yet.',
    };
    start += duration;
    return clip;
  });
}

test('clip windows follow the server slots, including a 7 second remainder', () => {
  const windows = {
    30: slots([10, 10, 10]).map(clipWindow),
    40: slots([10, 10, 10, 10]).map(clipWindow),
    47: slots([10, 10, 10, 10, 7]).map(clipWindow),
    50: slots([10, 10, 10, 10, 10]).map(clipWindow),
    60: slots([10, 10, 10, 10, 10, 10]).map(clipWindow),
  };
  assert.deepEqual(windows[30], ['0–10 sec', '10–20 sec', '20–30 sec']);
  assert.deepEqual(windows[47], ['0–10 sec', '10–20 sec', '20–30 sec', '30–40 sec', '40–47 sec']);
  assert.equal(windows[47][4], '40–47 sec');
  assert.equal(windows[50].length, 5);
  assert.equal(windows[60].length, 6);
  assert.equal(clipWindow({ start_second: 0, end_second: 40 }), '0–40 sec');
});

test('scene summary counts approval and selection from server versions', () => {
  const clips = slots([10, 10, 10, 10, 7]);
  clips.forEach((clip, index) => {
    clip.versions = [
      { label: 'Version A', status: 'approved', approved: true, selected: true, preview_available: true },
    ];
    if (index === 2) {
      clip.versions.push({ label: 'Version B', status: 'pending_review', approved: false, selected: false, preview_available: true });
    }
  });
  const summary = sceneSummary(clips, []);
  assert.equal(summary.total, 5);
  assert.equal(summary.approved, 5);
  assert.equal(summary.selected, 5);
  assert.equal(summary.ready, true);
  assert.equal(summary.sceneVideo, 'Not ready');

  clips[4].versions = [{ label: 'Version A', status: 'approved', approved: true, selected: false, preview_available: true }];
  const missing = sceneSummary(clips, []);
  assert.equal(missing.selected, 4);
  assert.equal(missing.ready, false);
  assert.equal(PARTS_NOT_READY, 'Some video parts are not ready yet.');
});

test('an unapproved selected version does not count as ready', () => {
  const clips = slots([10]);
  clips[0].versions = [{ label: 'Version A', status: 'pending_review', approved: false, selected: true, preview_available: true }];
  assert.equal(sceneSummary(clips, []).ready, false);
});

test('generation, review, approval, selection and failure labels come from server state', () => {
  assert.equal(clipState({ active_generation: { status: 'processing' }, versions: [] }).label, 'Generating');
  assert.equal(clipState({ versions: [], message: 'No video version was created. The generation failed.' }).detail, GENERATE_FAILED);
  assert.equal(clipState({ versions: [], message: 'No video versions yet.' }).label, 'No video yet');
  const review = clipState({
    versions: [{ label: 'Version A', status: 'pending_review', approved: false, selected: false }],
  });
  assert.equal(review.label, 'Version A — Ready for review');
  const approved = clipState({
    versions: [{ label: 'Version A', status: 'approved', approved: true, selected: false }],
  });
  assert.equal(approved.label, 'Version A — Approved');
  const selected = clipState({
    versions: [
      { label: 'Version A', status: 'approved', approved: true, selected: false },
      { label: 'Version B', status: 'approved', approved: true, selected: true },
    ],
  });
  assert.equal(selected.label, 'Version B — Selected');
  assert.equal(clipState({ versions: [{ label: 'Version C', status: 'needs_rework', selected: false }] }).label, 'Changes requested');
});

test('only an approved version that is not already selected can be selected', () => {
  assert.equal(versionActions({ status: 'pending_review', preview_available: true, selected: false }).select, false);
  assert.equal(versionActions({ status: 'pending_review', preview_available: true, approved: false }).approve, true);
  assert.equal(versionActions({ status: 'approved', approved: true, preview_available: true, selected: false }).select, true);
  assert.equal(versionActions({ status: 'approved', approved: true, preview_available: true, selected: true }).select, false);
  assert.equal(versionActions({ status: 'needs_rework', preview_available: true }).requestChanges, false);
  assert.equal(versionActions({ status: 'pending_review', preview_available: false }).approve, false);
});

test('changing the selected version leaves the other version approved', () => {
  const before = [
    { label: 'Version A', status: 'approved', approved: true, selected: true, preview_available: true },
    { label: 'Version B', status: 'approved', approved: true, selected: false, preview_available: true },
  ];
  const after = [
    { label: 'Version A', status: 'approved', approved: true, selected: false, preview_available: true },
    { label: 'Version B', status: 'approved', approved: true, selected: true, preview_available: true },
  ];
  assert.equal(versionActions(before[1]).select, true);
  assert.equal(versionActions(after[0]).select, true);
  assert.equal(versionActions(after[1]).select, false);
  assert.equal(after[0].approved, true);
  assert.equal(clipState({ versions: after }).label, 'Version B — Selected');
});

test('assembly progress, a preserved older version and failure stay distinct', () => {
  const building = sceneSummary(slots([10]), [{ status: 'processing', output_available: false, label: null }]);
  assert.equal(building.sceneVideo, 'Building');
  assert.equal(building.current, null);

  const ready = sceneSummary(slots([10]), [
    { status: 'completed', output_available: true, label: 'Version A', version: 'A' },
    { status: 'completed', output_available: true, label: 'Version B', version: 'B' },
  ]);
  assert.equal(ready.sceneVideo, 'Ready');
  assert.equal(ready.current.label, 'Version B');
  assert.equal(ready.assemblies[0].label, 'Version A');

  const failed = sceneSummary(slots([10]), [{ status: 'failed', output_available: false, error_message: 'The scene video could not be built.' }]);
  assert.equal(failed.sceneVideo, 'Could not be built');
  assert.equal(failed.current, null);
  assert.equal(plainFailure(failed.failed.error_message, BUILD_FAILED), 'The scene video could not be built.');
  assert.equal(plainFailure('ffmpeg -i /tmp/secret.mp4', BUILD_FAILED), BUILD_FAILED);
});

test('scene cards use the production summary and never a fixed 30 second scene', () => {
  assert.deepEqual(boardStatus({ production: { clip_count: 5, selected_count: 0, approved_count: 0, review_count: 0, changes_count: 0, generating_count: 0, failed_count: 0, scene_video: 'none' } }), {
    label: 'Ready to create',
    tone: 'neutral',
  });
  assert.equal(boardStatus({ production: { clip_count: 5, selected_count: 5, review_count: 0, changes_count: 0, generating_count: 0, failed_count: 0, scene_video: 'none' } }).label, 'Ready to build');
  assert.equal(boardStatus({ production: { clip_count: 5, selected_count: 4, review_count: 0, changes_count: 0, generating_count: 0, failed_count: 0, scene_video: 'none' } }).label, '4 of 5 clips ready');
  assert.equal(boardStatus({ production: { clip_count: 5, selected_count: 5, review_count: 0, changes_count: 0, generating_count: 1, failed_count: 0, scene_video: 'ready' } }).label, 'Generating clips');
  assert.equal(boardStatus({ production: { clip_count: 3, selected_count: 3, review_count: 0, changes_count: 0, generating_count: 0, failed_count: 0, scene_video: 'ready' } }).label, 'Scene ready');
  assert.equal(boardStatus({ production: { clip_count: 5, selected_count: 2, review_count: 1, changes_count: 0, generating_count: 0, failed_count: 0, scene_video: 'none' } }).label, 'Needs review');
  assert.equal(boardStatus({ production: { clip_count: 5, selected_count: 0, review_count: 0, changes_count: 0, generating_count: 0, failed_count: 1, scene_video: 'failed' } }).label, 'Could not be built');
});

test('generation modes stay within what the server says is connected', () => {
  assert.deepEqual(availableModes({ video: {} }, 0), []);
  assert.equal(NOT_CONFIGURED, 'Video generation is not configured yet.');
  assert.deepEqual(
    availableModes({ video: { text: true, image: true, reference: true } }, 0).map((mode) => mode.value),
    ['text_to_video'],
  );
  assert.deepEqual(
    availableModes({ video: { text: true, image: true, reference: false } }, 2).map((mode) => mode.label),
    ['From a description', 'From a picture'],
  );
  assert.equal(nextClipIntent(0), 'clip-1');
  assert.equal(nextClipIntent(2), 'clip-3');
});

test('sound and history stay in plain language', () => {
  assert.equal(soundSummary([]), 'Not added');
  assert.equal(soundSummary([{ status: 'processing', has_file: false }]), 'Generating');
  assert.equal(soundSummary([{ status: 'completed', has_file: true, review_status: 'pending_review' }]), 'Ready for review');
  assert.equal(soundSummary([{ status: 'completed', has_file: true, review_status: 'approved', approved_label: 'Version A' }]), 'Approved');
  assert.equal(historyText({ kind: 'unit_version', event: 'approved', version_label: 'Version B' }), 'Version B approved');
  assert.equal(historyText({ kind: 'scene_assembly', event: 'failed', version_label: 'job-uuid' }), 'Build failed');
  assert.equal(historyText({ kind: 'scene_assembly', event: 'completed' }), 'Scene built');
  const rows = sceneHistory(
    [
      { kind: 'unit_version', event: 'selected', scene_id: 'scene-1', version_label: 'Version A', created_at: '1' },
      { kind: 'video', event: 'completed', scene_id: 'scene-1', provider_key: 'video.runway' },
      { kind: 'scene_assembly', event: 'completed', scene_id: 'scene-2' },
    ],
    'scene-1',
  );
  assert.deepEqual(rows.map((row) => row.text), ['Version A selected']);
});
