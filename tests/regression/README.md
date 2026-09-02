# Publishing regression harness

Drives every path that publishes to Quant and asserts **which project each
request reached**. Publishing to the wrong project is the failure that matters
on a site serving several clients from one Drupal instance, and it is invisible
to the unit and kernel suites: those check the decision, this checks what
actually leaves Drupal.

Every bug this harness was written for was silent in production. A page
published to the wrong client's site, a delete withdrawing a live page from
another client's project, a translated page publishing a redirect at
`//fr/node/1` — none raised an error, and one of them only reached the PHP
error log where nobody looks.

## What it covers

39 cases across the shapes a customer can actually have:

| Section | What it asserts |
|---|---|
| Single site | Seed, cron, tome deploy, webform page, direct `seedNode`, deletion — all reaching the one project, nothing elsewhere |
| Multi domain | The same per domain, with `--uri`, asserting nothing crosses between projects |
| Guard cases | An unrecognised host publishes nothing: no content, no redirects, no withdrawals |
| Multilingual | Translations publish at their aliases, no path carries a doubled slash |
| Multilingual x multi domain | Every language reaching only its own domain's project, including search records and deletion |

Assertions are on routing, not counts. "Everything reached the expected
project and nothing reached another" survives a fixture gaining content or
languages; an exact count does not, and a brittle count is how a real gap hid
once already.

## Requirements

- A ddev Drupal 11 site with this module installed
- `drupal/domain`, `drupal/domain_config`, `drupal/token`, `drupal/purge`,
  `drupal/tome`, `drupal/webform`
- Two extra hostnames on the ddev project, `clienta` and `clientb`
- Content in three languages with aliases, matching the paths asserted in the
  multilingual sections

## Running it

Start the recording endpoint on the host, then point the site at it:

```
python3 mock-quant-api.py            # listens on :8899, logs to requests.jsonl
drush config:set quant_api.settings api_endpoint http://host.docker.internal:8899
```

Then:

```
./regression.sh
```

It prints a pass or fail line per case and exits non-zero on any failure. It
sets up and tears down its own domains, so it is safe to re-run.

## Run it against both Domain lines

Domain 2.0.x and 3.0.x both support Drupal 11 and both are stable, and they
store `domain_config` overrides differently. A run against one proves nothing
about the other. This was found the hard way: the per-domain purge resolution
named a service that exists only in 3.x, so on 2.x it silently returned the
base project for every domain, and a full green run on 3.0.1 said nothing
about it.

The harness writes overrides in both layouts, so it runs unchanged on either.
Switch version and repeat:

```
ddev drush pmu domain_config domain -y
ddev composer require "drupal/domain:^2.0"     # or ^3.0
ddev drush en domain domain_config -y
./regression.sh
```

Delete the domain records before switching, or the entities outlive the schema
that created them.

## Why a mock rather than the live API

The mock records the `Quant-Project` header of every request, which is the
single thing that decides whose site changes. Against the live API that is
invisible without going and looking in each project, and a mistake would
publish real content to a real customer.

Run one seed against a live throwaway project as well, to confirm the wire
format is still accepted. The mock proves routing; only the real API proves
the payload.
