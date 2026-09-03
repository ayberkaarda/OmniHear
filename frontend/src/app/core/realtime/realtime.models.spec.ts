import {
  channelNameFor,
  parseFeedbackAnalyzed,
  parseQuotaThresholdReached
} from './realtime.models';

describe('realtime payload parsing', () => {
  it('names the channel Echo will prefix with `private-`', () => {
    expect(channelNameFor(7)).toBe('company.7');
  });

  it('accepts the exact payload the contract documents', () => {
    expect(
      parseFeedbackAnalyzed({
        feedback_id: 1,
        sentiment_label: 'negative',
        sentiment_score: -0.5497,
        category: 'bug',
        model_version: 'omnihear-onnx-f50df013ccc9'
      })
    ).toEqual({
      feedback_id: 1,
      sentiment_label: 'negative',
      sentiment_score: -0.5497,
      category: 'bug',
      model_version: 'omnihear-onnx-f50df013ccc9'
    });

    expect(parseQuotaThresholdReached({ used: 160, limit: 200, remaining: 40 })).toEqual({
      used: 160,
      limit: 200,
      remaining: 40
    });
  });

  /**
   * These frames come off a socket, i.e. from outside anything TypeScript
   * checked. A `NaN` score written into the store would propagate into the KPI
   * running mean and silently corrupt every later reading, so a malformed frame
   * is dropped rather than partially applied.
   */
  it('drops a frame that is not the documented shape', () => {
    for (const payload of [
      null,
      undefined,
      'feedback.analyzed',
      {},
      { feedback_id: '1', sentiment_label: 'negative', sentiment_score: -0.5, category: 'bug', model_version: 'v' },
      { feedback_id: 1, sentiment_label: 'furious', sentiment_score: -0.5, category: 'bug', model_version: 'v' },
      { feedback_id: 1, sentiment_label: 'negative', sentiment_score: -0.5, category: 'invoice', model_version: 'v' },
      { feedback_id: 1, sentiment_label: 'negative', sentiment_score: Number.NaN, category: 'bug', model_version: 'v' },
      { feedback_id: 1, sentiment_label: 'negative', sentiment_score: -0.5, category: 'bug' }
    ]) {
      expect(parseFeedbackAnalyzed(payload)).toBeNull();
    }

    for (const payload of [null, {}, { used: 1, limit: 2 }, { used: 'a', limit: 2, remaining: 1 }]) {
      expect(parseQuotaThresholdReached(payload)).toBeNull();
    }
  });
});
