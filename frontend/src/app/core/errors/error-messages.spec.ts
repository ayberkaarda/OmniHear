import { API_ERROR_CODES, NETWORK_ERROR_CODE } from './api-error';
import { errorMessageForCode } from './error-messages';

describe('errorMessageForCode', () => {
  it('has a distinct message for every code in the contract catalogue', () => {
    const messages = API_ERROR_CODES.map((code) => errorMessageForCode(code));

    expect(messages).toHaveLength(10);
    expect(new Set(messages).size).toBe(messages.length);
    for (const message of messages) {
      expect(message.length).toBeGreaterThan(0);
    }
  });

  it('covers the client-side NETWORK_ERROR code as well', () => {
    expect(errorMessageForCode(NETWORK_ERROR_CODE)).not.toBe(errorMessageForCode('SERVER_ERROR'));
  });

  it('falls back to a generic message for an unknown code and logs it', () => {
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => undefined);

    const message = errorMessageForCode('SOME_FUTURE_BACKEND_CODE');

    expect(message).toBe(errorMessageForCode('ANOTHER_UNKNOWN_CODE'));
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('SOME_FUTURE_BACKEND_CODE'));
    warn.mockRestore();
  });

  it('never returns the raw server code as the user-facing message', () => {
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => undefined);

    expect(errorMessageForCode('TEAPOT')).not.toContain('TEAPOT');

    warn.mockRestore();
  });
});
