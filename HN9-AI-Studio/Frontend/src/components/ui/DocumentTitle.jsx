import { useEffect } from 'react';

export default function DocumentTitle({ title }) {
  useEffect(() => {
    const previous = document.title;
    document.title = title ? `${title} · HN9 AI Studio` : 'HN9 AI Studio';

    return () => {
      document.title = previous;
    };
  }, [title]);

  return null;
}
