import { expect, Locator, Page } from '@playwright/test';

/**
 * The app shell as the journey touches it, by role and `data-testid` only.
 *
 * Copy changes with every design pass; these hooks do not. The shell renders
 * the company name exactly once (`shell-company`), at every width, so a check
 * on it can never resolve to a second, CSS-hidden copy.
 */

/** The single element that names the signed-in company. */
export function shellCompany(page: Page): Locator {
  return page.getByTestId('shell-company');
}

/** Asserts the shell is up and names `companyName`, once. */
export async function expectSignedInAs(page: Page, companyName: string): Promise<void> {
  const company = shellCompany(page);
  await expect(company).toHaveCount(1);
  await expect(company).toBeVisible();
  await expect(company).toHaveText(companyName);
}

/**
 * Signs out through the account dialog: the avatar button opens it at every
 * width, and Sign out lives inside it. Waits for the landing page.
 */
export async function signOut(page: Page): Promise<void> {
  const trigger = page.getByTestId('shell-account-trigger');
  await trigger.click();

  const dialog = page.getByTestId('shell-account-sheet');
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute('role', 'dialog');

  await dialog.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/$/);
}
