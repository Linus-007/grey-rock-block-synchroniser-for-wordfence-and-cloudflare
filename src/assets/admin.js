'use strict';

(function () {
        const mode = document.getElementById('cloudflare_mode');
        const section = document.getElementById(
          'firewall-sync-manual-list-management'
        );

        if (!mode || !section) {
          return;
        }

        function updateManualListVisibility() {
          section.style.display =
            mode.value === 'account_list' ? '' : 'none';
        }

        mode.addEventListener(
          'change',
          updateManualListVisibility
        );

        updateManualListVisibility();
      }());

(function () {
        const mode = document.getElementById('cloudflare_mode');

        if (!mode) {
          return;
        }

        const zoneField = document.getElementById('cloudflare_zone_id');
        const accountField = document.getElementById('cloudflare_account_id');
        const listNameField = document.getElementById('cloudflare_list_name');

        const zoneRow = zoneField ? zoneField.closest('tr') : null;
        const accountRow = accountField ? accountField.closest('tr') : null;
        const listNameRow = listNameField ? listNameField.closest('tr') : null;

        function show(row, visible) {
          if (row) {
            row.style.display = visible ? '' : 'none';
          }
        }

        function update() {
          const accountListMode = mode.value === 'account_list';

          show(zoneRow, !accountListMode);
          show(accountRow, accountListMode);
          show(listNameRow, accountListMode);
        }

        mode.addEventListener('change', update);
        update();
      }());

/* GREY ROCK CLOUDFLARE FIELD LAYOUT START */
(function () {
  'use strict';

  function findActionControl(label) {
    return Array.from(
      document.querySelectorAll(
        'input[type="submit"], button[type="submit"], button'
      )
    ).find(function (control) {
      const controlLabel = (
        control.value || control.textContent || ''
      ).trim();

      return controlLabel === label;
    }) || null;
  }

  function findLabelByText(labelText) {
    return Array.from(
      document.querySelectorAll('label')
    ).find(function (label) {
      return label.textContent.trim() === labelText;
    }) || null;
  }

  function initialiseCloudflareFieldLayout() {
    /*
     * Cloudflare Account ID must appear before Cloudflare API Token.
     */
    const accountInput = document.getElementById(
      'cloudflare_account_id'
    );
    const tokenInput = document.getElementById(
      'cloudflare_api_token'
    );

    if (accountInput && tokenInput) {
      const accountRow = accountInput.closest('tr');
      const tokenRow = tokenInput.closest('tr');

      if (
        accountRow
        && tokenRow
        && accountRow.parentNode === tokenRow.parentNode
      ) {
        tokenRow.parentNode.insertBefore(accountRow, tokenRow);
      }
    }

    /*
     * Rebuild the Cloudflare Tests controls in this order:
     *
     * 1. Validate Saved Cloudflare Configuration
     * 2. Test IP address
     * 3. Test description
     * 4. Run Test Block
     */
    const heading = Array.from(
      document.querySelectorAll('h2, h3')
    ).find(function (element) {
      return element.textContent.trim() === 'Cloudflare Tests';
    });

    const validateButton = findActionControl(
      'Validate Saved Cloudflare Configuration'
    );
    const runButton = findActionControl('Run Test Block');
    const originalLabel = findLabelByText('Test IP address');

    let testInput = null;

    if (originalLabel && originalLabel.htmlFor) {
      testInput = document.getElementById(
        originalLabel.htmlFor
      );
    }

    if (!testInput && originalLabel) {
      testInput = originalLabel.querySelector('input');
    }

    if (!testInput) {
      testInput = document.querySelector(
        'input[name*="test_ip"], input[id*="test_ip"]'
      );
    }

    if (
      !heading
      || !validateButton
      || !runButton
      || !testInput
    ) {
      return;
    }

    if (
      document.querySelector(
        '.grey-rock-cloudflare-test-controls'
      )
    ) {
      return;
    }

    const originalRows = new Set();

    [
      validateButton,
      runButton,
      testInput,
      originalLabel,
    ].forEach(function (element) {
      if (!element) {
        return;
      }

      const row = element.closest('tr');

      if (row) {
        originalRows.add(row);
      }
    });

    const originalInputContainer = testInput.parentElement;
    const description = originalInputContainer
      ? originalInputContainer.querySelector('.description')
      : null;

    const controls = document.createElement('div');
    controls.className =
      'grey-rock-cloudflare-test-controls';

    const validateContainer = document.createElement('div');
    validateContainer.className =
      'grey-rock-cloudflare-test-validate';

    const fieldContainer = document.createElement('div');
    fieldContainer.className =
      'grey-rock-cloudflare-test-field';

    const testLabel = document.createElement('label');
    testLabel.className =
      'grey-rock-cloudflare-test-label';
    testLabel.textContent = 'Test IP address';

    if (!testInput.id) {
      testInput.id = 'grey-rock-cloudflare-test-ip';
    }

    testLabel.htmlFor = testInput.id;

    const runContainer = document.createElement('div');
    runContainer.className =
      'grey-rock-cloudflare-test-run';

    validateContainer.appendChild(validateButton);
    fieldContainer.appendChild(testLabel);
    fieldContainer.appendChild(testInput);

    if (description) {
      fieldContainer.appendChild(description);
    }

    runContainer.appendChild(runButton);

    controls.appendChild(validateContainer);
    controls.appendChild(fieldContainer);
    controls.appendChild(runContainer);

    heading.insertAdjacentElement('afterend', controls);

    originalRows.forEach(function (row) {
      row.hidden = true;
      row.setAttribute('aria-hidden', 'true');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener(
      'DOMContentLoaded',
      initialiseCloudflareFieldLayout
    );
  } else {
    initialiseCloudflareFieldLayout();
  }
}());
/* GREY ROCK CLOUDFLARE FIELD LAYOUT END */
