<?php
/**
 * Plugin Name: Formula Price Sync / طلا ارز پرو
 * Plugin URI:  https://webgraphx.ir
 * Description: قیمت‌گذاری خودکار محصولات ووکامرس بر اساس نرخ ارز، طلا و فرمول‌های سفارشی – با پشتیبانی از محصولات ساده و متغیر، Circuit Breaker و لاگ تغییرات قیمت.
 * Version:     2.0.0
 * Author:      WebGraphx
 * Author URI:  https://webgraphx.ir
 * Text Domain: formula-price-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package FormulaPriceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FPS_VERSION', '2.0.0' );
define( 'FPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FPS_URL', plugin_dir_url( __FILE__ ) );
define( 'FPS_BASENAME', plugin_basename( __FILE__ ) );
define( 'FPS_FILE', __FILE__ );

$fps_autoload = FPS_PATH . 'vendor/autoload.php';
if ( ! file_exists( $fps_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'طلا ارز پرو: فایل vendor/autoload.php یافت نشد. لطفاً composer install را اجرا کنید یا از بسته انتشار رسمی استفاده کنید.', 'formula-price-sync' );
			echo '</p></div>';
		}
	);
	return;
}
require_once $fps_autoload;

register_activation_hook( __FILE__, array( 'FormulaPriceSync\\Core\\DB_Installer', 'install' ) );
register_deactivation_hook( __FILE__, array( 'FormulaPriceSync\\Core\\DB_Installer', 'deactivate' ) );

/**
 * Declare WooCommerce feature compatibility (HPOS / Custom Order Tables).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
function fps_init() {
	load_plugin_textdomain( 'formula-price-sync', false, dirname( FPS_BASENAME ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'fps_woocommerce_missing_notice' );
		return;
	}

	// Admin UI always available (license form reachable even if invalid).
	\FormulaPriceSync\Admin\Admin_Menu::init();
	\FormulaPriceSync\Admin\Settings_API::init();
	\FormulaPriceSync\Admin\Ajax_Handler::init();
	\FormulaPriceSync\Admin\Product_Columns::init();
	\FormulaPriceSync\Core\Logger::init();
	\FormulaPriceSync\Core\Logger::register_settings();
	\FormulaPriceSync\Core\Cron_Manager::init();
	\FormulaPriceSync\Core\Cron_Manager::register_option();
	\FormulaPriceSync\Integrations\Notifier::init();

	add_action( 'admin_notices', array( '\FormulaPriceSync\Licensing\Zhaket_Guard', 'maybe_show_license_notice' ) );

	if ( \FormulaPriceSync\Licensing\Zhaket_Guard::should_block() ) {
		\FormulaPriceSync\Core\DB_Installer::maybe_upgrade();
		return;
	}

	\FormulaPriceSync\Admin\Metaboxes::init();
	\FormulaPriceSync\Queue\Action_Scheduler_Handler::init();
	add_action( 'admin_notices', array( '\FormulaPriceSync\API\Circuit_Breaker', 'maybe_show_admin_notice' ) );
	\FormulaPriceSync\Core\DB_Installer::maybe_upgrade();
}
add_action( 'plugins_loaded', 'fps_init', 20 );

/**
 * Admin notice when WooCommerce is missing.
 */
function fps_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html__(
				'افزونه طلا ارز پرو (Formula Price Sync) نیاز به نصب و فعال بودن ووکامرس دارد.',
				'formula-price-sync'
			);
			?>
		</p>
	</div>
	<?php
}
