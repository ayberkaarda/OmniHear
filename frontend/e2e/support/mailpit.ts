import { APIRequestContext, expect } from '@playwright/test';

import { MAILPIT_URL } from './env';

/**
 * Reading the verification mail out of the development mailbox.
 *
 * Spec 7.1 makes verification mandatory and the tenant surface enforces it
 * (`403 EMAIL_NOT_VERIFIED`), so a journey test cannot skip past it. The only
 * honest way through is the way a user goes through: open the link that was
 * mailed. Mailpit (a compose service, see `infra/docker-compose.dev.yml`)
 * receives it over SMTP and serves it over HTTP.
 *
 * The alternative — a test-only endpoint that hands out verification links — is
 * a permanent authentication bypass in production code, so it is not on the
 * table.
 */

interface MailpitSummary {
  ID: string;
  Subject: string;
  To: { Address: string }[];
}

interface MailpitSearch {
  total: number;
  messages: MailpitSummary[];
}

interface MailpitMessage {
  ID: string;
  Subject: string;
  Text: string;
  HTML: string;
}

/** Every message currently addressed to `address`, newest first. */
export async function messagesFor(api: APIRequestContext, address: string): Promise<MailpitSummary[]> {
  const response = await api.get(`${MAILPIT_URL}/api/v1/search`, {
    params: { query: `to:"${address}"` }
  });
  expect(response.ok(), `Mailpit search failed with ${response.status()}`).toBeTruthy();
  const body = (await response.json()) as MailpitSearch;
  return body.messages ?? [];
}

/**
 * Waits for the verification mail and returns the SPA link it carries.
 *
 * Polls rather than sleeps: the mail is sent inside the register request, but
 * SMTP delivery and Mailpit's indexing are still two hops after the HTTP
 * response the browser already has.
 *
 * The link is read out of the plain-text part, which carries it unescaped —
 * the HTML part has `&amp;` between the query parameters, and a signature
 * verified against a mangled query string fails for a reason that has nothing
 * to do with the application.
 */
export async function waitForVerificationLink(api: APIRequestContext, address: string): Promise<string> {
  let link: string | null = null;

  await expect
    .poll(
      async () => {
        const messages = await messagesFor(api, address);
        if (messages.length === 0) {
          return null;
        }

        const detail = await api.get(`${MAILPIT_URL}/api/v1/message/${messages[0].ID}`);
        expect(detail.ok(), `Mailpit message fetch failed with ${detail.status()}`).toBeTruthy();
        const body = (await detail.json()) as MailpitMessage;

        const match = /https?:\/\/\S*\/auth\/verify-email\?[^\s)>\]"]+/.exec(body.Text ?? '');
        link = match ? match[0] : null;
        return link;
      },
      {
        message: `No verification mail with a /auth/verify-email link arrived for ${address}`,
        timeout: 30_000,
        intervals: [500, 500, 1_000, 1_000, 2_000]
      }
    )
    .not.toBeNull();

  // `expect.poll` has already proved this, but the compiler has not seen it.
  if (link === null) {
    throw new Error(`No verification link for ${address}`);
  }

  return link;
}
