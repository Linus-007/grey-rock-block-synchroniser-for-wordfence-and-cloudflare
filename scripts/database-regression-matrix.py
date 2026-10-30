#!/usr/bin/env python3
"""Disposable MySQL/MariaDB WordPress matrix; no published ports or host services."""
import os
import argparse
from cve_approval import source_fingerprint
from pathlib import Path
import secrets
import signal
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / 'reports/1.3.16'
REPORT.mkdir(parents=True, exist_ok=True)

def run(args, **kwargs):
    return subprocess.run(args, check=True, text=True, **kwargs)

def main():
    def interrupted(signum, frame):
        raise KeyboardInterrupt
    signal.signal(signal.SIGTERM, interrupted)
    fixture = ROOT / '.tools/1316/wordfence.zip'
    if not fixture.exists():
        fixture.parent.mkdir(parents=True, exist_ok=True)
        run(['curl', '-fLsS', 'https://downloads.wordpress.org/plugin/wordfence.9.0.2.zip', '-o', str(fixture)])
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--engine', action='append', choices=['mysql:8.4', 'mariadb:11.8'])
    parser.add_argument('--faults-only', action='store_true', help='Diagnose database fault behavior without Wordfence/sync setup')
    args = parser.parse_args()
    candidate_source = source_fingerprint(ROOT)
    for engine in args.engine or ('mysql:8.4', 'mariadb:11.8'):
        name = 'greyrock-1316-' + secrets.token_hex(5)
        network, db, cli, volume, data = [name + suffix for suffix in ('-net', '-db', '-cli', '-wp', '-data')]
        env = os.environ.copy()
        env.update(MYSQL_ROOT_PASSWORD=secrets.token_hex(24), MYSQL_DATABASE='greyrock_task13_matrix',
                   MYSQL_USER='wordpress', MYSQL_PASSWORD=secrets.token_hex(24))
        env['WORDPRESS_DB_PASSWORD'] = env['MYSQL_PASSWORD']
        with (REPORT / (engine.replace(':', '-') + '.log')).open('w') as log:
            def command(*args, **kwargs):
                return run(['docker', *args], stdout=log, stderr=subprocess.STDOUT, env=env, **kwargs)
            try:
                log.write('SOURCE_SHA256=' + candidate_source + '\n'); log.flush()
                command('network', 'create', '--internal', network)
                command('volume', 'create', volume)
                command('volume', 'create', data)
                command('run', '-d', '--name', db, '--network', network,
                        '-e', 'MYSQL_ROOT_PASSWORD', '-e', 'MYSQL_DATABASE', '-e', 'MYSQL_USER', '-e', 'MYSQL_PASSWORD',
                        '-v', data + ':/var/lib/mysql', engine)
                for attempt in range(180):
                    probe = subprocess.run(['docker', 'exec', db, 'sh', '-c',
                        'MYSQL_PWD="$MYSQL_PASSWORD" ' + ('mysql' if engine.startswith('mysql') else 'mariadb') + ' -uwordpress -e "SELECT 1" "$MYSQL_DATABASE"'],
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                    if probe.returncode == 0:
                        break
                    time.sleep(1)
                else:
                    raise RuntimeError('Database readiness timeout')
                command('run', '--rm', '--name', name + '-seed', '--network', network, '-v', volume + ':/var/www/html',
                        '--entrypoint', 'sh', 'wordpress:php8.5-apache', '-c',
                        'cp -a /usr/src/wordpress/. /var/www/html/; chown -R 33:33 /var/www/html')
                command('run', '-d', '--name', cli, '--network', network, '--user', '0:0',
                        '-e', 'WORDPRESS_DB_PASSWORD', '-e', 'GREY_ROCK_TASK13_DISPOSABLE=YES',
                        '-v', volume + ':/var/www/html', '-v', str(ROOT) + ':/repo:ro',
                        '-v', str(ROOT / 'tests/docker/php-security.ini') + ':/usr/local/etc/php/conf.d/zz-greyrock-security.ini:ro',
                        '--entrypoint', 'sh', 'wordpress:cli-php8.5', '-c', 'sleep infinity')
                config = "<?php\ndefine('DB_NAME', 'greyrock_task13_matrix');\ndefine('DB_USER','wordpress');\ndefine('DB_PASSWORD',getenv('WORDPRESS_DB_PASSWORD'));\ndefine('DB_HOST','" + db + "');\ndefine('WP_ENVIRONMENT_TYPE','local');\ndefine('WP_DEBUG',true);\ndefine('DISABLE_WP_CRON',true);\n$table_prefix='wp_';\nif (!defined('ABSPATH')) { define('ABSPATH',__DIR__.'/'); }\nrequire_once ABSPATH.'wp-settings.php';\n"
                command('exec', '-i', cli, 'sh', '-c', 'cat > /var/www/html/wp-config.php', input=config)
                command('exec', '-i', cli, 'sh', '-c', 'mkdir -p /var/www/html/wp-content/mu-plugins; cat > /var/www/html/wp-content/mu-plugins/deny-http.php',
                        input="<?php add_filter('pre_http_request', static fn () => new WP_Error('fixture_egress', 'External HTTP denied'), PHP_INT_MIN);")
                def wp(*args):
                    return command('exec', cli, 'wp', '--allow-root', '--path=/var/www/html', *args)
                wp('eval', '--skip-wordpress', "$_SERVER['HTTP_HOST']='greyrock.test'; $_SERVER['REQUEST_URI']='/'; define('WP_INSTALLING', true); require '/var/www/html/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; wp_install('Matrix', 'matrix', 'matrix@example.test', true, '', bin2hex(random_bytes(24))); update_option('home','http://greyrock.test'); update_option('siteurl','http://greyrock.test');")
                command('exec', cli, 'sh', '-c', 'cp -a /repo/src /var/www/html/wp-content/plugins/grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
                if args.faults_only:
                    wp('plugin', 'activate', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
                    wp('eval-file', '--use-include', '/repo/tests/integration/greyrock-1316-faults.php')
                    log.write('FAULTS ONLY PASS\n')
                else:
                    wp('plugin', 'install', '/repo/.tools/1316/wordfence.zip', '--activate')
                    wp('plugin', 'activate', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
                    wp('eval-file', '--use-include', '/repo/tests/integration/greyrock-1316.php')
                    wp('eval-file', '--use-include', '/repo/tests/integration/greyrock-1316-faults.php')
                    wp('core', 'multisite-convert', '--title=Matrix Network')
                    multisite_config = config.replace("$table_prefix=", "define('MULTISITE',true);\ndefine('SUBDOMAIN_INSTALL',false);\ndefine('DOMAIN_CURRENT_SITE','greyrock.test');\ndefine('PATH_CURRENT_SITE','/');\ndefine('SITE_ID_CURRENT_SITE',1);\ndefine('BLOG_ID_CURRENT_SITE',1);\n$table_prefix=")
                    command('exec', '-i', cli, 'sh', '-c', 'cat > /var/www/html/wp-config.php', input=multisite_config)
                    wp('plugin', 'activate', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare', '--network')
                    wp('eval-file', '--use-include', '/repo/tests/integration/greyrock-1316.php')
                    wp('eval-file', '--use-include', '/repo/tests/integration/greyrock-1316-faults.php')
                    wp('eval-file', '--use-include', '/repo/tests/integration/greyrock-1316-ui.php')
                    wp('plugin', 'deactivate', 'wordfence')
                    command('exec', cli, 'php', '/repo/tests/integration/task14-mariadb-suite.php', '/var/www/html', 'all')
                    if source_fingerprint(ROOT) != candidate_source:
                        raise RuntimeError('Candidate PHP source changed during matrix execution')
                    log.write('MATRIX PASS\n')
            finally:
                # Remove only resources named by this invocation, on every exit.
                for container in (cli, db, name + '-seed'):
                    subprocess.run(['docker', 'rm', '-f', container], stdout=log, stderr=subprocess.STDOUT)
                cleanup = []
                for item in (volume, data):
                    cleanup.append(subprocess.run(['docker', 'volume', 'rm', item], stdout=log, stderr=subprocess.STDOUT).returncode)
                cleanup.append(subprocess.run(['docker', 'network', 'rm', network], stdout=log, stderr=subprocess.STDOUT).returncode)
                if any(cleanup):
                    raise RuntimeError('Task resource cleanup failed')
                log.write('CLEANUP PASS\n')
        print(engine + ': PASS', flush=True)

if __name__ == '__main__':
    main()
