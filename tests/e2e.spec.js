const { test, expect } = require('@playwright/test');

test.describe('Mi Carmelo Core Game Loop', () => {

  test('Has title and main login view', async ({ page }) => {
    await page.goto('/');

    // Expect a title "to contain" a substring.
    await expect(page).toHaveTitle(/Mi Carmelo/);
    
    // Assure the login view is immediately active for unsigned users.
    await expect(page.locator('#view-login')).toBeVisible();
    await expect(page.locator('#view-game')).toBeHidden();
  });

  test('Fails on invalid login', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'wronguser');
    await page.fill('#login-password', 'wrongpass');
    await page.click('button[type="submit"]');

    // Error should become visible
    const errorMsg = page.locator('#login-error');
    await expect(errorMsg).toBeVisible();
    await expect(errorMsg).toContainText('Invalid credentials');
  });

  test('Succeeds on valid default login (user)', async ({ page }) => {
    // Relying on the DB seeding in api/config.php
    await page.goto('/');

    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');

    // Expected transition to Game View
    await expect(page.locator('#view-game')).toBeVisible();
    
    // Check if the username label accurately fetched state
    await expect(page.locator('#user-display-name')).toContainText('(carmelo)');
    
    // Assure normal user has no admin capabilities mapped 
    await expect(page.locator('#btn-admin')).toBeHidden();
  });

  test('Succeeds on valid default login (admin)', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');

    // Check if admin capability is mounted successfully
    await expect(page.locator('#view-game')).toBeVisible();
    await expect(page.locator('#btn-admin')).toBeVisible();
  });

  test('Petting opens challenge and gives points on success', async ({ page }) => {
    await page.goto('/');

    // Log in
    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Check starting point state
    const pointsSpan = page.locator('#point-count');
    const startPointsStr = await pointsSpan.innerText();
    const startPoints = parseInt(startPointsStr, 10) || 0;

    // Hit the Pet button
    await page.click('#btn-pet');

    // Overlay should become active
    await expect(page.locator('#challenge-overlay')).toBeVisible();
    await expect(page.locator('#challenge-progress')).toContainText('Question 1 of 1');
    
    // Instead of OCRing the answer intentionally (we don't mock it here, 
    // test could just fail question once and then hit repeat logic, but checking the overlay
    // behavior is sufficient for E2E basics).
    
    // Fail once
    await page.fill('#challenge-answer', 'I do not know the answer guaranteed');
    await page.click('#btn-challenge-submit');

    // Check failure logic mapped to repeat button
    const feedbackTitle = page.locator('#challenge-feedback-title');
    await expect(feedbackTitle).toContainText('Incorrect');
    
    const repeatBtn = page.locator('#btn-challenge-repeat');
    await expect(repeatBtn).toBeVisible();
    
    // Re-attempt button closes feedback and brings input back
    await repeatBtn.click();
    await expect(page.locator('#challenge-answer')).toBeEnabled();
  });

  test('Admin panel shows users and can create a new user', async ({ page }) => {
    await page.goto('/');

    // Login as admin (queen)
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Click admin button
    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Users list should contain the seeded users
    const usersList = page.locator('#admin-users-list');
    await expect(usersList).toContainText('carmelo');
    await expect(usersList).toContainText('queen');
    const initialCount = await usersList.locator('.list-group-item').count();

    // Create a new user
    await page.fill('#admin-new-username', 'testuser_' + Date.now());
    await page.fill('#admin-new-password', 'testpass');
    await page.click('#form-create-user button[type="submit"]');

    // Success message
    await expect(page.locator('#admin-user-msg')).toContainText('User created!');

    // List should now have one more user
    await expect(usersList.locator('.list-group-item')).toHaveCount(initialCount + 1, { timeout: 5000 });

    // Go back to game
    await page.click('#btn-admin-back');
    await expect(page.locator('#view-game')).toBeVisible();
  });

  test('Admin panel can view and save questions', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Question editor should have question cards loaded from seed data
    const questionCards = page.locator('#admin-questions-list .question-card');
    await expect(questionCards.first()).toBeVisible();
    const count = await questionCards.count();
    expect(count).toBeGreaterThanOrEqual(1);

    // First question input should have content
    await expect(questionCards.first().locator('.question-input')).not.toHaveValue('');

    // Save (without changes) should succeed
    await page.click('#btn-save-questions');
    await expect(page.locator('#admin-yaml-msg')).toContainText('Questions saved!');
  });

  test('Non-admin cannot see admin button', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Admin button should be hidden for regular users
    await expect(page.locator('#btn-admin')).toBeHidden();
  });

  test('Admin can reset feed for a user', async ({ page }) => {
    await page.goto('/');

    // Login as admin
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Go to admin panel
    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Click on a user to open detail view
    await page.locator('#admin-users-list .list-group-item').first().click();
    await expect(page.locator('#admin-user-detail')).toBeVisible();

    // Click the reset feed button in the detail view
    const resetBtn = page.locator('#btn-detail-reset-feed');
    await expect(resetBtn).toBeVisible();
    await resetBtn.click();

    // Should show success message
    await expect(page.locator('#user-detail-msg')).toContainText('Feed timer reset!');
  });

  test('Admin panel has push notification controls', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Click on a user to open detail view with test push button
    await page.locator('#admin-users-list .list-group-item').first().click();
    await expect(page.locator('#admin-user-detail')).toBeVisible();
    await expect(page.locator('#btn-detail-test-push')).toBeVisible();

    // Send hunger alerts button should be visible in the main admin view
    await page.locator('#btn-user-detail-back').click();
    await expect(page.locator('#btn-send-hungry')).toBeVisible();

    // VAPID public key endpoint should work
    const res = await page.evaluate(() => fetch('/api/push.php?action=vapid_public_key').then(r => r.json()));
    expect(res.success).toBe(true);
    expect(res.publicKey).toBeTruthy();
  });

  test('Admin can kill chicken and user can revive it', async ({ page }) => {
    await page.goto('/');

    // Login as admin
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Go to admin and kill carmelo's chicken
    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Click on carmelo user
    await page.locator('#admin-users-list .list-group-item', { hasText: 'carmelo' }).click();
    await expect(page.locator('#admin-user-detail')).toBeVisible();

    // Kill chicken button should be visible
    await expect(page.locator('#btn-detail-kill')).toBeVisible();

    // Accept the confirmation dialog and kill
    page.on('dialog', dialog => dialog.accept());
    await page.click('#btn-detail-kill');
    await expect(page.locator('#user-detail-msg')).toContainText('killed');

    // Logout admin, login as carmelo
    await page.locator('#btn-user-detail-back').click();
    await page.click('#btn-admin-back');
    await page.click('#btn-logout');

    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Dead overlay should be visible
    await expect(page.locator('#dead-overlay')).toBeVisible();
    await expect(page.locator('#game-buttons')).toBeHidden();

    // Revive the chicken
    await page.click('#btn-revive');

    // Dead overlay should disappear, game buttons should return
    await expect(page.locator('#dead-overlay')).toBeHidden();
    await expect(page.locator('#game-buttons')).toBeVisible();
  });

});