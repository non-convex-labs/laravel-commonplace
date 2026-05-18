<!--
  Thanks for sending a PR. A few quick notes:

  - One logical change per PR. CI gates on Pint, PHPStan (level 5), and
    PHPUnit; please run them locally first (see CONTRIBUTING.md).
  - Reference the issue with `Fixes #123` or `Refs #123`.
  - Update CHANGELOG.md under [Unreleased] in the existing voice.
  - If the change was meaningfully AI-assisted, include the attribution
    block from CONTRIBUTING.md at the bottom of the description.
-->

## Summary

<!-- One or two sentences on what this changes and why. -->

## Test plan

<!--
  Bullets describing what you ran and what you'd like a reviewer to
  re-run. Mention any test you added.
-->

- [ ] `composer test`
- [ ] `vendor/bin/pint --test`
- [ ] `vendor/bin/phpstan analyse`

## Reading verification

<!--
  The last word of CONTRIBUTING.md. 1990s manual-lookup DRM, but for
  open source — it's a small proof you skimmed the contributing guide.
  Scroll to the bottom of that file. Capitalization and punctuation
  don't matter.

  Replace this comment block with your answer on its own line.
-->

your-answer-here
