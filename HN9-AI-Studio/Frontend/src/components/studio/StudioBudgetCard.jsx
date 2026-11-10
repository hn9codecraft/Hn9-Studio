import { useEffect, useState } from 'react';
import { getStoryCostSummary, updateStoryBudget } from '../../services/storyService';
import { friendlyError } from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton } from './StudioUi';
import { SpendLines } from './CostEstimate';

export default function StudioBudgetCard() {
  const { projectId } = useStudio();
  const feedback = useFeedback();
  const [summary, setSummary] = useState(null);
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('INR');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getStoryCostSummary(projectId)
      .then((result) => {
        if (cancelled || !result) return;
        setSummary(result);
        setAmount(result.budget?.amount ?? '');
        setCurrency(result.budget?.currency || 'INR');
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  async function save(event) {
    event.preventDefault();
    setSaving(true);
    try {
      const result = await updateStoryBudget(projectId, {
        budget_amount: amount === '' ? null : Number(amount),
        budget_currency: currency,
      });
      setSummary(result);
      feedback.success('Project budget saved.');
    } catch (err) {
      feedback.error(friendlyError(err, 'The budget could not be saved. Please try again.'));
    } finally {
      setSaving(false);
    }
  }

  const budgetUsd = summary?.budget?.amount_usd;
  const spent = Number(summary?.spent_total_usd || 0);
  const ratio = budgetUsd > 0 ? Math.min(100, Math.round((spent / budgetUsd) * 100)) : 0;
  const tone = ratio >= 100 ? 'bg-danger' : ratio >= 80 ? 'bg-warning' : 'bg-success';

  return (
    <section className="glass-card p-3 mb-3">
      <h2 className="h6">Project budget</h2>
      <p className="small text-secondary">A generation is stopped before it is sent if the estimate would pass this amount.</p>
      <form className="row g-2 align-items-end" onSubmit={save}>
        <div className="col-auto">
          <label className="form-label" htmlFor="story-budget-amount">
            Amount
          </label>
          <input
            id="story-budget-amount"
            className="form-control"
            type="number"
            min="0"
            step="0.01"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
          />
        </div>
        <div className="col-auto">
          <label className="form-label" htmlFor="story-budget-currency">
            Currency
          </label>
          <select id="story-budget-currency" className="form-select" value={currency} onChange={(event) => setCurrency(event.target.value)}>
            <option value="INR">INR</option>
            <option value="USD">USD</option>
          </select>
        </div>
        <div className="col-auto">
          <BusyButton type="submit" className="btn btn-primary" busy={saving} busyLabel="Saving…">
            Save budget
          </BusyButton>
        </div>
      </form>
      {budgetUsd > 0 ? (
        <div className="progress mt-3" role="progressbar" aria-valuenow={ratio} aria-valuemin={0} aria-valuemax={100}>
          <div className={`progress-bar ${tone}`} style={{ width: `${ratio}%` }}>
            {ratio}%
          </div>
        </div>
      ) : null}
      {summary ? <SpendLines summary={summary} /> : null}
    </section>
  );
}
