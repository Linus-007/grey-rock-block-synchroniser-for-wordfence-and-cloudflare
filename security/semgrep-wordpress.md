The community snapshot is downloaded and recorded by security_tools.py unchanged.
semgrep_wordpress.py verifies its entire file inventory against manifest.json and
requires the reviewed SHA-256 of lang/security/injection/echoed-request.yaml.
Only that rule receives one additional sanitizer in a temporary scan copy. All
other rule bytes, IDs, sources, sinks, severity and existing sanitizers remain.
Any upstream change to this rule requires explicit review of the adapter hash.

The sanitizer intersects the single-argument PHP esc_url($URL) call with the exact complete
<a href="<?php echo esc_url(...); ?>"> template used by Settings.php. It is
intentionally formatting-sensitive: other quote styles, spacing, assignments,
and other URL attributes remain conservative findings pending separate review.
It does not grant esc_url() global HTML, JavaScript or arbitrary attribute safety.
Tests include a tainted concatenated suffix, non-URL output contexts, and
explicit non-display esc_url(..., null, 'db') calls.

The mandatory scan runner tests both repository rules and the adapted upstream
rule before scanning all src PHP files. wordpress-adaptation.json records the
upstream commit, original and adapted rule hashes, and adapter hash alongside
the unchanged installation manifest. No downloaded .tools rule is edited.
