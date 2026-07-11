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
