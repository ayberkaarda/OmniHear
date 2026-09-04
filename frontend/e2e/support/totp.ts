import { createHmac } from 'node:crypto';

/**
 * RFC 6238 TOTP, computed here rather than pulled from a package.
 *
 * The whole algorithm is an HMAC-SHA1 over a counter (RFC 4226 section 5) and
 * `node:crypto` already has the hard part, so a dependency for this would add a
 * supply chain to `frontend/package.json` for thirty lines of arithmetic — and
 * `package-lock.json` is a file the regression gate checks.
 *
 * This is a *test* implementation. It plays the part of the phone in the E2E
 * journey; the application never generates a code, it only ever verifies one.
 */

/** The interval every authenticator app uses, and the one the backend assumes. */
export const TIME_STEP_SECONDS = 30;

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** RFC 4648 base32, no padding, case-insensitive — how the secret is published. */
function decodeBase32(secret: string): Buffer {
  const cleaned = secret.replace(/=+$/, '').replace(/\s+/g, '').toUpperCase();
  let bits = 0;
  let value = 0;
  const bytes: number[] = [];

  for (const character of cleaned) {
    const index = BASE32_ALPHABET.indexOf(character);
    if (index === -1) {
      throw new Error(`"${character}" is not a base32 character; the secret is not the one the API published.`);
    }
    value = (value << 5) | index;
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      bytes.push((value >>> bits) & 0xff);
    }
  }

  return Buffer.from(bytes);
}

/** The counter both sides derive from the wall clock. */
export function currentTimeStep(atMs: number = Date.now()): number {
  return Math.floor(atMs / 1000 / TIME_STEP_SECONDS);
}

/** RFC 4226 section 5.3: HMAC, dynamic truncation, six decimal digits. */
export function totpCode(secret: string, timeStep: number = currentTimeStep()): string {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(timeStep));

  const digest = createHmac('sha1', decodeBase32(secret)).update(counter).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const binary =
    ((digest[offset] & 0x7f) << 24) |
    ((digest[offset + 1] & 0xff) << 16) |
    ((digest[offset + 2] & 0xff) << 8) |
    (digest[offset + 3] & 0xff);

  return (binary % 1_000_000).toString().padStart(6, '0');
}

/**
 * A code the server has not seen yet.
 *
 * The contract makes an accepted code single-use: the last accepted timestep is
 * stored and anything at or below it is refused as a replay. So the step that
 * confirms enrolment and the step that signs in with the same secret cannot
 * share a timestep, and this waits out the boundary when they would.
 *
 * The wait is on the clock TOTP is *defined* against, not on a machine being
 * slow — the only place in this suite where a duration, rather than a
 * condition, is the thing being waited for.
 */
export async function freshTotpCode(
  secret: string,
  afterTimeStep?: number
): Promise<{ code: string; timeStep: number }> {
  let timeStep = currentTimeStep();

  while (afterTimeStep !== undefined && timeStep <= afterTimeStep) {
    await new Promise((resolve) => setTimeout(resolve, 1_000));
    timeStep = currentTimeStep();
  }

  return { code: totpCode(secret, timeStep), timeStep };
}
