from playwright.sync_api import sync_playwright

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context()
    page = context.new_page()

    # Superadmin login
    page.goto("http://localhost:8000/superadmin_login.php")
    page.get_by_label("Username").fill("superadmin")
    page.get_by_label("Password").fill("superadmin@123")
    page.get_by_role("button", name="Login").click()
    page.wait_for_url("http://localhost:8000/superadmin_panel.php")

    # Screenshot of superadmin_panel.php
    page.screenshot(path="jules-scratch/verification/superadmin_panel.png")

    # Navigate to vendor_requests.php and take screenshot
    page.get_by_role("link", name="Unit Requests").click()
    page.wait_for_url("http://localhost:8000/vendor_requests.php")
    page.screenshot(path="jules-scratch/verification/vendor_requests.png")

    # Navigate to manage_admins.php and take screenshot
    page.get_by_role("link", name="Manage Admins").click()
    page.wait_for_url("http://localhost:8000/manage_admins.php")
    page.screenshot(path="jules-scratch/verification/manage_admins.png")

    # Navigate to manage_products.php and take screenshot
    page.get_by_role("link", name="Products").click()
    page.wait_for_url("http://localhost:8000/manage_products.php")
    page.screenshot(path="jules-scratch/verification/manage_products.png")

    # Navigate to manage_dispositions.php and take screenshot
    page.get_by_role("link", name="Dispositions").click()
    page.wait_for_url("http://localhost:8000/manage_dispositions.php")
    page.screenshot(path="jules-scratch/verification/manage_dispositions.png")

    context.close()
    browser.close()

with sync_playwright() as playwright:
    run(playwright)
