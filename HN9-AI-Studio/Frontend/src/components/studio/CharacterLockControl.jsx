import { useState } from 'react';
import { lockCharacter, unlockCharacter } from '../../services/storyCharacterLockService';
import { getStoryCharacterReferenceFileUrl } from '../../services/storyService';
import { friendlyError } from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, StatusBadge, StoredPicture } from './StudioUi';

export default function CharacterLockControl({ character }) {
  const { projectId, refresh } = useStudio();
  const feedback = useFeedback();
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const locked = character?.lock;
  const approved = character?.approved_reference;

  async function run(action, success) {
    setBusy(true);
    try {
      await action();
      await refresh(['characters']);
      feedback.success(success);
      setConfirm(false);
    } catch (err) {
      feedback.error(friendlyError(err, 'The character look could not be updated.'));
    } finally {
      setBusy(false);
    }
  }

  if (!character?.id) return null;

  if (locked) {
    return (
      <div className="mt-2">
        <StatusBadge tone="success">Locked</StatusBadge>
        <p className="small mb-2 mt-1">{locked.identity_text}</p>
        {locked.reference_uuid ? (
          <StoredPicture
            pictureKey={locked.reference_uuid}
            load={() => getStoryCharacterReferenceFileUrl(projectId, character.id, locked.reference_uuid)}
            alt={`Locked picture of ${character.name}`}
          />
        ) : null}
        {confirm ? (
          <div className="mt-2">
            <p className="small mb-1">New clips will no longer be forced to match this look.</p>
            <BusyButton type="button" className="btn btn-outline-secondary btn-sm" busy={busy} busyLabel="Unlocking…" onClick={() => run(() => unlockCharacter(projectId, character.id), `${character.name} is unlocked.`)}>
              Unlock
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setConfirm(false)}>
              Keep locked
            </button>
          </div>
        ) : (
          <button type="button" className="btn btn-link btn-sm px-0" onClick={() => setConfirm(true)}>
            Unlock
          </button>
        )}
      </div>
    );
  }

  if (!approved) {
    return (
      <p className="small text-secondary mb-0 mt-2">
        Approve a reference picture first.{' '}
        <a href="#character-references">Go to pictures</a>
      </p>
    );
  }

  return (
    <BusyButton
      type="button"
      className="btn btn-outline-primary btn-sm mt-2"
      busy={busy}
      busyLabel="Locking…"
      onClick={() => run(() => lockCharacter(projectId, character.id, approved.id), `${character.name}'s look is locked.`)}
    >
      Lock look
    </BusyButton>
  );
}
