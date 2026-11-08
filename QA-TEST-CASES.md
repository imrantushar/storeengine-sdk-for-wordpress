# StoreEngine License SDK — QA test cases (`fix/wporg-best-practices`)

## Changes in this branch

- Every SDK PHP file blocks direct access (`ABSPATH` guard). `init.php` and `SE_License_SDK_Version_Manager.php` keep `declare( strict_types=1 )` as their first statement.

## Test cases

Test with a host plugin that bundles this SDK (StoreEngine free, and one Pro product), PHP 7.4 and 8.x.

| # | Steps | Expected |
|---|---|---|
| 1 | Activate host plugin(s). | No PHP errors; license screen loads. |
| 2 | Activate, deactivate and re-check a license. | Works as before. |
| 3 | Two products bundling **different** SDK versions active together. | Highest version is elected; both products work. |
| 4 | Update check, one-click update and rollback. | Work as before. |
| 5 | Opt-in notice and promotions; dismiss a promotion. | Dismiss works (nonce-checked AJAX). |
| 6 | Open `…/vendor/<sdk path>/init.php` or any `classes/*.php` directly in the browser. | Blank page, no PHP error or path disclosure. |
| 7 | `php -l` on all SDK files under PHP 7.4. | No syntax errors. |
