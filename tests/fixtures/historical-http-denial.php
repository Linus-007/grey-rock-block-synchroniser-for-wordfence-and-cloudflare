<?php
// A second barrier in addition to the Docker network's denied external egress.
add_filter('pre_http_request', static function () {
  return new WP_Error('fixture_egress_denied', 'External HTTP is denied in the historical regression fixture.');
}, PHP_INT_MAX);
