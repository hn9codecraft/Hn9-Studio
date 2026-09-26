import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import {
  deleteStoryTimelineClip,
  duplicateStoryTimelineClip,
  getStoryTimeline,
  replaceStoryTimelineClip,
  listStoryReels,
  reorderStoryTimeline,
  setStoryTimelineTransition,
  splitStoryTimelineClip,
  trimStoryTimelineClip,
} from '../../services/storyService';

const transitionTypes = ['cut', 'dissolve', 'fade'];

export default function StoryTimelinePanel({ projectId }) {
  const [reels, setReels] = useState([]);
  const [reelId, setReelId] = useState('');
  const [timeline, setTimeline] = useState(null);
  const [inMs, setInMs] = useState('0');
  const [outMs, setOutMs] = useState('8000');
  const [splitAt, setSplitAt] = useState('4000');
  const [transitionType, setTransitionType] = useState('dissolve');
  const [replaceSourceId, setReplaceSourceId] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    listStoryReels(projectId)
      .then((rows) => {
        if (!cancelled) {
          setReels(rows);
          setReelId(rows[0]?.id || '');
        }
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Unable to load reels.');
      });
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  useEffect(() => {
    if (!reelId) return undefined;
    let cancelled = false;
    getStoryTimeline(projectId, reelId)
      .then((result) => {
        if (!cancelled) setTimeline(result);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Unable to load the timeline.');
      });
    return () => {
      cancelled = true;
    };
  }, [projectId, reelId]);

  async function run(action, clip, index) {
    if (!reelId || !timeline) return;
    setError('');
    try {
      let next = timeline;
      const clips = timeline.clips || [];
      if (action === 'trim') {
        next = await trimStoryTimelineClip(projectId, reelId, clip.id, Number(inMs), Number(outMs));
      } else if (action === 'split') {
        next = await splitStoryTimelineClip(projectId, reelId, clip.id, Number(splitAt));
      } else if (action === 'replace') {
        next = await replaceStoryTimelineClip(projectId, reelId, clip.id, replaceSourceId);
      } else if (action === 'duplicate') {
        next = await duplicateStoryTimelineClip(projectId, reelId, clip.id);
      } else if (action === 'delete') {
        next = await deleteStoryTimelineClip(projectId, reelId, clip.id);
      } else if (action === 'up' || action === 'down') {
        const ids = clips.map((item) => item.id);
        const swap = action === 'up' ? index - 1 : index + 1;
        if (swap < 0 || swap >= ids.length) return;
        [ids[index], ids[swap]] = [ids[swap], ids[index]];
        next = await reorderStoryTimeline(projectId, reelId, ids);
      } else if (action === 'transition' && clips[index + 1]) {
        next = await setStoryTimelineTransition(
          projectId,
          reelId,
          clip.id,
          clips[index + 1].id,
          transitionType,
          500,
        );
      }
      setTimeline(next);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to update the timeline.');
    }
  }

  const clips = timeline?.clips || [];

  return (
    <section className="card border-0 glass-card">
      <div className="card-body">
        <h2 className="h5 mb-3">Timeline</h2>
        {error ? <AlertMessage variant="danger" message={error} /> : null}
        <label className="form-label" htmlFor="timeline-reel">Reel</label>
        <select
          id="timeline-reel"
          className="form-select mb-3"
          value={reelId}
          onChange={(event) => setReelId(event.target.value)}
        >
          {reels.map((reel) => (
            <option key={reel.id} value={reel.id}>{reel.title || reel.id}</option>
          ))}
        </select>
        <div className="row g-2 mb-3">
          <div className="col-4">
            <label className="form-label" htmlFor="timeline-in">Trim in</label>
            <input id="timeline-in" className="form-control" value={inMs} onChange={(event) => setInMs(event.target.value)} />
          </div>
          <div className="col-4">
            <label className="form-label" htmlFor="timeline-out">Trim out</label>
            <input id="timeline-out" className="form-control" value={outMs} onChange={(event) => setOutMs(event.target.value)} />
          </div>
          <div className="col-4">
            <label className="form-label" htmlFor="timeline-split">Split at</label>
            <input id="timeline-split" className="form-control" value={splitAt} onChange={(event) => setSplitAt(event.target.value)} />
          </div>
        </div>
        <label className="form-label" htmlFor="timeline-replace">Replace source</label>
        <input
          id="timeline-replace"
          className="form-control mb-3"
          value={replaceSourceId}
          onChange={(event) => setReplaceSourceId(event.target.value)}
          placeholder="Stored scene version or audio id"
        />
        <label className="form-label" htmlFor="timeline-transition">Transition</label>
        <select
          id="timeline-transition"
          className="form-select mb-3"
          value={transitionType}
          onChange={(event) => setTransitionType(event.target.value)}
        >
          {transitionTypes.map((type) => (
            <option key={type} value={type}>{type}</option>
          ))}
        </select>
        {clips.length === 0 ? <p className="text-secondary mb-0">No clips on this timeline.</p> : null}
        <ul className="list-group">
          {clips.map((clip, index) => (
            <li className="list-group-item" key={clip.id}>
              <div className="d-flex flex-wrap justify-content-between gap-2">
                <span>
                  {index + 1}. {clip.media_kind} · {clip.in_ms}–{clip.out_ms} ms
                </span>
                <span className="d-flex flex-wrap gap-2">
                  <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => run('up', clip, index)}>Move up</button>
                  <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => run('down', clip, index)}>Move down</button>
                  <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => run('trim', clip, index)}>Trim</button>
                  <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => run('split', clip, index)}>Split</button>
                  <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => run('replace', clip, index)}>Replace</button>
                  <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => run('duplicate', clip, index)}>Duplicate</button>
                  <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => run('transition', clip, index)}>Transition</button>
                  <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => run('delete', clip, index)}>Delete</button>
                </span>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
