<?php
// File: wp-config.php

define('DB_NAME', 'wordpress');
define('DB_USER', 'root');
define('DB_PASSWORD', 'root');
define('DB_HOST', 'db');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

define('AUTH_KEY',         'YQw:TGEQ`Lo,T/ux(aG+wl-hrRGxv([+wpvQV~q< zON ln]9jc|vx152.}dyvYd');
define('SECURE_AUTH_KEY',  '|JC=|QfLWH-v; e6WRw,GSv_,+TH>:Df|k|I!d2W^yCbOS~(Z}+ |~x&j.8#ok*9');
define('LOGGED_IN_KEY',    'Zm[T))a7VH1C|kAZgIDT4 f2Km,pl)-toH*VXhJR^x+:Rwz`qrV&<#!9iQy|z%hj');
define('NONCE_KEY',        '|B8OQ%>(ah},jA2q]i{;F[iuuiz$z=_m+v_EJ@V**ozP|$EsG4rV81D;#LyHIng/');
define('AUTH_SALT',        ',FT-%T;. rhS&4*aV|r6qv`gNSRkVLL+oo WJ3(H~qx[h,#ly4I+L-m X5(|M3hD');
define('SECURE_AUTH_SALT', '4U`UT`tK>iEn3u(@IPstz@iZ0Z{C@~T.A-9]FsifX|i(Q+zHc|MvNN-dTcO7lm<z');
define('LOGGED_IN_SALT',   '.ACS7bTltf!tpr`Mh(CDF!1W{o@1JsNq;QNZC$G0Q7i~m^glHn!Q++o}[u|spB3i');
define('NONCE_SALT',       '1, 76ur(<]TXski%t m;$%^9xWxZ#(1bCr)WKRAF>>(rP(=ozv5Ak-za_[5dRI*q');

$table_prefix = 'wp_';
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);
define('FS_METHOD', 'direct');

define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);

if (! defined('ABSPATH')) {
  define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
