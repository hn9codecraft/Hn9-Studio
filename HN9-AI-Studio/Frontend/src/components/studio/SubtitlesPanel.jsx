import { useEffect, useState } from 'react';
import { m16Message } from '../../services/studioMessagesM16';
import { friendlyError } from '../../services/studioMessages';
import { burnSubtitles, createSubtitles, downloadSubtitles, listSubtitles } from '../../services/storySubtitlesService';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, StatusBadge } from './StudioUi';

function stamp(ms) {
  const total = Math.max(0, Number(ms) || 0);
  const minutes = Math.floor(total / 60000);
  const seconds = Math.floor((total % 60000) / 1000);
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

export default function SubtitlesPanel() {
  const { projectId, reelId } = useStudio();
  const feedback = useFeedback();
  const [renderId, setRenderId] = useState('');
  const [tracks, setTracks] = useState([]);
  const [track, setTrack] = useState(null);
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState('');

  useEffect(() => {
    if (!reelId) return undefined;
    let cancelled = false;
    import('../../services/storyService')
      .then(({ listStoryRenders }) => listStoryRenders(projectId, reelId))
      .then((renders) => {
        if (cancelled) return;
        const completed = (renders || []).find((render) => render.status === 'completed' && render.has_file);
        setRenderId(completed?.id || '');
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [projectId, reelId]);

  useEffect(() => {
    if (!renderId || !reelId) return undefined;
    let cancelled = false;
    listSubtitles(projectId, reelId, renderId)
      .then((rows) => {
        if (!cancelled) setTracks(rows);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [projectId, reelId, renderId]);

  if (!renderId) return null;
  const current = track || null;

  async function make() {
    setBusy('make');
    try {
      const created = await createSubtitles(projectId, reelId, renderId);
      setTrack(created);
      setTracks(await listSubtitles(projectId, reelId, renderId));
    } catch (error) {
      feedback.error(m16Message(error) || friendlyError(error, 'Subtitles could not be made.'));
    } finally {
      setBusy('');
    }
  }

  async function burn() {
    if (!current?.uuid) return;
    setBusy('burn');
    try {
      const render = await burnSubtitles(projectId, reelId, renderId, current.uuid);
      setConfirming(false);
      feedback.success('A new copy of the video with subtitles is ready. The original stays.');
      if (render?.id) setRenderId(render.id);
    } catch (error) {
      feedback.error(m16Message(error) || friendlyError(error, 'The subtitled video could not be built.'));
    } finally {
      setBusy('');
    }
  }

  return (
    <section className="mt-4" aria-labelledby="subtitles-title">
      <h3 className="h6" id="subtitles-title">Subtitles</h3>
      <div className="d-flex flex-wrap gap-2 mb-2">
        <BusyButton type="button" className="btn btn-outline-primary btn-sm" busy={busy === 'make'} busyLabel="Making…" onClick={make}>
          Make subtitles
        </BusyButton>
        {current ? (
          <>
            <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => downloadSubtitles(projectId, reelId, renderId, current.uuid, 'srt')}>Download SRT</button>
            <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => downloadSubtitles(projectId, reelId, renderId, current.uuid, 'vtt')}>Download VTT</button>
          </>
        ) : null}
      </div>
      {current?.timing_mode && current.timing_mode !== 'exact' ? (
        <p className="small mb-2">
          <StatusBadge tone="progress">Approximate timing</StatusBadge>
          <span className="ms-2">Made from older voiceovers without word timing; make the voiceover again for exact timing.</span>
        </p>
      ) : null}
      {current?.cues?.length ? (
        <ol className="list-unstyled d-grid gap-1 mb-2" style={{ fontFamily: '"Nirmala UI", "Noto Sans Gujarati", "Noto Sans Devanagari", system-ui, sans-serif' }}>
          {current.cues.map((cue, index) => (
            <li key={`${cue.start_ms}-${index}`}>
              <span className="small text-secondary me-2">{stamp(cue.start_ms)}</span>
              {String(cue.text || '').split('\n').join(' ')}
            </li>
          ))}
        </ol>
      ) : null}
      {tracks.length > 0 && !current ? <p className="small text-secondary">A subtitle track already exists for this video. Make subtitles again to preview it.</p> : null}
      {current ? (
        confirming ? (
          <div className="d-grid gap-2">
            <p className="mb-0">A new copy of the video with subtitles is made; the original stays.</p>
            <div className="d-flex gap-2">
              <BusyButton type="button" busy={busy === 'burn'} busyLabel="Burning…" onClick={burn}>Burn into video</BusyButton>
              <button type="button" className="btn btn-link btn-sm" onClick={() => setConfirming(false)}>Cancel</button>
            </div>
          </div>
        ) : (
          <button type="button" className="btn btn-primary btn-sm" onClick={() => setConfirming(true)}>Burn into video</button>
        )
      ) : null}
    </section>
  );
}
