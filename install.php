<?php
function generate_random($length, $symbols = false) {

	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	if ($symbols) $chars .= '.:,;-_@?¿¡!"$%&=';

	return substr(str_shuffle(str_repeat($chars, $length)), 0, $length);

}

function output($message) {

	echo "<p>$message</p>";

	@ob_flush();

	@flush();

	sleep(1);

}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$db_name = $_POST['db_name'];
	$db_user = $_POST['db_user'];
	$db_pass = $_POST['db_pass'];
	$db_host = $_POST['db_host'];

	$prefix = $_POST['prefix'];

	$email = $_POST['email'];
	$username  = $_POST['username'];
	$password  = $_POST['password'];

	echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalando...</title></head><body style='font-family:sans-serif;'>";
	echo "<h2>Instalación de WordPress</h2>";

	output("Descargando WordPress...");
	file_put_contents("latest.zip", file_get_contents("https://wordpress.org/latest.zip"));
	$zip = new ZipArchive;
	if ($zip->open('latest.zip') === TRUE) {
		$zip->extractTo('.');
		$zip->close();
		unlink('latest.zip');
		output("WordPress descomprimido.");
	} else {
		die("No se pudo descomprimir WordPress.");
	}

	foreach (scandir('wordpress') as $file) {
		if (!in_array($file, ['.', '..'])) {
			rename("wordpress/$file", $file);
		}
	}
	rmdir('wordpress');

	output("Creando archivo wp-config.php...");
	$config = file_get_contents("wp-config-sample.php");
	$config = str_replace(
		['database_name_here', 'username_here', 'password_here', 'localhost', '$table_prefix  = \'wp_\';'],
		[$db_name, $db_user, $db_pass, $db_host, "\$table_prefix  = '{$prefix}';"],
		$config
	);
	file_put_contents("wp-config.php", $config);

	define('WP_INSTALLING', true);
	require_once __DIR__ . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';

	output("Instalando WordPress...");
	wp_install('Sitio Web', $username, $email, true, '', $password);

	global $wpdb;
	$wpdb->update($wpdb->users, ['user_nicename' => 'admin'], ['user_login' => $username]);

	output("Aplicando configuraciones básicas...");
	update_option('timezone_string', 'Europe/Madrid');
	update_option('uploads_use_yearmonth_folders', 0);
	update_option('permalink_structure', '/%postname%/');
	update_option('thumbnail_size_w', 100);
	update_option('thumbnail_size_h', 100);
	update_option('medium_size_w', 500);
	update_option('medium_size_h', 500);
	update_option('large_size_w', 1000);
	update_option('large_size_h', 1000);

	output("Instalando tema personalizado...");
	$theme_url = 'https://github.com/orenesgrupo/orenes/archive/refs/heads/main.zip';
	$theme_zip = 'orenes.zip';
	file_put_contents($theme_zip, file_get_contents($theme_url));
	$theme_dir = WP_CONTENT_DIR . '/themes/';
	unzip_file($theme_zip, $theme_dir);
	unlink($theme_zip);
	$folders = glob($theme_dir . 'orenes-*');
	if ($folders) {
		$theme_slug = basename($folders[0]);
		switch_theme($theme_slug);
		foreach (wp_get_themes() as $slug => $theme) {
			if ($slug !== $theme_slug) delete_theme($slug);
		}
	}

	output("Instalando plugins...");
	$plugins = [
		'elementor' => 'https://downloads.wordpress.org/plugin/elementor.latest-stable.zip',
		'complianz' => 'https://downloads.wordpress.org/plugin/complianz-gdpr.latest-stable.zip',
		'seo-by-rank-math' => 'https://downloads.wordpress.org/plugin/seo-by-rank-math.latest-stable.zip',
		'wp-cerber' => 'https://downloads.wpcerber.com/plugin/wp-cerber.zip',
	];
	include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$upgrader = new Plugin_Upgrader();
	foreach ($plugins as $slug => $url) {
		$upgrader->install($url);
		activate_plugin("$slug/$slug.php");
	}

	output("Configurando Elementor...");
	update_option('elementor_disable_color_schemes', 'yes');
	update_option('elementor_disable_typography_schemes', 'yes');
	update_option('elementor_load_fa4_shim', '');
	update_option('elementor_experiment-markup', 'active');
	update_option('elementor_page_title_selector', 'h1');
	update_option('elementor_active', 'yes');
	update_option('elementor_use_google_fonts', 'no');
	update_option('elementor_fonts_manager_font_display', 'swap');

	output("Ajustando página de inicio...");
	wp_update_post(['ID' => 2, 'post_title' => 'Inicio', 'post_name' => 'inicio']);
	update_option('show_on_front', 'page');
	update_option('page_on_front', 2);

	$posts = get_posts(['post_type' => 'post', 'numberposts' => -1]);
	foreach ($posts as $p) wp_delete_post($p->ID, true);

	output("<strong>✅ Instalación finalizada correctamente.</strong>");
output("Serás redirigido al panel de administración en 5 segundos...");

echo '<meta http-equiv="refresh" content="5;url=' . site_url('/wp-admin') . '">';
echo "<p style='color:gray;font-size:13px;'>Este instalador se autodestruirá automáticamente.</p>";

// Eliminar el instalador
unlink(__FILE__);

echo "</body></html>";
exit;
}

function htmlInput($name, $value, $label) {
	return "<label><strong>$label</strong><br><input type='text' name='$name' value=\"$value\" required style='width:100%;margin-bottom:10px;'></label>";
}

$defaults = [
	'db_name'     => generate_random(20),
	'db_user'     => generate_random(20),
	'db_pass'     => generate_random(25, true),
	'db_host'     => 'localhost',
	'prefix'      => generate_random(7) . '_',
	'email'       => 'samuel.cerezo@orenesgrupo.com',
	'username'  => generate_random(20, true),
	'password'  => generate_random(25, true)
];
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Instalador WordPress</title></head>
<body style="font-family:sans-serif;max-width:600px;margin:auto;">
<h2>Instalador WordPress Personalizado</h2>
<form method="POST">
	<?= htmlInput('db_name', $defaults['db_name'], 'Nombre de la base de datos') ?>
	<?= htmlInput('db_user', $defaults['db_user'], 'Usuario de la base de datos') ?>
	<?= htmlInput('db_pass', $defaults['db_pass'], 'Contraseña de la base de datos') ?>
	<?= htmlInput('db_host', $defaults['db_host'], 'Host de la base de datos') ?>
	<?= htmlInput('prefix',  $defaults['prefix'],  'Prefijo de tablas') ?>
	<?= htmlInput('email',   $defaults['email'],   'Email del administrador') ?>
	<?= htmlInput('username', $defaults['username'], 'Usuario administrador (login)') ?>
	<?= htmlInput('password', $defaults['password'], 'Contraseña del administrador') ?>
	<input type="submit" value="Instalar WordPress" style="padding:10px 20px;margin-top:10px;">
</form>
</body>
</html>
