<?php $sort_url = add_query_arg(['ip' => $_GET['ip']], network_admin_url('admin.php')); ?>
<!-- ok: echoed-request -->
<a href="<?php echo esc_url($sort_url); ?>">Sort</a>
<!-- ruleid: echoed-request -->
<a href="<?php echo $sort_url; ?>">Unsafe</a>
<?php
// ok: echoed-request
echo esc_html($_GET['text']);
// ok: echoed-request
echo esc_attr($_GET['attribute']);
// ruleid: echoed-request
echo $_GET['raw'];
// ruleid: echoed-request
echo esc_url($_GET['wrong_context']);
?>
<!-- ruleid: echoed-request -->
<div onclick="<?php echo esc_url($_GET['event']); ?>">Unsafe context</div>
<!-- ruleid: echoed-request -->
<a href="<?php echo esc_url($_GET['url']) . $_GET['suffix']; ?>">Unsafe suffix</a>
<!-- ruleid: echoed-request -->
<div data-href="<?php echo esc_url($_GET['data']); ?>">Other attribute</div>
<!-- ruleid: echoed-request -->
<a href="<?php echo esc_url($_GET['url'], null, 'db'); ?>">Non-display escaping</a>
