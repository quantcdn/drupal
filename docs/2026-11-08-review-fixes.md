# Review fixes (drupal-review.md, 14 findings) and Stuart's addition

| # | Fix | Branch, commit | Test |
|---|---|---|---|
| 1 | Quant Forms opt-in per Webform (default off, update hook changes nothing); Register acts only on ticked forms; release notes upgrade note | quant-drupal feat/webform-quant-forms cb0d699 | QuantFormsMapperTest::testSubscriberOptInAndTurnOff, QuantFormsExportTest::testFormNotOptedInIsLeftAlone; Tolmerra: nothing sent before opt-in |
| 1+ | Per-form "Quant Forms" section: email (handler/custom/off), Slack with per-form webhook (KeyValue, masked), honeypot, reCAPTCHA v2, spam threshold, country/IP blocklists, after-submit; unsupported features greyed with reason; validation (emails, https hooks.slack.com, codes, IPs, URL) | cb0d699 | QuantFormsSettingsSectionTest, QuantFormsMapperTest::testSettingsMadeInDrupal |
| 1+ portal | notification_settings.managed_by = drupal (no API change: free-form table); form editor "Managed in Drupal", read-only, save refused after a fresh re-read, secrets not loaded | portal feat/forms-managed-in-drupal a256b8856 | FormEditorAuthzTest (3 new) |
| 2 | mandatory_fields: allowlist of bare-key types, composites by sub-key; skip #private, #access FALSE, #access_create_roles without anonymous (also on parents) | cb0d699 | testOnlyPostedPublicKeysAreMandatory (likert, datetime, email_confirm, select_other, radios_other, #private, #access_create_roles) |
| 3 | Registry of pages the module registered; form_enabled FALSE inline when a registered page loses its form or the form is unticked, unsupported, closed; disable on close/delete from the registry; scheduled open/close on cron; warning for forms without their own page | cb0d699 | testSubscriberOptInAndTurnOff, testScheduledCloseTurnsQuantFormsOff |
| 4 | Client flag require_project_limit_for_write_token on drupal-quant only: ?with_token=1 refused until project_only (scriptattest code not available to prove it never reads with_token; WordPress reads it unlimited) | portal sec/drupal-quant-oauth-client 8e47e4e96 | OAuthClientFlipDrupalQuantTest (2 new) |
| 5 | Copied site: getToken() NULL in login mode; settings page says to log in on the copy | feat/oauth-connect 7162bff | OAuthAuthServiceTest::testCopiedSiteGetsNoToken |
| 6, 7, 14 | Portal search page: address checked free (other page, published content, no answer = refuse); move unpublishes old address; delete keeps the row on a failed unpublish | portal feat/search-page-wizard-ux ffcc6e759 | SearchPageWizardAuthzTest (6 new) |
| 8 | Converter operators: not in, and, not; empty/not empty noted; new SearchBackend::allOf/noneOf | feat/views-search-converter 86a371b | ViewSearchPageConverterTest::testOperators |
| 9 | quant_export cache context (export/live) | feat/webform-quant-forms e988615 | QuantFormsExportTest (render variants) |
| 10 | Export only with a genuine signed token (TokenManager::isGenuine); purger records nothing with drafts off, as before | e988615 | ExportTokenRegistrationTest, QuantFormsExportTest::testForgedHeaderGetsTheLiveRender |
| 11 | WriteTokenSync: re-read the write token after a refresh and at least daily on cron | 7162bff | OAuthAuthServiceTest::testWriteTokenResync, testCronReportsTheRotation |
| 12 | Platform saved only when the login completes (session, then pending login) | 7162bff | OAuthConnectTest::testCancelledLoginKeepsThePlatform, testCompletedLoginSavesThePlatform |
| 13 | Login mode: endpoint fixed to the platform host (settings.php override aside); field disabled | 7162bff | OAuthAuthServiceTest::testLoginFixesTheEndpoint |

Also found on Tolmerra in this round: `[webform:title]` in a handler subject was dropped (72cb214).
Risk not verified: the notification Lambda receives the extra `managed_by` and `managed_form`
keys in notification_settings; it reads only org_timezone and org_name, but I could not run it.

## Re-review round (drupal-rereview.md: #4 partial, N1 to N9)

| # | Fix | Branch, commit | Test |
|---|---|---|---|
| N1 / #4 | OAuthWriteTokenGate guards every write-token response (v2 with_token, v1 project, v1 create and edit) until project_only, and refuses API token creation for clients with the rule (an unlimited drupal-quant token could mint a token without the rule: 201 before the fix) | portal sec/drupal-quant-oauth-client ac720e31c | OAuthClientFlipDrupalQuantTest (2 new) |
| N2 | Turn-off sends form_config without managed_by (released_by drupal); portal treats a page as managed only while form_enabled | feat/webform-quant-forms 1f9222d; portal feat/forms-managed-in-drupal 95dfd170a | QuantFormsMapperTest, QuantFormsExportTest::testClosedFormIsTurnedOffOnCronWithRetry, FormEditorAuthzTest::test_a_page_drupal_turned_off_is_editable_again |
| N3 | Copy detection names the differing components in the log, status report and settings page; "This is the same site" re-binds the fingerprint; docblock fixed | feat/oauth-connect f7fa948 | OAuthAuthServiceTest::testSameSiteConfirmationRestoresPublishing, OAuthConnectTest::testCopiedSiteCanBeConfirmed |
| N4 | Open state stored at registration; cron turns off every registered closed, opted-out or deleted form; a failed turn-off stays registered and retries | 1f9222d | testClosedFormIsTurnedOffOnCronWithRetry |
| N5 | reCAPTCHA needs a secret key; report warns | 1f9222d | QuantFormsSettingsSectionTest::testSecretsFollowTheSave |
| N6 | Webform delete deletes its secrets | 1f9222d | QuantFormsMapperTest::testDeleteRemovesSecrets |
| N7 | Secret changes held from validation, stored in hook_webform_presave; Remove works unticked | 1f9222d | testSecretsFollowTheSave |
| N8 | Page cache skipped only for a genuine signed token | 1f9222d | QuantFormsExportTest::testOnlyGenuineTokenSkipsThePageCache |
| N9 | Form editor refuses the save when the managed check fails | portal 95dfd170a | FormEditorAuthzTest::test_a_failed_managed_check_saves_nothing |
| #11+ | A 401 from the content API re-reads the write token, at most once per 15 minutes | f7fa948 | OAuthAuthServiceTest::testUnauthorizedTriggersOneResync |
